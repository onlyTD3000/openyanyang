<?php
/**
 * WebSocket SSH 终端服务器
 * 用法：php wsssh_server.php <端口>
 * 默认端口：8801
 */

error_reporting(E_ALL);
ini_set("display_errors", "0");
set_time_limit(0);
ob_implicit_flush();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/crypto.php';
require_once __DIR__ . '/../inc/ssh_guard.php';

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

$port = isset($argv[1]) ? (int)$argv[1] : 8801;
// 只听本机。浏览器通过 nginx 的 /wsssh 路径反代进来，
// 这个端口不需要暴露到公网——直接开在 0.0.0.0 上等于把 SSH 入口摆到外面。
$host = '127.0.0.1';

echo "WebSocket SSH Server starting on $host:$port...\n";

// 创建主 socket
$master = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$master) {
    die("Failed to create socket: $errstr\n");
}
stream_set_blocking($master, false);

$clients = []; // WebSocket 客户端列表
$sessions = []; // SSH 会话状态 [id => [...]]
$sessionDir = __DIR__ . '/../assets/wsssh';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0755, true);
}

function saveSessions() {
    global $sessions, $sessionDir;
    $data = [];
    foreach ($sessions as $id => $s) {
        $data[$id] = [
            'id' => $id,
            'host_id' => $s['host_id'],
            'host' => $s['host'],
            'user' => $s['user'] ?? '?',
            'started_at' => $s['started_at'],
            'last_activity' => $s['last_activity'],
            'status' => $s['status'] ?? 'active',
        ];
    }
    file_put_contents($sessionDir . '/sessions.json', json_encode(array_values($data)));
}

function wsHandshake($socket, $headers) {
    $key = null;
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'sec-websocket-key') {
            $key = $v;
            break;
        }
    }
    if (!$key) {
        return false;
    }
    $accept = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $upgrade = "HTTP/1.1 101 Switching Protocols\r\n";
    $upgrade .= "Upgrade: websocket\r\n";
    $upgrade .= "Connection: Upgrade\r\n";
    $upgrade .= "Sec-WebSocket-Accept: $accept\r\n";
    $upgrade .= "\r\n";
    fwrite($socket, $upgrade);
    return true;
}

function wsEncode($data, $type = 'text') {
    $length = strlen($data);
    $header = '';
    if ($type === 'text') {
        $header .= chr(0x81);
    } else if ($type === 'binary') {
        $header .= chr(0x82);
    } else if ($type === 'close') {
        $header .= chr(0x88);
    }
    if ($length <= 125) {
        $header .= chr($length);
    } else if ($length <= 65535) {
        $header .= chr(126);
        $header .= pack('n', $length);
    } else {
        $header .= chr(127);
        $header .= pack('J', $length);
    }
    return $header . $data;
}

function wsDecode($data) {
    if (strlen($data) < 2) return false;
    $bytes = $data;
    $opcode = ord($bytes[0]) & 0x0F;
    $masked = (ord($bytes[1]) & 0x80) != 0;
    $length = ord($bytes[1]) & 0x7F;
    $offset = 2;
    if ($length == 126) {
        if (strlen($data) < 4) return false;
        $unpacked = unpack('n', substr($bytes, 2, 2));
        $length = $unpacked[1];
        $offset = 4;
    } else if ($length == 127) {
        if (strlen($data) < 10) return false;
        $unpacked = unpack('J', substr($bytes, 2, 8));
        $length = $unpacked[1];
        $offset = 10;
    }
    $mask = '';
    if ($masked) {
        if (strlen($data) < $offset + 4) return false;
        $mask = substr($bytes, $offset, 4);
        $offset += 4;
    }
    if (strlen($data) < $offset + $length) return false;
    $payload = substr($bytes, $offset, $length);
    if ($masked) {
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        }
    }
    $frameLen = $offset + $length;
    if ($opcode == 0x8) return ['type' => 'close', 'data' => '', 'len' => $frameLen];
    if ($opcode == 0x1) return ['type' => 'text', 'data' => $payload, 'len' => $frameLen];
    if ($opcode == 0x2) return ['type' => 'binary', 'data' => $payload, 'len' => $frameLen];
    return ['type' => 'unknown', 'data' => $payload, 'len' => $frameLen];
}

/**
 * 核销一次性令牌，换出用户身份和被授权的主机。
 *
 * 用 UPDATE ... WHERE used=0 的影响行数来判断核销是否成功：
 * 这一步在数据库层面是原子的，同一张票被并发送两次也只有一次能成功，
 * 先查后改会有竞态窗口。
 */
function 核销令牌($明文) {
    $明文 = trim((string)$明文);
    // 令牌是 32 字节随机数的十六进制，长度固定 64
    if ($明文 === '' || !preg_match('/^[0-9a-f]{64}$/', $明文)) {
        return ['ok' => false, 'error' => '令牌格式不对'];
    }
    $哈希 = hash('sha256', $明文);

    $行 = db_one('SELECT id, user_id, host_id FROM wsssh_tokens
                  WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1', [$哈希]);
    if (!$行) {
        return ['ok' => false, 'error' => '令牌无效或已过期，请重新打开终端'];
    }
    // 原子核销：抢到这一行才算这次握手有效
    $改 = db_exec('UPDATE wsssh_tokens SET used = 1 WHERE id = ? AND used = 0', [(int)$行['id']]);
    if ($改 < 1) {
        return ['ok' => false, 'error' => '令牌已被使用'];
    }
    return ['ok' => true, 'user_id' => (int)$行['user_id'], 'host_id' => (int)$行['host_id']];
}

function connectSSH($hostId, $userId) {
    // user_id 条件是必须的。少了它，任何能连上这个端口的人都能靠猜 host_id
    // 打开别人机器的 root shell——归属校验只能在这里做，不能信客户端传的 host_id。
    $host = db_one('SELECT * FROM ssh_hosts WHERE id = ? AND user_id = ? AND status = 1 LIMIT 1',
        [$hostId, $userId]);
    if (!$host) return ['ok' => false, 'error' => '主机不存在或不属于你'];
    $deny = ssh_host_deny_reason((string)$host['host']);
    if ($deny !== '') return ['ok' => false, 'error' => $deny];
    try {
        $conn = new SSH2((string)$host['host'], (int)$host['port'], 20);
        $conn->setPreferredAlgorithms([
            'hostkey' => ['ssh-rsa', 'ssh-dss', 'ecdsa-sha2-nistp256', 'ssh-ed25519'],
        ]);
        $user = (string)$host['username'];
        if ($host['auth_type'] === 'password') {
            $pwd = dec_secret((string)$host['secret_enc']);
            if ($pwd === '') return ['ok' => false, 'error' => '凭据解密失败'];
            $ok = $conn->login($user, $pwd);
        } else {
            $pem = dec_secret((string)$host['secret_enc']);
            if ($pem === '') return ['ok' => false, 'error' => '凭据解密失败'];
            $pass = dec_secret((string)$host['key_pass_enc']);
            try {
                $key = $pass !== ''
                    ? PublicKeyLoader::load($pem, $pass)
                    : PublicKeyLoader::load($pem);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => '私钥格式无法识别: ' . $e->getMessage()];
            }
            $ok = $conn->login($user, $key);
        }
        if (!$ok) return ['ok' => false, 'error' => '认证失败'];

        // 建交互式 shell。顺序很关键，写反了必然报 "An integer was expected."：
        // openShell() 里要等服务端回 CHANNEL_OPEN_CONFIRMATION 才能填上 server_channels，
        // 如果提前把超时压到 0.05 秒，这个确认包根本来不及收，
        // server_channels[CHANNEL_SHELL] 就是空的，接着 packSSH2 拿它去打包就抛异常。
        // 所以必须先在默认超时（构造函数给的 20 秒）下把通道建好，之后再压超时。
        // 之前的注释把这个当成 phpseclib 的 bug 而禁用了 PTY，其实是调用顺序问题。
        $conn->setWindowColumns(120);
        $conn->setWindowRows(30);
        if (!$conn->openShell()) {
            return ['ok' => false, 'error' => '无法建立交互式 shell'];
        }

        // 通道建好之后再压超时，供主循环做非阻塞轮询
        $conn->setTimeout(0.05);
        return ['ok' => true, 'conn' => $conn, 'host' => $host['host'], 'port' => $host['port'], 'user' => $user];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

echo "Server started. Waiting for connections...\n";
saveSessions();

while (true) {
    $read = [$master];
    foreach ($clients as $c) {
        $read[] = $c['socket'];
    }
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 0, 50000);
    
    if ($changed === false) {
        usleep(50000);
        continue;
    }
    
    // 处理新连接
    if (in_array($master, $read)) {
        // 变量名不能叫 $client：下面的 foreach 是按引用遍历 $clients，
        // 循环结束后 $client 仍指向最后一个元素，这里再赋值会顺着引用
        // 把那条客户端记录整个覆盖成一个裸 socket，
        // 于是它的 handshake / buffer / user_id 全丢，后续帧被误当 HTTP 头解析。
        $新socket = stream_socket_accept($master);
        if ($新socket) {
            stream_set_blocking($新socket, false);
            $id = uniqid('ws_', true);
            $clients[$id] = [
                'socket' => $新socket,
                'handshake' => false,
                'id' => $id,
                'ssh' => null,
                'host_id' => 0,
                // 鉴权前是 0。connect 等动作都要求它 > 0。
                'user_id' => 0,
                'token_host' => 0,
                'buffer' => '',
                'last_activity' => time(),
            ];
            echo "New client: $id\n";
        }
        $key = array_search($master, $read);
        unset($read[$key]);
    }
    
    // 处理客户端数据
    foreach ($clients as $id => &$client) {
        if (!in_array($client['socket'], $read)) continue;
        
        $data = fread($client['socket'], 8192);
        if ($data === false || $data === '') {
            // 断开连接
            if ($client['ssh']) {
                try { $client['ssh']['conn']->disconnect(); } catch (Throwable $e) {}
            }
            fclose($client['socket']);
            unset($clients[$id], $sessions[$id]);
            saveSessions();
            echo "Client disconnected: $id\n";
            continue;
        }
        
        $client['last_activity'] = time();
        
        // 握手
        if (!$client['handshake']) {
            $client['buffer'] .= $data;
            $endPos = strpos($client['buffer'], "\r\n\r\n");
            $headerLen = 4;
            if ($endPos === false) {
                $endPos = strpos($client['buffer'], "\n\n");
                $headerLen = 2;
            }
            if ($endPos !== false) {
                $headerText = substr($client['buffer'], 0, $endPos);
                $headers = [];
                foreach (explode("\n", $headerText) as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $parts = explode(':', $line, 2);
                    if (count($parts) == 2) {
                        $headers[trim($parts[0])] = trim($parts[1]);
                    }
                }
                if (wsHandshake($client['socket'], $headers)) {
                    $client['handshake'] = true;
                    $client['buffer'] = substr($client['buffer'], $endPos + $headerLen);
                    echo "Handshake OK: $id\n";
                    // 握手成功后，处理 buffer 中剩余的帧数据
                    while (strlen($client['buffer']) > 0) {
                        $frame = wsDecode($client['buffer']);
                        if (!$frame) break;
                        processWsFrame($id, $client, $frame);
                        $client['buffer'] = substr($client['buffer'], $frame['len']);
                    }
                } else {
                    echo "Handshake failed: no Sec-WebSocket-Key for $id\n";
                    fclose($client['socket']);
                    unset($clients[$id]);
                }
            }
            continue;
        }
        
        // 已经握手，把新数据追加到 buffer
        $client['buffer'] .= $data;
        
        // 循环解析 buffer 中的所有完整帧
        while (strlen($client['buffer']) > 0) {
            $frame = wsDecode($client['buffer']);
            if (!$frame) break; // 帧不完整，等更多数据
            processWsFrame($id, $client, $frame);
            $client['buffer'] = substr($client['buffer'], $frame['len']);
        }
    }
    unset($client);
    
    // 从 SSH 读取输出并发送给客户端
    foreach ($clients as $id => &$client) {
        if (!$client['ssh']) continue;
        try {
            $out = $client['ssh']['conn']->read();
            if ($out !== '' && $out !== false) {
                fwrite($client['socket'], wsEncode(json_encode(['type' => 'output', 'data' => $out])));
                if (isset($sessions[$id])) {
                    $sessions[$id]['last_activity'] = time();
                }
            }
        } catch (Throwable $e) {
            // 连接断开
            fwrite($client['socket'], wsEncode(json_encode(['type' => 'error', 'msg' => 'SSH连接断开: ' . $e->getMessage()])));
            try { $client['ssh']['conn']->disconnect(); } catch (Throwable $e2) {}
            $client['ssh'] = null;
            unset($sessions[$id]);
            saveSessions();
        }
    }
    unset($client);
    
    // 每 10 秒清理一下过期会话
    static $lastClean = 0;
    if (time() - $lastClean > 10) {
        $lastClean = time();
        saveSessions();
    }
}

function processWsFrame($id, &$client, $frame) {
    global $sessions;
    
    if ($frame['type'] === 'close') {
        if ($client['ssh']) {
            try { $client['ssh']['conn']->disconnect(); } catch (Throwable $e) {}
        }
        fwrite($client['socket'], wsEncode('', 'close'));
        fclose($client['socket']);
        unset($GLOBALS['clients'][$id], $sessions[$id]);
        saveSessions();
        echo "Client closed: $id\n";
        return;
    }
    
    $msg = $frame['data'];
    $msgObj = json_decode($msg, true);
    if (!$msgObj) {
        echo "Invalid JSON from $id\n";
        return;
    }
    
    $action = $msgObj['action'] ?? '';
    echo "Action from $id: $action\n";
    
    // 令牌换身份。这一步必须在 connect 之前完成，否则一律拒绝。
    // 令牌是一次性的：核销掉之后同一张票再送来也无效，防止被重放。
    if ($action === 'auth') {
        $票 = (string)($msgObj['token'] ?? '');
        $r = 核销令牌($票);
        if (!$r['ok']) {
            fwrite($client['socket'], wsEncode(json_encode(['type' => 'error', 'msg' => $r['error']])));
            echo "Auth failed for $id: {$r['error']}\n";
            return;
        }
        $client['user_id']   = $r['user_id'];
        $client['token_host'] = $r['host_id'];
        fwrite($client['socket'], wsEncode(json_encode(['type' => 'authed'])));
        echo "Authed $id as user {$r['user_id']} for host {$r['host_id']}\n";
        return;
    }

    // 除了 auth 和 ping，其余动作都要求先通过鉴权
    if ((int)($client['user_id'] ?? 0) <= 0 && $action !== 'ping') {
        fwrite($client['socket'], wsEncode(json_encode(['type' => 'error', 'msg' => '未鉴权，请先发送 auth'])));
        echo "Unauthed action '$action' from $id, rejected\n";
        return;
    }

    if ($action === 'connect') {
        $hostId = (int)($msgObj['host_id'] ?? 0);
        // 令牌是按主机发的，只能开令牌里那一台。
        // 不然拿一张合法令牌就能去开自己名下的其他机器，绕过「一次一授权」。
        if ($hostId !== (int)($client['token_host'] ?? 0)) {
            fwrite($client['socket'], wsEncode(json_encode(['type' => 'error', 'msg' => '令牌与目标主机不匹配'])));
            echo "Host mismatch from $id: asked $hostId, token allows {$client['token_host']}\n";
            return;
        }
        echo "Connecting to host $hostId as user {$client['user_id']}...\n";
        $result = connectSSH($hostId, (int)$client['user_id']);
        if ($result['ok']) {
            $client['ssh'] = $result;
            $client['host_id'] = $hostId;
            $sessions[$id] = [
                'id' => $id,
                'host_id' => $hostId,
                'host' => $result['host'],
                'user' => $result['user'],
                'started_at' => time(),
                'last_activity' => time(),
                'status' => 'active',
            ];
            saveSessions();
            fwrite($client['socket'], wsEncode(json_encode(['type' => 'connected', 'host' => $result['host'], 'user' => $result['user']])));
            echo "SSH connected: $id -> {$result['host']}\n";
        } else {
            echo "SSH connect failed for $id: {$result['error']}\n";
            fwrite($client['socket'], wsEncode(json_encode(['type' => 'error', 'msg' => $result['error']])));
        }
    } else if ($action === 'input' && $client['ssh']) {
        $input = $msgObj['data'] ?? '';
        try {
            $client['ssh']['conn']->write($input);
            $sessions[$id]['last_activity'] = time();
            saveSessions();
        } catch (Throwable $e) {
            fwrite($client['socket'], wsEncode(json_encode(['type' => 'error', 'msg' => $e->getMessage()])));
        }
    } else if ($action === 'resize' && $client['ssh']) {
        $cols = (int)($msgObj['cols'] ?? 80);
        $rows = (int)($msgObj['rows'] ?? 24);
        try {
            $client['ssh']['conn']->setWindowSize($rows, $cols);
        } catch (Throwable $e) {}
    } else if ($action === 'ping') {
        fwrite($client['socket'], wsEncode(json_encode(['type' => 'pong'])));
    }
}
