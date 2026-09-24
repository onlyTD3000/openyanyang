<?php
/**
 * 后台 - 支付设置。
 *
 * 单独一页而不是塞进站点设置，原因有两个：
 * 一是配置项不少（渠道开关、三种形态、面额、优惠、支付宝五个参数），混在一起太挤；
 * 二是这里有应用私钥，需要单独的加密存储和「不回显明文」处理，逻辑跟普通设置不一样。
 */
$adminOn = 'pay';
$pageTitle = '支付设置';

// POST 要在 _head.php 之前处理完，否则重定向发不出去，刷新会重发表单。
require_once __DIR__ . '/../inc/helpers.php';
$me = require_admin();
require_once __DIR__ . '/../inc/pay.php';

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_page();

    if (($_POST['act'] ?? '') === 'save') {
        // ---- 渠道开关（多选） ----
        $on = array_values(array_intersect(
            (array) ($_POST['channels'] ?? []),
            ['alipay']
        ));
        setting_set('pay_channels_on', implode(',', $on));

        // ---- 面额 ----
        // 存回去时统一成逗号分隔，用户填的换行、中文逗号都在 pay_amount_options() 里兼容了
        $amts = preg_split('/[\s,，、]+/u', (string) ($_POST['pay_amounts'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];
        foreach ($amts as $a) {
            $v = round((float) $a, 2);
            if ($v > 0) { $clean[(string) $v] = $v; }
        }
        $clean = array_values($clean);
        sort($clean, SORT_NUMERIC);
        if (!$clean) {
            $err = '至少要设置一个大于 0 的面额';
        } else {
            setting_set('pay_amounts', implode(',', array_map(function ($v) {
                return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
            }, $clean)));
        }

        // ---- 阶梯优惠 ----
        // 用户填的门槛/比例分隔符五花八门，解析统一交给 pay_discount_tiers() 兼容，
        // 这里先原样存，再读回来规范化成「门槛:比例」一行一档存回去，
        // 这样下次打开页面看到的就是清理过的内容。
        setting_set('pay_discount_tiers', trim((string) ($_POST['pay_discount_tiers'] ?? '')));
        $norm = [];
        foreach (pay_discount_tiers() as $t) {
            $norm[] = rtrim(rtrim(number_format($t['min'], 2, '.', ''), '0'), '.')
                    . ':' . rtrim(rtrim(number_format($t['rate'], 3, '.', ''), '0'), '.');
        }
        setting_set('pay_discount_tiers', implode("\n", $norm));
        // 旧的单档键清零。pay_discount_tiers() 在新键为空时会回落到旧键，
        // 不清的话「清空阶梯想取消优惠」会被旧值悄悄复活。
        setting_set('pay_discount', '0.000');
        setting_set('pay_discount_min', '0.00');

        // ---- 自定义金额 ----
        setting_set('pay_custom_on', isset($_POST['pay_custom_on']) ? '1' : '0');
        setting_set('pay_custom_min',
            number_format(max(0.01, (float) ($_POST['pay_custom_min'] ?? 1)), 2, '.', ''));
        setting_set('pay_custom_max',
            number_format(max(0.01, (float) ($_POST['pay_custom_max'] ?? 10000)), 2, '.', ''));

        // ---- 支付宝参数 ----
        setting_set('site_url', rtrim(trim((string) ($_POST['site_url'] ?? '')), '/'));
        setting_set('alipay_app_id', trim((string) ($_POST['alipay_app_id'] ?? '')));
        setting_set('alipay_gateway', trim((string) ($_POST['alipay_gateway'] ?? '')));
        setting_set('alipay_public_key', trim((string) ($_POST['alipay_public_key'] ?? '')));
        foreach (['page', 'wap', 'qr'] as $s) {
            setting_set('alipay_scene_' . $s, isset($_POST['scene_' . $s]) ? '1' : '0');
        }
        // 私钥：留空表示不修改。加密存储，页面上永不回显明文。
        $pk = trim((string) ($_POST['alipay_private_key'] ?? ''));
        if ($pk !== '') {
            setting_set('alipay_private_key', enc_secret($pk));
        }

        // ---- 余额预警 ----
        setting_set('balance_warn_on', (($_POST['balance_warn_on'] ?? '0') === '1') ? '1' : '0');
        setting_set('balance_warn_threshold',
            number_format(max(0.01, (float) ($_POST['balance_warn_threshold'] ?? 1)), 2, '.', ''));

        if ($err === '') { $msg = '支付设置已保存'; }
        audit_log((int) $me['id'], 'pay_settings', 0, 0, '修改支付设置');
    }

    flash_set($err !== '' ? 'error' : 'ok', $err !== '' ? $err : $msg);
    redirect_self();
}

[$flash类型, $flash文本] = flash_get();
if ($flash类型 === 'error') {
    $err = $flash文本;
} elseif ($flash类型 === 'ok') {
    $msg = $flash文本;
}

require __DIR__ . '/_head.php';

$cur      = settings_all();
$hasPk    = trim((string) ($cur['alipay_private_key'] ?? '')) !== '';
$amounts  = pay_amount_options();
$tiers    = pay_discount_tiers();
// 文本框里显示规范化后的内容。首次从旧单档配置升级上来时，
// 新键还是空的，这里用解析结果反填，管理员打开就能看到已有规则。
$tiersText = implode("\n", array_map(function ($t) {
    return rtrim(rtrim(number_format($t['min'], 2, '.', ''), '0'), '.')
         . ':' . rtrim(rtrim(number_format($t['rate'], 3, '.', ''), '0'), '.');
}, $tiers));
$notifyUrl = site_base_url() . '/pay/alipay_notify.php';
$returnUrl = site_base_url() . '/pay/alipay_return.php';
?>
<div class="page-head"><h1 class="page-title">支付设置</h1></div>

<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<input type="hidden" name="act" value="save">

<div class="card mb-16">
  <div class="card-head">充值渠道</div>
  <div class="card-body">
    <p class="muted mb-8">勾选后该渠道会出现在前台充值页。参数没配齐的渠道会自动隐藏，不会让用户点到报错。</p>
    <label style="display:block;margin-bottom:6px">
      <input type="checkbox" name="channels[]" value="alipay"
        <?= in_array('alipay', explode(',', (string) ($cur['pay_channels_on'] ?? 'alipay')), true) ? 'checked' : '' ?>>
      支付宝官方支付
      <?php if (!alipay_ready()): ?>
        <span class="text-err">（参数未配齐，暂不会显示在前台）</span>
      <?php endif; ?>
    </label>
    <p class="muted">后续接微信、其他渠道时，这里会多出对应选项，前台充值页无需改动。</p>
  </div>
</div>

<div class="card mb-16">
  <div class="card-head">面额与优惠</div>
  <div class="card-body">
    <div class="form-grid">
      <label class="field">
        <span class="field-label">可选面额（元，逗号或空格分隔）</span>
        <input class="input" name="pay_amounts" value="<?= h(implode(', ', array_map(function ($v) {
            return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        }, $amounts))) ?>" placeholder="10, 50, 100, 200">
      </label>
    </div>

    <label class="field mt-8">
      <span class="field-label">阶梯优惠（一行一档，格式「门槛:比例%」，留空表示不打折）</span>
      <textarea class="input" name="pay_discount_tiers" rows="4"
                placeholder="30:1&#10;50:2&#10;100:3"><?= h($tiersText) ?></textarea>
    </label>
    <p class="muted">
      算法是「到账固定、实付打折」：面额 100 + 优惠 2%，用户实付 <b>98</b> 元，余额到账 <b>100</b> 元。
      实付金额向上取整到分，避免舍入产生亏损。
      按<b>满足条件的最高一档</b>计算：填 30:1 / 50:2 / 100:3 时，
      充 30~49 优惠 1%，充 50~99 优惠 2%，充 100 及以上优惠 3%，不满 30 按原价。
      门槛填 0 就是所有金额都享该档。自定义金额同样按这套阶梯算。
      比例上限 90%，填 0 的档会被忽略。
    </p>

    <div class="form-grid mt-8">
      <label class="field">
        <span class="field-label">允许自定义金额</span>
        <span><input type="checkbox" name="pay_custom_on" value="1"
          <?= ($cur['pay_custom_on'] ?? '0') === '1' ? 'checked' : '' ?>> 用户可自己填充值金额</span>
      </label>
      <label class="field">
        <span class="field-label">自定义最低（元）</span>
        <input class="input" type="number" step="0.01" min="0.01" name="pay_custom_min"
               value="<?= h($cur['pay_custom_min'] ?? '1.00') ?>">
      </label>
      <label class="field">
        <span class="field-label">自定义最高（元）</span>
        <input class="input" type="number" step="0.01" min="0.01" name="pay_custom_max"
               value="<?= h($cur['pay_custom_max'] ?? '10000.00') ?>">
      </label>
    </div>

    <?php if ($amounts): ?>
      <div class="mt-8">
        <span class="muted">当前效果预览：</span>
        <?php foreach ($amounts as $a): $c = pay_calc($a); $hit = $c['rate'] > 0; ?>
          <span class="badge <?= $hit ? 'badge-ok' : 'badge-off' ?>" style="margin-right:6px">
            到账 <?= h(rtrim(rtrim(number_format($c['credit'], 2, '.', ''), '0'), '.')) ?>
            / 实付 <?= h(number_format($c['pay'], 2)) ?>
            <?= $hit
                ? '（优惠 ' . h(rtrim(rtrim(number_format($c['rate'], 3, '.', ''), '0'), '.')) . '%）'
                : '（未达门槛，原价）' ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-16">
  <div class="card-head">支付宝官方支付</div>
  <div class="card-body">
    <div class="form-grid">
      <label class="field">
        <span class="field-label">本站对外地址（用于拼回调地址，务必填对）</span>
        <input class="input" name="site_url" value="<?= h($cur['site_url'] ?? '') ?>"
               placeholder="https://your-domain.com">
      </label>
      <label class="field">
        <span class="field-label">APPID</span>
        <input class="input" name="alipay_app_id" value="<?= h($cur['alipay_app_id'] ?? '') ?>"
               placeholder="2021xxxxxxxxxxxx">
      </label>
      <label class="field">
        <span class="field-label">支付宝网关（留空用正式环境）</span>
        <input class="input" name="alipay_gateway" value="<?= h($cur['alipay_gateway'] ?? '') ?>"
               placeholder="https://openapi.alipay.com/gateway.do">
      </label>
    </div>

    <label class="field mt-8">
      <span class="field-label">
        应用私钥（PKCS#1，留空表示不修改）
        <?php if ($hasPk): ?><span class="badge">已配置</span><?php endif; ?>
      </span>
      <textarea class="input" name="alipay_private_key" rows="4"
                placeholder="<?= $hasPk ? '已保存，留空则保持不变' : '粘贴应用私钥，可以是裸 base64 串' ?>"></textarea>
    </label>

    <label class="field mt-8">
      <span class="field-label">支付宝公钥（用于验签）</span>
      <textarea class="input" name="alipay_public_key" rows="4"
                placeholder="粘贴支付宝公钥，可以是裸 base64 串"><?= h($cur['alipay_public_key'] ?? '') ?></textarea>
    </label>

    <div class="mt-8">
      <span class="field-label">启用的支付形态</span>
      <label style="margin-right:14px"><input type="checkbox" name="scene_page" value="1"
        <?= ($cur['alipay_scene_page'] ?? '1') === '1' ? 'checked' : '' ?>> 电脑网站支付</label>
      <label style="margin-right:14px"><input type="checkbox" name="scene_wap" value="1"
        <?= ($cur['alipay_scene_wap'] ?? '1') === '1' ? 'checked' : '' ?>> 手机网站支付</label>
      <label><input type="checkbox" name="scene_qr" value="1"
        <?= ($cur['alipay_scene_qr'] ?? '0') === '1' ? 'checked' : '' ?>> 当面付扫码</label>
      <p class="muted">
        前台会按设备自动选：电脑用「电脑网站支付」，手机用「手机网站支付」。
        每种形态都要在支付宝开放平台单独签约对应产品，没签约的别勾。
      </p>
    </div>

    <div class="mt-8" style="padding:10px;background:#f9fafb;border-radius:6px">
      <div class="muted">请把下面两个地址填到支付宝开放平台的应用配置里：</div>
      <div style="font-family:monospace;font-size:12px;margin-top:4px">
        异步通知：<?= h($notifyUrl) ?><br>
        同步跳转：<?= h($returnUrl) ?>
      </div>
    </div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-head">余额预警</div>
  <div class="card-body">
    <div class="form-grid">
      <label class="field">
        <span class="field-label">余额预警开关</span>
        <select class="input" name="balance_warn_on" id="balanceWarnSwitch">
          <option value="0"<?= ($cur['balance_warn_on'] ?? '0') !== '1' ? ' selected' : '' ?>>关闭</option>
          <option value="1"<?= ($cur['balance_warn_on'] ?? '0') === '1' ? ' selected' : '' ?>>开启</option>
        </select>
      </label>
      <label class="field" id="balanceWarnThresholdField"<?= ($cur['balance_warn_on'] ?? '0') !== '1' ? ' style="display:none"' : '' ?>>
        <span class="field-label">预警金额（元）</span>
        <input class="input" type="number" step="0.01" min="0.01" name="balance_warn_threshold"
               value="<?= h($cur['balance_warn_threshold'] ?? '1.00') ?>">
      </label>
    </div>
    <p class="muted">开启后，用户余额低于预警金额时，顶栏余额变红并弹出充值提醒。关闭后不显示。</p>
  </div>
</div>
<script>
(function(){
  var sw = document.getElementById('balanceWarnSwitch');
  var field = document.getElementById('balanceWarnThresholdField');
  if (!sw || !field) return;
  sw.addEventListener('change', function(){ field.style.display = sw.value === '1' ? '' : 'none'; });
})();
</script>

<button class="btn btn-primary" type="submit">保存支付设置</button>
</form>

<?php require __DIR__ . '/_foot.php';
