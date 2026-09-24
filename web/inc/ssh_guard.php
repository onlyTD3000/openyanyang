<?php
require_once __DIR__ . '/ip_rules.php';

/**
 * 目标地址校验。
 *
 * 命令层面的安全策略已按客户要求全部取消：客户自己的服务器由客户自己负责，
 * 想执行什么就执行什么，平台不做任何命令拦截、不分风险等级、不需要人工确认。
 * ssh_classify() 保留下来只为兼容调用方的返回结构，恒定返回 safe。
 *
 * 这里唯一保留的是目标地址校验（ssh_host_deny_reason）。它拦的不是客户服务器上的
 * 操作，而是「目标主机填成内网地址」这种情况——放开会让任何注册用户借这台机器扫内网、
 * 读云厂商元数据接口拿到本平台服务器的临时凭据。它保护的是本平台自己，与客户
 * 在自己机器上的操作自由无关，因此不在放开范围内。
 */

/**
 * 判定命令风险等级。策略已取消，恒为 safe。
 *
 * @return array{risk:string, reason:string}
 */
function ssh_classify(string $cmd): array
{
    return ['risk' => 'safe', 'reason' => ''];
}

/**
 * 校验目标主机是否允许连接。
 *
 * 两层判定：
 *   1. 内网、回环、保留地址硬禁止，任何名单都解除不了。
 *   2. 后台可管理的黑白名单，白名单只用于解除黑名单，管不到第 1 层。
 *
 * @return string 空串表示允许，否则为拒绝原因
 */
function ssh_host_deny_reason(string $host): string
{
    $h = strtolower(trim($host));
    if ($h === '') {
        return '主机地址为空';
    }
    if (!preg_match('/^[a-z0-9\.\-\:]+$/', $h)) {
        return '主机地址含非法字符';
    }
    // 解析成 IP 再判断，避免用域名指向内网绕过
    $ips = [];
    if (filter_var($h, FILTER_VALIDATE_IP)) {
        $ips[] = $h;
    } else {
        $rec = @dns_get_record($h, DNS_A + DNS_AAAA);
        foreach ((array) $rec as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (!$ips) {
            $r = gethostbyname($h);
            if ($r !== $h) {
                $ips[] = $r;
            }
        }
        if (!$ips) {
            return '域名无法解析';
        }
    }
    foreach ($ips as $ip) {
        // 内网与保留地址硬禁止，白名单也解除不了——这是防止平台被当跳板打内网的底线。
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '不允许连接内网或保留地址（' . $ip . '）';
        }
    }

    // 平台自身 IP 不再自动禁止（按客户要求改为后台管理）。
    // 需要拦本机就在后台「服务器名单」里加一条黑名单。
    // 名单判定顺序：白名单命中即豁免，其次黑名单拦截。
    if (function_exists('ip_rules_deny_reason')) {
        $reason = ip_rules_deny_reason($h, $ips);
        if ($reason !== '') {
            return $reason;
        }
    }

    return '';
}
