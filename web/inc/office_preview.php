<?php
/**
 * Office 文档在线预览：用 LibreOffice 把 pptx/docx/xlsx 转成 PDF，浏览器原生渲染。
 *
 * 为什么按需转而不是生成时就转：
 *  1. 已经存在工作中心里的旧文件也能预览，不用回溯补转。
 *  2. 不拖慢 PPT 生成——用户点了预览才付这 3 秒。
 *  3. 用户上传的 Office 文档同样受益，不限于 AI 生成的。
 *
 * 转完的 PDF 缓存在原文件同目录的 .pdf/ 下，按源文件 mtime 判断是否过期。
 * 源文件被覆盖（比如重新生成同名 PPT）后 mtime 变化，缓存自动失效重转。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ws_files.php';

/** 转换超时（秒）。LibreOffice 首次启动慢，几十页 PPT 实测 3 秒左右 */
const 预览超时秒 = 60;
/** PDF 转 PNG 的超时（秒）。11 页实测 8 秒，页数多的留够余量 */
const 预览转图超时秒 = 120;
/** 转图分辨率（DPI）。110 在 16:9 下约 1467×825，清晰度和体积平衡点 */
const 预览转图DPI = 110;
/** 最多转多少页。页数失控时别把磁盘写满 */
const 预览转图页数上限 = 60;
/** 能转 PDF 的扩展名。只放确定 LibreOffice 支持的，别放视频音频 */
const 预览可转扩展 = 'pptx,ppt,docx,doc,xlsx,xls,odp,odt,ods,rtf';

/** 这个文件能不能转 PDF 预览 */
function 预览可转(string $名): bool
{
    $ext = strtolower(pathinfo($名, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, explode(',', 预览可转扩展), true);
}

/**
 * LibreOffice 可执行文件路径，没装返回空串。
 *
 * 不能用 is_file() 判断：宝塔的 .user.ini 里 open_basedir 只放开了站点目录、
 * /tmp 和上传目录，/usr/bin 不在其中，任何 PHP 文件系统函数碰它都会被拦，
 * is_file('/usr/bin/soffice') 在 php-fpm 下恒为 false，看起来就像没装。
 * proc_open 执行外部命令不受 open_basedir 约束，所以改用 command -v 探测。
 */
function 预览取soffice(): string
{
    static $缓存 = null;
    if ($缓存 !== null) {
        return $缓存;
    }
    $管道 = [];
    $进程 = @proc_open('command -v soffice || command -v libreoffice',
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $管道);
    if (!is_resource($进程)) {
        return $缓存 = '';
    }
    $出 = trim((string) stream_get_contents($管道[1]));
    fclose($管道[1]);
    fclose($管道[2]);
    proc_close($进程);

    // command -v 可能输出多行（两个都装了），取第一行
    $行 = strtok($出, "\n");
    $路径 = is_string($行) ? trim($行) : '';
    // 只认绝对路径，且必须是 soffice/libreoffice，避免 PATH 被污染时执行到别的东西
    if ($路径 === '' || $路径[0] !== '/'
        || !preg_match('~/(soffice|libreoffice)$~', $路径)) {
        return $缓存 = '';
    }
    return $缓存 = $路径;
}

/** 缓存 PDF 的绝对路径。放源文件同目录的 .pdf/ 下，跟着源文件一起被删 */
function 预览缓存路径(string $源全路径): string
{
    return dirname($源全路径) . '/.pdf/' . basename($源全路径) . '.pdf';
}

/** 转图缓存目录。一份文档一个子目录，页图按 p-1.png 命名 */
function 预览图目录(string $源全路径): string
{
    return dirname($源全路径) . '/.pdf/' . basename($源全路径) . '.png';
}

/**
 * pdftoppm 路径，没装返回空串。
 *
 * 和 预览取soffice() 同样的理由：open_basedir 挡住 /usr/bin，
 * 不能用 is_file() 判断，只能靠 command -v。
 */
function 预览取pdftoppm(): string
{
    static $缓存 = null;
    if ($缓存 !== null) {
        return $缓存;
    }
    $管道 = [];
    $进程 = @proc_open('command -v pdftoppm',
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $管道);
    if (!is_resource($进程)) {
        return $缓存 = '';
    }
    $出 = trim((string) stream_get_contents($管道[1]));
    fclose($管道[1]);
    fclose($管道[2]);
    proc_close($进程);

    $行 = strtok($出, "\n");
    $路径 = is_string($行) ? trim($行) : '';
    if ($路径 === '' || $路径[0] !== '/' || !preg_match('~/pdftoppm$~', $路径)) {
        return $缓存 = '';
    }
    return $缓存 = $路径;
}

/**
 * 取某个工作中心文件的预览 PDF，没有就现转。
 *
 * @return array{ok:bool, error:string, pdf:string, cached:bool}
 */
function 预览取PDF(int $用户id, int $文件id): array
{
    $空 = ['ok' => false, 'error' => '', 'pdf' => '', 'cached' => false];

    // 走工作区取文件，它带 user_id 条件，别人的文件取不出来
    $行 = 工作区取文件($用户id, $文件id);
    if (!$行) {
        return array_merge($空, ['error' => '文件不存在']);
    }
    $名 = (string) ($行['name'] !== '' ? $行['name'] : basename((string) $行['path']));
    if (!预览可转($名)) {
        return array_merge($空, ['error' => '这个格式不支持转 PDF 预览']);
    }

    $源 = 工作区绝对路径((string) $行['path']);
    if ($源 === '' || !is_file($源)) {
        return array_merge($空, ['error' => '文件实体已丢失']);
    }

    $缓存 = 预览缓存路径($源);
    // 缓存比源文件新才算有效。源文件被覆盖后 mtime 变新，这里就会重转。
    if (is_file($缓存) && filemtime($缓存) >= filemtime($源) && filesize($缓存) > 0) {
        return ['ok' => true, 'error' => '', 'pdf' => $缓存, 'cached' => true];
    }

    $r = 预览转PDF($源, $缓存);
    if (!$r['ok']) {
        return array_merge($空, ['error' => $r['error']]);
    }
    return ['ok' => true, 'error' => '', 'pdf' => $缓存, 'cached' => false];
}

/**
 * 调 LibreOffice 转 PDF。
 *
 * @return array{ok:bool, error:string}
 */
function 预览转PDF(string $源, string $目标): array
{
    $soffice = 预览取soffice();
    if ($soffice === '') {
        return ['ok' => false, 'error' => '服务器未安装 LibreOffice，无法生成预览'];
    }

    $目录 = dirname($目标);
    if (!is_dir($目录) && !@mkdir($目录, 0750, true)) {
        return ['ok' => false, 'error' => '无法创建预览缓存目录，请检查权限'];
    }

    // LibreOffice 必须有一个可写的用户配置目录，否则直接退出不干活。
    // php-fpm 跑在 www 下，HOME 通常不可写，所以每次转换单独给一个临时的。
    $临时 = sys_get_temp_dir() . '/lo_' . bin2hex(random_bytes(6));
    if (!@mkdir($临时, 0700, true)) {
        return ['ok' => false, 'error' => '无法创建临时目录'];
    }

    // --convert-to 的产出名是「源文件基名 + .pdf」，落在 --outdir 里，
    // 不能直接指定目标文件名，所以先让它落到临时目录再搬走。
    $命令 = 'timeout ' . 预览超时秒 . ' ' . escapeshellarg($soffice)
        . ' --headless --norestore --invisible --nologo --nolockcheck'
        . ' -env:UserInstallation=' . escapeshellarg('file://' . $临时 . '/cfg')
        . ' --convert-to pdf --outdir ' . escapeshellarg($临时) . ' '
        . escapeshellarg($源) . ' 2>&1';

    $管道规格 = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $管道 = [];
    $进程 = @proc_open($命令, $管道规格, $管道);
    if (!is_resource($进程)) {
        预览清理目录($临时);
        return ['ok' => false, 'error' => '无法启动转换进程，proc_open 可能被禁用'];
    }
    $输出 = (string) stream_get_contents($管道[1]);
    $输出 .= (string) stream_get_contents($管道[2]);
    fclose($管道[1]);
    fclose($管道[2]);
    $退出码 = proc_close($进程);

    if ($退出码 === 124) {
        预览清理目录($临时);
        return ['ok' => false, 'error' => '转换超时（超过 ' . 预览超时秒 . ' 秒），文件可能过大'];
    }

    // 产出名 = 源文件去掉扩展名后加 .pdf
    $产出 = $临时 . '/' . pathinfo($源, PATHINFO_FILENAME) . '.pdf';
    if (!is_file($产出) || filesize($产出) === 0) {
        预览清理目录($临时);
        $因 = trim($输出) !== '' ? mb_substr(trim($输出), -200) : '退出码 ' . $退出码;
        return ['ok' => false, 'error' => '转换失败：' . $因];
    }

    // 先删旧缓存再搬。rename 跨设备会失败，临时目录和上传目录可能不在一个分区，
    // 所以失败后退回 copy。
    @unlink($目标);
    if (!@rename($产出, $目标) && !@copy($产出, $目标)) {
        预览清理目录($临时);
        return ['ok' => false, 'error' => '预览文件写入失败，请检查目录权限'];
    }
    落盘收尾($目标, 0640);
    预览清理目录($临时);
    return ['ok' => true, 'error' => ''];
}

/**
 * 取文档的逐页 PNG 列表，没有就现转。
 *
 * 为什么默认走图片而不是 PDF：Chrome 里「下载 PDF 而不是自动打开」这个设置开着时，
 * iframe 内嵌的 PDF 也会被当下载处理，服务端的 Content-Disposition: inline 覆盖不了。
 * 图片没有任何浏览器设置能拦，这是唯一能保证显示出来的方式。
 *
 * @return array{ok:bool, error:string, pages:int, cached:bool}
 */
function 预览取页图(int $用户id, int $文件id): array
{
    $空 = ['ok' => false, 'error' => '', 'pages' => 0, 'cached' => false];

    $行 = 工作区取文件($用户id, $文件id);
    if (!$行) {
        return array_merge($空, ['error' => '文件不存在']);
    }
    $名 = (string) ($行['name'] !== '' ? $行['name'] : basename((string) $行['path']));
    if (!预览可转($名)) {
        return array_merge($空, ['error' => '这个格式不支持预览']);
    }
    $源 = 工作区绝对路径((string) $行['path']);
    if ($源 === '' || !is_file($源)) {
        return array_merge($空, ['error' => '文件实体已丢失']);
    }

    $图目录 = 预览图目录($源);
    // 目录里已有页图且比源文件新，直接用
    if (is_dir($图目录)) {
        $已有 = 预览数页图($图目录);
        if ($已有 > 0 && filemtime($图目录) >= filemtime($源)) {
            return ['ok' => true, 'error' => '', 'pages' => $已有, 'cached' => true];
        }
    }

    // 转图要先有 PDF，复用已有的那条链路
    $pdf = 预览取PDF($用户id, $文件id);
    if (!$pdf['ok']) {
        return array_merge($空, ['error' => $pdf['error']]);
    }

    $r = 预览PDF转图($pdf['pdf'], $图目录);
    if (!$r['ok']) {
        return array_merge($空, ['error' => $r['error']]);
    }
    return ['ok' => true, 'error' => '', 'pages' => $r['pages'], 'cached' => false];
}

/** 数一个目录里有多少张页图 */
function 预览数页图(string $图目录): int
{
    if (!is_dir($图目录)) {
        return 0;
    }
    $n = 0;
    while ($n < 预览转图页数上限 && is_file($图目录 . '/p-' . ($n + 1) . '.png')) {
        $n++;
    }
    return $n;
}

/**
 * 调 pdftoppm 把 PDF 每页转成 PNG，存进目标目录，按 p-1.png 顺序命名。
 *
 * @return array{ok:bool, error:string, pages:int}
 */
function 预览PDF转图(string $pdf, string $图目录): array
{
    $工具 = 预览取pdftoppm();
    if ($工具 === '') {
        return ['ok' => false, 'error' => '服务器未安装 poppler-utils，无法生成逐页预览', 'pages' => 0];
    }

    // 先转到临时目录，全部成功后再搬进缓存目录。
    // 直接往缓存目录写的话，转一半失败会留下不完整的页序列，
    // 下次命中缓存就只显示前几页，而且看不出是残缺的。
    $临时 = sys_get_temp_dir() . '/pv_' . bin2hex(random_bytes(6));
    if (!@mkdir($临时, 0700, true)) {
        return ['ok' => false, 'error' => '无法创建临时目录', 'pages' => 0];
    }

    $命令 = 'timeout ' . 预览转图超时秒 . ' ' . escapeshellarg($工具)
        . ' -png -r ' . 预览转图DPI
        . ' -l ' . 预览转图页数上限
        . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($临时 . '/pg') . ' 2>&1';

    $管道 = [];
    $进程 = @proc_open($命令, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $管道);
    if (!is_resource($进程)) {
        预览清理目录($临时);
        return ['ok' => false, 'error' => '无法启动转图进程', 'pages' => 0];
    }
    $输出 = (string) stream_get_contents($管道[1]) . (string) stream_get_contents($管道[2]);
    fclose($管道[1]);
    fclose($管道[2]);
    $退出码 = proc_close($进程);

    if ($退出码 === 124) {
        预览清理目录($临时);
        return ['ok' => false, 'error' => '转图超时，页数可能过多', 'pages' => 0];
    }

    // pdftoppm 的产出名带零填充位宽，页数不同位宽也不同（pg-1 / pg-01 / pg-001），
    // 所以用通配收集再按数字排序，不能假定固定格式。
    $文件 = glob($临时 . '/pg-*.png');
    if (!$文件) {
        预览清理目录($临时);
        $因 = trim($输出) !== '' ? mb_substr(trim($输出), -200) : '退出码 ' . $退出码;
        return ['ok' => false, 'error' => '转图失败：' . $因, 'pages' => 0];
    }
    natsort($文件);
    $文件 = array_values($文件);

    // 搬进缓存目录前先清空旧内容，避免上一版页数更多时残留多余的尾页
    预览清空图目录($图目录);
    if (!is_dir($图目录) && !@mkdir($图目录, 0750, true)) {
        预览清理目录($临时);
        return ['ok' => false, 'error' => '无法创建预览目录，请检查权限', 'pages' => 0];
    }

    $页 = 0;
    foreach ($文件 as $i => $f) {
        $目标 = $图目录 . '/p-' . ($i + 1) . '.png';
        if (!@rename($f, $目标) && !@copy($f, $目标)) {
            预览清理目录($临时);
            return ['ok' => false, 'error' => '页图写入失败，请检查目录权限', 'pages' => 0];
        }
        落盘收尾($目标, 0640);
        $页++;
    }
    预览清理目录($临时);

    // 把目录 mtime 推到当前，缓存判定靠它和源文件比对
    @touch($图目录);
    return ['ok' => true, 'error' => '', 'pages' => $页];
}

/** 清空转图缓存目录里的页图。只删 p-*.png，不碰别的 */
function 预览清空图目录(string $图目录): void
{
    if (!is_dir($图目录)) {
        return;
    }
    foreach ((array) glob($图目录 . '/p-*.png') as $f) {
        @unlink($f);
    }
}

/** 删掉临时目录及其内容。只删一层，LibreOffice 的配置目录会有子目录所以要递归 */
function 预览清理目录(string $目录): void
{
    if ($目录 === '' || !is_dir($目录)) {
        return;
    }
    // 防御：只允许删系统临时目录下的路径，避免传错参数删到别处
    $根 = rtrim(sys_get_temp_dir(), '/');
    if (strncmp($目录, $根 . '/', strlen($根) + 1) !== 0) {
        return;
    }
    $项 = @scandir($目录);
    if ($项 === false) {
        return;
    }
    foreach ($项 as $x) {
        if ($x === '.' || $x === '..') {
            continue;
        }
        $全 = $目录 . '/' . $x;
        if (is_dir($全)) {
            预览清理目录($全);
        } else {
            @unlink($全);
        }
    }
    @rmdir($目录);
}
