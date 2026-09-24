<?php
/**
 * 开发文档：API 对接说明，供第三方客户端接入参考。
 */
require_once __DIR__ . '/../inc/helpers.php';

$me = require_login();
$siteName = app_name();
$navOn = 'models';
$showSideToggle = false;

// 取当前站点地址作为 base URL
$baseUrl = rtrim((string) setting_get('site_url', ''), '/');
if ($baseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'code.77bot.cn');
}
$apiBase = $baseUrl . '/v1';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var ls=localStorage.getItem('theme');if(ls==='dark'||(!ls&&<?= (int)($me['dark_mode'] ?? 0) ?>))document.documentElement.classList.add('dark');})();</script>
<title>开发文档 - <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<style>
.docs-page { max-width: 900px; margin: 0 auto; padding: 24px 16px 80px; }
.docs-header { margin-bottom: 28px; }
.docs-header h1 { font-size: 26px; margin: 0 0 6px; }
.docs-header p { color: var(--text2, #888); font-size: 14px; margin: 0; }
.docs-back { margin-bottom: 16px; }
.docs-back a { color: var(--primary, #6366f1); text-decoration: none; font-size: 14px; }
.docs-back a:hover { text-decoration: underline; }
.docs-section { margin-bottom: 36px; }
.docs-section h2 { font-size: 20px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--border, #eee); }
.docs-section h3 { font-size: 16px; margin: 20px 0 8px; }
.docs-section p { line-height: 1.7; color: var(--text, #333); font-size: 14px; }
.docs-section ul, .docs-section ol { line-height: 1.8; color: var(--text, #333); font-size: 14px; padding-left: 20px; }
.docs-section li { margin-bottom: 4px; }
.docs-code {
  background: var(--bg2, #f5f5f5); border: 1px solid var(--border, #e0e0e0);
  border-radius: 8px; padding: 14px 16px; overflow-x: auto; margin: 10px 0;
  font-size: 13px; line-height: 1.6;
}
.docs-code code {
  font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
  color: var(--text, #333); white-space: pre;
}
.docs-table { width: 100%; border-collapse: collapse; font-size: 14px; margin: 10px 0; }
.docs-table th { background: var(--bg2, #f5f5f5); padding: 10px 14px; text-align: left; border: 1px solid var(--border, #ddd); font-weight: 600; color: var(--text2, #666); }
.docs-table td { padding: 10px 14px; border: 1px solid var(--border, #ddd); color: var(--text, #333); }
.docs-table code { font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace; font-size: 13px; }
.docs-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-right: 4px; }
.docs-badge.get { background: #22c55e20; color: #22c55e; }
.docs-badge.post { background: #6366f120; color: #6366f1; }
.docs-note { background: rgba(99,102,241,.08); border-left: 3px solid var(--primary, #6366f1); padding: 10px 14px; border-radius: 0 8px 8px 0; margin: 10px 0; font-size: 14px; line-height: 1.6; color: var(--text, #333); }
.dark .docs-code { background: var(--bg2, #1a1a2e); border-color: var(--border, #333); }
.dark .docs-code code { color: var(--text, #e0e0e0); }
.dark .docs-table th { background: var(--bg2, #1e1e32); border-color: var(--border, #333); color: var(--text2, #999); }
.dark .docs-table td { border-color: var(--border, #333); color: var(--text, #e0e0e0); }
.dark .docs-section p, .dark .docs-section ul, .dark .docs-section ol, .dark .docs-section li { color: var(--text, #ccc); }
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/topbar.php'; ?>

<div class="docs-page">
  <div class="docs-back"><a href="/models.php">← 返回模型广场</a></div>
  <div class="docs-header">
    <h1>API 开发文档</h1>
    <p>岩羊AI 开放 API 网关，兼容 OpenAI 与 Anthropic 协议，供第三方客户端对接。</p>
  </div>

  <div class="docs-section">
    <h2>基本信息</h2>
    <table class="docs-table">
      <tr><th style="width:160px">API 地址</th><td><code><?= h($apiBase) ?></code></td></tr>
      <tr><th>认证方式</th><td>Bearer Token（API Key）</td></tr>
      <tr><th>获取 Key</th><td>登录后前往 <a href="/profile.php">个人资料</a> 页面生成 API Key</td></tr>
      <tr><th>请求头</th><td><code>Authorization: Bearer &lt;your_api_key&gt;</code></td></tr>
      <tr><th>Content-Type</th><td><code>application/json</code></td></tr>
    </table>
    <div class="docs-note">
      模型名称请到 <a href="/models.php">模型广场</a> 查看，点击「复制」按钮一键获取。
    </div>
  </div>

  <div class="docs-section">
    <h2>接口列表</h2>
    <table class="docs-table">
      <thead>
        <tr><th>方法</th><th>路径</th><th>说明</th></tr>
      </thead>
      <tbody>
        <tr><td><span class="docs-badge post">POST</span></td><td><code>/v1/chat/completions</code></td><td>OpenAI 兼容对话补全（流式 / 非流式）</td></tr>
        <tr><td><span class="docs-badge post">POST</span></td><td><code>/v1/messages</code></td><td>Anthropic 兼容对话补全（流式 / 非流式）</td></tr>
        <tr><td><span class="docs-badge post">POST</span></td><td><code>/v1/responses</code></td><td>OpenAI Responses API 兼容</td></tr>
        <tr><td><span class="docs-badge get">GET</span></td><td><code>/v1/models</code></td><td>获取可用模型列表</td></tr>
        <tr><td><span class="docs-badge get">GET</span></td><td><code>/v1/me</code></td><td>校验 Key 并返回用户信息</td></tr>
      </tbody>
    </table>
  </div>

  <div class="docs-section">
    <h2>对接第三方客户端</h2>
    <h3>OpenAI 兼容客户端（Trae / Codex / Cursor / ChatBox 等）</h3>
    <p>在客户端的 API 设置中填入：</p>
    <table class="docs-table">
      <tr><th style="width:160px">Base URL</th><td><code><?= h($apiBase) ?></code></td></tr>
      <tr><th>API Key</th><td>在个人资料页生成的密钥</td></tr>
      <tr><th>模型名称</th><td>从模型广场复制，如 <code>deepseek-chat</code></td></tr>
    </table>

    <h3>Anthropic 兼容客户端（Claude Code 等）</h3>
    <p>在客户端的 API 设置中填入：</p>
    <table class="docs-table">
      <tr><th style="width:160px">Base URL</th><td><code><?= h($apiBase) ?></code></td></tr>
      <tr><th>API Key</th><td>在个人资料页生成的密钥</td></tr>
      <tr><th>请求头</th><td><code>x-api-key: &lt;your_api_key&gt;</code><br><code>anthropic-version: 2023-06-01</code></td></tr>
    </table>
    <div class="docs-note">
      Anthropic 端点 <code>/v1/messages</code> 会自动识别请求格式：如果请求体包含 Anthropic 特有字段（system、stop_sequences、content 块数组等），按 Anthropic 协议处理；否则自动转为 OpenAI 格式处理。
    </div>
  </div>

  <div class="docs-section">
    <h2>cURL 示例</h2>

    <h3>OpenAI 格式 — 非流式</h3>
    <div class="docs-code"><code>curl <?= h($apiBase) ?>/chat/completions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "deepseek-chat",
    "messages": [
      {"role": "user", "content": "你好"}
    ]
  }'</code></div>

    <h3>OpenAI 格式 — 流式</h3>
    <div class="docs-code"><code>curl <?= h($apiBase) ?>/chat/completions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "deepseek-chat",
    "messages": [
      {"role": "user", "content": "你好"}
    ],
    "stream": true
  }'</code></div>

    <h3>Anthropic 格式</h3>
    <div class="docs-code"><code>curl <?= h($apiBase) ?>/messages \
  -H "x-api-key: YOUR_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet-4-20250514",
    "max_tokens": 1024,
    "messages": [
      {"role": "user", "content": "你好"}
    ]
  }'</code></div>

    <h3>获取模型列表</h3>
    <div class="docs-code"><code>curl <?= h($apiBase) ?>/models \
  -H "Authorization: Bearer YOUR_API_KEY"</code></div>

    <h3>校验 API Key</h3>
    <div class="docs-code"><code>curl <?= h($apiBase) ?>/me \
  -H "Authorization: Bearer YOUR_API_KEY"</code></div>
  </div>

  <div class="docs-section">
    <h2>Python 示例</h2>
    <h3>使用 openai 库</h3>
    <div class="docs-code"><code>from openai import OpenAI

client = OpenAI(
    api_key="YOUR_API_KEY",
    base_url="<?= h($apiBase) ?>"
)

response = client.chat.completions.create(
    model="deepseek-chat",
    messages=[
        {"role": "user", "content": "你好"}
    ]
)

print(response.choices[0].message.content)</code></div>

    <h3>使用 anthropic 库</h3>
    <div class="docs-code"><code>import anthropic

client = anthropic.Anthropic(
    api_key="YOUR_API_KEY",
    base_url="<?= h($apiBase) ?>"
)

message = client.messages.create(
    model="claude-sonnet-4-20250514",
    max_tokens=1024,
    messages=[
        {"role": "user", "content": "你好"}
    ]
)

print(message.content[0].text)</code></div>
  </div>

  <div class="docs-section">
    <h2>JavaScript 示例</h2>
    <div class="docs-code"><code>// OpenAI 格式
const res = await fetch("<?= h($apiBase) ?>/chat/completions", {
  method: "POST",
  headers: {
    "Authorization": "Bearer YOUR_API_KEY",
    "Content-Type": "application/json"
  },
  body: JSON.stringify({
    model: "deepseek-chat",
    messages: [{ role: "user", content: "你好" }]
  })
});

const data = await res.json();
console.log(data.choices[0].message.content);</code></div>
  </div>

  <div class="docs-section">
    <h2>计费说明</h2>
    <ul>
      <li>价格单位：<b>CNY / 1M tokens</b>（1M = 百万 token）</li>
      <li>输入（Input）：请求中所有 token 的单价</li>
      <li>输出（Output）：模型生成 token 的单价</li>
      <li>缓存读取（Cache Read）：命中上游缓存的输入 token，价格更低</li>
      <li>缓存写入（Cache Write）：创建缓存内容的 token，首次请求时产生</li>
      <li>最终费用 = 输入量 × 输入价 + 缓存读取量 × 缓存读取价 + 缓存写入量 × 缓存写入价 + 输出量 × 输出价</li>
      <li>计费按上游返回的实际 usage 统计，从账户余额中扣除</li>
    </ul>
  </div>

  <div class="docs-section">
    <h2>思考模型</h2>
    <p>支持 DeepSeek R1 等思考型模型的推理输出。客户端可通过以下参数控制：</p>
    <table class="docs-table">
      <tr><th style="width:200px">OpenAI 格式</th><td><code>reasoning_effort: "low" | "medium" | "high"</code></td></tr>
      <tr><th>Anthropic 格式</th><td><code>thinking: {"type": "enabled", "budget_tokens": 10000}</code></td></tr>
    </table>
    <div class="docs-note">
      网关会自动将 Anthropic 的 <code>thinking</code> 参数转换为 OpenAI 的 <code>reasoning_effort</code>，按 budget_tokens 映射：≥10000 → high，≥3000 → medium，>0 → low。
    </div>
  </div>

  <div class="docs-section">
    <h2>常见问题</h2>
    <h3>支持哪些客户端？</h3>
    <p>所有兼容 OpenAI 或 Anthropic API 的客户端均可对接，包括但不限于：Trae、Cursor、Codex、ChatBox、NextChat、Claude Code、Cherry Studio 等。</p>

    <h3>API Key 在哪里获取？</h3>
    <p>登录后前往 <a href="/profile.php">个人资料</a> 页面，在 API Key 区域生成。</p>

    <h3>流式输出是什么格式？</h3>
    <p>OpenAI 端点返回 <code>text/event-stream</code>，格式与 OpenAI 官方一致（<code>data: {...}\n\n</code>，以 <code>data: [DONE]</code> 结尾）。Anthropic 端点返回 Anthropic 官方 SSE 格式（<code>event: message_start</code> 等）。</p>

    <h3>思考模型的推理内容如何返回？</h3>
    <p>OpenAI 格式通过 <code>delta.reasoning_content</code> 返回；Anthropic 格式通过 <code>thinking</code> 类型的 content block 返回，与正文 <code>text</code> 块分开。</p>

    <h3>余额不足怎么办？</h3>
    <p>API 会返回 <code>402</code> 状态码，提示余额不足。请前往 <a href="/recharge.php">充值页面</a> 充值。</p>
  </div>
</div>
</body>
</html>
