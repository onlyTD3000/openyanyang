/**
 * 对话里的 SSH 命令卡片。
 *
 * AI 用 ```ssh-exec 代码块提出命令，这里渲染成卡片。
 * 按客户要求，命令一律自动执行，不征询确认，也没有次数上限和安全策略拦截。
 * 历史消息里的卡片不会自动执行，保留手动按钮供用户重跑。
 */
(function () {
  'use strict';

  var seq = 0;

  /* 自动执行不再计数限流，这个函数保留是因为 chat.js 在用户发消息时会调它。 */
  window.sshResetAuto = function () {};

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * 把 ssh-exec 块的内容解析成卡片 HTML。
   * 块内格式：host: 编号 / cmd: 命令
   * 注意：传进来的 raw 必须是未转义的原始命令，否则 &quot; 之类会混进真实命令。
   */
  window.sshCard = function (raw) {
    var host = '', cmd = [];
    String(raw).split('\n').forEach(function (line) {
      var mh = line.match(/^\s*host\s*:\s*(\d+)/i);
      var mc = line.match(/^\s*cmd\s*:\s*([\s\S]*)$/i);
      if (mh) { host = mh[1]; return; }
      if (mc) { cmd.push(mc[1]); return; }
      // cmd 后面的续行也算命令内容
      if (cmd.length && line.trim() !== '') { cmd.push(line); }
    });
    var command = cmd.join('\n').trim();
    if (!host || !command) {
      // 格式不对就按普通代码块显示，不做任何执行入口
      return '<pre><code>' + esc(raw) + '</code></pre>';
    }
    var id = 'sshc' + (++seq);
    return '<div class="ssh-card" id="' + id + '" data-host="' + esc(host) + '">' +
      '<div class="ssh-card-head">' +
        '<span class="ssh-ico" aria-hidden="true">▸</span>' +
        '<span class="ssh-title">在服务器上执行命令</span>' +
      '</div>' +
      '<pre class="ssh-cmd"><code>' + esc(command) + '</code></pre>' +
      '<div class="ssh-card-foot">' +
        '<span class="ssh-state">待执行</span>' +
      '</div>' +
      '<pre class="ssh-out" hidden tabindex="0"></pre>' +
      '<textarea class="ssh-raw" hidden aria-hidden="true">' + esc(command) + '</textarea>' +
      '</div>';
  };

  function post(data) {
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/api/ssh_run.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { error: '服务端返回异常' }; }); });
  }

  function setState(card, txt, cls) {
    var el = card.querySelector('.ssh-state');
    if (!el) { return; }
    el.textContent = txt;
    el.className = 'ssh-state' + (cls ? ' ' + cls : '');
  }

  function showOut(card, txt) {
    var box = card.querySelector('.ssh-out');
    if (!box) { return; }
    box.textContent = txt;
    box.hidden = false;
  }

  /* 累计输出的上限，与主进程 本地ssh.js 的 输出上限 一致。
     那边是每次读取截断，这里是累计截断：长任务读几十轮，
     不设上限的话 DOM 里会堆到几百兆，界面直接卡死。 */
  var 输出上限 = 40000;
  /* 直连模式下把新增输出追加到卡片，而不是整体替换。
     job 机制每轮只回增量（靠 from/next 偏移），替换会把前面的内容抹掉。 */
  function 追加输出(card, 文) {
    var box = card.querySelector('.ssh-out');
    if (!box) { return; }
    box.textContent += 文;
    if (box.textContent.length > 输出上限) {
      box.textContent = '…（前段输出已省略）\n' +
        box.textContent.slice(-输出上限);
    }
    box.hidden = false;
    box.scrollTop = box.scrollHeight;
  }
  /**
   * 执行一张卡片上的命令。
   *
   * 两条路径：
   *   客户端 —— window.后端.本地SSH起 存在，走本地直连。命令在目标机后台跑，
   *             轮询读进度，输出实时滚，长任务不受 HTTP 生命周期限制。
   *   网页端 —— 该方法不存在，回落到原来的 /api/ssh_run.php 单次请求。
   *
   * 这个分支是客户端专属逻辑。从网页端覆盖本文件时要保留它，
   * 否则客户端会退回服务端代连，目标机日志里又会出现本站源站 IP。
   */
  function 执行(card) {
    if (card.getAttribute('data-ssh-done') === '1') { return; }
    card.setAttribute('data-ssh-done', '1');
    var rawEl = card.querySelector('.ssh-raw');
    if (!rawEl) { return; }
    var command = rawEl.value;
    var hostId  = card.getAttribute('data-host');
    setState(card, '执行中…', '');
    if (window.后端 && typeof window.后端.本地SSH起 === 'function') {
      return 直连执行(card, hostId, command);
    }
    return 代连执行(card, hostId, command);
  }
  /* ---- 客户端：本地直连 ---- */
  function 直连执行(card, hostId, command) {
    var 密钥 = (window.态 && window.态.密钥) || '';
    /* 回执只能发一次：chat.js 拿到 fillSshResult 才解锁发送按钮，
       重复发会让 AI 收到两份结果。 */
    var 已回执 = false;
    function 回执(文, 码) {
      if (已回执) { return; }
      已回执 = true;
      if (window.fillSshResult) { window.fillSshResult(command, 文, 码); }
    }
    function 失败(因) {
      setState(card, '执行失败', 'bad');
      追加输出(card, 因);
      回执('执行失败：' + 因, -1);
    }
    return window.后端.本地SSH起(hostId, command, 密钥).then(function (r) {
      if (!r || !r.ok) { 失败((r && r.error) || '起任务失败'); return; }
      var job = r.job, 偏移 = 0, 空转 = 0;
      /* 轮询间隔从 600ms 递增到 2s：刚起步时用户最想看到反应，
         跑久了就没必要那么勤，省 SSH 往返。 */
      var 间隔 = 600;
      function 读一轮() {
        window.后端.本地SSH读(hostId, job, 偏移, 密钥).then(function (x) {
          if (!x || !x.ok) { 失败((x && x.error) || '读进度失败'); return; }
          if (x.out) { 追加输出(card, x.out); 空转 = 0; }
          else { 空转++; }
          if (typeof x.next === 'number') { 偏移 = x.next; }
          if (x.done) {
            var 码 = Number(x.exit);
            var 成 = 码 === 0;
            setState(card, 成 ? '执行完成' : ('退出码 ' + 码), 成 ? 'good' : 'warn');
            var box = card.querySelector('.ssh-out');
            var 全文 = box ? box.textContent : '';
            if (全文 === '') { 追加输出(card, '（命令无输出）'); 全文 = '（命令无输出）'; }
            回执(全文, 码);
            return;
          }
          /* 进程没了但 .rc 还没落地，多等几轮；一直等不到就当它异常终止，
             不能无限轮询下去，否则卡片永远停在执行中，发送按钮一直锁着。 */
          if (!x.alive && 空转 > 8) {
            setState(card, '任务已终止', 'warn');
            var b2 = card.querySelector('.ssh-out');
            回执((b2 ? b2.textContent : '') || '（任务已终止，未拿到退出码）', -1);
            return;
          }
          间隔 = Math.min(间隔 + 200, 2000);
          setTimeout(读一轮, 间隔);
        }).catch(function (e) {
          失败('读进度出错：' + ((e && e.message) || e));
        });
      }
      setTimeout(读一轮, 300);
    }).catch(function (e) {
      失败('直连出错：' + ((e && e.message) || e));
    });
  }
  /* ---- 网页端：服务端代连，一次请求走完 ---- */
  function 代连执行(card, hostId, command) {
    return post({
      act: 'run', host_id: hostId, command: command,
      conv_id: (window.currentConvId || 0)
    }).then(function (x) {
      if (x && x.ok) {
        var okRun = Number(x.exit) === 0;
        setState(card, okRun ? ('执行完成（' + x.ms + ' ms）')
                             : ('退出码 ' + x.exit + '（' + x.ms + ' ms）'),
                 okRun ? 'good' : 'warn');
        showOut(card, x.out === '' ? '（命令无输出）' : x.out);
        if (window.fillSshResult) {
          window.fillSshResult(command, x.out, x.exit);
        }
      } else {
        setState(card, '执行失败', 'bad');
        var 错 = (x && x.error) || '未知错误';
        showOut(card, 错);
        if (window.fillSshResult) {
          window.fillSshResult(command, '执行失败：' + 错, -1);
        }
      }
    }).catch(function (e) {
      setState(card, '执行失败', 'bad');
      var 因 = '请求发送失败：' + ((e && e.message) || e);
      showOut(card, 因);
      if (window.fillSshResult) { window.fillSshResult(command, 因, -1); }
    });
  }

  /**
   * 供 chat.js 在一条回复流式结束后调用：自动执行这条回复里的命令卡片。
   * 一次只跑第一张未执行的卡片，AI 的提示词也要求一次只提一条。
   *
   * 这里不按命令内容做任何拦截或分级。与 inc/ssh_guard.php 的策略一致：
   * 客户自己的服务器由客户自己负责，想执行什么就执行什么，平台不插手，
   * 也不因为命令「看起来危险」就多要一次确认。
   *
   * 需要停下来征求用户意见时，靠 AI 自己在回复里打 [[等待用户确认]] 标记
   * （见 chat.js 的等待闸）。那是 AI 按语境判断的结果，不是按命令关键词硬判的。
   *
   * @param 容器 该条回复的气泡元素
   */
  window.sshAutoRun = function (容器) {
    if (!容器) { return; }
    var card = 容器.querySelector('.ssh-card:not(.pend):not([data-ssh-done="1"])');
    if (!card) { return; }
    if (!card.querySelector('.ssh-raw')) { return; }
    执行(card);
  };
})();
