<?php
/**
 * PPT 生成：把 AI 给的结构化大纲交给 python-pptx 排版，产出的 .pptx 存进工作中心。
 *
 * 为什么落在工作中心：那边现成有账号隔离、空间配额、下载接口和列表页面，
 * 不用再造一套存储和鉴权。PPT 只是 kind='bin' 的一条普通记录。
 *
 * 职责边界：这里只做「校验 + 调用 + 入库」，排版全在 ppt_build.py，
 * 内容全由 AI 负责。三层分开，出问题能直接定位到哪一层。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ws_files.php';

/** 单份 PPT 最多多少页，超了截断。页数失控通常是 AI 跑飞了 */
const PPT页数上限 = 40;
/** 生成超时（秒）。正常几十页也就一两秒，超过这个数说明卡住了 */
const PPT超时秒 = 30;

/**
 * 校验并规范化大纲。
 *
 * 输入来自 AI，必须假定字段缺失、类型不对、内容超长都有可能。
 * 返回 ['ok'=>bool, 'error'=>string, 'data'=>array]
 */
function ppt_check_outline($大纲): array
{
    if (!is_array($大纲)) {
        return ['ok' => false, 'error' => '大纲不是对象', 'data' => []];
    }
    $标题 = trim((string) ($大纲['title'] ?? ''));
    if ($标题 === '') {
        return ['ok' => false, 'error' => '缺 title（演示标题）', 'data' => []];
    }
    $页 = $大纲['slides'] ?? null;
    if (!is_array($页) || !$页) {
        return ['ok' => false, 'error' => '缺 slides，或者它不是数组', 'data' => []];
    }

    $净页 = [];
    foreach ($页 as $p) {
        // 模型有时会把一页直接写成字符串，按只有标题处理，不算错
        if (is_string($p)) {
            $p = ['title' => $p];
        }
        if (!is_array($p)) {
            continue;
        }
        $t = trim((string) ($p['title'] ?? ''));
        if ($t === '') {
            continue;                 // 没标题的页跳过，排出来是个空页没意义
        }
        $要点 = [];
        $bl = $p['bullets'] ?? [];
        if (is_string($bl)) {
            // 模型可能把要点写成一段换行分隔的文本
            $bl = preg_split('/\r?\n/', $bl);
        }
        if (is_array($bl)) {
            foreach ($bl as $b) {
                $b = trim((string) (is_array($b) ? implode(' ', $b) : $b));
                // 去掉模型可能自带的项目符号，排版时会自己画圆点
                $b = preg_replace('/^\s*[-*•·]\s*/u', '', $b);
                if ($b !== '') {
                    $要点[] = mb_substr($b, 0, 120);
                }
            }
        }
        $净页[] = [
            'title'   => mb_substr($t, 0, 60),
            'bullets' => array_slice($要点, 0, 10),
            'text'    => mb_substr(trim((string) ($p['text'] ?? '')), 0, 300),
        ];
        if (count($净页) >= PPT页数上限) {
            break;
        }
    }

    if (!$净页) {
        return ['ok' => false, 'error' => 'slides 里没有一页带 title', 'data' => []];
    }

    return ['ok' => true, 'error' => '', 'data' => [
        'title'    => mb_substr($标题, 0, 80),
        'subtitle' => mb_substr(trim((string) ($大纲['subtitle'] ?? '')), 0, 120),
        'footer'   => mb_substr(trim((string) ($大纲['footer'] ?? '')), 0, 80),
        'ending'   => mb_substr(trim((string) ($大纲['ending'] ?? '')), 0, 40),
        'theme'    => mb_substr(trim((string) ($大纲['theme'] ?? '')), 0, 12),
        'slides'   => $净页,
    ]];
}

/**
 * 生成 PPT 并存进工作中心。
 *
 * @param int    $用户id
 * @param array  $大纲   已经过 ppt_check_outline 的数据
 * @param string $文件名 账号内虚拟名，为空则按标题取
 * @return array ok/error/id/name/size/slides
 */
function ppt_generate(int $用户id, array $大纲, string $文件名 = ''): array
{
    $空 = ['ok' => false, 'error' => '', 'id' => 0, 'name' => '', 'size' => 0, 'slides' => 0];

    // ---- 文件名 ----
    if (trim($文件名) === '') {
        $文件名 = $大纲['title'] . '.pptx';
    }
    if (!preg_match('/\.pptx$/i', $文件名)) {
        $文件名 .= '.pptx';
    }
    $规范 = 工作区规范名($文件名);
    if ($规范 === '') {
        return array_merge($空, ['error' => '文件名不合法：' . $文件名]);
    }

    // ---- 临时文件 ----
    $临时目录 = sys_get_temp_dir() . '/ppt_' . bin2hex(random_bytes(6));
    if (!@mkdir($临时目录, 0700, true)) {
        return array_merge($空, ['error' => '无法创建临时目录']);
    }
    $大纲文件 = $临时目录 . '/outline.json';
    $产出文件 = $临时目录 . '/out.pptx';

    $清理 = function () use ($临时目录, $大纲文件, $产出文件) {
        @unlink($大纲文件);
        @unlink($产出文件);
        @rmdir($临时目录);
    };

    $json = json_encode($大纲, JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($大纲文件, $json) === false) {
        $清理();
        return array_merge($空, ['error' => '大纲写入临时文件失败']);
    }

    // ---- 调 Python ----
    // 用 proc_open 而不是 shell_exec：宝塔默认把 shell_exec 放进 disable_functions，
    // proc_open 和 exec 没被禁。proc_open 还能把 stdout 和 stderr 分开收，
    // Python 的栈回溯不会混进要解析的那行 JSON。
    // escapeshellarg 保证路径原样送达，别去掉。
    $脚本 = __DIR__ . '/ppt_build.py';
    $命令 = 'timeout ' . PPT超时秒 . ' python3.9 ' . escapeshellarg($脚本) . ' '
        . escapeshellarg($大纲文件) . ' ' . escapeshellarg($产出文件);

    $管道规格 = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $管道 = [];
    $进程 = @proc_open($命令, $管道规格, $管道);
    if (!is_resource($进程)) {
        $清理();
        return array_merge($空, ['error' => '无法启动生成脚本，proc_open 可能被禁用']);
    }
    $标准输出 = (string) stream_get_contents($管道[1]);
    $错误输出 = (string) stream_get_contents($管道[2]);
    fclose($管道[1]);
    fclose($管道[2]);
    $退出码 = proc_close($进程);

    // timeout 命令超时会返回 124，这时候脚本是被砍掉的，产出不可信
    if ($退出码 === 124) {
        $清理();
        return array_merge($空, ['error' => 'PPT 生成超时（超过 ' . PPT超时秒 . ' 秒），'
            . '大纲可能太大，减少页数后再试']);
    }

    // 脚本正常时 stdout 最后一行是 JSON
    $行 = array_values(array_filter(array_map('trim', explode("\n", $标准输出)), 'strlen'));
    $末 = $行 ? end($行) : '';
    $结果 = json_decode($末, true);
    if (!is_array($结果) || empty($结果['ok'])) {
        $清理();
        if (is_array($结果)) {
            $因 = (string) ($结果['error'] ?? '未知');
        } elseif (trim($错误输出) !== '') {
            // Python 崩了，stderr 里才有真正原因，取最后一行（异常信息那行）
            $错行 = array_values(array_filter(array_map('trim', explode("\n", $错误输出)), 'strlen'));
            $因 = '脚本异常：' . mb_substr($错行 ? end($错行) : $错误输出, 0, 200);
        } else {
            $因 = '脚本没有输出（退出码 ' . $退出码 . '）';
        }
        return array_merge($空, ['error' => 'PPT 生成失败：' . $因]);
    }

    if (!is_file($产出文件)) {
        $清理();
        return array_merge($空, ['error' => '脚本报告成功但没找到产出文件']);
    }
    $内容 = (string) @file_get_contents($产出文件);
    $清理();
    if ($内容 === '') {
        return array_merge($空, ['error' => '产出文件是空的']);
    }

    // ---- 存进工作中心 ----
    return ppt_save_to_workspace($用户id, $规范, $内容, (int) ($结果['slides'] ?? 0), $大纲['title']);
}

/**
 * 把生成好的 pptx 二进制存进工作中心。
 *
 * 工作区写文件() 只收白名单里的文本扩展名，pptx 是二进制，走不了那条路，
 * 所以这里单独落盘 + 入库，但复用它的规范名、配额、落盘路径这几个闸门。
 */
function ppt_save_to_workspace(int $用户id, string $规范, string $内容,
                               int $页数, string $标题): array
{
    $空 = ['ok' => false, 'error' => '', 'id' => 0, 'name' => '', 'size' => 0, 'slides' => 0];
    $长 = strlen($内容);

    $旧 = 工作区取文件($用户id, $规范);
    $旧字节 = $旧 ? (int) $旧['size'] : 0;
    $err = 工作区可写($用户id, $长, $旧字节);
    if ($err !== '') {
        return array_merge($空, ['error' => $err]);
    }

    $说明 = '共 ' . $页数 . ' 页 · ' . mb_substr($标题, 0, 60);
    $MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    // ---- 覆盖同名 ----
    if ($旧) {
        $全 = 工作区绝对路径((string) $旧['path']);
        if ($全 === '') {
            return array_merge($空, ['error' => '原文件路径异常，已拒绝写入']);
        }
        // 旧版本留快照，和文本文件一致，重新生成后还能取回上一版
        if (is_file($全)) {
            $快照 = dirname($全) . '/.ver';
            if (!is_dir($快照)) {
                @mkdir($快照, 0750, true);
            }
            @copy($全, $快照 . '/' . basename($全) . '.' . date('YmdHis'));
        }
        if (!is_dir(dirname($全))) {
            @mkdir(dirname($全), 0750, true);
        }
        if (@file_put_contents($全, $内容) === false) {
            return array_merge($空, ['error' => '写入失败，请检查存储目录权限']);
        }
        落盘收尾($全, 0640);
        db_exec('UPDATE uploads SET size=?, note=?, ver=ver+1, updated_at=NOW(),
                 kind=\'bin\', mime=? WHERE id=? AND user_id=?',
            [$长, mb_substr($说明, 0, 250), $MIME, (int) $旧['id'], $用户id]);
        return ['ok' => true, 'error' => '', 'id' => (int) $旧['id'], 'name' => $规范,
            'size' => $长, 'slides' => $页数, 'new' => false];
    }

    // ---- 新建 ----
    $相对 = 工作区落盘名($用户id, $规范);
    $全   = 工作区绝对路径($相对);
    if ($全 === '') {
        return array_merge($空, ['error' => '存储路径异常，已拒绝写入']);
    }
    if (!is_dir(dirname($全)) && !@mkdir(dirname($全), 0750, true)) {
        return array_merge($空, ['error' => '无法创建存储目录，请检查权限']);
    }
    if (@file_put_contents($全, $内容) === false) {
        return array_merge($空, ['error' => '写入失败，请检查存储目录权限']);
    }
    落盘收尾($全, 0640);

    // 入库失败要把已落盘的文件删掉，否则磁盘上会留一个没有记录的孤儿文件，
    // 既占配额（统计走数据库，它不被计入，结果是配额算不准）又没人能删。
    try {
        $id = db_insert('INSERT INTO uploads
            (user_id, conv_id, path, name, kind, source, mime, size, width, height,
             used, note, ver, created_at, updated_at)
            VALUES (?,0,?,?,\'bin\',\'ai\',?,?,0,0,1,?,1,NOW(),NOW())',
            [$用户id, $相对, $规范, $MIME, $长, mb_substr($说明, 0, 250)]);
    } catch (Throwable $e) {
        @unlink($全);
        return array_merge($空, ['error' => '入库失败：' . $e->getMessage()]);
    }

    return ['ok' => true, 'error' => '', 'id' => (int) $id, 'name' => $规范,
        'size' => $长, 'slides' => $页数, 'new' => true];
}
