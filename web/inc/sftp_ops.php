<?php
/**
 * SFTP 操作的 Function Calling 封装。
 * 把底层的 直改* 函数包装成统一返回字符串的格式，供 tool_execute.php 调用。
 */

require_once __DIR__ . '/sftp_edit.php';

/**
 * 读取 SFTP 文件。
 */
function sftp_read_file(int $userId, int $hostId, string $deployDir, string $path): string
{
    $项目 = ['host_id' => $hostId, 'deploy_dir' => $deployDir];
    $r = 直改读文件($项目, $userId, $path);
    
    if (!$r['ok']) {
        return "读取失败：" . $r['error'];
    }
    
    $截断 = '';
    if (strlen($r['text']) > 30000) {
        $r['text'] = mb_substr($r['text'], 0, 30000);
        $截断 = "\n\n（文件过长，已截断到前 30000 字符）";
    }
    
    return "文件路径：{$r['path']}\n文件大小：" . size_text($r['size']) 
         . "\n行数：" . (substr_count($r['text'], "\n") + 1)
         . "\n\n文件内容：\n{$r['text']}{$截断}";
}

/**
 * 写入 SFTP 文件。
 */
function sftp_write_file(int $userId, int $hostId, string $deployDir, string $path, 
                         string $content, string $note): string
{
    $项目 = ['id' => 0, 'host_id' => $hostId, 'deploy_dir' => $deployDir];
    $r = 直改写文件($项目, $userId, $path, $content, 'write', 0, $note);
    
    if (!$r['ok']) {
        return "写入失败：" . $r['error'];
    }
    
    $msg = "已通过 SFTP 写入：{$r['path']}\n"
         . "改动：+{$r['add']} -{$r['del']} 行\n";
    
    if (empty($r['new'])) {
        $msg .= "原文件已备份到：{$r['backup']}\n";
    } else {
        $msg .= "这是新建文件\n";
    }
    
    $msg .= "改动已生效";
    
    return $msg;
}

/**
 * SFTP 局部补丁：读取远端文件，在内存中应用补丁，再写回。
 * 补丁逻辑复用 仓应用补丁（精确匹配 → 统一换行 → 按行模糊匹配）。
 */
function sftp_patch_file(int $userId, int $hostId, string $deployDir, string $path,
                         string $original, string $replacement, string $note): string
{
    // 先读取远端文件
    $项目 = ['host_id' => $hostId, 'deploy_dir' => $deployDir];
    $r = 直改读文件($项目, $userId, $path);
    
    if (!$r['ok']) {
        return "读取失败：" . $r['error'];
    }
    
    $原文 = $r['text'];
    
    // 应用补丁
    $p = 仓应用补丁($原文, $original, $replacement);
    if (!$p['ok']) {
        $档名 = ['1' => '精确匹配', '2' => '忽略行尾空白', '3' => '模糊匹配'];
        $档 = $档名[(string)$p['档']] ?? '匹配';
        return "补丁失败（{$档}）：{$p['error']}";
    }
    
    // 写回远端
    $项目2 = ['id' => 0, 'host_id' => $hostId, 'deploy_dir' => $deployDir];
    $w = 直改写文件($项目2, $userId, $path, $p['text'], 'patch', 0, $note);
    
    if (!$w['ok']) {
        return "补丁后写入失败：" . $w['error'];
    }
    
    $msg = "已通过 SFTP 补丁修改：{$w['path']}\n"
         . "改动：+{$w['add']} -{$w['del']} 行\n"
         . "匹配档位：{$p['档']}\n";
    
    if (empty($w['new'])) {
        $msg .= "原文件已备份到：{$w['backup']}\n";
    }
    
    $msg .= "改动已生效";
    
    return $msg;
}

/**
 * 列出 SFTP 目录。
 */
function sftp_list_dir(int $userId, int $hostId, string $deployDir, string $dir): string
{
    $项目 = ['host_id' => $hostId, 'deploy_dir' => $deployDir];
    $r = 直改列目录($项目, $userId, $dir);
    
    if (!$r['ok']) {
        return "列目录失败：" . $r['error'];
    }
    
    if (empty($r['list'])) {
        return "目录 {$r['dir']} 为空";
    }
    
    $out = "目录：{$r['dir']}\n共 " . count($r['list']) . " 项";
    
    if ($r['truncated']) {
        $out .= "（已截断，只显示前 " . 直改列表上限 . " 项）";
    }
    
    $out .= "\n\n";
    
    foreach ($r['list'] as $item) {
        $type = $item['is_dir'] ? '[目录]' : '[文件]';
        $size = $item['is_dir'] ? '' : ' (' . size_text($item['size']) . ')';
        $out .= "{$type} {$item['name']}{$size}\n";
    }
    
    return $out;
}

/**
 * 删除 SFTP 文件。
 */
function sftp_delete_file(int $userId, int $hostId, string $deployDir, string $path, 
                          string $note): string
{
    $项目 = ['id' => 0, 'host_id' => $hostId, 'deploy_dir' => $deployDir];
    $r = 直改删文件($项目, $userId, $path, 0, $note);
    
    if (!$r['ok']) {
        return "删除失败：" . $r['error'];
    }
    
    return "已删除：{$r['path']}\n"
         . "原文件已备份到：{$r['backup']}\n"
         . "如需还原可使用编辑记录功能";
}
