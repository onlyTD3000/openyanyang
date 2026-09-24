<?php
/**
 * 工作中心打包：把账号里已有的若干文件压成一个 zip，同样存回工作中心。
 *
 * 为什么单独一个文件而不塞进 ws_files.php：
 * ws_files 是「单文件读写」的存储层，打包属于组合操作（多文件 → 一个产物），
 * 而且依赖 ZipArchive 这个扩展。分开放，ws_files 保持无扩展依赖。
 *
 * 安全前提（逐条对应真实风险）：
 *  1. 只按 user_id 查库取文件，AI 给的是「账号内虚拟路径」，
 *     查不到就是查不到，不存在用 ../ 摸到别人文件或站点源码的可能。
 *  2. 不接受任何形式的绝对路径或服务器路径——入口只认虚拟名/id。
 *     所以哪怕 AI 被诱导写 inc/config.local.php，查库也命中不了。
 *  3. 配额按压缩前的总体积预估，超额在打包前就拦住，不用等压完再回滚。
 *  4. zip 落盘名走 工作区落盘名()，扩展名白名单外一律无害化。
 */

require_once __DIR__ . '/ws_files.php';

/** 一个包最多装多少个文件 */
const 工作区打包数上限 = 200;
/** 打包产物体积上限，256 MB。超了大概率是误操作 */
const 工作区打包体积上限 = 268435456;

/**
 * 打包账号内的指定文件。
 *
 * @param int   $用户id
 * @param array $文件表 元素是虚拟路径字符串或 uploads.id
 * @param string $包名  产物文件名，可省略
 * @param string $说明  备注
 * @return array ok/error/id/name/size/count/skipped
 */
function 工作区打包(int $用户id, array $文件表, string $包名 = '', string $说明 = ''): array
{
    $空 = ['ok' => false, 'error' => '', 'id' => 0, 'name' => '', 'size' => 0,
           'count' => 0, 'skipped' => []];

    if (!class_exists('ZipArchive')) {
        return array_merge($空, ['error' => '服务器未启用 ZipArchive 扩展，无法打包']);
    }
    if (!$文件表) {
        return array_merge($空, ['error' => '没有指定要打包的文件']);
    }
    if (count($文件表) > 工作区打包数上限) {
        return array_merge($空, ['error' => '一次最多打包 ' . 工作区打包数上限 . ' 个文件']);
    }

    // ---- 逐个查库确认归属，同时估算总体积 ----
    $待打 = [];
    $跳过 = [];
    $原始字节 = 0;
    foreach ($文件表 as $标识) {
        if (is_string($标识) && trim($标识) === '') {
            continue;
        }
        // 关键隔离点：带 user_id 查，别人的文件在这里就消失了
        $行 = 工作区取文件($用户id, is_numeric($标识) ? (int) $标识 : (string) $标识);
        if (!$行) {
            $跳过[] = (string) $标识 . '（不存在）';
            continue;
        }
        $全 = 工作区绝对路径((string) $行['path']);
        if ($全 === '' || !is_file($全)) {
            $跳过[] = (string) $行['name'] . '（文件已丢失）';
            continue;
        }
        // 同名去重：AI 可能把同一个文件列两遍
        if (isset($待打[(string) $行['name']])) {
            continue;
        }
        $待打[(string) $行['name']] = $全;
        $原始字节 += (int) $行['size'];
    }

    if (!$待打) {
        return array_merge($空, ['error' => '指定的文件都不存在，没东西可打包', 'skipped' => $跳过]);
    }
    if ($原始字节 > 工作区打包体积上限) {
        return array_merge($空, ['error' => '待打包内容 ' . size_text($原始字节)
            . ' 超过单包上限 ' . size_text(工作区打包体积上限), 'skipped' => $跳过]);
    }

    // 配额按「压缩前」的总体积估算，偏保守：真实 zip 一般小得多，
    // 所以可能误拒一些其实装得下的包。换来的是超额在打包之前就拦住，
    // 不用先实打实压一遍再告诉客户空间不够——大包尤其省时间。
    $err = 工作区可写($用户id, $原始字节);
    if ($err !== '') {
        return array_merge($空, ['error' => $err, 'skipped' => $跳过]);
    }

    // ---- 包名规范化 ----
    $包名 = trim($包名);
    if ($包名 === '') {
        $包名 = '打包_' . date('Ymd_His') . '.zip';
    }
    // 包名只取最后一段，不允许 AI 用包名造目录
    $包名 = basename(str_replace('\\', '/', $包名));
    if (!preg_match('/\.zip$/i', $包名)) {
        $包名 .= '.zip';
    }
    $规范包名 = 工作区规范名($包名);
    if ($规范包名 === '') {
        return array_merge($空, ['error' => '包名不合法：' . $包名, 'skipped' => $跳过]);
    }

    // 同名已存在就加序号，不覆盖：打包是「新产物」，静默覆盖旧包容易丢东西
    $最终名 = $规范包名;
    if (工作区取文件($用户id, $最终名)) {
        $主 = preg_replace('/\.zip$/i', '', $规范包名);
        for ($i = 2; $i <= 100; $i++) {
            $试 = $主 . '_' . $i . '.zip';
            if (!工作区取文件($用户id, $试)) {
                $最终名 = $试;
                break;
            }
        }
    }

    // ---- 落盘 ----
    $相对 = 工作区落盘名($用户id, $最终名);
    $全路径 = 工作区绝对路径($相对);
    if ($全路径 === '') {
        return array_merge($空, ['error' => '存储路径异常，已拒绝打包', 'skipped' => $跳过]);
    }
    if (!is_dir(dirname($全路径)) && !@mkdir(dirname($全路径), 0750, true)) {
        return array_merge($空, ['error' => '无法创建存储目录，请检查权限', 'skipped' => $跳过]);
    }

    $zip = new ZipArchive();
    if ($zip->open($全路径, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return array_merge($空, ['error' => '创建压缩包失败', 'skipped' => $跳过]);
    }
    $装入 = 0;
    foreach ($待打 as $包内名 => $磁盘路径) {
        // 包内保留虚拟路径结构，解压出来就是原来的目录层次
        if ($zip->addFile($磁盘路径, $包内名)) {
            $装入++;
        } else {
            $跳过[] = $包内名 . '（写入压缩包失败）';
        }
    }
    if ($装入 === 0) {
        $zip->close();
        @unlink($全路径);
        return array_merge($空, ['error' => '所有文件都写入失败', 'skipped' => $跳过]);
    }
    if (!$zip->close()) {
        @unlink($全路径);
        return array_merge($空, ['error' => '压缩包收尾失败，可能是磁盘空间不足', 'skipped' => $跳过]);
    }

    clearstatcache(true, $全路径);
    $体积 = (int) @filesize($全路径);
    if ($体积 <= 0) {
        @unlink($全路径);
        return array_merge($空, ['error' => '压缩包为空，已丢弃', 'skipped' => $跳过]);
    }

    落盘收尾($全路径, 0640);

    $id = db_insert('INSERT INTO uploads
        (user_id, conv_id, path, name, kind, source, mime, size, width, height,
         used, note, ver, created_at, updated_at)
        VALUES (?,0,?,?,\'archive\',\'ai\',\'application/zip\',?,0,0,1,?,1,NOW(),NOW())',
        [$用户id, $相对, $最终名, $体积, mb_substr($说明, 0, 250)]);

    return ['ok' => true, 'error' => '', 'id' => (int) $id, 'name' => $最终名,
        'size' => $体积, 'count' => $装入, 'skipped' => $跳过];
}
