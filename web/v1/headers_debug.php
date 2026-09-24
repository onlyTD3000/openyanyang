<?php
header('Content-Type: application/json; charset=utf-8');
$headers = [];
if (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
        $headers[$k] = substr($v, 0, 30);
    }
}
echo json_encode([
    'server' => [
        'HTTP_AUTHORIZATION' => isset($_SERVER['HTTP_AUTHORIZATION']) ? substr($_SERVER['HTTP_AUTHORIZATION'], 0, 30) : '(none)',
        'HTTP_X_API_KEY' => isset($_SERVER['HTTP_X_API_KEY']) ? substr($_SERVER['HTTP_X_API_KEY'], 0, 10) . '...' : '(none)',
        'HTTP_ANTHROPIC_VERSION' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '(none)',
    ],
    'all_headers' => $headers,
    'get' => $_GET,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
