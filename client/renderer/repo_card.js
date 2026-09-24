/**
 * 对话里的代码仓卡片。
 *
 * AI 用四种代码块操作工作中心里的客户代码副本，这里渲染成卡片并自动执行：
 *   file-read   读文件
 *   file-write  整文件覆写
 *   file-patch  局部补丁（原文 / 替换 两段）
 *   file-push   回传到客户服务器
 *
 * 与 ssh_card.js 同样的思路：执行完把结果作为下一轮消息回传给 AI。
 * 按客户要求，四种动作一律自动执行，不征询确认，也没有次数上限——
 * 包括 file-push 回传到客户服务器。服务端写入前仍会在远端留备份。
 */
(function () {
  'use strict';

  var seq = 0;

  /* 自动执行不再计数限流，这个函数保留是因为 chat.js 在用户发消息时会调它。 */
  window.repoResetAuto = function () {};

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* 从块内容里取 key: value 形式的头部字段 */
  function 取字段(文本, 名) {
    var re = new RegExp('^\\s*' + 名 + '\\s*:\\s*(.*)$', 'im');
    var m = String(文本).match(re);
    return m ? m[1].trim() : '';
  }

  /**
   * 解析块内容。不同动作的正文格式不一样：
   *   write  头部字段后跟 --- 分隔符，之后全是文件正文
   *   patch  用 <<<<<<< 原文 / ======= / >>>>>>> 三段式
   */
  function 解析(动作, raw) {
    var 文 = String(raw);
    var 出 = { path: 取字段(文, 'path'), note: 取字段(文, 'note') };

    if (动作 === 'write') {
      // 分隔符可以是 --- 或 ---- 等，取第一个独占一行的
      var i = 文.search(/^\s*-{3,}\s*$/m);
      if (i >= 0) {
        var 后 = 文.slice(i);
        var j = 后.indexOf('\n');
        出.text = j >= 0 ? 后.slice(j + 1) : '';
      } else {
        // 没写分隔符时兜底：把 path/note 行之后的内容全当正文
        出.text = 文.replace(/^\s*(path|note)\s*:.*$/gim, '').replace(/^\n+/, '');
      }
      // 去掉正文末尾多余空行，但保留一个换行
      出.text = 出.text.replace(/\s+$/, '\n');
    } else if (动作 === 'patch') {
      var m = 文.match(/<{5,}[^\n]*\n([\s\S]*?)\n={5,}[^\n]*\n([\s\S]*?)\n>{5,}/);
      if (m) {
        出.find = m[1];
        出.replace = m[2];
      }
    } else if (动作 === 'list') {
      // dirty: 1 只列未回传的改动。写成 true / 是 也认，模型措辞不稳定。
      var v = 取字段(文, 'dirty').toLowerCase();
      出.dirty = (v === '1' || v === 'true' || v === 'yes' || v === '是') ? 1 : 0;
    }
    return 出;
  }

  var 标题 = {
    list:  '查看代码仓文件清单',
    read:  '读取代码文件',
    write: '改写代码文件',
    patch: '给代码文件打补丁',
    push:  '回传改动到客户服务器'
  };

  /**
   * 生成卡片 HTML。
   * @param 动作 read/write/patch/push
   * @param raw  未转义的块原文
   */
  window.repoCard = function (动作, raw) {
    var d = 解析(动作, raw);
    var 缺 = false;
    // push 和 list 都不需要 path：一个是推全部改动，一个是列清单
    if (动作 !== 'push' && 动作 !== 'list' && !d.path) { 缺 = true; }
    if (动作 === 'write' && !d.text) { 缺 = true; }
    if (动作 === 'patch' && (d.find === undefined || d.replace === undefined)) { 缺 = true; }
    if (缺) {
      return '<pre><code>' + esc(raw) + '</code></pre>';
    }

    var id = 'repoc' + (++seq);
    var 摘要 = '';
    if (动作 === 'list') {
      摘要 = '<div class="repo-path">' +
             (d.dirty ? '只列未回传的本地改动' : '列出仓内全部文件') + '</div>';
    } else if (动作 === 'read') {
      摘要 = '<div class="repo-path">' + esc(d.path) + '</div>';
    } else if (动作 === 'write') {
      var 行数 = d.text.split('\n').length;
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">整文件覆写，' + 行数 + ' 行</span></div>';
    } else if (动作 === 'patch') {
      摘要 = '<div class="repo-path">' + esc(d.path) + ' <span class="repo-dim">局部补丁</span></div>' +
             '<pre class="repo-diff">' +
             d.find.split('\n').map(function (l) {
               return '<span class="dl-del">- ' + esc(l) + '</span>';
             }).join('\n') + '\n' +
             d.replace.split('\n').map(function (l) {
               return '<span class="dl-add">+ ' + esc(l) + '</span>';
             }).join('\n') + '</pre>';
    } else {
      摘要 = '<div class="repo-path">把本地改动上传到客户服务器' +
             '<span class="repo-dim">（会先在服务器上备份）</span></div>';
    }

    var 说明 = d.note ? '<div class="repo-note">' + esc(d.note) + '</div>' : '';

    return '<div class="repo-card" id="' + id + '" data-act="' + 动作 + '">' +
      '<div class="repo-card-head">' +
        '<span class="repo-ico" aria-hidden="true">◧</span>' +
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

  /* 当前项目 id。项目上下文由 project.js 维护在 window.当前项目 上。 */
  function 当前项目id() {
    return Number(window.当前项目 && window.当前项目.id) || 0;
  }

  function post(data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    fd.append('project_id', 当前项目id());
    fd.append('conv_id', window.currentConvId || 0);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/api/repo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { error: '服务端返回异常' }; });
      });
  }

  /* 只读动作走 GET。api/repo.php 里 tree 的 dirty 参数只从 $_GET 取，
     用 POST 提交那个参数拿不到（ws-list 上次就踩在这儿）。 */
  function get(data) {
    var q = ['project_id=' + 当前项目id(), 'conv_id=' + (window.currentConvId || 0)];
    Object.keys(data).forEach(function (k) {
      q.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
    });
    return fetch('/api/repo.php?' + q.join('&'), { credentials: 'same-origin' })
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

  /**
   * 执行一张卡片。
   * @param 自动 true 表示自动触发
   */
  function 执行(card, 自动) {
    if (card.getAttribute('data-repo-done') === '1') { return; }
    card.setAttribute('data-repo-done', '1');

    var 动作  = card.getAttribute('data-act');
    var rawEl = card.querySelector('.repo-raw');
    // 这两条提前退出的路径必须回执。chat.js 在发现有卡片时就调了 工具开始()
    // 把按钮锁上，解锁只发生在回执里（fillRepoResult 会调 工具结束）。
    // 直接 return 等于让「工具执行中」一直是 true——发送键卡在「生成中」，
    // 暂停键因为 busy 已是 false 而藏起来，两个键一起消失，
    // 要等 300 秒看门狗超时才恢复。
    if (!rawEl) {
      setState(card, '失败', 'bad');
      var 因1 = '卡片内部结构缺失，无法取出原文。这是前端问题，请把这一步跳过。';
      showOut(card, 因1);
      if (window.fillRepoResult) { window.fillRepoResult(动作 || 'read', '', 因1, false); }
      return;
    }
    var d = 解析(动作, rawEl.value);

    // 本地项目分支
    if (window.当前项目 && window.当前项目.deploy_type === 'local') {
      return 执行本地(card, 自动, 动作, d);
    }

    if (!当前项目id()) {
      setState(card, '未选项目', 'bad');
      var 因2 = '当前对话没有归属项目，无法定位代码仓。'
              + 'file-read / file-write / file-patch / file-push / file-list 都需要项目上下文。'
              + '要产出代码请改用工作中心（ws-write），那里不需要项目。';
      showOut(card, 因2);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因2, false); }
      return;
    }

    setState(card, 自动 ? '自动执行中…' : '执行中…', '');
    var 请求;
    if (动作 === 'list') {
      // 后端动作名是 tree，标签叫 file-list 是为了跟 ws-list 读法一致
      请求 = get({ act: 'tree', dirty: d.dirty || 0 });
    } else if (动作 === 'read') {
      请求 = post({ act: 'read', path: d.path });
    } else if (动作 === 'write') {
      请求 = post({ act: 'write', path: d.path, text: d.text, note: d.note || '' });
    } else if (动作 === 'patch') {
      请求 = post({ act: 'patch', path: d.path, find: d.find, replace: d.replace, note: d.note || '' });
    } else {
      请求 = post({ act: 'push', note: d.note || '' });
    }

    return 请求.then(function (r) {
      if (!r || r.error) {
        setState(card, '失败', 'bad');
        var 提示 = (r && r.error) || '未知错误';
        showOut(card, 提示);
        // 失败原因也要回传给 AI，它才知道该怎么补救（尤其补丁没匹配上）
        if (window.fillRepoResult) {
          window.fillRepoResult(动作, d.path || '', '失败：' + 提示, false);
        }
        return;
      }

      if (动作 === 'list') {
        var 条数 = r.total || (r.list ? r.list.length : 0);
        setState(card, 条数 + ' 个文件', 'good');
        var 清单;
        if (!r.list || !r.list.length) {
          // 空仓必须说清楚下一步，否则 AI 会接着去试 file-read 然后一路失败。
          // 这是这次排查的起因：AI 在空仓上反复打，客户只看到「失败」。
          清单 = d.dirty
            ? '（没有未回传的本地改动）'
            : '（代码仓是空的，还没从客户服务器拉取过代码）\n\n'
              + '不要再用 file-read / file-write / file-patch，它们都会失败。\n'
              + '要产出代码请改用工作中心（ws-write）；'
              + '要改客户线上的代码，先让客户在项目设置里绑定服务器和部署目录，再拉取。';
        } else {
          清单 = r.list.map(function (f) {
            var 行 = f.path + '（' + f.size_text + '）';
            if (f.state === 'edited') { 行 += ' ← 本地已改，未回传'; }
            else if (f.state === 'new')  { 行 += ' ← 本地新增，未回传'; }
            else if (f.state === 'gone') { 行 += ' ← 远端已删除'; }
            if (!f.is_text) { 行 += ' [二进制，不能改]'; }
            return 行;
          }).join('\n');
        }
        showOut(card, 清单);
        if (window.fillRepoResult) {
          window.fillRepoResult('list', '', 清单, true);
        }
      } else if (动作 === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        showOut(card, r.text.length > 3000 ? r.text.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : r.text);
        var 正文 = r.text + (r.truncated ? '\n…（文件过长已截断）' : '');
        if (window.fillRepoResult) {
          window.fillRepoResult('read', r.path, 正文, true);
        }
      } else if (动作 === 'push') {
        setState(card, r.fail_count > 0 ? '部分失败' : '回传完成',
                 r.fail_count > 0 ? 'warn' : 'good');
        var 文 = r.msg + '\n\n';
        (r.detail || []).forEach(function (x) {
          文 += (x.ok ? '✓ ' : '✗ ') + x.path + ' — ' + x.msg + '\n';
        });
        showOut(card, 文);
        if (window.fillRepoResult) {
          window.fillRepoResult('push', '', 文, true);
        }
      } else {
        setState(card, '已写入本地副本', 'good');
        showOut(card, r.msg);
        if (window.fillRepoResult) {
          window.fillRepoResult(动作, r.path, r.msg, true);
        }
      }
    }).catch(function (e) {
      // 网络层就失败了（断网、超时、被中断）。必须回执，
      // 否则 chat.js 的按钮会一直锁着等这一步的结果。
      setState(card, '失败', 'bad');
      var 因 = '请求发送失败：' + ((e && e.message) || e);
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
    });
  }

  /* ---- 本地项目文件操作 ---- */
  function 执行本地(card, 自动, 动作, d) {
    setState(card, 自动 ? '自动执行中…' : '执行中…', '');

    var 目录 = window.当前项目.local_dir;
    if (!目录) {
      var 因 = '本地项目未指定文件夹路径';
      setState(card, '失败', 'bad');
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
      return Promise.resolve();
    }

    var 操作;
    if (动作 === 'list') {
      操作 = window.后端.列出文件夹(目录).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '列出失败');
        return { act: 'list', list: r.list };
      });
    } else if (动作 === 'read') {
      操作 = window.后端.读取本地文件(目录, d.path).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '读取失败');
        return { act: 'read', path: d.path, text: r.content, lines: r.content.split('\n').length };
      });
    } else if (动作 === 'write') {
      操作 = window.后端.写入本地文件(目录, d.path, d.text).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '写入失败');
        return { act: 'write', path: d.path, msg: '已写入本地文件：' + d.path };
      });
    } else if (动作 === 'patch') {
      操作 = window.后端.读取本地文件(目录, d.path).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '读取失败');
        var 内容 = r.content;
        var 位置 = 内容.indexOf(d.find);
        if (位置 === -1) throw new Error('找不到要替换的原文，补丁失败');
        var 新内容 = 内容.slice(0, 位置) + d.replace + 内容.slice(位置 + d.find.length);
        return window.后端.写入本地文件(目录, d.path, 新内容).then(function(rr) {
          if (!rr.ok) throw new Error(rr.msg || '写入失败');
          return { act: 'patch', path: d.path, msg: '补丁应用成功：' + d.path };
        });
      });
    } else {
      var 因2 = '本地项目不支持回传（push）功能，所有修改直接作用于本地文件夹。';
      setState(card, '不支持', 'bad');
      showOut(card, 因2);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因2, false); }
      return Promise.resolve();
    }

    return 操作.then(function(r) {
      if (r.act === 'list') {
        var 列表 = r.list || [];
        var 条数 = 列表.length;
        setState(card, 条数 + ' 个文件', 'good');
        var 清单 = 条数 === 0 ? '（文件夹为空）' : 列表.map(function(f) {
          return f.name + (f.isDirectory ? '/' : '') + '（' + (f.isDirectory ? '目录' : '文件') + '）';
        }).join('\n');
        showOut(card, 清单);
        if (window.fillRepoResult) { window.fillRepoResult('list', '', 清单, true); }
      } else if (r.act === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        var 正文 = r.text || '';
        showOut(card, 正文.length > 3000 ? 正文.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : 正文);
        if (window.fillRepoResult) { window.fillRepoResult('read', r.path, 正文, true); }
      } else {
        setState(card, '已完成', 'good');
        showOut(card, r.msg);
        if (window.fillRepoResult) { window.fillRepoResult(r.act, r.path, r.msg, true); }
      }
    }).catch(function(e) {
      setState(card, '失败', 'bad');
      var 因 = '本地操作失败：' + (e.message || e);
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
    });
  }

  /* ---- 本地项目文件操作 ---- */
  function 执行本地(card, 自动, 动作, d) {
    setState(card, 自动 ? '自动执行中…' : '执行中…', '');

    var 目录 = window.当前项目.local_dir;
    if (!目录) {
      var 因 = '本地项目未指定文件夹路径';
      setState(card, '失败', 'bad');
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
      return Promise.resolve();
    }

    var 操作;
    if (动作 === 'list') {
      操作 = window.后端.列出文件夹(目录).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '列出失败');
        return { act: 'list', list: r.list };
      });
    } else if (动作 === 'read') {
      操作 = window.后端.读取本地文件(目录, d.path).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '读取失败');
        return { act: 'read', path: d.path, text: r.content, lines: r.content.split('\n').length };
      });
    } else if (动作 === 'write') {
      操作 = window.后端.写入本地文件(目录, d.path, d.text).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '写入失败');
        return { act: 'write', path: d.path, msg: '已写入本地文件：' + d.path };
      });
    } else if (动作 === 'patch') {
      操作 = window.后端.读取本地文件(目录, d.path).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '读取失败');
        var 内容 = r.content;
        var 位置 = 内容.indexOf(d.find);
        if (位置 === -1) throw new Error('找不到要替换的原文，补丁失败');
        var 新内容 = 内容.slice(0, 位置) + d.replace + 内容.slice(位置 + d.find.length);
        return window.后端.写入本地文件(目录, d.path, 新内容).then(function(rr) {
          if (!rr.ok) throw new Error(rr.msg || '写入失败');
          return { act: 'patch', path: d.path, msg: '补丁应用成功：' + d.path };
        });
      });
    } else {
      var 因2 = '本地项目不支持回传（push）功能，所有修改直接作用于本地文件夹。';
      setState(card, '不支持', 'bad');
      showOut(card, 因2);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因2, false); }
      return Promise.resolve();
    }

    return 操作.then(function(r) {
      if (r.act === 'list') {
        var 列表 = r.list || [];
        var 条数 = 列表.length;
        setState(card, 条数 + ' 个文件', 'good');
        var 清单 = 条数 === 0 ? '（文件夹为空）' : 列表.map(function(f) {
          return f.name + (f.isDirectory ? '/' : '') + '（' + (f.isDirectory ? '目录' : '文件') + '）';
        }).join('\n');
        showOut(card, 清单);
        if (window.fillRepoResult) { window.fillRepoResult('list', '', 清单, true); }
      } else if (r.act === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        var 正文 = r.text || '';
        showOut(card, 正文.length > 3000 ? 正文.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : 正文);
        if (window.fillRepoResult) { window.fillRepoResult('read', r.path, 正文, true); }
      } else {
        setState(card, '已完成', 'good');
        showOut(card, r.msg);
        if (window.fillRepoResult) { window.fillRepoResult(r.act, r.path, r.msg, true); }
      }
    }).catch(function(e) {
      setState(card, '失败', 'bad');
      var 因 = '本地操作失败：' + (e.message || e);
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
    });
  }

  /* ---- 本地项目文件操作 ---- */
  function 执行本地(card, 自动, 动作, d) {
    setState(card, 自动 ? '自动执行中…' : '执行中…', '');

    var 目录 = window.当前项目.local_dir;
    if (!目录) {
      var 因 = '本地项目未指定文件夹路径';
      setState(card, '失败', 'bad');
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
      return Promise.resolve();
    }

    var 操作;
    if (动作 === 'list') {
      操作 = window.后端.列出文件夹(目录).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '列出失败');
        return { act: 'list', list: r.list };
      });
    } else if (动作 === 'read') {
      操作 = window.后端.读取本地文件(目录, d.path).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '读取失败');
        return { act: 'read', path: d.path, text: r.content, lines: r.content.split('\n').length };
      });
    } else if (动作 === 'write') {
      操作 = window.后端.写入本地文件(目录, d.path, d.text).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '写入失败');
        return { act: 'write', path: d.path, msg: '已写入本地文件：' + d.path };
      });
    } else if (动作 === 'patch') {
      操作 = window.后端.读取本地文件(目录, d.path).then(function(r) {
        if (!r.ok) throw new Error(r.msg || '读取失败');
        var 内容 = r.content;
        var 位置 = 内容.indexOf(d.find);
        if (位置 === -1) throw new Error('找不到要替换的原文，补丁失败');
        var 新内容 = 内容.slice(0, 位置) + d.replace + 内容.slice(位置 + d.find.length);
        return window.后端.写入本地文件(目录, d.path, 新内容).then(function(rr) {
          if (!rr.ok) throw new Error(rr.msg || '写入失败');
          return { act: 'patch', path: d.path, msg: '补丁应用成功：' + d.path };
        });
      });
    } else {
      var 因2 = '本地项目不支持回传（push）功能，所有修改直接作用于本地文件夹。';
      setState(card, '不支持', 'bad');
      showOut(card, 因2);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因2, false); }
      return Promise.resolve();
    }

    return 操作.then(function(r) {
      if (r.act === 'list') {
        var 列表 = r.list || [];
        var 条数 = 列表.length;
        setState(card, 条数 + ' 个文件', 'good');
        var 清单 = 条数 === 0 ? '（文件夹为空）' : 列表.map(function(f) {
          return f.name + (f.isDirectory ? '/' : '') + '（' + (f.isDirectory ? '目录' : '文件') + '）';
        }).join('\n');
        showOut(card, 清单);
        if (window.fillRepoResult) { window.fillRepoResult('list', '', 清单, true); }
      } else if (r.act === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        var 正文 = r.text || '';
        showOut(card, 正文.length > 3000 ? 正文.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : 正文);
        if (window.fillRepoResult) { window.fillRepoResult('read', r.path, 正文, true); }
      } else {
        setState(card, '已完成', 'good');
        showOut(card, r.msg);
        if (window.fillRepoResult) { window.fillRepoResult(r.act, r.path, r.msg, true); }
      }
    }).catch(function(e) {
      setState(card, '失败', 'bad');
      var 因 = '本地操作失败：' + (e.message || e);
      showOut(card, 因);
      if (window.fillRepoResult) { window.fillRepoResult(动作, d.path || '', 因, false); }
    });
  }

  /**
   * 供 chat.js 调用：自动执行这条回复里的仓卡片。
   * 一次只跑第一张未执行的，四种动作都跑，包括回传。
   */
  window.repoAutoRun = function (容器) {
    if (!容器) { return; }
    var 卡 = 容器.querySelectorAll('.repo-card:not(.pend):not([data-repo-done="1"])');
    for (var i = 0; i < 卡.length; i++) {
      var c = 卡[i];
      if (!c.querySelector('.repo-raw')) { continue; }
      执行(c, true);
      return;      // 一次只执行一张
    }
  };
})();
