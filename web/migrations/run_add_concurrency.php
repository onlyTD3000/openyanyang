<?php
require_once __DIR__ . '/../inc/helpers.php';

echo "正在创建 concurrency_slots 表...\n";

$sql = file_get_contents(__DIR__ . '/add_concurrency_slots.sql');
try {
    db_exec($sql);
    echo "✓ 表创建成功\n";
} catch (Throwable $e) {
    echo "✗ 失败: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n完成！\n";
