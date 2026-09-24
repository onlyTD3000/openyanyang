/**
 * 对话里的 SFTP 直连卡片。
 *
 * AI 用五种代码块直接读改客户服务器上的文件，这里渲染成卡片：
 *   sftp-list    列远端目录
 *   sftp-read    读远端文件
 *   sftp-write   整文件覆写远端文件
 *   sftp-patch   局部补丁（原文 / 替换 两段）
 *   sftp-delete  删远端文件
 *
 * 和 repo_card.js 的区别：这里没有本地副本做缓冲，每个写动作**立即落到客户线上服务器**。
 * 按客户要求，五种动作一律自动执行，不征询确认，也没有次数上限，
 * 包括 sftp-delete 删远端文件。服务端在写入和删除前仍会在远端留备份。
 *
 * 复用 .repo-card 的样式，另加 .sftp-card-op 区分左边框颜色。
 */
(function () {
  'use strict';

  var seq = 0;

  /* 自动执行不再计数限流，这个函数保留是因为 chat.js 在用户发消息时会调它。 */
  window.sftpResetAuto = function () {};

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
    var 出 = {
      path: 取字段(文, 'path'),
      note: 取字段(文, 'note'),
      dir:  取字段(文, 'dir')
    };

    if (动作 === 'write') {
      var i = 文.search(/^\s*-{3,}\s*$/m);
      if (i >= 0) {
        var 后 = 文.slice(i);
        var j = 后.indexOf('\n');
        出.text = j >= 0 ? 后.slice(j + 1) : '';
      } else {
        // 没写分隔符时兜底：把 path/note 行之后的内容全当正文
        出.text = 文.replace(/^\s*(path|note)\s*:.*$/gim, '').replace(/^\n+/, '');
      }
      出.text = 出.text.replace(/\s+$/, '\n');
    } else if (动作 === 'patch') {
      var m = 文.match(/<{5,}[^\n]*\n([\s\S]*?)\n={5,}[^\n]*\n([\s\S]*?)\n>{5,}/);
      if (m) {
        出.find = m[1];
        出.replace = m[2];
      }
    }
    return 出;
  }

  var 标题 = {
    list:   '查看服务器目录',
    read:   '读取服务器文件',
    write:  '改写服务器文件',
    patch:  '给服务器文件打补丁',
    delete: '删除服务器文件'
  };

  /**
   * 生成卡片 HTML。
   * @param 动作 list/read/write/patch/delete
   * @param raw  未转义的块原文
   */
  window.sftpCard = function (动作, raw) {
    var d = 解析(动作, raw);
    var 缺 = false;
    if (动作 !== 'list' && !d.path) { 缺 = true; }
    if (动作 === 'write' && !d.text) { 缺 = true; }
    if (动作 === 'patch' && (d.find === undefined || d.replace === undefined)) { 缺 = true; }
    if (缺) {
      return '<pre><code>' + esc(raw) + '</code></pre>';
    }

    var id = 'sftpc' + (++seq);
    var 摘要 = '';
    if (动作 === 'list') {
      摘要 = '<div class="repo-path">' +
             esc(d.dir && d.dir !== '.' ? d.dir : '部署目录') +
             ' <span class="repo-dim">列目录</span></div>';
    } else if (动作 === 'read') {
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">线上文件</span></div>';
    } else if (动作 === 'write') {
      var 行数 = d.text.split('\n').length;
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">覆写线上文件，' + 行数 + ' 行</span></div>';
    } else if (动作 === 'patch') {
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">线上打补丁</span></div>' +
             '<pre class="repo-diff">' +
             d.find.split('\n').map(function (l) {
               return '<span class="dl-del">- ' + esc(l) + '</span>';
             }).join('\n') + '\n' +
             d.replace.split('\n').map(function (l) {
               return '<span class="dl-add">+ ' + esc(l) + '</span>';
             }).join('\n') + '</pre>';
    } else {
      摘要 = '<div class="repo-path">' + esc(d.path) +
             ' <span class="repo-dim">从服务器删除（删前自动备份）</span></div>';
    }

    var 说明 = d.note ? '<div class="repo-note">' + esc(d.note) + '</div>' : '';

    return '<div class="repo-card sftp-card-op" id="' + id + '" data-sftp-act="' + 动作 + '">' +
      '<div class="repo-card-head">' +
        '<span class="repo-ico" aria-hidden="true">☁</span>' +
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

  /* 当前项目 id。SFTP 动作必须有项目，否则不知道改哪台机器的哪个目录。 */
  function 当前项目id() {
    return Number(window.当前项目 && window.当前项目.id) || 0;
  }

  function post(data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    fd.append('project_id', 当前项目id());
    fd.append('conv_id', window.currentConvId || 0);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/api/sftp.php', { method: 'POST', body: fd, credentials: 'same-origin' })
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
   * 写入/补丁/删除成功后，在卡片底部挂一个「还原这次改动」按钮。
   * 还原本身也是一次写远端的动作，所以要求点两下确认，避免误触。
   * @param editId 服务端返回的 sftp_edits 记录号
   */
  function 挂还原按钮(card, editId, 是新建) {
    var foot = card.querySelector('.repo-card-foot');
    if (!foot || !editId) { return; }
    // 新建文件没有旧内容可还原，后端会直接拒绝，这里就不给按钮，改成一句说明
    if (是新建) {
      var 提 = document.createElement('span');
      提.className = 'repo-dim sftp-undo-hint';
      提.style.marginLeft = 'auto';
      提.style.fontSize = '12px';
      提.textContent = '新建文件，如需撤销请手动删除';
      foot.appendChild(提);
      return;
    }
    var 盒 = document.createElement('span');
    盒.className = 'repo-btns';
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-sm';
    b.textContent = '还原这次改动';
    b.setAttribute('data-sftp-undo', String(editId));
    盒.appendChild(b);
    foot.appendChild(盒);
  }

  /* 执行还原。第一下变确认态，第二下才真发请求。 */
  function 还原(btn) {
    var card = btn.closest('.sftp-card-op');
    if (!card) { return; }
    var id = Number(btn.getAttribute('data-sftp-undo')) || 0;
    if (!id) { return; }

    if (btn.getAttribute('data-armed') !== '1') {
      btn.setAttribute('data-armed', '1');
      btn.classList.add('btn-primary');
      btn.textContent = '再点一次确认还原';
      // 5 秒内不点就复位，避免按钮长期停在确认态被误点
      setTimeout(function () {
        if (btn.getAttribute('data-armed') === '1' && btn.isConnected) {
          btn.removeAttribute('data-armed');
          btn.classList.remove('btn-primary');
          btn.textContent = '还原这次改动';
        }
      }, 5000);
      return;
    }

    btn.disabled = true;
    btn.textContent = '还原中…';
    post({ act: 'restore', edit_id: id }).then(function (r) {
      var 盒 = card.querySelector('.repo-btns');
      if (!r || r.error) {
        btn.disabled = false;
        btn.removeAttribute('data-armed');
        btn.classList.remove('btn-primary');
        btn.textContent = '还原这次改动';
        setState(card, '还原失败', 'bad');
        showOut(card, (r && r.error) || '未知错误');
        return;
      }
      if (盒) { 盒.remove(); }
      setState(card, '已还原', 'warn');
      showOut(card, r.msg + '\n（这张卡片的改动已撤销。若要 AI 知道，请在输入框里告诉它。）');
    });
  }

  /* 把列目录结果排成人能看的文本，同时也是回传给 AI 的正文 */
  function 目录文本(r) {
    var 行 = ['目录：' + r.dir, ''];
    (r.list || []).forEach(function (f) {
      行.push((f.is_dir ? '[目录] ' : '       ') + f.name +
              (f.is_dir ? '' : '  ' + f.size + ' 字节'));
    });
    if (!r.list || !r.list.length) { 行.push('（空目录）'); }
    if (r.truncated) { 行.push('', '…（条目过多已截断）'); }
    return 行.join('\n');
  }

  /**
   * 执行一张卡片。
   * @param 自动 true 表示自动触发
   * @param 收集 传数组时不逐张回执，把结果攒进数组交给 sftpAutoRun 合成一条
   */
  function 执行(card, 自动, 收集) {
    if (card.getAttribute('data-sftp-done') === '1') { return Promise.resolve(true); }
    card.setAttribute('data-sftp-done', '1');

    var 动作  = card.getAttribute('data-sftp-act');
    var rawEl = card.querySelector('.repo-raw');

    /* 回执出口。串行跑多张时不能每张都调 fillSftpResult——
       那会连发好几轮请求给 AI，上下文被同一批改动的碎片灌满。 */
    var 本次成功 = true;
    function 回执(动作x, 路径x, 正文x, 成功x) {
      本次成功 = !!成功x;
      if (收集) {
        收集.push({ 动作: 动作x, 路径: 路径x, 正文: String(正文x), 成功: !!成功x });
        return;
      }
      if (window.fillSftpResult) { window.fillSftpResult(动作x, 路径x, 正文x, 成功x); }
    }

    // 提前退出的路径也必须回执：chat.js 发现卡片时已调 工具开始() 锁了按钮，
    // 解锁只发生在回执里，直接 return 会把发送键卡死到看门狗超时。
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
      var 因2 = '当前对话没有归属项目，无法确定要操作哪台服务器。' +
        '请先把这条对话挂到一个项目下，并确认项目里已绑定服务器和部署目录。';
      showOut(card, 因2);
      回执(动作, d.path || d.dir || '', 因2, false);
      return Promise.resolve(false);
    }

    setState(card, 自动 ? '自动执行中…' : '执行中…', '');
    var 请求;
    if (动作 === 'list') {
      请求 = post({ act: 'list', dir: d.dir || '' });
    } else if (动作 === 'read') {
      请求 = post({ act: 'read', path: d.path });
    } else if (动作 === 'write') {
      请求 = post({ act: 'write', path: d.path, text: d.text, note: d.note || '' });
    } else if (动作 === 'patch') {
      请求 = post({ act: 'patch', path: d.path, find: d.find,
                    replace: d.replace, note: d.note || '' });
    } else {
      请求 = post({ act: 'delete', path: d.path, note: d.note || '' });
    }

    return 请求.then(function (r) {
      if (!r || r.error) {
        setState(card, '失败', 'bad');
        var 提示 = (r && r.error) || '未知错误';
        showOut(card, 提示);
        // 失败原因要回传给 AI，它才知道怎么补救（尤其补丁没匹配上）
        回执(动作, d.path || d.dir || '', '失败：' + 提示, false);
        return false;
      }

      if (动作 === 'list') {
        var 文 = 目录文本(r);
        setState(card, '共 ' + (r.count || 0) + ' 项', 'good');
        showOut(card, 文);
        回执('list', r.dir, 文, true);
      } else if (动作 === 'read') {
        setState(card, '已读取 ' + (r.lines || 0) + ' 行', 'good');
        showOut(card, r.text.length > 3000
          ? r.text.slice(0, 3000) + '\n…（页面只显示前 3000 字符）' : r.text);
        var 正文 = r.text + (r.truncated ? '\n…（文件过长已截断）' : '');
        回执('read', r.path, 正文, true);
      } else if (动作 === 'delete') {
        setState(card, '已删除', 'good');
        showOut(card, r.msg);
        挂还原按钮(card, r.edit_id, false);
        回执('delete', r.path, r.msg, true);
      } else {
        setState(card, '已写入服务器', 'good');
        showOut(card, r.msg);
        // 新建文件（后端 new 标记）没有旧内容，还原按钮给不了
        挂还原按钮(card, r.edit_id, !!r.new);
        回执(动作, r.path, r.msg, true);
      }
      return 本次成功;
    }).catch(function (e) {
      // 网络层就失败了（断网、超时、被中断）。必须回执，
      // 否则 chat.js 的按钮会一直锁着等这一步的结果。
      setState(card, '失败', 'bad');
      var 因 = '请求发送失败：' + ((e && e.message) || e);
      showOut(card, 因);
      回执(动作, d.path || d.dir || '', 因, false);
      return false;
    });
  }

  /* 写类动作：同一条回复里连着出现时可以整批跑完。
     list / read 必须一次只跑一张——AI 要靠这一张的结果才能决定下一步，
     攒批发回去它就没法边看边改了。 */
  function 是写类(card) {
    var a = card.getAttribute('data-sftp-act');
    return a === 'write' || a === 'patch' || a === 'delete';
  }

  /* 把一批卡片的结果合成一条回执。
     不逐张发的原因：每次 fillSftpResult 都会触发一轮新请求，
     一条回复改五个文件就要连打五轮，上下文被同一批改动的碎片灌满。 */
  function 合成回执(结果表, 跳过表) {
    var 文 = '这一批共 ' + 结果表.length + ' 个文件操作，已直接落到服务器上，'
           + '逐个结果如下：\n\n';
    结果表.forEach(function (x, i) {
      文 += (i + 1) + '. ' + (x.成功 ? '✓ ' : '✗ ')
          + (x.路径 || '(未知路径)') + '\n'
          + '   ' + String(x.正文).replace(/\n/g, '\n   ') + '\n';
    });
    if (跳过表.length) {
      文 += '\n上面有一步失败了，为避免在错误状态上继续叠改动，'
          + '后面这 ' + 跳过表.length + ' 个文件<strong>没有执行</strong>：\n';
      跳过表.forEach(function (p, i) {
        文 += (i + 1) + '. ' + (p || '(未知路径)') + '\n';
      });
      文 += '\n请先弄清失败那一步的原因，修正后重新提交剩下的改动。'
          + '不要假设它们已经写进去了。';
    }
    return 文;
  }

  /**
   * 供 chat.js 调用：自动执行这条回复里的 SFTP 卡片。
   *
   * 写类动作（write / patch / delete）串行整批执行：AI 一条回复里改了几个文件
   * 就都落地，跑完合成一条回执发回去。一旦有一张失败就停下，剩下的不执行并在
   * 回执里点名，避免在错误状态上叠改动。
   * list / read 仍一次只跑一张，理由见 是写类() 的注释。
   */
  window.sftpAutoRun = function (容器) {
    if (!容器) { return; }
    var 全部 = 容器.querySelectorAll('.sftp-card-op:not(.pend):not([data-sftp-done="1"])');
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

    // 从头连着取写类卡片。碰到 list / read 就断开，留给下一轮单独跑
    var 批 = [];
    for (var j = 0; j < 卡.length && 是写类(卡[j]); j++) {
      批.push(卡[j]);
    }

    // 只有一张就不必攒批，直接走单张回执，措辞更贴切
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
          // 失败即停。剩下的标记成已处理，避免看门狗之后又把它们捡起来跑
          while (序 < 批.length) {
            var 剩 = 批[序++];
            剩.setAttribute('data-sftp-done', '1');
            setState(剩, '已跳过', 'warn');
            var d剩 = 解析(剩.getAttribute('data-sftp-act'),
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
      if (window.fillSftpResult) {
        window.fillSftpResult('batch', '', 合成回执(结果表, 跳过表), 全成);
      }
    }

    下一张();
  };

  // 还原按钮保留：它是出问题后把远端文件退回改动前的唯一入口，
  // 不属于「执行前的确认」，去掉就没法回滚了。
  document.addEventListener('click', function (e) {
    var undo = e.target.closest('[data-sftp-undo]');
    if (undo) { 还原(undo); }
  });
})();
