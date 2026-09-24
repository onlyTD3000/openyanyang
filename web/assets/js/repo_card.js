/**
 * 对话里的代码仓卡片。
 *
 * AI 用这几种代码块操作工作中心里的客户代码副本，这里渲染成卡片并自动执行：
 *   file-pull   从客户服务器拉代码到本地副本
 *   file-list   列出仓内文件清单
 *   file-read   读文件
 *   file-write  整文件覆写
 *   file-patch  局部补丁（原文 / 替换 两段）
 *   file-delete 删掉本地副本里的一个文件
 *   file-push   回传到客户服务器
 *
 * 与 ssh_card.js 同样的思路：执行完把结果作为下一轮消息回传给 AI。
 * 按客户要求，这些动作一律自动执行，不征询确认，也没有次数上限——
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
    } else if (动作 === 'pull') {
      // force: 1 表示连本地未回传的改动一起覆盖。措辞变体同 dirty。
      var f = 取字段(文, 'force').toLowerCase();
      出.force = (f === '1' || f === 'true' || f === 'yes' || f === '是') ? 1 : 0;
    } else if (动作 === 'push') {
      // confirm: 1 表示客户已经在对话里口头同意覆盖敏感文件。
      // 没有这个字段时，后端遇到敏感路径会拒绝执行并把清单退回来。
      var cf = 取字段(文, 'confirm').toLowerCase();
      出.confirm = (cf === '1' || cf === 'true' || cf === 'yes' || cf === '是') ? 1 : 0;
    }
    return 出;
  }

  var 标题 = {
    list:   '查看代码仓文件清单',
    read:   '读取代码文件',
    write:  '改写代码文件',
    patch:  '给代码文件打补丁',
    delete: '删除本地副本里的文件',
    push:   '回传改动到客户服务器',
    pull:   '从客户服务器拉取代码'
  };

  /**
   * 生成卡片 HTML。
   * @param 动作 read/write/patch/push
   * @param raw  未转义的块原文
   */
  window.repoCard = function (动作, raw) {
    var d = 解析(动作, raw);
    var 缺 = false;
    // push / list / pull 都不需要 path：推全部改动、列清单、整仓拉取
    if (动作 !== 'push' && 动作 !== 'list' && 动作 !== 'pull' && !d.path) { 缺 = true; }
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
    } else if (动作 === 'pull') {
      摘要 = '<div class="repo-path">' +
             (d.force ? '强制拉取，覆盖本地未回传的改动'
                      : '从部署目录拉取代码，保留本地未回传的改动') + '</div>';
    } else if (动作 === 'read') {
      摘要 = '<div class="repo-path">' + esc(d.path) + '</div>';
    } else if (动作 === 'write') {
      var 行数 = d.text.split('\n').length;
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">整文件覆写，' + 行数 + ' 行</span></div>';
    } else if (动作 === 'delete') {
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">只删本地副本，服务器上那份不动</span></div>';
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

    return '<div class="repo-card collapsed" id="' + id + '" data-act="' + 动作 + '">' +
      '<div class="repo-card-head">' +
        '<span class="repo-ico" aria-hidden="true">▸</span>' +
        '<span class="repo-title">' + 标题[动作] + '</span>' +
        '<span class="repo-state">待执行</span>' +
      '</div>' +
      '<div class="repo-card-body" hidden>' +
        摘要 + 说明 +
        '<pre class="repo-out" hidden tabindex="0"></pre>' +
      '</div>' +
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

  // 卡片头部点击展开/收起（排除 sftp-card-op 和 ws-card-op，它们有自己的处理）
  document.addEventListener('click', function (e) {
    var head = e.target.closest('.repo-card-head');
    if (!head) { return; }
    var card = head.closest('.repo-card');
    if (!card) { return; }
    // SFTP 和 WS 卡片有自己的处理函数，这里只处理普通 repo-card
    if (card.classList.contains('sftp-card-op') || card.classList.contains('ws-card-op')) {
      return;
    }
    
    var body = card.querySelector('.repo-card-body');
    var arrow = head.querySelector('.repo-ico');
    if (!body || !arrow) { return; }
    
    if (card.classList.contains('collapsed')) {
      card.classList.remove('collapsed');
      body.hidden = false;
      arrow.textContent = '▾';
    } else {
      card.classList.add('collapsed');
      body.hidden = true;
      arrow.textContent = '▸';
    }
  });

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
  function 执行(card, 自动, 收集) {
    if (card.getAttribute('data-repo-done') === '1') { return Promise.resolve(true); }
    card.setAttribute('data-repo-done', '1');

    var 动作  = card.getAttribute('data-act');
    var rawEl = card.querySelector('.repo-raw');

    /* 回执出口。串行执行多张卡片时不能每张都调 fillRepoResult——
       那会连发好几轮请求给 AI，上下文被同一批改动的碎片结果灌满。
       传了 收集 数组就先攒着，等整批跑完由 repoAutoRun 合成一条发出去。 */
    var 本次成功 = true;
    function 回执(动作x, 路径x, 正文x, 成功x) {
      本次成功 = !!成功x;
      if (收集) {
        收集.push({ 动作: 动作x, 路径: 路径x, 正文: String(正文x), 成功: !!成功x });
        return;
      }
      if (window.fillRepoResult) { window.fillRepoResult(动作x, 路径x, 正文x, 成功x); }
    }
    // 这两条提前退出的路径必须回执。chat.js 在发现有卡片时就调了 工具开始()
    // 把按钮锁上，解锁只发生在回执里（fillRepoResult 会调 工具结束）。
    // 直接 return 等于让「工具执行中」一直是 true——发送键卡在「生成中」，
    // 暂停键因为 busy 已是 false 而藏起来，两个键一起消失，
    // 要等 300 秒看门狗超时才恢复。
    if (!rawEl) {
      setState(card, '失败', 'bad');
      var 因1 = '卡片内部结构缺失，无法取出原文。这是前端问题，请把这一步跳过。';
      showOut(card, 因1);
      回执(动作 || 'read', '', 因1, false);
      return Promise.resolve(false);
    }
    var d = 解析(动作, rawEl.value);

    if (!当前项目id()) {
      setState(card, '未选项目', 'bad');
      var 因2 = '当前对话没有归属项目，无法定位代码仓。'
              + 'file-read / file-write / file-patch / file-delete / file-push / file-list '
              + '都需要项目上下文。'
              + '要产出代码请改用工作中心（ws-write），那里不需要项目。';
      showOut(card, 因2);
      回执(动作, d.path || '', 因2, false);
      return Promise.resolve(false);
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
    } else if (动作 === 'delete') {
      请求 = post({ act: 'del', path: d.path, note: d.note || '' });
    } else if (动作 === 'pull') {
      请求 = post({ act: 'pull', force: d.force || 0 });
    } else {
      请求 = post({ act: 'push', note: d.note || '', confirm: d.confirm || 0 });
    }

    return 请求.then(function (r) {
      if (!r || r.error) {
        setState(card, '失败', 'bad');
        var 提示 = (r && r.error) || '未知错误';
        showOut(card, 提示);
        // 失败原因也要回传给 AI，它才知道该怎么补救（尤其补丁没匹配上）
        回执(动作, d.path || '', '失败：' + 提示, false);
        return false;
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
        回执('list', '', 清单, true);
      } else if (动作 === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        showOut(card, r.text.length > 3000 ? r.text.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : r.text);
        var 正文 = r.text + (r.truncated ? '\n…（文件过长已截断）' : '');
        回执('read', r.path, 正文, true);
      } else if (动作 === 'pull') {
        setState(card, '拉取完成', 'good');
        var 拉 = r.msg + '\n\n';
        if (r.截断) {
          拉 += '注意：文件数达到上限，没有拉全。\n';
        }
        // 拉完必须提醒下一步查清单：清单不进系统提示词，
        // AI 手上依然没有路径，不说清楚它会直接猜路径去读。
        拉 += '代码已拉到本地副本。接着用 file-list 查看清单，再 file-read 读要改的文件。';
        showOut(card, 拉);
        回执('pull', '', 拉, true);
      } else if (动作 === 'delete') {
        setState(card, '已从本地副本删除', 'good');
        // 必须说清远端还在，否则 AI 会以为线上文件也没了，
        // 回头跟客户汇报「已删除」，客户去服务器上一看文件还在。
        var 删 = r.msg + '\n\n'
               + '注意：删掉的只是本地副本。回传（file-push）只上传改动过的文件，'
               + '从不删除服务器上的东西，所以客户服务器上那份还在，下次拉取还会回来。'
               + '要删线上文件得走 SFTP 或命令行。';
        showOut(card, 删);
        回执('delete', d.path, 删, true);
      } else if (动作 === 'push') {
        // 后端认为这批里有敏感文件或压缩包，什么都没做就退回来了。
        // 这里不给「确认」按钮：让 AI 在对话里把风险讲清楚，
        // 客户口头同意后由 AI 重发带 confirm: 1 的 file-push。
        if (r.need_confirm) {
          setState(card, '需你确认', 'warn');
          var 险 = r.msg + '\n\n服务器没有任何改动。';
          showOut(card, 险);
          回执('push', '',
            险 + '\n\n这批回传已被拦下，服务器上什么都没改。'
               + '\n请把上面每个文件的风险讲给客户听，然后停下等他回话，不要自己决定。'
               + '\n客户明确同意后，重新发一个 file-push，块里加一行 confirm: 1 即可照原样回传；'
               + '\n他只同意推部分文件的话，就在 note 里说清，并只对那几个文件做改动后再推。',
            false);
          return false;
        }
        setState(card, r.fail_count > 0 ? '部分失败' : '回传完成',
                 r.fail_count > 0 ? 'warn' : 'good');
        var 文 = r.msg + '\n\n';
        (r.detail || []).forEach(function (x) {
          文 += (x.ok ? '✓ ' : '✗ ') + x.path + ' — ' + x.msg + '\n';
        });
        showOut(card, 文);
        // push 的 fail_count > 0 时整批不算全成功，串行链要据此停下
        回执('push', '', 文, !(r.fail_count > 0));
      } else {
        setState(card, '已写入本地副本', 'good');
        showOut(card, r.msg);
        回执(动作, r.path, r.msg, true);
      }
      return 本次成功;
    }).catch(function (e) {
      // 网络层就失败了（断网、超时、被中断）。必须回执，
      // 否则 chat.js 的按钮会一直锁着等这一步的结果。
      setState(card, '失败', 'bad');
      var 因 = '请求发送失败：' + ((e && e.message) || e);
      showOut(card, 因);
      回执(动作, d.path || '', 因, false);
      return false;
    });
  }

  /* 写类动作：同一条回复里连着出现时可以整批跑完。
     其余动作（读、列清单、拉取、回传）必须一次只跑一张——AI 要靠这一张的
     结果才能决定下一步做什么，攒批发回去它就没法边看边改了。 */
  function 是写类(card) {
    var a = card.getAttribute('data-act');
    return a === 'write' || a === 'patch' || a === 'delete';
  }

  /* 把一批卡片的结果合成一条回执。
     不逐张发的原因：每次 fillRepoResult 都会触发一轮新请求，
     一条回复写五个文件就要连打五轮，上下文被同一批改动的碎片灌满，
     而 AI 真正需要的只是「这几个文件都写好了没」。 */
  function 合成回执(结果表, 跳过表) {
    var 文 = '这一批共 ' + 结果表.length + ' 个文件改动，逐个执行结果如下：\n\n';
    结果表.forEach(function (x, i) {
      文 += (i + 1) + '. ' + (x.成功 ? '✓ ' : '✗ ')
          + (x.路径 || '(未知路径)') + '\n'
          + '   ' + String(x.正文).replace(/\n/g, '\n   ') + '\n';
    });
    if (跳过表.length) {
      文 += '\n上面有一步失败了，为避免在错误状态上继续叠改动，'
          + '后面这 ' + 跳过表.length + ' 个文件**没有执行**：\n';
      跳过表.forEach(function (p, i) {
        文 += (i + 1) + '. ' + (p || '(未知路径)') + '\n';
      });
      文 += '\n请先弄清失败那一步的原因，修正后重新提交剩下的改动。'
          + '不要假设它们已经写进去了。';
    }
    return 文;
  }

  /**
   * 供 chat.js 调用：自动执行这条回复里的仓卡片。
   *
   * 写类动作（write / patch）串行整批执行：AI 一条回复里写了几个文件就都落地，
   * 跑完合成一条回执发回去。之前只跑第一张、其余静默丢弃，AI 以为都写成功了，
   * 实际只进去一个——这是客户报的那个 bug。
   * 一旦有一张失败就停下，剩下的不再执行并在回执里点名，避免在错误状态上叠改动。
   *
   * 其余动作仍然一次只跑一张，理由见 是写类() 的注释。
   */
  window.repoAutoRun = function (容器) {
    if (!容器) { return; }
    var 全部 = 容器.querySelectorAll('.repo-card:not(.pend):not([data-repo-done="1"])');
    // 过掉结构不全的（没有 raw 就取不出参数）
    var 卡 = [];
    for (var i = 0; i < 全部.length; i++) {
      if (全部[i].querySelector('.repo-raw')) { 卡.push(全部[i]); }
    }
    if (!卡.length) { return; }

    // 第一张不是写类：照旧只跑这一张，结果直接回执
    if (!是写类(卡[0])) {
      执行(卡[0], true);
      return;
    }

    // 从头连着取写类卡片。碰到非写类就断开，留给下一轮——
    // 那类动作要单独跑，混进这批会让回执语义乱掉。
    var 批 = [];
    for (var j = 0; j < 卡.length && 是写类(卡[j]); j++) {
      批.push(卡[j]);
    }

    // 只有一张就不必攒批，直接走原来的单张回执，措辞更贴切
    if (批.length === 1) {
      执行(批[0], true);
      return;
    }

    var 结果表 = [];
    var 跳过表 = [];
    var 序 = 0;

    function 下一张() {
      if (序 >= 批.length) { return 收尾(); }
      var c = 批[序++];
      return 执行(c, true, 结果表).then(function (成功) {
        if (成功 === false) {
          // 失败即停。剩下的卡片不执行，标记成已处理避免看门狗之后又被别处捡起来跑，
          // 同时把状态写在卡面上，用户能看出这几张是被跳过而不是卡住了。
          while (序 < 批.length) {
            var 剩 = 批[序++];
            剩.setAttribute('data-repo-done', '1');
            setState(剩, '已跳过', 'warn');
            var d剩 = 解析(剩.getAttribute('data-act'), 
                          (剩.querySelector('.repo-raw') || {}).value || '');
            showOut(剩, '前一步失败，这一步没有执行。');
            跳过表.push(d剩.path || '');
          }
          return 收尾();
        }
        return 下一张();
      });
    }

    function 收尾() {
      var 全成 = !跳过表.length && 结果表.every(function (x) { return x.成功; });
      if (window.fillRepoResult) {
        window.fillRepoResult('batch', '', 合成回执(结果表, 跳过表), 全成);
      }
    }

    下一张();
  };
})();
