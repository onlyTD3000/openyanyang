<?php
/**
 * 后台 - AFF 推介：某个推广人的下级列表片段。
 *
 * 整页渲染（admin/aff.php 里的 #aff-detail 容器）和 partial=detail 的异步请求
 * 都 require 这个文件，保证两条路径产出的 HTML 完全一致。
 * 依赖调用方已准备好的变量：$detailUser、$detailInvitees、$detailStat。
 */
if (empty($detailUser)) { return; }   // 没展开任何人时输出空，等于收起状态
?>
<div class="card mb-16">
  <div class="card-head">
    <?= h($detailUser['username']) ?> 邀请的用户
    <span class="hint" style="margin:0">
      邀请码 <strong><?= h($detailUser['invite_code']) ?></strong>
      · 共 <?= fmt_int($detailStat['invited']) ?> 人
      · 累计返现 ￥<?= money($detailStat['commission_sum']) ?>
      · <a href="/admin/aff.php">收起</a>
    </span>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr><th class="nowrap">ID</th><th>用户</th><th>邮箱</th><th class="nowrap">状态</th><th class="nowrap">充值笔数</th>
            <th class="nowrap">充值总额</th><th class="nowrap">贡献返现</th><th class="nowrap">绑定时间</th></tr>
      </thead>
      <tbody>
      <?php if (!$detailInvitees): ?>
        <tr><td colspan="8" class="empty">该用户还没有邀请到人</td></tr>
      <?php else: foreach ($detailInvitees as $iv): ?>
        <tr>
          <td class="nowrap">#<?= (int) $iv['id'] ?></td>
          <td><a href="/admin/users.php?kw=<?= urlencode($iv['username'] ?? '') ?>"><?= h($iv['username'] ?? '已注销') ?></a></td>
          <td><?= h(($iv['email'] ?? '') !== '' ? $iv['email'] : '—') ?></td>
          <td class="nowrap"><?= (int) $iv['status'] === 1
              ? '<span class="badge badge-blue">正常</span>'
              : '<span class="badge">禁用</span>' ?></td>
          <td><?= fmt_int($iv['recharge_n']) ?></td>
          <td>￥<?= money($iv['recharge_sum']) ?></td>
          <td style="color:var(--ok,#16a34a)">￥<?= money($iv['commission_sum']) ?></td>
          <td class="nowrap"><?= h($iv['referred_at'] ?: $iv['created_at']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
