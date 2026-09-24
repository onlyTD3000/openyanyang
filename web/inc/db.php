<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        // 检测连接是否还活着（流式请求可能持续数十秒，期间 MySQL 连接可能断开）
        try {
            $pdo->query("SELECT 1");
        } catch (PDOException $e) {
            $pdo = null;   // 连接已死，重建
        }
    }
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('数据库连接失败，请检查配置。');
        }
    }
    return $pdo;
}

/** 查询多行 */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 查询单行 */
function db_one(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 查询单值 */
function db_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** 执行写入，返回影响行数 */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** 插入并返回自增 ID */
function db_insert(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) db()->lastInsertId();
}

/**
 * 站点设置：请求级缓存。
 * 一次请求中 setting_get 被调 5-10 次（prompt_cache、upstream_retry_times、sk_cool_* 等），
 * 每次都查库是浪费。首次调用时一次读全表存内存，后续直接从数组取。
 */
$GLOBALS['_settings_cache'] = null;

function setting_get(string $key, $default = null)
{
    if ($GLOBALS['_settings_cache'] === null) {
        $GLOBALS['_settings_cache'] = [];
        try {
            foreach (db_all('SELECT k, v FROM settings') as $r) {
                $GLOBALS['_settings_cache'][$r['k']] = $r['v'];
            }
        } catch (Throwable $e) {
            // 表不存在或数据库不可用时不崩，走默认值
        }
    }
    if (!array_key_exists($key, $GLOBALS['_settings_cache'])) {
        return $default;
    }
    $v = $GLOBALS['_settings_cache'][$key];
    return $v === null ? $default : $v;
}

function setting_set(string $key, $val): void
{
    db_exec('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, (string) $val]);
    $GLOBALS['_settings_cache'] = null;   // 写入后清缓存，下次 setting_get 重新加载
}

/**
 * 站点名称：取后台「站点设置」里的 site_name，没配或取不到时退回 APP_NAME_FALLBACK。
 *
 * 不在 config.php 里直接查库的原因见那边第 15 行的注释（循环依赖）。
 * 结果按请求缓存，一次请求内多处引用只查一次库。
 * 查库失败时（装机初期 settings 表还没建、数据库临时不可用）不能抛异常，
 * 否则页面标题这种小事会把整站带崩，所以吞掉异常走兜底值。
 */
function app_name(): string
{
    static $名称 = null;
    if ($名称 !== null) {
        return $名称;
    }
    try {
        $v = setting_get('site_name', '');
        $名称 = ($v === null || trim((string) $v) === '')
            ? APP_NAME_FALLBACK
            : (string) $v;
    } catch (Throwable $e) {
        $名称 = APP_NAME_FALLBACK;
    }
    return $名称;
}

/** 一次取出全部站点设置，返回 k => v 数组 */
function settings_all(): array
{
    $out = [];
    foreach (db_all('SELECT k, v FROM settings') as $r) {
        $out[$r['k']] = $r['v'];
    }
    return $out;
}
