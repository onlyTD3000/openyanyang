/**
 * 对话里的工作中心文件卡片。
 *
 * AI 用三种代码块读写自己的文件库，这里渲染成卡片并自动执行：
 *   ws-read    读文件
 *   ws-write   写文件（不存在新建，存在覆盖）
 *   ws-patch   局部补丁（原文 / 替换 两段）
 *
 * 和 repo_card.js 的区别：
 *   - 作用对象是账号自己的工作中心文件库，不涉及客户服务器，所以全部自动执行
 *   - 按 name（账号内相对路径）定位，不需要项目上下文
 */
(function () {
  'use strict';

  var seq = 0;

  /* 自动执行不再计数限流，这个函数保留是因为 chat.js 在用户发消息时会调它。 */
  window.wsResetAuto = function () {};

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function 取字段(文本, 名) {
    var re = new RegExp('^\\s*' + 名 + '\\s*:\\s*(.*)$', 'im');
    var m = String(文本).match(re);
    return m ? m[1].trim() : '';
  }

  /**
   * 解析块内容。
   *   write  头部字段后跟 --- 分隔符，之后全是文件正文
   *   patch  用 <<<<<<< 原文 / ======= / >>>>>>> 三段式
   */
  function 解析(动作, raw) {
    var 文 = String(raw);
    // name 是主字段；兼容模型写成 path 的情况
    var 出 = { name: 取字段(文, 'name') || 取字段(文, 'path'), note: 取字段(文, 'note') };

    if (动作 === 'write') {
      var i = 文.search(/^\s*-{3,}\s*$/m);
      if (i >= 0) {
        var 后 = 文.slice(i);
        var j = 后.indexOf('\n');
        出.text = j >= 0 ? 后.slice(j + 1) : '';
      } else {
        出.text = 文.replace(/^\s*(name|path|note)\s*:.*$/gim, '').replace(/^\n+/, '');
      }
      出.text = 出.text.replace(/\s+$/, '\n');
    } else if (动作 === 'patch') {
      var m = 文.match(/<{5,}[^\n]*\n([\s\S]*?)\n={5,}[^\n]*\n([\s\S]*?)\n>{5,}/);
      if (m) {
        出.find = m[1];
        出.replace = m[2];
      }
    } else if (动作 === 'zip') {
      // 分隔符之后一行一个文件名。没写分隔符时就把非字段行都当文件名，
      // 模型漏写 --- 的情况比想象中多，容错掉比报错更实用。
      var k = 文.search(/^\s*-{3,}\s*$/m);
      var 体 = 文;
      if (k >= 0) {
        var 尾 = 文.slice(k);
        var p = 尾.indexOf('\n');
        体 = p >= 0 ? 尾.slice(p + 1) : '';
      } else {
        体 = 文.replace(/^\s*(name|path|note)\s*:.*$/gim, '');
      }
      出.files = 体.split('\n').map(function (l) {
        // 容忍 markdown 列表符号和反引号，模型爱加
        return l.replace(/^\s*[-*+]\s+/, '').replace(/`/g, '').trim();
      }).filter(function (l) {
        return l !== '';
      });
    }
    return 出;
  }

  var 标题 = {
    list:  '查看工作中心文件列表',
    read:  '读取工作中心文件',
    write: '写入工作中心文件',
    patch: '给工作中心文件打补丁',
    zip:   '打包工作中心文件'
  };

  /**
   * 生成卡片 HTML。
   * @param 动作 read/write/patch
   * @param raw  未转义的块原文
   */
  window.wsCard = function (动作, raw) {
    var d = 解析(动作, raw);
    var 缺 = false;
    // list 没有必填字段，块内可以完全为空，不做校验
    if (动作 === 'list') {
      缺 = false;
    } else if (动作 === 'zip') {
      // zip 的包名可以省略（服务端会按时间自动取名），所以不查 name，只查文件清单
      if (!d.files || !d.files.length) { 缺 = true; }
    } else {
      if (!d.name) { 缺 = true; }
      if (动作 === 'write' && !d.text) { 缺 = true; }
      if (动作 === 'patch' && (d.find === undefined || d.replace === undefined)) { 缺 = true; }
    }
    if (缺) {
      return '<pre><code>' + esc(raw) + '</code></pre>';
    }

    var id = 'wsc' + (++seq);
    var 摘要 = '';
    if (动作 === 'list') {
      var 搜 = 取字段(raw, 'q');
      摘要 = '<div class="repo-path">' +
             (搜 ? '筛选：' + esc(搜) : '全部文件') + '</div>';
    } else if (动作 === 'read') {
      摘要 = '<div class="repo-path">' + esc(d.name) + '</div>';
    } else if (动作 === 'write') {
      var 行数 = d.text.split('\n').length;
      var 字节 = d.text.length;
      摘要 = '<div class="repo-path">' + esc(d.name) +
             ' <span class="repo-dim">' + 行数 + ' 行 / ' + 字节 + ' 字符</span></div>';
    } else if (动作 === 'zip') {
      摘要 = '<div class="repo-path">' + esc(d.name || '（自动命名）') +
             ' <span class="repo-dim">' + d.files.length + ' 个文件</span></div>' +
             '<pre class="repo-diff">' + d.files.map(function (f) {
               return '<span class="dl-add">+ ' + esc(f) + '</span>';
             }).join('\n') + '</pre>';
    } else {
      摘要 = '<div class="repo-path">' + esc(d.name) + ' <span class="repo-dim">局部补丁</span></div>' +
             '<pre class="repo-diff">' +
             d.find.split('\n').map(function (l) {
               return '<span class="dl-del">- ' + esc(l) + '</span>';
             }).join('\n') + '\n' +
             d.replace.split('\n').map(function (l) {
               return '<span class="dl-add">+ ' + esc(l) + '</span>';
             }).join('\n') + '</pre>';
    }

    var 说明 = d.note ? '<div class="repo-note">' + esc(d.note) + '</div>' : '';

    return '<div class="repo-card ws-card-op" id="' + id + '" data-ws-act="' + 动作 + '">' +
      '<div class="repo-card-head">' +
        '<span class="repo-ico" aria-hidden="true">🗂</span>' +
        '<span class="repo-title">' + 标题[动作] + '</span>' +
      '</div>' +
      摘要 + 说明 +
      '<div class="repo-card-foot">' +
        '<span class="repo-state">待执行</span>' +
      '</div>' +
      '<pre class="repo-out" hidden tabindex="0"></pre>' +
      '<textarea class="repo-raw" hidden aria-hidden="true">' + esc(raw) + '</textarea>' +
      '</div>';
  };

  function post(data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/api/ws.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { error: '服务端返回异常' }; });
      });
  }

  function setState(card, txt, cls) {
    var el = card.querySelector('.repo-state');
    if (!el) { return; }
    el.textContent = txt;
    el.className = 'repo-state' + (cls ? ' ' + cls : '');
  }

  function showOut(card, txt) {
    var box = card.querySelector('.repo-out');
    if (!box) { return; }
    box.textContent = txt;
    box.hidden = false;
  }

  function 执行(card, 自动) {
    if (card.getAttribute('data-ws-done') === '1') { return; }
    card.setAttribute('data-ws-done', '1');

    var 动作  = card.getAttribute('data-ws-act');
    var rawEl = card.querySelector('.repo-raw');
    if (!rawEl) { return; }
    var d = 解析(动作, rawEl.value);

    setState(card, 自动 ? '自动执行中…' : '执行中…', '');
    var 请求;
    if (动作 === 'list') {
      // 服务端的 list 只读 $_GET（它在只读白名单里，免 CSRF），
      // 用 POST 发参数取不到，所以这里必须走 query string。
      // size 给大些：AI 要的是完整清单，分页对它没意义。
      请求 = fetch('/api/ws.php?act=list&kind=all&page=1&size=200&q='
          + encodeURIComponent(取字段(rawEl.value, 'q')),
          { credentials: 'same-origin' })
        .then(function (r) {
          return r.json().catch(function () { return { error: '服务端返回异常' }; });
        });
    } else if (动作 === 'read') {
      请求 = post({ act: 'read', name: d.name });
    } else if (动作 === 'write') {
      请求 = post({ act: 'write', name: d.name, text: d.text, note: d.note || '' });
    } else if (动作 === 'zip') {
      // files 走 JSON 字符串：FormData 传数组会被拍平成多个同名字段，
      // 服务端那边已经兼容 JSON 字符串，这样最省事也最不容易错
      请求 = post({ act: 'zip', files: JSON.stringify(d.files),
                    name: d.name || '', note: d.note || '' });
    } else {
      请求 = post({ act: 'patch', name: d.name, find: d.find,
                    replace: d.replace, note: d.note || '' });
    }

    return 请求.then(function (r) {
      if (!r || r.error) {
        setState(card, '失败', 'bad');
        var 提示 = (r && r.error) || '未知错误';
        showOut(card, 提示);
        if (window.fillWsResult) {
          window.fillWsResult(动作, d.name || '', '失败：' + 提示, false);
        }
        return;
      }

      if (动作 === 'list') {
        var 条数 = r.total || (r.list ? r.list.length : 0);
        setState(card, 条数 + ' 个文件', 'good');
        // 格式化成 AI 能直接拿来用的清单文本
        var 清单;
        if (!r.list || !r.list.length) {
          清单 = '（工作中心目前没有文件）';
        } else {
          清单 = r.list.map(function (f) {
            var 行 = f.name;
            if (f.size) { 行 += '（' + f.size_text + '）'; }
            if (f.note) { 行 += ' — ' + f.note; }
            return 行;
          }).join('\n');
          if (r.total > r.list.length) {
            清单 += '\n…（共 ' + r.total + ' 个，已列前 ' + r.list.length + ' 个）';
          }
        }
        showOut(card, 清单);
        if (window.fillWsResult) {
          window.fillWsResult('list', '', 清单, true);
        }
      } else if (动作 === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        showOut(card, r.text.length > 3000
          ? r.text.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : r.text);
        var 正文 = r.text + (r.truncated ? '\n…（文件过长已截断）' : '');
        if (window.fillWsResult) {
          window.fillWsResult('read', r.name, 正文, true);
        }
      } else if (动作 === 'zip') {
        setState(card, '已打包 ' + (r.count || 0) + ' 个文件', 'good');
        showOut(card, r.msg || '完成');
        // 直接把下载入口放在卡片上，客户不用再翻工作中心
        if (r.url) {
          var 脚 = card.querySelector('.repo-card-foot');
          if (脚 && !脚.querySelector('.ws-zip-dl')) {
            var a = document.createElement('a');
            a.className = 'ws-zip-dl';
            a.href = r.url;
            a.textContent = '下载 ' + (r.name || '压缩包')
              + (r.size_text ? '（' + r.size_text + '）' : '');
            a.setAttribute('download', '');
            a.style.marginLeft = '10px';
            脚.appendChild(a);
          }
        }
        if (window.wsRefreshQuota) { window.wsRefreshQuota(); }
        if (window.fillWsResult) {
          window.fillWsResult('zip', r.name, r.msg || '完成', true);
        }
      } else {
        setState(card, r.new ? '已新建' : '已更新', 'good');
        showOut(card, r.msg || '完成');
        // 刷新侧边栏的空间用量显示（如果页面上有）
        if (window.wsRefreshQuota) { window.wsRefreshQuota(); }
        if (window.fillWsResult) {
          window.fillWsResult(动作, r.name, r.msg || '完成', true);
        }
      }
    }).catch(function (e) {
      // 网络层就失败了（断网、超时、被中断）。必须回执，
      // 否则 chat.js 的按钮会一直锁着等这一步的结果。
      setState(card, '失败', 'bad');
      var 因 = '请求发送失败：' + ((e && e.message) || e);
      showOut(card, 因);
      if (window.fillWsResult) { window.fillWsResult(动作, d.name || '', 因, false); }
    });
  }

  /**
   * 供 chat.js 调用：自动执行这条回复里的工作中心卡片。
   * 一次只跑第一张未执行的。
   */
  window.wsAutoRun = function (容器) {
    if (!容器) { return; }
    var 卡 = 容器.querySelectorAll('.ws-card-op:not(.pend):not([data-ws-done="1"])');
    for (var i = 0; i < 卡.length; i++) {
      var c = 卡[i];
      if (!c.querySelector('.repo-raw')) { continue; }
      执行(c, true);
      return;
    }
  };
})();
