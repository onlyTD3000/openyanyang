<?php
/**
 * 文件上传接口（图片 + 压缩包）。
 * 安全要点：
 *  - 图片：只认 getimagesize 判定出来的真实类型，不信客户端的 MIME 和扩展名
 *  - 压缩包：按扩展名白名单放行（zip/rar/7z/tar/gz/tar.gz/tar.bz2/tar.xz），
 *    不解压、不执行，只是原样落盘存起来供对话里引用，本身不具备可执行性
 *  - 文件名随机生成，扩展名由服务端白名单决定，杜绝 .php 之类的可执行后缀
 *  - 存储目录在网站根目录之外（UPLOAD_DIR），无法被直接 URL 访问
 *  - 记录 user_id，读取时校验归属
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();   // 让未捕获异常返回可读 JSON，而不是空的 500

$me = require_login_api();
csrf_check();

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    json_out(['error' => '没有收到文件'], 400);
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $emap = [
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器允许的大小',
        UPLOAD_ERR_FORM_SIZE  => '文件过大',
        UPLOAD_ERR_PARTIAL    => '文件只上传了一部分，请重试',
        UPLOAD_ERR_NO_FILE    => '没有选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
    ];
    json_out(['error' => $emap[$f['error']] ?? '上传失败'], 400);
}

if ((int) $f['size'] <= 0) {
    json_out(['error' => '文件是空的'], 400);
}

if (!is_uploaded_file($f['tmp_name'])) {
    json_out(['error' => '非法上传'], 400);
}

// 压缩包扩展名白名单，按原始文件名后缀判断（不看客户端传的 MIME，那个能随便改）
$origName = (string) ($f['name'] ?? '');
$zipExts = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'];
$isArchive = false;
$archiveExt = '';
if (preg_match('/\.(tar\.gz|tar\.bz2|tar\.xz)$/i', $origName, $m)) {
    $isArchive = true;
    $archiveExt = str_replace('.', '', strtolower($m[1])); // targz / tarbz2 / tarxz
} else {
    foreach ($zipExts as $e) {
        if (strtolower(substr($origName, -strlen('.' . $e))) === '.' . $e) {
            $isArchive = true;
            $archiveExt = $e;
            break;
        }
    }
}

// 先判断真实图片类型；不是图片时再看是否命中压缩包白名单
$info = @getimagesize($f['tmp_name']);
$isImage = $info && !empty($info['mime']);

if (!$isImage && !$isArchive) {
    json_out(['error' => '只支持图片（JPG/PNG/GIF/WebP）或压缩包（zip/rar/7z/tar/gz/tar.bz2/tar.xz）'], 400);
}

if ($isImage) {
    // ---------- 图片走原有校验 ----------
    $maxKB = max(0, (int) setting_get('upload_max_image_size', 300));
    if ($maxKB > 0 && (int) $f['size'] > $maxKB * 1024) {
        json_out([
            'error'  => '图片不能超过 ' . $maxKB . 'KB，请压缩后重试',
            'code'   => 'too_large',
            'max_kb' => $maxKB,
            'size'   => (int) $f['size'],
        ], 400);
    }
    $maxMb = max(1, (int) setting_get('upload_max_mb', 5));
    if ((int) $f['size'] > $maxMb * 1024 * 1024) {
        json_out(['error' => '图片不能超过 ' . $maxMb . 'MB'], 400);
    }

    $allow = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $type = (int) ($info[2] ?? 0);
    if (!isset($allow[$type])) {
        json_out(['error' => '只支持 JPG / PNG / GIF / WebP 格式'], 400);
    }
    $ext  = $allow[$type];
    $mime = (string) $info['mime'];
    $w    = (int) $info[0];
    $hgt  = (int) $info[1];
    if ($w < 4 || $hgt < 4) {
        json_out(['error' => '图片尺寸过小'], 400);
    }
    if ($w > 12000 || $hgt > 12000) {
        json_out(['error' => '图片尺寸过大（单边上限 12000 像素）'], 400);
    }
} else {
    // ---------- 压缩包：不限制走 getimagesize，用单独的体积上限 ----------
    // 源码包比图片大得多，单独设一个上限（默认 100MB），跟图片上限区分开
    $maxArchiveMb = max(1, (int) setting_get('upload_max_archive_mb', 100));
    if ((int) $f['size'] > $maxArchiveMb * 1024 * 1024) {
        json_out(['error' => '压缩包不能超过 ' . $maxArchiveMb . 'MB'], 400);
    }
    $ext  = $archiveExt;
    $mime = 'application/octet-stream';
    $w    = 0;
    $hgt  = 0;
}

// 配额限制：防止用户批量上传占满磁盘（图片和压缩包共用一个配额）
$quotaMb = max(10, (int) setting_get('upload_quota_mb', 200));
$usedBytes = (int) db_val('SELECT COALESCE(SUM(size),0) FROM uploads WHERE user_id = ?', [$me['id']]);
if ($usedBytes + (int) $f['size'] > $quotaMb * 1024 * 1024) {
    json_out([
        'error' => '你的存储空间已用满（上限 ' . $quotaMb . 'MB），请到工作中心删除一些文件',
    ], 413);
}

// 未发送出去的文件不允许无限堆积
$pendingMax = max(5, (int) setting_get('upload_pending_max', 20));
$pending = (int) db_val('SELECT COUNT(*) FROM uploads WHERE user_id = ? AND used = 0', [$me['id']]);
if ($pending >= $pendingMax) {
    json_out([
        'error' => '有 ' . $pending . ' 个文件尚未发送，请先发送或到工作中心清理',
    ], 429);
}

// 按 用户/年月 分目录，避免单目录文件过多
$rel = $me['id'] . '/' . date('Ym');
$dir = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    json_out(['error' => '服务器无法创建上传目录，请检查目录权限'], 500);
}

$name = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $name;
if (!@move_uploaded_file($f['tmp_name'], $dest)) {
    json_out(['error' => '保存文件失败，请检查目录权限'], 500);
}
落盘收尾($dest, 0640);

// 原始文件名存 name 字段，压缩包展示给 AI 时用得上。
// 注意字段名：表里是 name / kind，不是 orig_name / is_archive
$origNameSafe = mb_substr($origName, 0, 255);
$kind = $isArchive ? 'archive' : 'image';

$id = db_insert(
    'INSERT INTO uploads (user_id, conv_id, path, mime, size, width, height, used, name, kind, source, created_at)
     VALUES (?,0,?,?,?,?,?,0,?,?,?,NOW())',
    [$me['id'], $rel . '/' . $name, $mime, (int) $f['size'], $w, $hgt, $origNameSafe, $kind, 'user']
);

json_out([
    'ok'         => 1,
    'id'         => $id,
    'url'        => '/api/img.php?id=' . $id,
    'width'      => $w,
    'height'     => $hgt,
    'size'       => (int) $f['size'],
    'is_archive' => $isArchive,
    'name'       => $origNameSafe,
]);
