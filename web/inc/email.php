<?php
/**
 * 邮件发送模块
 * 支持 SMTP 直连发送（无需外部依赖），以及 PHP mail() 降级
 * 所有发送操作自动记录到 email_logs 表
 */

/**
 * 获取邮件配置
 */
function email_config(): array
{
    return [
        'host'     => setting_get('email_smtp_host', ''),
        'port'     => (int) setting_get('email_smtp_port', '465'),
        'user'     => setting_get('email_smtp_user', ''),
        'pass'     => setting_get('email_smtp_pass', ''),
        'from'     => setting_get('email_from', ''),
        'from_name'=> setting_get('email_from_name', setting_get('site_name', '云智 AI')),
        'encrypt'  => setting_get('email_smtp_encrypt', 'ssl'), // ssl / tls / none
    ];
}

/**
 * 发送一封邮件并记录日志
 *
 * @param string $to      收件人邮箱
 * @param string $subject 邮件主题
 * @param string $body    HTML 正文
 * @param string $reason  发送原因（如：注册验证、密码重置等）
 * @param int    $userId  关联用户 ID（可选）
 * @return array [bool $ok, string $error]
 */
function email_send(string $to, string $subject, string $body, string $reason = '', int $userId = 0): array
{
    $cfg = email_config();
    $logId = email_log_insert($to, $subject, $body, $reason, $userId, 'pending');

    if ($cfg['host'] === '' || $cfg['user'] === '') {
        email_log_update($logId, 'fail', 'SMTP 未配置，请先在后台设置邮件服务器');
        return [false, 'SMTP 未配置'];
    }

    [$ok, $err] = email_send_smtp($cfg, $to, $subject, $body);

    if ($ok) {
        email_log_update($logId, 'ok', '');
    } else {
        email_log_update($logId, 'fail', $err);
    }

    return [$ok, $err];
}

/**
 * 通过 SMTP 直连发送邮件
 */
function email_send_smtp(array $cfg, string $to, string $subject, string $body): array
{
    $host    = $cfg['host'];
    $port    = $cfg['port'];
    $user    = $cfg['user'];
    $pass    = $cfg['pass'];
    $from    = $cfg['from'] ?: $user;
    $fromName= $cfg['from_name'];
    $encrypt = $cfg['encrypt'];

    $errno  = 0;
    $errstr = '';

    // 根据加密方式选择连接
    if ($encrypt === 'ssl') {
        $fp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 15);
    } elseif ($encrypt === 'tls') {
        $fp = @fsockopen($host, $port, $errno, $errstr, 15);
    } else {
        $fp = @fsockopen($host, $port, $errno, $errstr, 15);
    }

    if (!$fp) {
        return [false, "连接 SMTP 服务器失败: $errstr ($errno)"];
    }

    $read = function() use ($fp) {
        $data = '';
        while ($s = @fgets($fp, 512)) {
            $data .= $s;
            if (isset($s[3]) && $s[3] === ' ') break;
        }
        return $data;
    };

    $send = function($cmd) use ($fp) {
        @fwrite($fp, $cmd . "\r\n");
    };

    // 读欢迎信息
    $resp = $read();
    if (substr($resp, 0, 3) !== '220') {
        @fclose($fp);
        return [false, "SMTP 握手失败: " . trim($resp)];
    }

    // HELO
    $send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $resp = $read();
    if (substr($resp, 0, 3) !== '250') {
        // 尝试 HELO 降级
        $send('HELO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $resp = $read();
        if (substr($resp, 0, 3) !== '250') {
            @fclose($fp);
            return [false, "EHLO/HELO 失败: " . trim($resp)];
        }
    }

    // 如果需要 TLS 且不是 SSL 连接
    if ($encrypt === 'tls') {
        $send('STARTTLS');
        $resp = $read();
        if (substr($resp, 0, 3) !== '220') {
            @fclose($fp);
            return [false, "STARTTLS 失败: " . trim($resp)];
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($fp);
            return [false, 'TLS 握手失败'];
        }
        $send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $read();
    }

    // AUTH LOGIN
    $send('AUTH LOGIN');
    $resp = $read();
    if (substr($resp, 0, 3) !== '334') {
        @fclose($fp);
        return [false, "AUTH LOGIN 不支持: " . trim($resp)];
    }

    $send(base64_encode($user));
    $resp = $read();
    if (substr($resp, 0, 3) !== '334') {
        @fclose($fp);
        return [false, "用户名验证失败: " . trim($resp)];
    }

    $send(base64_encode($pass));
    $resp = $read();
    if (substr($resp, 0, 3) !== '235') {
        @fclose($fp);
        return [false, "密码验证失败: " . trim($resp)];
    }

    // MAIL FROM
    $send('MAIL FROM:<' . $from . '>');
    $resp = $read();
    if (substr($resp, 0, 3) !== '250') {
        @fclose($fp);
        return [false, "MAIL FROM 失败: " . trim($resp)];
    }

    // RCPT TO
    $send('RCPT TO:<' . $to . '>');
    $resp = $read();
    if (substr($resp, 0, 3) !== '250') {
        @fclose($fp);
        return [false, "收件人地址无效: " . trim($resp)];
    }

    // DATA
    $send('DATA');
    $resp = $read();
    if (substr($resp, 0, 3) !== '354') {
        @fclose($fp);
        return [false, "DATA 命令失败: " . trim($resp)];
    }

    // 构造邮件头
    $fromEncoded = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $boundary = '----=_Part_' . md5(uniqid((string)random_int(0, getrandmax()), true));

    $headers = "From: {$fromEncoded} <{$from}>\r\n"
             . "To: <{$to}>\r\n"
             . "Subject: {$subjectEncoded}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
             . "Date: " . date('r') . "\r\n"
             . "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

    $message = "--{$boundary}\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($textBody))
             . "\r\n--{$boundary}\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body))
             . "\r\n--{$boundary}--\r\n";

    $send($headers . "\r\n" . $message . "\r\n.");

    $resp = $read();
    if (substr($resp, 0, 3) !== '250') {
        @fclose($fp);
        return [false, "发送失败: " . trim($resp)];
    }

    // QUIT
    $send('QUIT');
    @fclose($fp);

    return [true, ''];
}

/**
 * 写入邮件日志
 */
function email_log_insert(string $to, string $subject, string $body, string $reason, int $userId, string $status): int
{
    return db_insert(
        'INSERT INTO email_logs (user_id, to_email, subject, body, reason, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [$userId, mb_substr($to, 0, 255), mb_substr($subject, 0, 500), $body, mb_substr($reason, 0, 100), $status]
    );
}

/**
 * 更新邮件日志状态
 */
function email_log_update(int $id, string $status, string $error = ''): void
{
    db_exec('UPDATE email_logs SET status = ?, error_msg = ? WHERE id = ?', [$status, mb_substr($error, 0, 500), $id]);
}

/**
 * 生成邮箱验证码 HTML 邮件模板
 */
function email_verify_template(string $siteName, string $username, string $code): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:30px;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06)">
  <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:28px 24px;text-align:center">
    <div style="font-size:24px;color:#fff;font-weight:700">{$siteName}</div>
    <div style="font-size:14px;color:rgba(255,255,255,0.8);margin-top:6px">邮箱验证</div>
  </div>
  <div style="padding:32px 24px">
    <p style="font-size:15px;color:#374151;margin:0 0 16px;line-height:1.6">
      你好 <strong>{$username}</strong>，感谢你注册 {$siteName}！
    </p>
    <p style="font-size:14px;color:#6b7280;margin:0 0 24px;line-height:1.6">
      请使用以下验证码完成邮箱验证，验证码 <strong>10 分钟内有效</strong>。
    </p>
    <div style="text-align:center;margin:24px 0">
      <div style="display:inline-block;background:#f3f4f6;border-radius:8px;padding:16px 32px;letter-spacing:6px;font-size:28px;font-weight:700;color:#6366f1;font-family:monospace">
        {$code}
      </div>
    </div>
    <p style="font-size:13px;color:#9ca3af;margin:0;line-height:1.6">
      如果这不是你本人的操作，请忽略此邮件。<br>
      此邮件由系统自动发送，请勿回复。
    </p>
  </div>
  <div style="background:#f9fafb;padding:14px 24px;text-align:center;border-top:1px solid #e5e7eb">
    <span style="font-size:12px;color:#9ca3af">© {$siteName} · 邮箱验证</span>
  </div>
</div>
</body>
</html>
HTML;
}