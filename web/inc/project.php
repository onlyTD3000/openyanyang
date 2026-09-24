<?php
/**
 * 项目相关的公共函数。
 *
 * 项目是一个独立的开发工作空间：底下可以挂多条对话，
 * 项目档案（绑定服务器、部署目录、技术栈、说明）会注入该项目每条对话的系统提示，
 * 这样 AI 无需用户重复交代就知道往哪台机器、哪个目录干活。
 */

/** 项目名允许的最大长度 */
const 项目名上限 = 40;

/**
 * 取用户自己的项目。取不到说明不存在或不归他，一律当不存在处理。
 */
function project_of(int $projectId, int $userId): ?array
{
    if ($projectId <= 0) {
        return null;
    }
    $row = db_one('SELECT * FROM projects WHERE id = ? AND user_id = ? LIMIT 1',
        [$projectId, $userId]);
    return $row ?: null;
}

/**
 * 项目列表。默认不含归档项目，置顶的排前面。
 */
function project_list(int $userId, bool $含归档 = false): array
{
    $条件 = $含归档 ? '' : ' AND p.archived = 0';
    return db_all(
        'SELECT p.id, p.name, p.intro, p.stack, p.host_id, p.deploy_dir, p.site_url,
                p.color, p.pinned, p.archived, p.conv_count, p.created_at, p.updated_at,
                h.name AS host_name, h.host AS host_addr, h.username AS host_user
           FROM projects p
           LEFT JOIN ssh_hosts h ON h.id = p.host_id AND h.user_id = p.user_id
          WHERE p.user_id = ? AND p.hidden = 0' . $条件 . '
          ORDER BY p.pinned DESC, p.updated_at DESC
          LIMIT 200',
        [$userId]);
}

/**
 * 校验项目表单。返回错误提示，空串表示通过。
 */
function project_validate(array $入): string
{
    $名 = trim((string) ($入['name'] ?? ''));
    if ($名 === '') {
        return '项目名称不能为空';
    }
    if (mb_strlen($名) > 项目名上限) {
        return '项目名称请控制在 ' . 项目名上限 . ' 字以内';
    }
    if (mb_strlen((string) ($入['intro'] ?? '')) > 500) {
        return '项目说明请控制在 500 字以内';
    }
    if (mb_strlen((string) ($入['stack'] ?? '')) > 100) {
        return '技术栈请控制在 100 字以内';
    }
    $目录 = trim((string) ($入['deploy_dir'] ?? ''));
    if ($目录 !== '') {
        if (mb_strlen($目录) > 200) {
            return '部署目录太长';
        }
        // 只做基本形态校验，真正的越界拦截在命令执行层
        if ($目录[0] !== '/') {
            return '部署目录请填绝对路径，例如 /www/wwwroot/我的站点';
        }
        if (strpos($目录, '..') !== false) {
            return '部署目录里不要出现 ..';
        }
    }
    $网址 = trim((string) ($入['site_url'] ?? ''));
    if ($网址 !== '' && !preg_match('~^https?://~i', $网址)) {
        return '站点地址要以 http:// 或 https:// 开头';
    }
    return '';
}

/**
 * 刷新项目的对话计数与更新时间。
 */
function project_touch(int $projectId, int $userId): void
{
    db_exec('UPDATE projects
                SET conv_count = (SELECT COUNT(*) FROM conversations WHERE project_id = ?),
                    updated_at = NOW()
              WHERE id = ? AND user_id = ?',
        [$projectId, $projectId, $userId]);
}

/**
 * 生成项目档案的系统提示，注入该项目下每条对话。
 * 没有任何档案信息时返回空串，不白占上下文。
 */
function project_system_prompt(?array $项目): string
{
    if (!$项目) {
        return '';
    }
    $行 = [];
    $行[] = '当前项目：' . $项目['name'];
    if (trim((string) $项目['intro']) !== '') {
        $行[] = '项目说明：' . trim((string) $项目['intro']);
    }
    if (trim((string) $项目['stack']) !== '') {
        $行[] = '技术栈：' . trim((string) $项目['stack']);
    }
    if ((int) $项目['host_id'] > 0) {
        $主机 = db_one('SELECT name, host, username FROM ssh_hosts WHERE id = ? AND user_id = ?',
            [(int) $项目['host_id'], (int) $项目['user_id']]);
        if ($主机) {
            $行[] = '部署服务器：编号 ' . (int) $项目['host_id'] . ' —— ' . $主机['name']
                . '（' . $主机['host'] . '，登录用户 ' . $主机['username'] . '）';
        }
    }
    if (trim((string) $项目['deploy_dir']) !== '') {
        $行[] = '部署目录：' . trim((string) $项目['deploy_dir']);
    }
    if (trim((string) $项目['site_url']) !== '') {
        $行[] = '站点地址：' . trim((string) $项目['site_url']);
    }
    if (count($行) <= 1 && trim((string) $项目['intro']) === '') {
        // 只有名字，给个简短交代就够
        return "## 当前项目\n\n用户正在开发「" . $项目['name'] . "」这个项目。\n";
    }
    $文 = "## 当前项目档案\n\n" . implode("\n", array_map(fn($l) => '- ' . $l, $行)) . "\n\n";

    // 尾巴按实际填了什么来说，别无端提到不存在的服务器或目录
    $有机 = (int) $项目['host_id'] > 0;
    $有目 = trim((string) $项目['deploy_dir']) !== '';
    if ($有机 && $有目) {
        $文 .= "涉及这个项目的部署、改代码、查日志时，默认就用上面这台服务器和这个目录，"
             . "不用再反问用户。路径要写在部署目录内，不要跑到目录外面去。\n";
    } elseif ($有机) {
        $文 .= "涉及这个项目的服务器操作，默认就用上面这台机器，不用再问用户选哪台。\n";
    } elseif ($有目) {
        $文 .= "这个项目的文件都放在上面这个目录里，路径不要跑到目录外面去。\n";
    } else {
        // 这里必须明确指路，否则 AI 会一直去试 file-write（那条路需要绑机），
        // 失败后就只剩反复道歉解释，客户始终拿不到东西。
        $文 .= "用户还没给这个项目绑定服务器和部署目录，所以**项目代码仓的写入（file-write）用不了**，"
             . "不要去试，会失败。\n\n"
             . "这种情况下要产出代码文件，一律写进**工作中心**（ws-write），"
             . "那里不需要服务器，写完客户就能在工作中心页面看到和下载。"
             . "客户要压缩包时，先用 ws-write 逐个写好文件、等系统回执，再用 ws-zip 打包。\n\n"
             . "只有客户明确要「部署上线」时，才提醒他去项目设置里绑定服务器。"
             . "客户只是要源码或压缩包，不需要绑服务器，别拿这个当借口拖着不干活。\n";
    }
    return $文;
}
