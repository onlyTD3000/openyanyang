/**
 * 卡片解析测试。
 *
 * 测的是各 xxxCard(raw) 把代码块内容解析成卡片 HTML 这一步。
 * 这层出错的后果最直接：host 认错会连到别的服务器，
 * 命令转义错会让 &quot; 之类混进真实命令行。
 *
 * 跑法：node --test tests/
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const { 装载 } = require('./harness.js');
/* ============ ssh-exec ============ */
test('ssh：host 和 cmd 都能解析出来', () => {
  const w = 装载('ssh_card.js');
  const h = w.sshCard('host: 8\ncmd: ls -la /tmp');
  assert.match(h, /data-host="8"/, 'host 应写进 data-host');
  assert.match(h, /ls -la \/tmp/, '命令原文应出现在卡片里');
});
test('ssh：命令里的引号保持原样，不能变成 HTML 实体', () => {
  const w = 装载('ssh_card.js');
  const h = w.sshCard('host: 8\ncmd: grep "hello world" a.txt');
  // 卡片是 HTML，双引号在正文里会被转义成 &quot; 属于正常渲染，
  // 但绝不能出现 &amp;quot; —— 那是被转义了两次，执行时会当成命令的一部分
  assert.ok(!/&amp;quot;/.test(h), '出现双重转义，命令会被改坏');
  assert.match(h, /hello world/, '命令内容应完整保留');
});
test('ssh：cmd 后面的续行也算命令内容', () => {
  const w = 装载('ssh_card.js');
  const h = w.sshCard('host: 8\ncmd: cd /tmp\nls -la\necho 完');
  assert.match(h, /ls -la/, '第二行应并入命令');
  assert.match(h, /echo/, '第三行应并入命令');
});
test('ssh：host 缺失时不该编造一个出来', () => {
  const w = 装载('ssh_card.js');
  const h = w.sshCard('cmd: echo hi');
  assert.ok(!/data-host="[1-9]/.test(h), '没写 host 就不该有具体编号');
});
test('ssh：脚本对外的三个接口都在', () => {
  const w = 装载('ssh_card.js');
  for (const 名 of ['sshCard', 'sshAutoRun', 'sshResetAuto']) {
    assert.strictEqual(typeof w[名], 'function', 名 + ' 应该是函数');
  }
});
/* ============ file-*（客户代码仓）============ */
test('repo：路径能解析出来', () => {
  const w = 装载('repo_card.js');
  const h = w.repoCard('read', 'path: api/chat.php');
  assert.match(h, /api\/chat\.php/, '路径应显示在卡片上');
  assert.match(h, /data-act="read"/, '动作应写进 data-act');
});
test('repo：dirty 的几种写法都要认', () => {
  const w = 装载('repo_card.js');
  for (const 写法 of ['dirty: 1', 'dirty: true', 'dirty: 是']) {
    const h = w.repoCard('list', 写法);
    assert.match(h, /未回传/, 写法 + ' 应被识别为只列改动');
  }
});
test('repo：五种动作各有自己的标题', () => {
  const w = 装载('repo_card.js');
  // write / patch 必须带正文才会渲染成卡片，所以按动作给足输入
  const 输入 = {
    list: 'dirty: 1',
    read: 'path: x.txt',
    write: 'path: x.txt\nnote: 测试\n---\n新内容',
    patch: 'path: x.txt\nnote: 测试\n<<<<<<< 原文\n旧\n=======\n新\n>>>>>>>',
    push: 'note: 测试'
  };
  const 标题 = Object.keys(输入)
    .map((a) => (w.repoCard(a, 输入[a]).match(/repo-title">([^<]*)/) || [])[1]);
  assert.ok(标题.every(Boolean), '每个动作都应渲染出标题，实际：' + JSON.stringify(标题));
  assert.strictEqual(new Set(标题).size, 标题.length, '各动作的标题不应重复');
});
test('repo：write 块缺正文时不渲染成可执行卡片', () => {
  const w = 装载('repo_card.js');
  // 这是一道安全线：没有 --- 和正文的 write 块若被执行，
  // 会把客户的文件覆盖成空。此时应退化成普通代码块，不给执行入口。
  const h = w.repoCard('write', 'path: x.txt');
  assert.ok(!/repo-card/.test(h), '不完整的 write 块不该渲染出卡片');
  assert.ok(!/data-act="write"/.test(h), '不该带上可执行的动作标记');
});
test('repo：patch 块缺分隔标记时同样不渲染卡片', () => {
  const w = 装载('repo_card.js');
  const h = w.repoCard('patch', 'path: x.txt\nnote: 只有说明没有补丁体');
  assert.ok(!/data-act="patch"/.test(h), '不完整的 patch 块不该给执行入口');
});
/* ============ ws-*（工作中心）============ */
test('ws：文件名能解析出来，中文名不乱码', () => {
  const w = 装载('ws_card.js');
  const h = w.wsCard('read', 'name: 报价单.md');
  assert.match(h, /报价单\.md/, '中文文件名应原样显示');
  assert.match(h, /data-ws-act="read"/, '动作应写进 data-ws-act');
});
test('ws：带目录的路径不被截断', () => {
  const w = 装载('ws_card.js');
  const h = w.wsCard('read', 'name: docs/api/说明.md');
  assert.match(h, /docs\/api\/说明\.md/, '多层目录应完整保留');
});
