<?php
/**
 * 工具执行统一入口，供 Function Calling 调用。
 * 每个工具执行完返回字符串结果，由 chat.php 包装成 tool 消息发回上游。
 */

require_once __DIR__ . '/ssh_guard.php';
require_once __DIR__ . '/ssh_run.php';
require_once __DIR__ . '/sftp_ops.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/ws_files.php';
require_once __DIR__ . '/ws_zip.php';
require_once __DIR__ . '/web_fetch.php';
require_once __DIR__ . '/ppt.php';
require_once __DIR__ . '/project.php';

/**
 * 执行 SSH 命令。
 *
 * 改后台任务模式：命令用 nohup/setsid 丢到远端后台执行，这里轮询日志文件。
 * 原来用同步 ssh_exec()，单条命令最长 240 秒，而且 nginx/FPM 的
 * fastcgi_read_timeout / request_terminate_timeout 是 300 秒，构建 APK、
 * 装依赖这类长命令一旦超时，进程被杀、SSE 流断裂，前端就报「网络中断」。
 * 现在命令本身不再受超时限制，轮询到上限就返回「已转后台运行」让 AI 结束
 * 本轮，用户能看到执行时间和任务号，不会再有 502/网络中断。
 */
function tool_execute_ssh(int $userId, array $args): string
{
    // 检查权限
    $cap = db_one('SELECT cap_ssh_exec FROM users WHERE id = ?', [$userId]);
    if ((int) ($cap['cap_ssh_exec'] ?? 1) !== 1) {
        return "权限错误：你没有 SSH 命令执行权限";
    }
    
    $hostId = (int) ($args['host'] ?? 0);
    $cmd = (string) ($args['cmd'] ?? '');
    
    if ($hostId <= 0) {
        return "错误：缺少服务器编号";
    }
    
    if (trim($cmd) === '') {
        return "错误：命令为空";
    }
    
    $host = ssh_host_of($hostId, $userId);
    if (!$host) {
        return "错误：服务器不存在或不属于你";
    }
    
    // 连接服务器
    $c = ssh_connect($host);
    if (!$c['ok']) {
        return "连接失败：" . $c['error'];
    }
    
    // 启动后台任务。ssh_job_start 内部用 setsid 起进程，不套 timeout，
    // 命令跑多久都不会被远端杀掉。
    ssh_job_gc($c['conn']);
    $r = ssh_job_start($c['conn'], $cmd);
    if (!$r['ok']) {
        return "命令启动失败：" . $r['error'];
    }
    $job = $r['job'];
    
    $t0   = microtime(true);
    $out  = '';
    $exit = -1;
    $done = false;
    $from = 0;
    // 轮询上限 200 秒：留给 nginx/FPM 300 秒天花板余量，避免请求本身被切断。
    $deadline = time() + 200;
    
    while (time() < $deadline) {
        $tr = ssh_job_tail($c['conn'], $job, $from);
        if (!$tr['ok']) {
            $out .= "\n[读取进度失败：{$tr['error']}]";
            break;
        }
        if ($tr['out'] !== '') {
            $out .= $tr['out'];
            $from = (int) ($tr['next'] ?? $tr['size'] ?? $from);
        }
        if ($tr['done']) {
            $done = true;
            $exit = (int) $tr['exit'];
            break;
        }
        usleep(400000);   // 0.4 秒一次，短命令第一次轮询就结束，无感
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    
    // 执行时间显示成秒/分钟
    $时长 = $ms >= 60000 ? sprintf('%.1f 分钟', $ms / 60000)
                        : sprintf('%.1f 秒', $ms / 1000);
    
    $output = "命令：{$cmd}\n";
    if ($done) {
        $output .= "执行完成，耗时 {$时长}（{$ms} ms）\n";
    } else {
        $output .= "命令仍在后台运行中，任务号：{$job}，已等待 {$时长}，" .
                   "未在等待上限内完成。可稍后用同一命令继续查询进度。\n";
    }
    $output .= "退出码：{$exit}\n";
    
    if ($out !== '') {
        $output .= "输出：\n{$out}";
    }
    
    return $output;
}

/**
 * 执行 SFTP 操作。
 */
function tool_execute_sftp(string $action, int $userId, array $args): string
{
    // 检查 SFTP 权限
    $cap = db_one('SELECT cap_sftp_read, cap_sftp_write, cap_sftp_list, cap_sftp_delete, cap_sftp_patch FROM users WHERE id = ?', [$userId]);
    $权限映射 = [
        'sftp_list' => 'cap_sftp_list',
        'sftp_read' => 'cap_sftp_read',
        'sftp_write' => 'cap_sftp_write',
        'sftp_patch' => 'cap_sftp_patch',
        'sftp_delete' => 'cap_sftp_delete',
    ];
    if (isset($权限映射[$action])) {
        $需要字段 = $权限映射[$action];
        if ((int) ($cap[$需要字段] ?? 1) !== 1) {
            $操作名 = ['sftp_list' => '列表', 'sftp_read' => '读取', 'sftp_write' => '写入', 'sftp_patch' => '补丁', 'sftp_delete' => '删除'][$action] ?? $action;
            return "权限错误：你没有 SFTP {$操作名}权限";
        }
    }
    
    $convId = (int) ($args['_conv_id'] ?? 0);
    
    // 获取项目上下文
    $project = null;
    if ($convId > 0) {
        $conv = db_one('SELECT project_id FROM conversations WHERE id = ? AND user_id = ?', 
                       [$convId, $userId]);
        if ($conv && $conv['project_id']) {
            $project = project_of((int) $conv['project_id'], $userId);
        }
    }
    
    if (!$project) {
        return "错误：当前对话未绑定项目，无法使用 SFTP 操作";
    }
    
    $hostId = (int) ($project['host_id'] ?? 0);
    $deployDir = (string) ($project['deploy_dir'] ?? '');
    
    if ($hostId <= 0) {
        return "错误：项目未绑定服务器";
    }
    
    if (trim($deployDir) === '') {
        return "错误：项目未设置部署目录";
    }
    
    $host = ssh_host_of($hostId, $userId);
    if (!$host) {
        return "错误：服务器不存在";
    }
    
    require_once __DIR__ . '/sftp_ops.php';
    
    switch ($action) {
        case 'sftp_read':
            $path = (string) ($args['path'] ?? '');
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            return sftp_read_file($userId, $hostId, $deployDir, $path);
            
        case 'sftp_write':
            $path = (string) ($args['path'] ?? '');
            $content = (string) ($args['content'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            
            return sftp_write_file($userId, $hostId, $deployDir, $path, $content, $note);
            
        case 'sftp_patch':
            $path = (string) ($args['path'] ?? '');
            $original = (string) ($args['original'] ?? '');
            $replacement = (string) ($args['replacement'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            if ($original === '') {
                return "错误：缺少原片段（original）";
            }
            
            return sftp_patch_file($userId, $hostId, $deployDir, $path, $original, $replacement, $note);
            
        case 'sftp_list':
            $dir = (string) ($args['dir'] ?? '');
            return sftp_list_dir($userId, $hostId, $deployDir, $dir);
            
        case 'sftp_delete':
            $path = (string) ($args['path'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            
            return sftp_delete_file($userId, $hostId, $deployDir, $path, $note);
            
        default:
            return "错误：未知的 SFTP 操作：{$action}";
    }
}

/**
 * 执行 Repo 操作（本地副本）。
 */
function tool_execute_repo(string $action, int $userId, int $convId, array $args): string
{
    // 检查代码仓权限
    $cap = db_one('SELECT cap_file_list, cap_file_read, cap_file_write, cap_file_patch, cap_file_delete, cap_file_push FROM users WHERE id = ?', [$userId]);
    $权限映射 = [
        'file_list' => 'cap_file_list',
        'file_read' => 'cap_file_read',
        'file_write' => 'cap_file_write',
        'file_patch' => 'cap_file_patch',
        'file_delete' => 'cap_file_delete',
        'file_push' => 'cap_file_push',
    ];
    if (isset($权限映射[$action])) {
        $需要字段 = $权限映射[$action];
        if ((int) ($cap[$需要字段] ?? 1) !== 1) {
            $操作名 = [
                'file_list' => '列表', 'file_read' => '读取',
                'file_write' => '写入', 'file_patch' => '补丁', 'file_delete' => '删除',
                'file_push' => '推送'
            ][$action] ?? $action;
            return "权限错误：你没有代码仓{$操作名}权限";
        }
    }
    
    // 获取项目
    $conv = db_one('SELECT project_id FROM conversations WHERE id = ? AND user_id = ?', 
                   [$convId, $userId]);
    
    if (!$conv || !$conv['project_id']) {
        return "错误：当前对话未绑定项目";
    }
    
    $projectId = (int) $conv['project_id'];
    $project = project_of($projectId, $userId);
    
    if (!$project) {
        return "错误：项目不存在";
    }
    
    // 获取或创建仓
    $repo = 仓取或建($projectId, $userId, 
                     (int) ($project['host_id'] ?? 0),
                     (string) ($project['deploy_dir'] ?? ''));
    
    switch ($action) {
        case 'file_list':
            $dirty = (int) ($args['dirty'] ?? 0);
            $where = 'repo_id = ?';
            $params = [(int) $repo['id']];
            
            if ($dirty === 1) {
                $where .= " AND state IN ('new','edited','deleted')";
            }
            
            $files = db_all("SELECT path, size, state, is_text, updated_at 
                            FROM repo_files WHERE {$where} ORDER BY path", $params);
            
            if (empty($files)) {
                return "本地仓为空。如需拉取代码，请在项目设置里配置服务器和部署目录后执行拉取操作。";
            }
            
            $output = "本地仓文件列表（共 " . count($files) . " 个文件）：\n\n";
            
            foreach ($files as $f) {
                $state = ['same' => '', 'new' => '[新建]', 'edited' => '[已改]', 'deleted' => '[已删]'][$f['state']] ?? '';
                $type = $f['is_text'] ? '[文本]' : '[二进制]';
                $output .= "{$state}{$type} {$f['path']} (" . size_text($f['size']) . ")\n";
            }
            
            return $output;
            
        case 'file_read':
            $path = (string) ($args['path'] ?? '');
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            
            $r = 仓读文件($repo, $path);
            
            if (!$r['ok']) {
                return "读取失败：" . $r['error'];
            }
            
            $text = $r['text'];
            $truncated = false;
            
            if (mb_strlen($text) > 仓正文上限) {
                $text = mb_substr($text, 0, 仓正文上限);
                $truncated = true;
            }
            
            $output = "文件路径：{$path}\n";
            $output .= "文件大小：" . size_text(strlen($r['text'])) . "\n";
            $output .= "行数：" . (substr_count($r['text'], "\n") + 1) . "\n\n";
            $output .= "文件内容：\n{$text}";
            
            if ($truncated) {
                $output .= "\n\n（内容过长，已截断到前 " . 仓正文上限 . " 字符）";
            }
            
            return $output;
            
        case 'file_write':
            $path = (string) ($args['path'] ?? '');
            $content = (string) ($args['content'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            
            $r = 仓写文件($repo, $path, $content, 'write', $convId, $note);
            
            if (!$r['ok']) {
                return "写入失败：" . $r['error'];
            }
            
            $output = "已写入本地仓：{$path}\n";
            $output .= "文件大小：" . size_text($r['size']) . "\n";
            $output .= $r['new'] ? "这是新建文件\n" : "已覆盖原有文件（旧版本已保存）\n";
            $output .= "注意：这只是写入本地副本，需要执行 file_push 才能回传到服务器";
            
            return $output;
            
        case 'file_patch':
            $path = (string) ($args['path'] ?? '');
            $original = (string) ($args['original'] ?? '');
            $replacement = (string) ($args['replacement'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            if ($original === '') {
                return "错误：缺少原片段（original）";
            }
            
            // 先读文件
            $r = 仓读文件($repo, $path);
            if (!$r['ok']) {
                return "读取失败：" . $r['error'];
            }
            
            // 应用补丁
            $p = 仓应用补丁($r['text'], $original, $replacement);
            if (!$p['ok']) {
                $档名 = ['1' => '精确匹配', '2' => '忽略行尾空白', '3' => '模糊匹配'];
                $档 = $档名[(string)$p['档']] ?? '匹配';
                return "补丁失败（{$档}）：{$p['error']}";
            }
            
            // 写回
            $w = 仓写文件($repo, $path, $p['text'], 'patch', $convId, $note);
            if (!$w['ok']) {
                return "补丁后写入失败：" . $w['error'];
            }
            
            $output = "已应用补丁：{$path}\n";
            $output .= "匹配档位：{$p['档']}\n";
            $output .= "文件大小：" . size_text($w['size']) . "\n";
            $output .= "注意：这只是写入本地副本，需要执行 file_push 才能回传到服务器";
            
            return $output;
            
        case 'file_delete':
            $path = (string) ($args['path'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($path) === '') {
                return "错误：缺少文件路径";
            }
            
            $r = file_delete($repo, $path, $convId, $note);
            
            if (!$r['ok']) {
                return "删除失败：" . $r['error'];
            }
            
            $output = "已从本地仓删除：{$path}\n";
            $output .= "注意：这只是删除本地副本，该文件的历史版本也已清除。\n";
            $output .= "服务器上的文件不受影响（回传只上传改动过的文件，从不删除远端）。";
            
            return $output;
            
        case 'file_push':
            $note = (string) ($args['note'] ?? '改动文件');
            $confirm = (int) ($args['confirm'] ?? 0);
            
            // 获取待回传列表
            $list = db_all("SELECT path, state FROM repo_files 
                           WHERE repo_id = ? AND state IN ('new','edited') 
                           ORDER BY path", [(int) $repo['id']]);
            
            if (empty($list)) {
                return "本地仓没有需要回传的改动";
            }
            
            // 检查敏感文件
            $sensitive = [];
            foreach ($list as $f) {
                $name = basename($f['path']);
                if (preg_match('/\.(env|key|pem|p12|pfx)$/i', $name) 
                    || in_array($name, ['config.local.php', 'credentials.json'], true)) {
                    $sensitive[] = $f['path'];
                }
            }
            
            if (!empty($sensitive) && $confirm !== 1) {
                $output = "待回传的文件中包含敏感配置，需要确认：\n\n";
                foreach ($sensitive as $s) {
                    $output .= "- {$s}\n";
                }
                $output .= "\n这些文件可能包含密钥、密码等敏感信息。";
                $output .= "线上的配置通常和本地不同，覆盖后可能导致站点无法连接数据库。\n";
                $output .= "如确认要推送，请用户明确同意后再执行。";
                return $output;
            }
            
            // 执行回传
            require_once __DIR__ . '/repo_sync.php';
            $r = 同步推送($repo, $userId, $convId, $note);
            
            if (!$r['ok']) {
                return "回传失败：" . $r['error'];
            }
            
            $output = "已回传到服务器\n";
            $output .= "推送了 " . count($r['pushed']) . " 个文件\n";
            $output .= "远端备份：{$r['backup_dir']}\n\n";
            $output .= "推送的文件：\n";
            
            foreach ($r['pushed'] as $p) {
                $output .= "- {$p}\n";
            }
            
            return $output;
            
        default:
            return "错误：未知的 Repo 操作：{$action}";
    }
}

/**
 * 执行工作中心文件操作。
 */
function tool_execute_ws(string $action, int $userId, array $args): string
{
    // 检查工作中心权限
    $cap = db_one('SELECT cap_ws_list, cap_ws_read, cap_ws_write, cap_ws_delete, cap_ws_patch, cap_ws_zip FROM users WHERE id = ?', [$userId]);
    $权限映射 = [
        'ws_list' => 'cap_ws_list',
        'ws_read' => 'cap_ws_read',
        'ws_write' => 'cap_ws_write',
        'ws_patch' => 'cap_ws_patch',
        'ws_delete' => 'cap_ws_delete',
        'ws_zip' => 'cap_ws_zip',
    ];
    if (isset($权限映射[$action])) {
        $需要字段 = $权限映射[$action];
        if ((int) ($cap[$需要字段] ?? 1) !== 1) {
            $操作名 = ['ws_list' => '列表', 'ws_read' => '读取', 'ws_write' => '写入', 'ws_patch' => '补丁', 'ws_delete' => '删除', 'ws_zip' => '打包'][$action] ?? $action;
            return "权限错误：你没有工作中心{$操作名}权限";
        }
    }
    
    switch ($action) {
        case 'ws_list':
            $keyword = (string) ($args['q'] ?? '');
            $r = ws_list($userId, $keyword);
            
            if (!$r['ok']) {
                return "列表获取失败：" . $r['error'];
            }
            
            if (empty($r['list'])) {
                return "工作中心文件库为空";
            }
            
            $output = "工作中心文件（共 " . count($r['list']) . " 个）：\n\n";
            
            foreach ($r['list'] as $f) {
                $output .= "{$f['name']} (" . size_text($f['size']) . ") - {$f['updated_at']}\n";
            }
            
            return $output;
            
        case 'ws_read':
            $name = (string) ($args['name'] ?? '');
            if (trim($name) === '') {
                return "错误：缺少文件名";
            }
            
            $r = ws_read($userId, $name);
            
            if (!$r['ok']) {
                return "读取失败：" . $r['error'];
            }
            
            $text = $r['text'];
            $truncated = false;
            
            if (mb_strlen($text) > 30000) {
                $text = mb_substr($text, 0, 30000);
                $truncated = true;
            }
            
            $output = "文件：{$name}\n";
            $output .= "大小：" . size_text(strlen($r['text'])) . "\n\n";
            $output .= "内容：\n{$text}";
            
            if ($truncated) {
                $output .= "\n\n（内容过长，已截断）";
            }
            
            return $output;
            
        case 'ws_write':
            $name = (string) ($args['name'] ?? '');
            $content = (string) ($args['content'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($name) === '') {
                return "错误：缺少文件名";
            }
            
            $r = ws_write($userId, $name, $content, $note);
            
            if (!$r['ok']) {
                return "写入失败：" . $r['error'];
            }
            
            return "已写入工作中心：{$name}\n大小：" . size_text(strlen($content));
            
        case 'ws_patch':
            $name = (string) ($args['name'] ?? '');
            $original = (string) ($args['original'] ?? '');
            $replacement = (string) ($args['replacement'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($name) === '') {
                return "错误：缺少文件名";
            }
            if ($original === '') {
                return "错误：缺少原片段（original）";
            }
            
            $r = ws_patch($userId, $name, $original, $replacement, $note);
            
            if (!$r['ok']) {
                return "补丁失败：" . $r['error'];
            }
            
            return "已应用补丁：{$name}\n大小：" . size_text($r['size'] ?? 0);
            
        case 'ws_delete':
            $name = (string) ($args['name'] ?? '');
            $note = (string) ($args['note'] ?? '');
            
            if (trim($name) === '') {
                return "错误：缺少文件名";
            }
            
            $r = ws_delete($userId, $name, $note);
            
            if (!$r['ok']) {
                return "删除失败：" . $r['error'];
            }
            
            return "已从工作中心删除：{$name}\n注意：删除操作不可撤销。";
            
        case 'ws_zip':
            $name = (string) ($args['name'] ?? '');
            $files = (array) ($args['files'] ?? []);
            $note = (string) ($args['note'] ?? '');
            
            if (trim($name) === '') {
                return "错误：缺少包名";
            }
            if (empty($files)) {
                return "错误：缺少要打包的文件列表";
            }
            
            $r = 工作区打包($userId, $files, $name, $note);
            
            if (!$r['ok']) {
                $msg = "打包失败：" . $r['error'];
                if (!empty($r['skipped'])) {
                    $msg .= "\n跳过的文件：\n";
                    foreach ($r['skipped'] as $s) {
                        $msg .= "- {$s}\n";
                    }
                }
                return $msg;
            }
            
            $output = "已打包：{$r['name']}\n";
            $output .= "装入文件：{$r['count']} 个\n";
            $output .= "包大小：" . size_text($r['size']) . "\n";
            if (!empty($r['skipped'])) {
                $output .= "跳过的文件：\n";
                foreach ($r['skipped'] as $s) {
                    $output .= "- {$s}\n";
                }
            }
            
            return $output;
            
        default:
            return "错误：未知的工作中心操作：{$action}";
    }
}

/**
 * 执行网页抓取。
 */
function tool_execute_web(int $userId, array $args): string
{
    // 检查网页访问权限
    $cap = db_one('SELECT cap_web_open FROM users WHERE id = ?', [$userId]);
    if ((int) ($cap['cap_web_open'] ?? 1) !== 1) {
        return "权限错误：你没有网页访问权限";
    }
    
    $url = (string) ($args['url'] ?? '');
    $limit = (int) ($args['limit'] ?? 30000);
    
    if (trim($url) === '') {
        return "错误：缺少 URL";
    }
    
    $r = web_fetch($url, $limit);
    
    if (!$r['ok']) {
        return "抓取失败：" . $r['error'];
    }
    
    $output = "网页：{$url}\n";
    $output .= "标题：{$r['title']}\n";
    $output .= "内容长度：" . mb_strlen($r['text']) . " 字符\n\n";
    $output .= "内容：\n{$r['text']}";
    
    if ($r['truncated']) {
        $output .= "\n\n（内容过长，已截断）";
    }
    
    return $output;
}

/**
 * 执行实时搜索。
 */
function tool_execute_web_search(int $userId, array $args): string
{
    // 检查搜索权限
    $cap = db_one('SELECT cap_web_search FROM users WHERE id = ?', [$userId]);
    if ((int) ($cap['cap_web_search'] ?? 1) !== 1) {
        return "权限错误：你没有搜索权限";
    }
    
    $query = (string) ($args['query'] ?? '');
    
    if (trim($query) === '') {
        return "错误：缺少搜索关键词";
    }
    
    $r = web_search($query);
    
    if (!$r['ok']) {
        return "搜索失败：" . $r['error'];
    }
    
    $output = "搜索：{$query}\n";
    $output .= "结果数：" . count($r['results']) . "\n\n";
    
    foreach ($r['results'] as $i => $item) {
        $output .= ($i + 1) . ". {$item['title']}\n";
        $output .= "   {$item['url']}\n";
        if (!empty($item['snippet'])) {
            $output .= "   {$item['snippet']}\n";
        }
        $output .= "\n";
    }
    
    return $output;
}

/**
 * 执行 PPT 生成。
 */
function tool_execute_ppt(int $userId, array $args): string
{
    // 检查 PPT 生成权限
    $cap = db_one('SELECT cap_ppt_generate FROM users WHERE id = ?', [$userId]);
    if ((int) ($cap['cap_ppt_generate'] ?? 1) !== 1) {
        return "权限错误：你没有 PPT 生成权限";
    }
    
    $content = (string) ($args['content'] ?? '');
    $title = (string) ($args['title'] ?? 'AI生成演示文稿');
    
    if (trim($content) === '') {
        return "错误：缺少 PPT 内容";
    }
    
    $r = ppt_generate($userId, $content, $title);
    
    if (!$r['ok']) {
        return "生成失败：" . $r['error'];
    }
    
    return "PPT 已生成\n文件：{$r['filename']}\n下载链接：{$r['download_url']}";
}
