<?php
require_once __DIR__ . '/../inc/db.php';

try {
    $sql = file_get_contents(__DIR__ . '/add_rate_limit_table.sql');
    db_exec($sql);
    echo "✓ 并发限流表创建成功\n";
    
    $exists = db_val("SHOW TABLES LIKE 'concurrency_rate_limits'");
    if ($exists) {
        echo "✓ 表 concurrency_rate_limits 已存在\n";
        $columns = db_all("SHOW COLUMNS FROM concurrency_rate_limits");
        echo "\n表结构：\n";
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
    }
} catch (Throwable $e) {
    echo "✗ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
