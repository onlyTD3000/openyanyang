<?php
/**
 * 并发控制监控页面
 * 显示当前并发槽位占用情况和 SK 熔断状态
 */

$adminOn = 'concurrency_monitor';
$pageTitle = '并发控制监控';

require_once __DIR__ . '/_head.php';
require_once __DIR__ . '/../inc/concurrency.php';

// 获取当前并发统计
$rate_stats = concurrency_rate_stats();
$concurrency_stats = concurrency_stats();

// 获取全局配置
$concurrency_limit = (int) setting_get('concurrency_limit', 0);
$concurrency_rpm = (int) setting_get('concurrency_rpm', 0);
$concurrency_mode = setting_get('concurrency_mode', 'sk');

// 获取 SK 熔断状态
$sk_health = db_all('SELECT h.*, c.name as channel_name 
                     FROM channel_sk_health h
                     LEFT JOIN channels c ON c.id = h.channel_id
                     WHERE h.fail_count > 0 OR h.cooled_until > NOW()
                     ORDER BY h.channel_id, h.sk_index');

?>
<style>
    .monitor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
    .card h3 { margin: 0 0 15px 0; font-size: 16px; color: #333; }
    .stat-value { font-size: 32px; font-weight: bold; color: #2196F3; margin: 10px 0; }
    .stat-label { font-size: 14px; color: #666; }
    .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
    .badge-success { background: #4CAF50; color: white; }
    .badge-warning { background: #FF9800; color: white; }
    .badge-danger { background: #F44336; color: white; }
    .badge-info { background: #2196F3; color: white; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #f5f5f5; font-weight: 600; }
    tr:hover { background: #f9f9f9; }
    .empty-state { text-align: center; padding: 40px; color: #999; }
    .refresh-btn { float: right; padding: 6px 12px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .refresh-btn:hover { background: #1976D2; }
</style>

<div class="admin-main">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1><?= h($pageTitle) ?></h1>
        <button class="refresh-btn" onclick="location.reload()">刷新</button>
    </div>

    <!-- 配置概览 -->
    <div class="monitor-grid">
        <div class="card">
            <h3>全局配置</h3>
            <div class="stat-label">并发控制模式</div>
            <div class="stat-value" style="font-size: 24px;">
                <?= $concurrency_mode === 'sk' ? '按 SK 控制' : '按用户控制' ?>
            </div>
            <div class="stat-label">每分钟上限：<?= $concurrency_rpm > 0 ? $concurrency_rpm : '不限制' ?></div>
            <div class="stat-label">并发上限：<?= $concurrency_limit > 0 ? $concurrency_limit : '不限制' ?></div>
        </div>

        <div class="card">
            <h3>当前活动槽位</h3>
            <div class="stat-value"><?= count($concurrency_stats) ?></div>
            <div class="stat-label">个不同的 <?= $concurrency_mode === 'sk' ? 'SK' : '用户' ?></div>
        </div>

        <div class="card">
            <h3>SK 熔断状态</h3>
            <div class="stat-value"><?= count($sk_health) ?></div>
            <div class="stat-label">个 SK 存在故障记录</div>
        </div>
    </div>

    <!-- 并发槽位详情 -->
    <div class="card">
        <h3>活动槽位详情</h3>
        <?php if (empty($concurrency_stats)): ?>
            <div class="empty-state">当前无活动请求</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>槽位键</th>
                        <th>并发数</th>
                        <th>最早请求</th>
                        <th>最新请求</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($concurrency_stats as $stat): ?>
                        <?php 
                        $age = strtotime($stat['oldest']);
                        $now = time();
                        $duration = $now - $age;
                        $status = $duration > 300 ? 'danger' : ($duration > 60 ? 'warning' : 'success');
                        ?>
                        <tr>
                            <td><code><?= h($stat['slot_key']) ?></code></td>
                            <td><strong><?= (int) $stat['count'] ?></strong></td>
                            <td><?= h($stat['oldest']) ?> <small>(<?= $duration ?> 秒前)</small></td>
                            <td><?= h($stat['newest']) ?></td>
                            <td>
                                <span class="badge badge-<?= $status ?>">
                                    <?= $duration > 300 ? '可能异常' : ($duration > 60 ? '进行中' : '正常') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 每分钟限流详情 -->
    <div class="card" style="margin-top: 20px;">
        <h3>每分钟限流统计（最近 1 分钟）</h3>
        <?php if (empty($rate_stats)): ?>
            <div class="empty-state">最近 1 分钟无请求</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>限流键</th>
                        <th>请求数</th>
                        <th>最早请求</th>
                        <th>最新请求</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rate_stats as $stat): ?>
                        <?php 
                        $count = (int) $stat['count'];
                        $status = 'success';
                        if ($concurrency_rpm > 0) {
                            if ($count >= $concurrency_rpm) {
                                $status = 'danger';
                            } elseif ($count >= $concurrency_rpm * 0.8) {
                                $status = 'warning';
                            }
                        }
                        ?>
                        <tr>
                            <td><code><?= h($stat['rate_key']) ?></code></td>
                            <td>
                                <strong><?= $count ?></strong>
                                <?php if ($concurrency_rpm > 0): ?>
                                    / <?= $concurrency_rpm ?> 
                                    (<?= round($count / $concurrency_rpm * 100, 1) ?>%)
                                <?php endif; ?>
                            </td>
                            <td><?= h($stat['oldest']) ?></td>
                            <td><?= h($stat['newest']) ?></td>
                            <td>
                                <span class="badge badge-<?= $status ?>">
                                    <?php if ($concurrency_rpm > 0 && $count >= $concurrency_rpm): ?>
                                        已达上限
                                    <?php elseif ($concurrency_rpm > 0 && $count >= $concurrency_rpm * 0.8): ?>
                                        接近上限
                                    <?php else: ?>
                                        正常
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SK 熔断详情 -->
    <div class="card" style="margin-top: 20px;">
        <h3>SK 熔断详情</h3>
        <?php if (empty($sk_health)): ?>
            <div class="empty-state">所有 SK 运行正常</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>渠道</th>
                        <th>SK 下标</th>
                        <th>失败次数</th>
                        <th>最后错误码</th>
                        <th>最后错误信息</th>
                        <th>熔断状态</th>
                        <th>更新时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sk_health as $h): ?>
                        <?php 
                        $is_cooled = !empty($h['cooled_until']) && strtotime($h['cooled_until']) > time();
                        $cool_remain = $is_cooled ? strtotime($h['cooled_until']) - time() : 0;
                        ?>
                        <tr>
                            <td><?= h($h['channel_name'] ?? "渠道 {$h['channel_id']}") ?></td>
                            <td><strong>SK #<?= (int) $h['sk_index'] + 1 ?></strong></td>
                            <td><span class="badge badge-<?= (int) $h['fail_count'] > 10 ? 'danger' : 'warning' ?>"><?= (int) $h['fail_count'] ?> 次</span></td>
                            <td><?= (int) ($h['last_http'] ?? 0) ?></td>
                            <td><small><?= h(mb_substr($h['last_error'] ?? '', 0, 60)) ?></small></td>
                            <td>
                                <?php if ($is_cooled): ?>
                                    <span class="badge badge-danger">熔断中（剩余 <?= $cool_remain ?> 秒）</span>
                                <?php else: ?>
                                    <span class="badge badge-success">正常</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= h($h['updated_at']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
        <strong>说明：</strong>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>活动槽位：当前正在处理的请求，超过 10 分钟会自动清理</li>
            <li>SK 熔断：失败达到阈值后会暂时停用，冷却结束后自动恢复</li>
            <li>并发控制在 <a href="/admin/settings.php">站点设置 - 缓存与性能</a> 中配置</li>
        </ul>
    </div>
</div>

<script>
    // 自动刷新（每 10 秒）
    setTimeout(() => location.reload(), 10000);
</script>

<?php require __DIR__ . '/_foot.php'; ?>
