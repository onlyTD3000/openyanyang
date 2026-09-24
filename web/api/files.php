<?php
/**
 * 工作中心文件接口：列出 / 删除自己上传的图片。
 * 所有查询都带 user_id 条件，用户之间互不可见。
 */
require_once __DIR__ . '/../inc/helpers.php';
api_error_guard();   // 让未捕获异常返回可读 JSON，而不是空的 500

$me  = require_login_api();
$act = $_GET['act'] ?? $_POST['act'] ?? 'list';

if ($act === 'list') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $size = min(60, max(6, (int) ($_GET['size'] ?? 24)));
    $off  = ($page - 1) * $size;

    $total = (int) db_val('SELECT COUNT(*) FROM uploads WHERE user_id = ?', [$me['id']]);
    $rows  = db_all(
        'SELECT id, path, mime, size, width, height, used, conv_id, created_at
           FROM uploads WHERE user_id = ?
          ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off,
        [$me['id']]
    );

    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'id'         => (int) $r['id'],
            'url'        => '/api/img.php?id=' . (int) $r['id'],
            'size'       => (int) $r['size'],
            'size_text'  => size_text((int) $r['size']),
            'width'      => (int) $r['width'],
            'height'     => (int) $r['height'],
            'used'       => (int) $r['used'],
            'conv_id'    => (int) $r['conv_id'],
            'created_at' => $r['created_at'],
        ];
    }
    json_out([
        'ok'    => 1,
        'list'  => $list,
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $size)),
    ]);
}

if ($act === 'del') {
    csrf_check();
    $id  = (int) ($_POST['id'] ?? 0);
    // 关键隔离点：带 user_id 查，别人的文件查不出来
    $row = db_one('SELECT * FROM uploads WHERE id = ? AND user_id = ? LIMIT 1', [$id, $me['id']]);
    if (!$row) {
        json_out(['error' => '文件不存在'], 404);
    }

    $base = realpath(rtrim(UPLOAD_DIR, '/'));
    $file = realpath($base . '/' . $row['path']);
    if ($base !== false && $file !== false
        && strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0
        && is_file($file)) {
        @unlink($file);
    }
    db_exec('DELETE FROM uploads WHERE id = ? AND user_id = ?', [$id, $me['id']]);
    json_out(['ok' => 1]);
}

if ($act === 'stat') {
    $row = db_one(
        'SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS s FROM uploads WHERE user_id = ?',
        [$me['id']]
    );
    json_out([
        'ok'         => 1,
        'count'      => (int) $row['n'],
        'bytes'      => (int) $row['s'],
        'bytes_text' => size_text((int) $row['s']),
    ]);
}

json_out(['error' => '未知操作'], 400);
