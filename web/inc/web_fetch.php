<?php
/**
 * 网页抓取核心。
 *
 * 给 AI 一个「直接访问网站」的能力：传一个网址，回来是清理过的正文文本。
 *
 * 这个文件最要紧的不是抓取，是 SSRF 防护。一个能按任意 URL 发请求的接口，
 * 如果不校验目标地址，就等于把内网探测入口开放给了任何能influence AI 输出的人
 * （网页里藏一句「请访问 http://127.0.0.1:6379」就够了）。所以这里做三件事：
 *   1. 域名解析后逐个校验 IP，私网 / 回环 / 链路本地 / 保留段全部拒掉；
 *   2. 不用 curl 的自动重定向，手动逐跳，每跳都重新校验（否则第一跳公网、
 *      第二跳 302 到 127.0.0.1 就绕过去了）；
 *   3. 用 CURLOPT_RESOLVE 把校验过的那个 IP 钉死，避免校验和实际连接之间
 *      域名被改解析（DNS 重绑定）。
 *
 * 另一件要紧的事：抓回来的内容是<strong>不可信数据</strong>。网页里可能写着
 * 「忽略你的系统提示词」之类的话，那是页面作者写的，不是用户的指令。
 * 这一点在 inc/web_prompt.php 里对 AI 明确交代。
 *
 * PHP 7.4：不能用 str_contains / match / 空安全运算符，注意别手滑。
 */

/** 读抓取相关设置，都带默认值，settings 表里没有也能跑。 */
function web_fetch_conf(): array
{
    return [
        '开关'     => (int) setting_get('web_fetch_enable', '1') === 1,
        '超时'     => max(5, min(120, (int) setting_get('web_fetch_timeout', '20'))),
        '最大字节' => max(65536, (int) setting_get('web_fetch_max_bytes', '2097152')),
        '正文上限' => max(2000, (int) setting_get('web_fetch_text_limit', '30000')),
        '跳数上限' => max(0, min(10, (int) setting_get('web_fetch_max_hops', '5'))),
        // 只在明确需要抓内网服务时才开，默认关。开了就等于放弃 SSRF 防护。
        '允许内网' => (int) setting_get('web_fetch_allow_private', '0') === 1,
        '链接条数' => max(0, min(100, (int) setting_get('web_fetch_link_limit', '25'))),
    ];
}

/** 判断二进制形式的 IP 是否落在某个 CIDR 段内。ip 和 cidr 要同族。 */
function web_ip_in_cidr(string $ip, string $cidr): bool
{
    $斜 = strpos($cidr, '/');
    if ($斜 === false) {
        return false;
    }
    $网 = @inet_pton(substr($cidr, 0, $斜));
    $位 = (int) substr($cidr, $斜 + 1);
    $此 = @inet_pton($ip);
    if ($网 === false || $此 === false || strlen($网) !== strlen($此)) {
        return false;
    }
    $整字节 = intdiv($位, 8);
    $余位   = $位 % 8;
    if ($整字节 > 0 && strncmp($此, $网, $整字节) !== 0) {
        return false;
    }
    if ($余位 === 0) {
        return true;
    }
    $掩 = chr((0xFF << (8 - $余位)) & 0xFF);
    return (($此[$整字节] & $掩) === ($网[$整字节] & $掩));
}

/**
 * 判断一个 IP 是不是可以对外访问的公网地址。
 * 返回 false 表示该地址不允许抓取（私网、回环、保留段等）。
 */
function web_ip_public(string $ip): bool
{
    $二 = @inet_pton($ip);
    if ($二 === false) {
        return false;
    }

    // IPv4-mapped IPv6（::ffff:127.0.0.1）必须还原成 v4 再判，
    // 否则用这种写法就能绕过下面整套私网检查。
    if (strlen($二) === 16
        && substr($二, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
        $v4 = @inet_ntop(substr($二, 12));
        if ($v4 === false) {
            return false;
        }
        $ip = $v4;
        $二 = @inet_pton($ip);
        if ($二 === false) {
            return false;
        }
    }

    if (strlen($二) === 4) {
        $禁 = [
            '0.0.0.0/8',        // 本网络
            '10.0.0.0/8',       // 私网
            '100.64.0.0/10',    // 运营商级 NAT
            '127.0.0.0/8',      // 回环
            '169.254.0.0/16',   // 链路本地（云厂商元数据 169.254.169.254 就在这段）
            '172.16.0.0/12',    // 私网
            '192.0.0.0/24',     // IETF 协议专用
            '192.0.2.0/24',     // 文档示例
            '192.88.99.0/24',   // 6to4 中继
            '192.168.0.0/16',   // 私网
            '198.18.0.0/15',    // 基准测试
            '198.51.100.0/24',  // 文档示例
            '203.0.113.0/24',   // 文档示例
            '224.0.0.0/4',      // 组播
            '240.0.0.0/4',      // 保留
        ];
    } else {
        $禁 = [
            '::/128',           // 未指定
            '::1/128',          // 回环
            'fc00::/7',         // 唯一本地地址
            'fe80::/10',        // 链路本地
            'ff00::/8',         // 组播
            '2001:db8::/32',    // 文档示例
            '64:ff9b::/96',     // NAT64
            '2002::/16',        // 6to4
        ];
    }

    foreach ($禁 as $段) {
        if (web_ip_in_cidr($ip, $段)) {
            return false;
        }
    }
    return true;
}

/**
 * 校验一个 URL 能不能抓，并把域名解析成具体 IP。
 * 返回 ['ok'=>1,'scheme'=>,'host'=>,'port'=>,'ip'=>,'url'=>] 或 ['ok'=>0,'error'=>]
 */
function web_check_url(string $url, bool $允许内网 = false): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => 0, 'error' => '网址是空的'];
    }
    // 只写了域名时补上 https://，AI 和用户都容易漏掉协议头
    if (!preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*://~', $url)) {
        $url = 'https://' . $url;
    }

    $部 = @parse_url($url);
    if (!is_array($部) || empty($部['host'])) {
        return ['ok' => 0, 'error' => '网址格式不对，解析不出主机名'];
    }

    $协议 = strtolower((string) ($部['scheme'] ?? ''));
    if ($协议 !== 'http' && $协议 !== 'https') {
        // file:// gopher:// dict:// 这些是 SSRF 的经典跳板，一律拒
        return ['ok' => 0, 'error' => '只支持 http 和 https，收到的是 ' . $协议];
    }

    $主机 = $部['host'];
    // parse_url 对 IPv6 会带方括号，inet_pton 不认，去掉
    $裸主机 = trim($主机, '[]');
    $端口 = (int) ($部['port'] ?? ($协议 === 'https' ? 443 : 80));
    if ($端口 < 1 || $端口 > 65535) {
        return ['ok' => 0, 'error' => '端口号不合法'];
    }

    // 主机本身就是 IP 字面量，直接校验
    if (@inet_pton($裸主机) !== false) {
        if (!$允许内网 && !web_ip_public($裸主机)) {
            return ['ok' => 0, 'error' => '目标是内网或保留地址（' . $裸主机 . '），已拒绝'];
        }
        return ['ok' => 1, 'scheme' => $协议, 'host' => $主机,
                'port' => $端口, 'ip' => $裸主机, 'url' => $url];
    }

    if ($允许内网) {
        // 放开内网时不做解析校验，交给 curl 自己解析
        return ['ok' => 1, 'scheme' => $协议, 'host' => $主机,
                'port' => $端口, 'ip' => '', 'url' => $url];
    }

    // 解析域名。先 A 记录（绝大多数情况），没有再试 AAAA。
    $候选 = [];
    $a = @gethostbynamel($裸主机);
    if (is_array($a)) {
        $候选 = $a;
    }
    if (!$候选 && function_exists('dns_get_record')) {
        $rec = @dns_get_record($裸主机, DNS_AAAA);
        if (is_array($rec)) {
            foreach ($rec as $r) {
                if (!empty($r['ipv6'])) {
                    $候选[] = $r['ipv6'];
                }
            }
        }
    }
    if (!$候选) {
        return ['ok' => 0, 'error' => '域名解析不出来：' . $裸主机];
    }

    // 所有解析结果都必须是公网地址。只要有一个是内网就拒 ——
    // 攻击者可以让域名同时返回多个 A 记录，让 curl 挑中内网那个。
    foreach ($候选 as $ip) {
        if (!web_ip_public((string) $ip)) {
            return ['ok' => 0,
                    'error' => '域名 ' . $裸主机 . ' 解析到内网或保留地址（' . $ip . '），已拒绝'];
        }
    }

    return ['ok' => 1, 'scheme' => $协议, 'host' => $主机,
            'port' => $端口, 'ip' => (string) $候选[0], 'url' => $url];
}

/** 把相对地址拼成绝对地址。用于把页面里的链接还原成可点的完整网址。 */
function web_abs_url(string $基, string $相): string
{
    $相 = trim($相);
    if ($相 === '' || strpos($相, '#') === 0) {
        return '';
    }
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*://~', $相)) {
        return $相;
    }
    if (strpos($相, '//') === 0) {
        $b = @parse_url($基);
        return (($b['scheme'] ?? 'https') . ':' . $相);
    }
    // mailto: javascript: tel: 之类不是可抓的页面，丢掉
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $相)) {
        return '';
    }

    $b = @parse_url($基);
    if (!is_array($b) || empty($b['host'])) {
        return '';
    }
    $根 = ($b['scheme'] ?? 'https') . '://' . $b['host']
        . (isset($b['port']) ? ':' . $b['port'] : '');
    if (strpos($相, '/') === 0) {
        return $根 . $相;
    }

    $目录 = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';
    if ($目录 === '' || $目录 === null) {
        $目录 = '/';
    }
    $路 = $目录 . $相;
    // 压掉 ./ 和 ../
    $段 = [];
    foreach (explode('/', $路) as $s) {
        if ($s === '' || $s === '.') {
            continue;
        }
        if ($s === '..') {
            array_pop($段);
            continue;
        }
        $段[] = $s;
    }
    return $根 . '/' . implode('/', $段);
}

/** 从响应头和 HTML 里判断字符集，统一转成 UTF-8。 */
function web_to_utf8(string $体, string $类型头): string
{
    $字符集 = '';
    if (preg_match('~charset\s*=\s*["\']?([a-zA-Z0-9_\-]+)~i', $类型头, $m)) {
        $字符集 = $m[1];
    }
    if ($字符集 === ''
        && preg_match('~<meta[^>]+charset\s*=\s*["\']?([a-zA-Z0-9_\-]+)~i', substr($体, 0, 4096), $m)) {
        $字符集 = $m[1];
    }
    if ($字符集 === '') {
        // 没声明就猜一次，中文站常见 GBK 系列
        $猜 = @mb_detect_encoding($体, ['UTF-8', 'GB18030', 'BIG-5'], true);
        $字符集 = $猜 ? $猜 : 'UTF-8';
    }

    $标 = strtoupper($字符集);
    if ($标 === 'GB2312' || $标 === 'GBK') {
        $标 = 'GB18030';   // GB18030 是超集，按它转不会丢字
    }
    if ($标 === 'UTF-8' || $标 === 'UTF8') {
        // 已经是 UTF-8，但可能夹着坏字节，过一遍确保后续 DOM 不炸
        return @mb_convert_encoding($体, 'UTF-8', 'UTF-8');
    }
    $转 = @mb_convert_encoding($体, 'UTF-8', $标);
    return ($转 === false || $转 === '') ? $体 : $转;
}

/** 这些标签整棵子树都不要：脚本、样式、矢量图、内嵌框架等。 */
function web_drop_tags(): array
{
    return ['script', 'style', 'noscript', 'template', 'svg', 'canvas',
            'iframe', 'object', 'embed', 'audio', 'video', 'map', 'link', 'meta'];
}

/** 块级标签，转文本时前后要断行，否则整页会糊成一坨。 */
function web_block_tags(): array
{
    return ['p', 'div', 'section', 'article', 'header', 'footer', 'aside', 'nav',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre', 'form',
            'figure', 'figcaption', 'main', 'address', 'hr', 'br'];
}

/** 递归把 DOM 转成带结构的纯文本。$出 是累积用的字符串数组。 */
function web_dom_walk(DOMNode $节点, array &$出, int $深 = 0): void
{
    if ($深 > 64) {   // 防御畸形文档造成的深递归
        return;
    }

    if ($节点->nodeType === XML_TEXT_NODE) {
        $t = preg_replace('~[ \t\r\f\x{00A0}]+~u', ' ', (string) $节点->nodeValue);
        if ($t !== null && trim($t) !== '') {
            $出[] = $t;
        }
        return;
    }
    if ($节点->nodeType !== XML_ELEMENT_NODE) {
        return;
    }

    $名 = strtolower($节点->nodeName);
    if (in_array($名, web_drop_tags(), true)) {
        return;
    }

    $块 = in_array($名, web_block_tags(), true);
    if ($块) {
        $出[] = "\n";
    }
    // 标题加 # 前缀，让 AI 一眼看出层级
    if (preg_match('~^h([1-6])$~', $名, $m)) {
        $出[] = str_repeat('#', (int) $m[1]) . ' ';
    } elseif ($名 === 'li') {
        $出[] = '- ';
    } elseif ($名 === 'hr') {
        $出[] = "\n---\n";
    }

    if ($节点->hasChildNodes()) {
        foreach ($节点->childNodes as $子) {
            web_dom_walk($子, $出, $深 + 1);
        }
    }

    // 表格单元之间补分隔符，不然一行数字连在一起没法读
    if ($名 === 'td' || $名 === 'th') {
        $出[] = ' | ';
    }
    if ($块) {
        $出[] = "\n";
    }
}

/**
 * HTML 转正文。
 * 返回 ['title'=>标题, 'text'=>正文, 'links'=>[['text'=>,'url'=>], ...]]
 */
function web_html_to_text(string $html, string $基地址, int $链接条数): array
{
    $标题 = '';
    $链接 = [];

    if (!class_exists('DOMDocument')) {
        // 没有 dom 扩展时退化成正则清理，聊胜于无
        $纯 = preg_replace('~<(script|style|noscript)[^>]*>.*?</\1>~is', ' ', $html);
        $纯 = strip_tags((string) $纯);
        $纯 = html_entity_decode((string) $纯, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return ['title' => '', 'text' => trim(preg_replace('~\s+~u', ' ', $纯)), 'links' => []];
    }

    $旧 = libxml_use_internal_errors(true);
    $文档 = new DOMDocument();
    // 前面已统一转成 UTF-8，这里显式声明一次，否则 libxml 会按 Latin-1 猜
    $ok = $文档->loadHTML(
        '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html
    );
    libxml_clear_errors();
    libxml_use_internal_errors($旧);

    if (!$ok) {
        $纯 = strip_tags($html);
        return ['title' => '', 'text' => trim(preg_replace('~\s+~u', ' ', $纯)), 'links' => []];
    }

    $t = $文档->getElementsByTagName('title');
    if ($t->length > 0) {
        $标题 = trim(preg_replace('~\s+~u', ' ', (string) $t->item(0)->textContent));
    }

    if ($链接条数 > 0) {
        $见 = [];
        foreach ($文档->getElementsByTagName('a') as $a) {
            if (count($链接) >= $链接条数) {
                break;
            }
            $绝 = web_abs_url($基地址, (string) $a->getAttribute('href'));
            if ($绝 === '' || isset($见[$绝])) {
                continue;
            }
            $文 = trim(preg_replace('~\s+~u', ' ', (string) $a->textContent));
            if ($文 === '') {
                continue;
            }
            if (mb_strlen($文) > 60) {
                $文 = mb_substr($文, 0, 60) . '…';
            }
            $见[$绝] = 1;
            $链接[] = ['text' => $文, 'url' => $绝];
        }
    }

    $根 = $文档->getElementsByTagName('body');
    $起 = $根->length > 0 ? $根->item(0) : $文档->documentElement;
    $片 = [];
    if ($起 instanceof DOMNode) {
        web_dom_walk($起, $片);
    }

    $正文 = implode('', $片);
    $正文 = preg_replace('~[ \t]*\n[ \t]*~', "\n", $正文);   // 行首行尾空白
    $正文 = preg_replace('~\n{3,}~', "\n\n", (string) $正文); // 连续空行压成一个
    $正文 = preg_replace('~ {2,}~', ' ', (string) $正文);
    $正文 = preg_replace('~ \| *\n~', "\n", (string) $正文);  // 表格行尾多余的分隔符

    return ['title' => $标题, 'text' => trim((string) $正文), 'links' => $链接];
}

/**
 * 抓一个网址，返回清理过的正文。
 *
 * 成功：['ok'=>1,'url'=>最终地址,'status'=>,'title'=>,'text'=>,'links'=>,
 *        'bytes'=>,'truncated'=>bool,'hops'=>[中间跳转地址],'type'=>]
 * 失败：['ok'=>0,'error'=>说明]
 */
function web_fetch(string $url, array $选项 = []): array
{
    $配 = web_fetch_conf();
    if (!$配['开关']) {
        return ['ok' => 0, 'error' => '网页抓取功能已在后台关闭'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => 0, 'error' => '服务器缺少 curl 扩展，无法抓取'];
    }

    $正文上限 = isset($选项['limit'])
        ? max(500, min($配['正文上限'] * 4, (int) $选项['limit']))
        : $配['正文上限'];
    $要链接 = array_key_exists('links', $选项)
        ? (int) $选项['links'] : $配['链接条数'];

    $当前 = $url;
    $跳转 = [];

    for ($跳 = 0; $跳 <= $配['跳数上限']; $跳++) {
        $校 = web_check_url($当前, $配['允许内网']);
        if (empty($校['ok'])) {
            return ['ok' => 0, 'error' => (string) $校['error']];
        }
        $当前 = (string) $校['url'];

        $体   = '';
        $超限 = false;
        $ch = curl_init();
        $opt = [
            CURLOPT_URL            => $当前,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            // 重定向自己跟，每跳都要重新过 web_check_url，
            // 交给 curl 自动跟的话第二跳指向内网就防不住了
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(15, $配['超时']),
            CURLOPT_TIMEOUT        => $配['超时'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',   // 接受 gzip，省带宽
            CURLOPT_USERAGENT      =>
                'Mozilla/5.0 (compatible; SiteReader/1.0; +https://code.77bot.cn)',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ],
            // 边收边数，超过上限立刻中断，不把几百兆的文件拉完
            CURLOPT_WRITEFUNCTION  => function ($h, $块) use (&$体, &$超限, $配) {
                $体 .= $块;
                if (strlen($体) > $配['最大字节']) {
                    $超限 = true;
                    return 0;   // 返回 0 让 curl 主动断开
                }
                return strlen($块);
            },
        ];
        // 把校验通过的 IP 钉死，防止校验完成后域名被改解析（DNS 重绑定）
        if (!empty($校['ip'])) {
            $裸 = trim((string) $校['host'], '[]');
            $opt[CURLOPT_RESOLVE] = [$裸 . ':' . $校['port'] . ':' . $校['ip']];
        }
        curl_setopt_array($ch, $opt);
        curl_exec($ch);

        $错   = curl_errno($ch);
        $状态 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $类型 = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $位置 = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $错文 = curl_error($ch);

        // 超限是我们自己掐断的，不算错误
        if ($错 !== 0 && !$超限) {
            return ['ok' => 0, 'error' => '请求失败：' . $错文 . '（curl ' . $错 . '）'];
        }

        if ($状态 >= 300 && $状态 < 400 && $位置 !== '') {
            $跳转[] = $当前;
            $当前 = web_abs_url($当前, $位置);
            if ($当前 === '') {
                return ['ok' => 0, 'error' => '重定向地址解析失败'];
            }
            continue;
        }

        if ($状态 === 0) {
            return ['ok' => 0, 'error' => '没拿到响应状态，可能被目标站点直接断开'];
        }

        // 二进制内容读出来对 AI 没意义，明确告知而不是塞一堆乱码
        $主类型 = strtolower(trim(explode(';', $类型)[0]));
        $可读 = ['text/html', 'application/xhtml+xml', 'text/plain', 'text/markdown',
                 'application/json', 'text/xml', 'application/xml', 'text/csv',
                 'application/rss+xml', 'application/atom+xml', 'text/javascript',
                 'application/javascript', 'text/css'];
        if ($主类型 !== '' && !in_array($主类型, $可读, true)) {
            return ['ok' => 0,
                    'error' => '这个地址返回的是 ' . $主类型 . '，不是文本内容，读不出正文'];
        }

        $字节 = strlen($体);
        if ($字节 === 0) {
            return ['ok' => 0, 'error' => 'HTTP ' . $状态 . '，但响应体是空的'];
        }

        $体 = web_to_utf8($体, $类型);

        $是网页 = ($主类型 === 'text/html' || $主类型 === 'application/xhtml+xml'
                   || $主类型 === '' || strpos($主类型, 'xml') !== false);
        if ($是网页) {
            $解 = web_html_to_text($体, $当前, $要链接);
        } else {
            $解 = ['title' => '', 'text' => trim($体), 'links' => []];
        }

        $文 = (string) $解['text'];
        $截 = false;
        if (mb_strlen($文) > $正文上限) {
            $文 = mb_substr($文, 0, $正文上限);
            $截 = true;
        }

        return [
            'ok'        => 1,
            'url'       => $当前,
            'status'    => $状态,
            'type'      => $主类型,
            'title'     => (string) $解['title'],
            'text'      => $文,
            'links'     => $解['links'],
            'bytes'     => $字节,
            'chars'     => mb_strlen($文),
            'truncated' => $截,
            'oversize'  => $超限,
            'hops'      => $跳转,
        ];
    }

    return ['ok' => 0,
            'error' => '重定向次数超过 ' . $配['跳数上限'] . ' 次，放弃'];
}

/**
 * 实时搜索功能，使用 Google Custom Search API
 * 
 * @param string $query 搜索关键词
 * @param int $num 返回结果数量，默认 10
 * @return array ['ok' => 1, 'results' => [...]] 或 ['ok' => 0, 'error' => '...']
 */
function web_search(string $query, int $num = 10): array
{
    $query = trim($query);
    if ($query === '') {
        return ['ok' => 0, 'error' => '搜索关键词为空'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => 0, 'error' => '服务器缺少 curl 扩展，无法搜索'];
    }

    $num = max(1, min($num, 15));

    // 免 Key：直接抓必应结果页解析。cn.bing.com 国内直连，无需任何 API Key。
    $url = 'https://cn.bing.com/search?' . http_build_query([
        'q'        => $query,
        'setlang'  => 'zh-CN',
        'count'    => $num,
        'ensearch' => 0,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_ENCODING        => 'gzip, deflate',
        CURLOPT_USERAGENT       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['ok' => 0, 'error' => '搜索请求失败：' . $curlError];
    }
    if ($httpCode !== 200) {
        return ['ok' => 0, 'error' => '搜索返回 HTTP ' . $httpCode];
    }

    $html = (string) $response;

    $results = bing_parse_results($html, $num);
    if (empty($results)) {
        $results = bing_parse_fallback($html, $num);
    }

    return ['ok' => 1, 'results' => $results];
}

/**
 * 用 DOM 解析必应结果页，返回 [{title,url,snippet}]。
 * 每个自然结果在 <li class="b_algo"> 里：h2>a 是标题+链接，b_caption>p 是摘要。
 */
function bing_parse_results(string $html, int $num): array
{
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
        return [];
    }

    $旧 = libxml_use_internal_errors(true);
    $文档 = new DOMDocument();
    $文档->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($旧);

    $xpath = new DOMXPath($文档);
    $nodes = $xpath->query(
        "//li[contains(concat(' ', normalize-space(@class), ' '), ' b_algo ')]"
    );

    $结果 = [];
    foreach ($nodes as $li) {
        if (count($结果) >= $num) {
            break;
        }
        $title = '';
        $link  = '';
        $a = $xpath->query('.//h2//a', $li);
        if ($a->length > 0) {
            $title = trim(preg_replace('~\s+~u', ' ', (string) $a->item(0)->textContent));
            $link  = (string) $a->item(0)->getAttribute('href');
        }
        $snippet = '';
        $ps = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " b_caption ")]//p',
            $li
        );
        if ($ps->length > 0) {
            foreach ($ps as $p) {
                $t = trim(preg_replace('~\s+~u', ' ', (string) $p->textContent));
                if (mb_strlen($t) > mb_strlen($snippet)) {
                    $snippet = $t;
                }
            }
        }
        if ($title === '' && $link === '' && $snippet === '') {
            continue;
        }
        $结果[] = ['title' => $title, 'url' => $link, 'snippet' => $snippet];
    }

    return $结果;
}

/** DOM 解析失败时的正则兜底，按 h2>a 与 b_caption 两条规则对齐。 */
function bing_parse_fallback(string $html, int $num): array
{
    $结果 = [];
    preg_match_all(
        '~<h2[^>]*>\s*<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>\s*</h2>~is',
        $html, $m, PREG_SET_ORDER
    );
    $标题 = [];
    foreach ($m as $g) {
        if (count($标题) >= $num) {
            break;
        }
        $标题[] = [
            'title'   => trim(html_entity_decode(strip_tags($g[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'url'     => html_entity_decode($g[1], ENT_QUOTES, 'UTF-8'),
            'snippet' => '',
        ];
    }

    preg_match_all(
        '~<div[^>]*class="b_caption"[^>]*>\s*<p[^>]*>(.*?)</p>~is',
        $html, $m2, PREG_SET_ORDER
    );
    foreach ($标题 as $i => &$it) {
        if (isset($m2[$i])) {
            $it['snippet'] = trim(html_entity_decode(strip_tags($m2[$i][1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    unset($it);

    return $标题;
}
