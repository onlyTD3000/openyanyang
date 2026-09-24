/**
 * 卡片对外契约测试。
 *
 * 测两件事：
 *   1. 五套卡片脚本对外暴露的接口是否齐（chat.js 按名字调，少一个就静默失效）
 *   2. 输入不完整时是否安全退化，不给出可执行入口
 *
 * 跑法：node --test tests/card_contract.test.js
 */
'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const { 装载 } = require('./harness.js');
/* chat.js 里按名字调这些函数，缺哪个都是静默失效，所以逐个查 */
const 契约 = [
  ['ssh_card.js',  'sshCard',  'sshAutoRun',  'sshResetAuto'],
  ['sftp_card.js', 'sftpCard', 'sftpAutoRun', 'sftpResetAuto'],
  ['repo_card.js', 'repoCard', 'repoAutoRun', 'repoResetAuto'],
  ['ws_card.js',   'wsCard',   'wsAutoRun',   'wsResetAuto'],
  ['ppt_card.js',  'pptCard',  'pptAutoRun',  'pptResetAuto']
];
契约.forEach(function (行) {
  var 文件 = 行[0];
  var 接口 = 行.slice(1);
  test('契约：' + 文件 + ' 的三个接口齐备', () => {
    const w = 装载(文件);
    接口.forEach(function (名) {
      assert.strictEqual(typeof w[名], 'function',
        文件 + ' 少了 ' + 名 + '，chat.js 会静默失效');
    });
  });
});
test('契约：五套卡片的 ResetAuto 命名规则一致', () => {
  // 用户手动发消息时 chat.js 会逐个调 xxxResetAuto 归零。
  // 命名不统一的话新增卡片时很容易漏接，这里锁住规则。
  契约.forEach(function (行) {
    const w = 装载(行[0]);
    const 前缀 = 行[1].replace(/Card$/, '');
    assert.strictEqual(typeof w[前缀 + 'ResetAuto'], 'function',
      行[0] + ' 应有 ' + 前缀 + 'ResetAuto');
  });
});
/* ============ sftp ============ */
test('sftp：三种动作各有自己的标题', () => {
  const w = 装载('sftp_card.js');
  const 输入 = { list: 'dir: /tmp', read: 'path: /tmp/a.txt', delete: 'path: /tmp/a.txt' };
  const 标题 = Object.keys(输入)
    .map((a) => (w.sftpCard(a, 输入[a]).match(/repo-title">([^<]*)/) || [])[1]);
  assert.ok(标题.every(Boolean), '每个动作都应有标题，实际：' + JSON.stringify(标题));
  assert.strictEqual(new Set(标题).size, 标题.length, '标题不应重复');
});
test('sftp：动作写进 data-sftp-act，不会串到别的卡片', () => {
  const w = 装载('sftp_card.js');
  const h = w.sftpCard('delete', 'path: /tmp/a.txt');
  assert.match(h, /data-sftp-act="delete"/, '动作应写进专属属性');
  // sftp 和 repo 共用 repo-card 样式，但必须靠 sftp-card-op 区分，
  // 否则 repoAutoRun 会把 sftp 卡片一起执行
  assert.match(h, /sftp-card-op/, '缺了这个类名会被 repoAutoRun 误执行');
  assert.ok(!/data-act="/.test(h), '不该带 repo 的动作属性');
});
test('sftp：同一页里多张卡片的 id 不重复', () => {
  const w = 装载('sftp_card.js');
  const ids = ['list', 'read', 'delete']
    .map((a) => (w.sftpCard(a, 'path: /tmp/a.txt').match(/id="([^"]*)"/) || [])[1]);
  assert.strictEqual(new Set(ids).size, ids.length, 'id 重复会导致操作打到错误的卡片上');
});
/* ============ ppt ============ */
test('ppt：合法大纲能算出页数', () => {
  const w = 装载('ppt_card.js');
  const h = w.pptCard('{"title":"季度汇报","slides":[{"title":"页一"},{"title":"页二"}]}');
  assert.match(h, /季度汇报/, '标题应显示');
  assert.match(h, /2 页内容/, '页数应算对');
  assert.match(h, /ppt-card-op/, '应带专属类名');
});
test('ppt：JSON 坏掉时不渲染成可执行卡片', () => {
  const w = 装载('ppt_card.js');
  // 流式输出过程中会出现半截 JSON，这时不能给执行入口，
  // 否则会拿不完整的大纲去生成文件
  const h = w.pptCard('{"title":"缺括号",');
  assert.ok(!/ppt-card-op/.test(h), '坏 JSON 不该渲染出可执行卡片');
});
test('ppt：没有 slides 时不渲染成可执行卡片', () => {
  const w = 装载('ppt_card.js');
  const h = w.pptCard('{"title":"只有标题"}');
  assert.ok(!/ppt-card-op/.test(h), '缺 slides 的大纲不该给执行入口');
});
