/* 对话前端逻辑：SSE 流式渲染 + 会话管理 */
(function () {
  'use strict';

  var convId = 0;
  var busy = false;

  /* 上下文条数（会话级，已持久化到 conversations.context_limit）：
     0 = 跟随模型默认（后端回落 models.max_context），2..60 = 自定义。
     设置随会话保存：切换、关闭页面、重新打开都会恢复；
     新建会话默认跟随模型默认，可单独设置。 */
  var ctxLimit = 0;

  /* 把上下文条数设为指定值并同步按钮和输入框：
     切换/打开会话时按库里的设置调用，面板应用后也走这里。 */
  function setCtxLimit(v) {
    ctxLimit = v > 0 ? v : 0;
    var num = document.getElementById('ctxNum');
    if (num) { num.value = ctxLimit > 0 ? String(ctxLimit) : ''; }
    refreshCtxBtn();
  }

  /* 重置上下文条数为「跟随默认」：清数值、清输入框、还原按钮、收弹层。
     清空聊天区（会话已不存在）时调用。元素缺失时安全跳过。 */
  function resetCtx() {
    ctxLimit = 0;
    var num = document.getElementById('ctxNum');
    var pop = document.getElementById('ctxPop');
    var btn = document.getElementById('btnCtx');
    if (num) { num.value = ''; }
    if (pop) { pop.hidden = true; }
    if (btn) {
      btn.textContent = '⚙ 上下文';
      btn.setAttribute('aria-expanded', 'false');
      btn.title = '上下文条数：跟随模型默认，点击设置';
    }
  }

  /* 按当前 ctxLimit 刷新按钮文字：设过值就把条数显示在按钮上，
     让用户一眼看出这条对话用的是自定义窗口还是模型默认。 */
  function refreshCtxBtn() {
    var btn = document.getElementById('btnCtx');
    if (!btn) { return; }
    btn.textContent = ctxLimit > 0 ? ('⚙ 上下文 ' + ctxLimit) : '⚙ 上下文';
    btn.title = ctxLimit > 0
      ? '上下文条数：' + ctxLimit + ' 条（会话级，随会话保存）'
      : '上下文条数：跟随模型默认，点击设置';
  }

  /* 把上下文条数存进数据库（会话级）。cid<=0 表示新对话还没落库：
     值先留在内存，首条消息建会话时 chat.php 会随 INSERT 一并写入。 */
  function saveCtxLimit(cid, v) {
    if (cid <= 0) { return; }
    var fd = new FormData();
    // 写操作都要过 csrf_check()，漏带令牌会被 419 拦下，设置落不了库
    fd.append('csrf', window.CSRF);
    fd.append('conv_id', String(cid));
    fd.append('context_limit', String(v));
    fetch('/api/conv.php?act=set_context_limit', {
      method: 'POST', credentials: 'same-origin', body: fd
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.error) { showModelTip('上下文设置保存失败：' + j.error, true); }
    }).catch(function () { showModelTip('上下文设置保存失败，请重试', true); });
  }
  /* 卡片（命令 / SFTP / 仓 / 工作区 / PPT）正在执行。
     它和 busy 是两个阶段：busy 是 AI 在吐字，这个是工具在跑。
     一轮任务里两者交替出现，中间那段空档若让按钮弹回「发送」，
     用户会以为这轮完了，敲字回车就把消息插进命令中间，输入框也跟着被清掉。
     所以按钮状态由两者共同决定，整轮全程锁住。 */
  var 工具执行中 = false;
  var 工具看门狗 = 0;
  /* 用户点了暂停，整条链作废。
     暂停不只要停住 AI 当前这股流，还得拦住后面那几步：
     已经排进队列的工具回执、工具跑完准备自动发的那一轮，都不能再续上，
     否则按钮弹回「发送」了，任务却还在自己往下跑。 */
  var 链已取消 = false;

  /* 工具开始跑：锁住按钮。带看门狗兜底，卡片万一不回执也不会把按钮锁死。
     服务端单条命令上限 240 秒，这里留到 300 秒。 */
  function 工具开始() {
    工具执行中 = true;
    if (工具看门狗) { clearTimeout(工具看门狗); }
    工具看门狗 = setTimeout(function () {
      工具看门狗 = 0;
      工具执行中 = false;
      刷新按钮();
    }, 300000);
    刷新按钮();
  }

  /* 工具还在跑，把看门狗的计时重新拨回去。
     多个命令卡片串行执行时，回执只在最后一张跑完才发，中间不经过 工具结束。
     若不续期，三条各跑两分钟就会在第五分钟被看门狗误判成卡死、按钮提前解锁，
     用户此时敲字就插进了命令中间。每张卡片收尾时调一次这个即可。 */
  window.工具心跳 = function () {
    if (!工具执行中) { return; }
    工具开始();   // 内部会清掉旧的定时器再重设，等效于续期
  };

  /* 工具跑完：解锁。紧接着的回执会把 busy 重新置上，
     两次刷新之间没有重绘间隙，按钮不会闪。 */
  function 工具结束() {
    if (工具看门狗) { clearTimeout(工具看门狗); 工具看门狗 = 0; }
    if (!工具执行中) { return; }
    工具执行中 = false;
    刷新按钮();
  }
  /* ---------- 等待闸 ----------
     AI 用这个标记表示「这一步要用户拍板，先别往下跑」。
     和 inc/tool_results.php 里的常量、各 *_prompt.php 里教给 AI 的写法必须一致，
     三处对不上等待闸就会静默失效（AI 打了标记但程序不认，照旧自动执行）。 */
  var 等待标记 = '[[等待用户确认]]';

  /* 这轮回复里有没有等待标记。
     只在代码块之外找：AI 讲解这个机制本身时会把标记写进示例代码块里，
     那种情况不该真的停下来。做法是先把成对的 ``` 围栏内容剔掉再搜。
     落单的 ``` （流被截断时会出现）后面的内容一并当代码块处理，宁可漏判不误判——
     漏判只是照旧自动执行，误判会让正常任务莫名停住。 */
  function 等待用户确认(全文) {
    if (!全文) { return false; }
    var 净 = String(全文).replace(/```[\s\S]*?```/g, '')   // 成对围栏
                        .replace(/```[\s\S]*$/, '');        // 落单围栏到结尾
    return 净.indexOf(等待标记) !== -1;
  }

  /* 停下来时给用户一句话，说明为什么卡片没自动跑。
     不弹窗：这是正常流程不是错误，弹窗打断感太强。复用底部状态行。 */
  function 提示等待确认() {
    showModelTip('已停下等你确认，卡片可自行点击执行');
  }

  /* 本轮发出去的用户原文。点「暂停」后回填进输入框，方便改一改重新发。 */
  var 本轮原文 = '';
  // 队列里存 {txt, kind}：kind 要一路带到后端，决定回执落哪个 txt 文件的分类字段
  var resultQueue = [];
  /* 等待闸拦下本轮后，这个置 true，把队列里压着的回执一并冻住。
     不清空队列：用户确认后手动点卡片，那条链还得接着走，结果不该丢。
     手动发送时解冻——用户开口了，等待的理由就消失了。 */
  var 等待确认中 = false;

  function processQueue() {
    // 等待闸期间不发：闸只拦了「本轮结束时的自动执行」，而队列是另一条路，
    // 前一轮排进来的回执会在这里被发出去，等于绕开闸把循环续上。
    if (busy || 等待确认中 || 链已取消 || !resultQueue.length) return;
    var 项 = resultQueue.shift();
    send(true, 项.txt, 项.kind);
  }

  /* ---------- 当前会话记忆（刷新后不丢） ----------
     按用户 ID 分键，同一浏览器切换账号不会串会话。
     只存一个 ID，真正的消息内容仍然只从服务端按 user_id 取。 */
  var LAST_KEY = 'chat_last_conv_' + (window.UID || 0);

  function saveLastConv(id) {
    try {
      if (id > 0) {
        localStorage.setItem(LAST_KEY, String(id));
      } else {
        localStorage.removeItem(LAST_KEY);
      }
    } catch (e) { /* 隐私模式下不可用，忽略即可 */ }
  }

  function readLastConv() {
    try {
      return Number(localStorage.getItem(LAST_KEY) || 0) || 0;
    } catch (e) { return 0; }
  }

  /* ---------- 生成任务记忆（刷新后能接着看）----------
     后端 chat.php 带 ignore_user_abort，浏览器断开它照样跑完并按秒把增量写进 chat_runs。
     这里存的是「哪个会话的哪一轮还没收尾，前端已经显示到第几个字」，
     刷新回来后靠这三个值调 api/chat_resume.php 从断点续上。 */
  var RUN_KEY = 'chat_run_' + (window.UID || 0);

  /* 当前正在流式接收的那一轮。手机切后台再回来时要用它判断流还活着没有。
     代 是版本号：切后台重连后旧流的回调可能姗姗来迟，
     靠比对版本号把过期回调全部丢掉，否则会往已经删掉的气泡里写内容。 */
  var 当前流 = null;
  var 流代 = 0;

  function saveRun(conv, run, from) {
    try {
      localStorage.setItem(RUN_KEY, JSON.stringify({ conv: conv, run: run, from: from || 0 }));
    } catch (e) { /* 隐私模式忽略 */ }
  }

  function readRun() {
    try {
      var o = JSON.parse(localStorage.getItem(RUN_KEY) || 'null');
      return (o && o.run > 0) ? o : null;
    } catch (e) { return null; }
  }

  function clearRun() {
    try { localStorage.removeItem(RUN_KEY); } catch (e) { /* 同上 */ }
  }

  /* ---------- 流式内容本地缓存（中断兜底）----------
     后端虽然有 ignore_user_abort + chat_runs 增量存储，但仍有边缘场景导致内容丢失：
     后端进程被杀、chat_runs 记录被清理、resume 接口异常等。
     这里把流式过程中的已显示文本额外存一份到 localStorage，刷新后若服务端没有这条消息，
     就把它显示出来并标注「未完成」，作为最后一道兜底。 */
  var PARTIAL_KEY = 'chat_partial_' + (window.UID || 0);
  var partialTimer = 0;

  function savePartial(conv, text) {
    try {
      if (!text) { return; }
      localStorage.setItem(PARTIAL_KEY, JSON.stringify({
        conv: conv, text: text, time: Date.now()
      }));
    } catch (e) { /* 隐私模式或空间不足，忽略 */ }
  }

  function readPartial() {
    try {
      var o = JSON.parse(localStorage.getItem(PARTIAL_KEY) || 'null');
      return (o && o.text) ? o : null;
    } catch (e) { return null; }
  }

  function clearPartial() {
    try { localStorage.removeItem(PARTIAL_KEY); } catch (e) { /* 同上 */ }
    stopPartialTimer();
  }

  /* 定时把当前流式内容写入 localStorage，每秒一次。
     读 当前流.holder.__rawText 拿到最新的累积文本，不需要侵入 delta 回调。 */
  function startPartialTimer() {
    stopPartialTimer();
    partialTimer = setInterval(function () {
      if (当前流 && 当前流.holder && 当前流.holder.__rawText) {
        savePartial(当前流.conv, 当前流.holder.__rawText);
      }
    }, 1000);
  }

  function stopPartialTimer() {
    if (partialTimer) { clearInterval(partialTimer); partialTimer = 0; }
  }

  /* 刷新后检查 localStorage 里有没有中断时缓存的内容。
     只在「最后一条消息是用户发的」时才显示——说明 AI 的回复没落库。
     如果最后一条是 assistant 消息，说明后端已经保存了（即使不完整），不需要兜底。 */
  function 检查本地缓存(conv) {
    // resume 正在跑，不需要兜底
    if (busy) { return; }
    var p = readPartial();
    if (!p || Number(p.conv) !== Number(conv)) { return; }
    // 超过 30 分钟的缓存不显示
    if (Date.now() - (p.time || 0) > 30 * 60 * 1000) {
      clearPartial();
      return;
    }
    var text = p.text || '';
    if (text.length < 5) { clearPartial(); return; }

    // 检查最后一条消息是不是 assistant——是的话说明内容已落库
    var 所有消息 = $inner.querySelectorAll('.msg');
    var 最后一条 = 所有消息.length ? 所有消息[所有消息.length - 1] : null;
    if (最后一条 && 最后一条.classList.contains('assistant')) {
      // 进一步比对：如果最后一条 assistant 消息的原文尾部和缓存尾部吻合，
      // 说明是同一条，已落库。清掉缓存即可。
      var 已有原文 = 最后一条.__rawText || '';
      var 尾部 = text.slice(-80).trim();
      if (尾部 && 已有原文.indexOf(尾部) !== -1) {
        clearPartial();
        return;
      }
      // 原文对不上但确实是 assistant 消息：可能是另一条回复，也清掉
      clearPartial();
      return;
    }

    // 最后一条是 user 消息（或没有任何消息），说明 AI 回复没落库，显示缓存内容
    var holder = addMsg('assistant', '', '');
    holder.__rawText = text;   // 供引用按钮读取
    var bubble = holder.querySelector('.bubble');
    写气泡(bubble,
      '<div class="text-err" style="margin-bottom:8px;padding:6px 10px;background:#fff3cd;border-radius:4px">' +
      '⚠ 此回答因中断未完成，以下为浏览器缓存的部分内容</div>' +
      render(text)
    );
    highlightCodeBlocks(bubble);
    scrollDown();

    // 关闭按钮：用户可以手动清除这条缓存消息
    var closeBtn = document.createElement('button');
    closeBtn.className = 'btn';
    closeBtn.type = 'button';
    closeBtn.style.cssText = 'margin-top:8px;font-size:12px;color:#999;padding:2px 10px';
    closeBtn.textContent = '清除缓存内容';
    closeBtn.onclick = function () {
      clearPartial();
      holder.remove();
    };
    holder.querySelector('.body').appendChild(closeBtn);
  }

  /* ---------- 模型选择记忆 ----------
     会话已有模型时以服务端 conversations.model_id 为准（跨设备一致）；
     新对话没有归属会话，用本地记住的上次选择，避免每次回弹到第一项。 */
  var MODEL_KEY = 'chat_last_model_' + (window.UID || 0);
  function saveLastModel(id) {
    try {
      if (id > 0) { localStorage.setItem(MODEL_KEY, String(id)); }
    } catch (e) { /* 隐私模式忽略 */ }
  }

  function readLastModel() {
    try {
      return Number(localStorage.getItem(MODEL_KEY) || 0) || 0;
    } catch (e) { return 0; }
  }

  /* 把下拉框设成指定模型 id；该 id 不在选项里（已下架）则不动，返回 false */
  function applyModel(id) {
    id = Number(id) || 0;
    if (!$modelSel || id <= 0) { return false; }
    var hit = false;
    for (var i = 0; i < $modelSel.options.length; i++) {
      if (Number($modelSel.options[i].value) === id) {
        $modelSel.selectedIndex = i;
        hit = true;
        break;
      }
    }
    if (hit) { updatePrice(); syncVision(); }
    return hit;
  }

  /* 把当前选择写回服务端（会话已存在时）+ 本地记忆 */
  function persistModel() {
    if (!$modelSel || !$modelSel.value) { return; }
    var mid = Number($modelSel.value) || 0;
    if (mid <= 0) { return; }
    saveLastModel(mid);
    if (convId <= 0) { return; }   // 新对话还没落库，发送时会带上 model_id
    var fd = new FormData();
    fd.append('act', 'set_model');
    fd.append('conv_id', String(convId));
    fd.append('model_id', String(mid));
    fd.append('csrf', window.CSRF);
    fetch('/api/conv.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.error) { showModelTip('模型未保存：' + j.error, true); }
        else { showModelTip('已切换，后续按此模型计费'); }
      })
      .catch(function () { showModelTip('模型未保存：网络错误', true); });
  }

  /* 切换模型的轻量提示，2 秒后淡出，不打断输入 */
  var modelTipTimer = 0;
  // statusTip 平时显示免责文案，提示结束后要还原回去，不能清空
  var statusDefault = $status ? $status.textContent : '';
  function showModelTip(msg, isErr) {
    if (!$status) { return; }
    $status.textContent = msg;
    $status.style.color = isErr ? '#d93025' : '';
    if (modelTipTimer) { clearTimeout(modelTipTimer); }
    modelTipTimer = setTimeout(function () {
      $status.textContent = statusDefault;
      $status.style.color = '';
    }, 2500);
  }

  /* 任务完成提醒。三条通道各自独立开关，用户按自己的环境挑：
       声音   —— Web Audio 现场合成，不用音频文件，省一次请求也不怕文件丢
       桌面通知 —— 切到别的窗口、最小化浏览器都能看到，需要授权
       标题提醒 —— 标签页标题闪烁，零权限，挂后台等结果时最有效

     为什么要三条：网页拿不到系统音量控制权（那是原生应用才有的 audio ducking /
     audio focus），压不低用户正在放的音乐。所以没法靠「压低别的声音」被听见，
     只能靠「换个通道」和「让声音本身更穿透」。

     浏览器要求音频必须由用户交互后才能播，所以 AudioContext 是懒创建的：
     第一次点发送时就已经有交互记录了，到回答结束时能正常出声。
     所有设置按用户存 localStorage，同机换账号互不影响。 */
  var 音频上下文 = null;

  /* 提示音设置按用户隔离：同一台电脑上换账号登录，各自的开关和音量互不影响。
     沿用 window.UID（跟「上次打开的会话」同一套路子）。
     UID 缺失时退回 0，不至于报错。 */
  function 声键(名) { return 'chat_sound_' + 名 + '_' + (window.UID || 0); }

  /** 是否开启。默认开。 */
  function 声开着() { return localStorage.getItem(声键('on')) !== 'off'; }

  /** 音量百分比（5~100），默认 45。存的是百分比，读出来才换算成增益。 */
  function 声音量() {
    var v = parseInt(localStorage.getItem(声键('vol')), 10);
    if (!isFinite(v)) { v = 45; }
    return Math.min(100, Math.max(5, v));
  }

  /* 百分比换成 Web Audio 的增益峰值。
     上限取 0.9：再高指数衰减的起点会贴到削波边缘，出现失真。
     所以 100% 对应 0.9 而不是 1.0。 */
  function 声增益() { return 声音量() / 100 * 0.9; }

  /**
   * 响一声。
   * @param {number} [试听音量] 传了就用这个百分比试听，并忽略开关状态
   *        （面板里拖滑块要能实时听，关闭状态下点「试听」也得出声）。
   */
  function 提示音(试听音量) {
    if (试听音量 == null && !声开着()) { return; }
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { return; }
      if (!音频上下文) { 音频上下文 = new AC(); }

      // 真正发声。必须等上下文处于 running 再排音符：
      // 挂起状态下 currentTime 是冻结的，照它算出的时间点等上下文恢复后
      // 已经成了过去时间，音符会被直接丢掉——听起来就是「任务完成了却没声音」。
      var 发声 = function () {
        var 起 = 音频上下文.currentTime;
        // 拖动滑块时传入临时百分比，实时听效果；不传就用已保存的值
        var 峰值 = 试听音量 == null ? 声增益()
                 : Math.min(100, Math.max(5, 试听音量)) / 100 * 0.9;
        // 三声上升的短音。频率选在 2.4~3.4kHz：
        //   1. 人耳灵敏度在 2~5kHz 是峰值（警笛、婴儿哭声都在这个区间）；
        //   2. 音乐能量主要集中在 200~4000Hz 的低中段，这个高段相对空，不容易被盖住。
        // 原来的 880/660Hz 正好埋在人声和主要乐器里，放歌时最容易听不见。
        // 节奏上用急促三连而非两声叮咚：大脑对「模式变化」比对「音量变化」敏感得多。
        [[2400, 0], [2900, 0.11], [3400, 0.22]].forEach(function (对) {
          var 振 = 音频上下文.createOscillator();
          var 音量 = 音频上下文.createGain();
          // 三角波比正弦多一点奇次谐波，同音量下更容易听见，
          // 又不像方波那样发尖。外放或有背景音时差别明显。
          振.type = 'triangle';
          振.frequency.value = 对[0];
          振.connect(音量);
          音量.connect(音频上下文.destination);
          var t = 起 + 对[1];
          // 用指数衰减收尾，直接停会有"啪"的爆音。
          // 起音压到 8ms：越短越"脆"，穿透力越好。
          音量.gain.setValueAtTime(0.0001, t);
          音量.gain.exponentialRampToValueAtTime(峰值, t + 0.008);
          音量.gain.exponentialRampToValueAtTime(0.0001, t + 0.1);
          振.start(t);
          振.stop(t + 0.12);
        });
      };

      // resume() 是异步的，返回 Promise。等它 resolve 之后再排音符。
      // 笔记本合盖、切到别的标签页、或者浏览器为省电自动挂起音频上下文之后，
      // 长任务跑完那一刻上下文往往正处于 suspended，这条路径才是常态。
      if (音频上下文.state === 'suspended' && 音频上下文.resume) {
        var p = 音频上下文.resume();
        if (p && typeof p.then === 'function') { p.then(发声).catch(function () {}); }
        else { 发声(); }
      } else {
        发声();
      }
    } catch (e) { /* 出声失败不影响正事 */ }
  }

  /** 桌面通知是否开启。默认关：要授权，不能默认替用户决定。 */
  function 通知开着() { return localStorage.getItem(声键('notify')) === 'on'; }

  /** 标题提醒是否开启。默认开：零权限、无干扰，没有不开的理由。 */
  function 标题开着() { return localStorage.getItem(声键('title')) !== 'off'; }

  /* 桌面通知。只在页面不可见时弹——用户正看着屏幕就没必要再弹一个，
     反而挡视线。授权被拒时静默跳过，不反复骚扰。 */
  function 桌面通知(正文) {
    if (!通知开着()) { return; }
    if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
    if (document.visibilityState === 'visible') { return; }
    try {
      var n = new Notification('回答完成', {
        body: (正文 || '').slice(0, 120) || '任务已结束',
        // tag 让同一个标签的通知互相覆盖，长任务连着完成几个不会堆满通知中心
        tag: 'chat-done-' + (window.UID || 0),
        renotify: true
      });
      n.onclick = function () {
        window.focus();
        n.close();
      };
      // 8 秒自动关。部分系统不自动收，留着占地方
      setTimeout(function () { try { n.close(); } catch (e) {} }, 8000);
    } catch (e) { /* 通知失败不影响正事 */ }
  }

  /* 标题闪烁。原标题先存下来，闪烁期间和提醒文案交替；
     用户切回页面（visibilitychange）就立刻停并还原。

     只在页面不可见时启动：正看着屏幕时标题闪烁是纯干扰。 */
  var 原标题 = document.title;
  var 闪计时 = null;
  function 停止闪烁() {
    if (闪计时) { clearInterval(闪计时); 闪计时 = null; }
    document.title = 原标题;
  }
  function 标题提醒() {
    if (!标题开着()) { return; }
    if (document.visibilityState === 'visible') { return; }
    停止闪烁();
    // 每次启动都重新读一遍：会话切换时标题会变，不能用最初那次的值
    原标题 = document.title.replace(/^✅ 已完成 · /, '');
    var 亮 = false;
    闪计时 = setInterval(function () {
      亮 = !亮;
      document.title = 亮 ? '✅ 已完成 · ' + 原标题 : 原标题;
    }, 1000);
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { 停止闪烁(); }
  });

  /* 三条通道统一入口。原来散在各处调 提示音()，现在都改调这个，
     省得每加一条通道就要翻一遍调用点。 */
  function 完成提醒(正文) {
    提示音();
    桌面通知(正文);
    标题提醒();
  }

  var $msgs = document.getElementById('msgs');
  var $inner = document.getElementById('msgsInner');
  var $input = document.getElementById('input');
  var $send = document.getElementById('btnSend');
  var $stop = document.getElementById('btnStop');
  var $modelSel = document.getElementById('modelSel');
  // 侧栏已改为项目结构，由 project.js 负责渲染；这里只保留兼容引用
  var $convList = document.getElementById('projList');
  var $status = document.getElementById('statusTip');
  var $priceTip = document.getElementById('priceTip');
  var $fileInput = document.getElementById('fileInput');
  var $btnImg = document.getElementById('btnImg');
  var $imgTray = document.getElementById('imgTray');

  /* 待发送的图片：{id, url} */
  var pending = [];
  var uploading = 0;

  /* ---- 引用回复 ---- */
  var quoteText = '';   // 待发送的引用原文
  var $quoteBar = document.getElementById('quoteBar');
  var $quoteBarText = document.getElementById('quoteBarText');
  var $quoteBarClose = document.getElementById('quoteBarClose');

  /* 设置引用：截取前 200 字，显示预览条 */
  function setQuote(text) {
    var t = String(text || '');
    // 去掉已有的引用标记，避免嵌套引用
    t = t.replace(/^\[\[QUOTE\]\][\s\S]*?\[\[\/QUOTE\]\]\s*/i, '').trim();
    quoteText = t.slice(0, 200);
    if (quoteText && $quoteBar) {
      $quoteBarText.textContent = quoteText.length >= 200 ? quoteText + '…' : quoteText;
      $quoteBar.hidden = false;
    }
    if ($input) { $input.focus(); }
  }
  function clearQuote() {
    quoteText = '';
    if ($quoteBar) { $quoteBar.hidden = true; }
  }
  if ($quoteBarClose) {
    $quoteBarClose.addEventListener('click', clearQuote);
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* 极简 markdown：代码块 / 行内代码 / 加粗。
     其中 kiro-ssh 代码块会被渲染成需用户确认的命令卡片。 */
  /**
   * 把正文开头的思考前言用折叠标记包起来。
   * 只在 t 确实以 prefix 开头时才动，否则原样返回，避免错位。
   */
  /* 把正文里裸露的系统回执标记折叠成可展开块。
     正常情况这里没东西可折：工具回执走 tool_kind 参数落进 toolresults 的 txt，
     不进 messages 表，压根不经过消息流渲染。会命中的只有一种情况——AI 在自己
     回复的正文里引用了回执原文（排查问题时粘贴命令输出就会这样），标记会连着
     user 角色名一起显示在气泡里。
     必须按代码围栏切分、只处理块外片段：AI 的回复里常有脚本以字符串形式带着
     这对标记（改这段逻辑本身的补丁脚本就是），不切分会把代码块从中间剖开，
     围栏配对一乱后面整段渲染全崩。这个坑踩过一次，别再踩。
     调用点在 esc() 之后、代码块渲染之前，那时围栏还是原样的三个反引号。
     源码里用 \x60 写反引号：这文件要经 shell heredoc 传输，裸反引号会被
     当成命令替换吃掉。 */
  function 折回执(out) {
    var 围栏 = '\x60\x60\x60';
    var 闭合 = /(?:^|\n)[ \t]*(?:user|assistant)?[ \t]*&lt;&lt;&lt;系统回执\|非用户发言&gt;&gt;&gt;\n?([\s\S]*?)\n?&lt;&lt;&lt;系统回执结束&gt;&gt;&gt;\n*/g;
    var 未闭 = /(?:^|\n)[ \t]*(?:user|assistant)?[ \t]*&lt;&lt;&lt;系统回执\|非用户发言&gt;&gt;&gt;\n?([\s\S]*)$/;
    function 成块(m, inner) {
      return '\n<details class="think"><summary>系统回执（非用户发言）</summary>' +
             '<div class="think-body">' + String(inner).trim() + '</div></details>\n';
    }
    var 段 = out.split(围栏);
    // 偶数下标在代码块之外，奇数在块内；块内一律不碰
    for (var i = 0; i < 段.length; i += 2) {
      段[i] = 段[i].replace(闭合, 成块).replace(未闭, 成块);
    }
    return 段.join(围栏);
  }
  /* 围栏归行：把粘在正文后面的代码围栏拆到独立行。
     模型偶发把开围栏接在句子末尾（"我先读一下。"紧跟围栏加语言名），
     而代码块识别的正则要求围栏位于行首，粘住就整段落回纯文本渲染，
     卡片不生成、自动执行不触发，用户看到的是一个空灰框和卡死的对话。
     只在围栏之外补换行：AI 讲解这套机制本身时会把示例围栏写进代码块里，
     那种嵌套内容不能动，否则会把外层块从中间劈开，后面渲染全乱。 */
  function 围栏归行(文本) {
    var 围 = '\x60\x60\x60';
    var s = String(文本);
    var 出 = '';
    var 位 = 0;
    var 块内 = false;
    while (true) {
      var 下 = s.indexOf(围, 位);
      if (下 < 0) { 出 += s.slice(位); break; }
      出 += s.slice(位, 下);
      // 开围栏和闭围栏都要顶到独立行。
      // 开围栏粘在中文正文末尾、闭围栏粘在命令最后一行末尾（例如 `ls -la``` ）
      // 都会让下面的代码块正则匹配不到，整块 ssh-exec 就被当普通文本吐出来，
      // 卡片不生成 → sshAutoRun 挑不到卡片 → 没有回执 → 第二条消息不出现。
      if (出 !== '' && !/\n[ \t]*$/.test(出)) { 出 += '\n'; }
      出 += 围;
      位 = 下 + 围.length;
      块内 = !块内;
    }
    return 出;
  }
  /* 把开头的思考段包成折叠标记。
     流式期间 acc 和 prefix 谁更长都有可能：思考走独立字段的渠道会先发 fold
     再发 delta，那一瞬间 prefix 已经含新分片、acc 还没追上。这时若按「prefix
     必须是 acc 的前缀」判定就会落空、整段思考露在折叠块外面，下一个 delta 到了
     又收回去，逐片抖动。所以 prefix 反过来以 acc 开头时，把 acc 整体当思考处理。 */
  function markFold(t, prefix) {
    var s = t.replace(/^\s+/, '');
    if (!prefix) return t;
    if (s.indexOf(prefix) === 0) {
      var rest = s.slice(prefix.length).replace(/^\s+/, '');
      return '[[思考开始]]\n' + prefix + '\n[[思考结束]]\n\n' + rest;
    }
    // 正文还没开始，acc 目前只是思考的一截：整段折叠，不留尾巴在外面
    if (s !== '' && prefix.indexOf(s) === 0) {
      return '[[思考开始]]\n' + s + '\n[[思考结束]]\n\n';
    }
    return t;
  }

  /* 折叠块的开合状态保持。
     背景：流式期间每收到一个分片都执行 bubble.innerHTML = render(...)，
     整个气泡的 DOM 被销毁重建。重建出来的 <details> 是新元素、open 默认为关，
     于是用户手点开的「思考过程」会被下一个分片打回收起状态 —— 生成期间分片
     连续不断，看起来就是它自己一直在缩回去，根本没法看。
     做法：重刷前把各折叠块的开合记下来，重刷后按同一个键回填。

     键为什么不用内容：思考文字在流式期间一直在变长，拿内容当键每来一片
     都对不上。改用「summary 文字 + 同类里的第几个」——思考过程和系统回执
     分别计数，中途多出一个回执块也不会把思考块的状态错位挪走。 */
  function 折叠键(d, 计数) {
    var sm = d.querySelector('summary');
    var 名 = sm ? sm.textContent.trim() : '';
    计数[名] = (计数[名] || 0) + 1;
    return 名 + '#' + 计数[名];
  }

  /* 卡片执行状态的保持。
     和折叠块同一个成因：innerHTML 重刷会把整个气泡的 DOM 销毁重建，
     卡片上标记「已执行」的 data-*-done 属性随之消失。后果比折叠块严重得多：
     自动执行会重新挑中同一张卡片再跑一遍，而正在执行的那张卡片对应的
     DOM 节点已经被丢弃，ssh_card.js 里持有的引用变成脱离文档的孤儿节点，
     收尾时状态写不回页面、回执也发不出去，于是命令跑完了却没有下一条消息。

     按「同类卡片里的第几个」当键。不用命令内容：流式期间命令文本还在变长，
     拿内容当键每来一片都对不上。
     顺带把已有的输出文本也搬过去，否则重刷会把跑到一半的输出清空。 */
  var 卡片选择器 = '.ssh-card,.sftp-card-op,.repo-card,.ws-card-op,.ppt-card-op,.web-card-op,.tool-call';
  var 完成标记 = ['data-ssh-done', 'data-sftp-done', 'data-repo-done',
                  'data-ws-done', 'data-ppt-done', 'data-web-done'];
  function 卡片键(节点, 计数) {
    // 用 class 列表做分类，pend 是流式中途的临时态，不参与分类
    var 类 = String(节点.className || '').replace(/\bpend\b/g, '').trim();
    计数[类] = (计数[类] || 0) + 1;
    return 类 + '#' + 计数[类];
  }
  function 采集卡片态(el) {
    var 表 = {};
    var 计数 = {};
    el.querySelectorAll(卡片选择器).forEach(function (c) {
      var 键 = 卡片键(c, 计数);
      var 项 = {};
      完成标记.forEach(function (a) {
        var v = c.getAttribute(a);
        if (v !== null) { 项[a] = v; }
      });
      var 出 = c.querySelector('.ssh-out');
      if (出 && 出.textContent !== '') {
        项.out = 出.textContent;
        项.outHidden = 出.hidden;
      }
      var 态 = c.querySelector('.ssh-state');
      if (态) { 项.state = 态.textContent; 项.stateCls = 态.className; }
      // tool-call 卡片：记录 body 的展开/收起状态和标题图标
      var 折 = c.querySelector('.tool-call-body');
      if (折) { 项.toolFold = 折.hidden; }
      if (Object.keys(项).length) { 表[键] = 项; }
    });
    return 表;
  }
  function 回填卡片态(el, 表) {
    if (!表 || !Object.keys(表).length) { return; }
    var 计数 = {};
    el.querySelectorAll(卡片选择器).forEach(function (c) {
      var 项 = 表[卡片键(c, 计数)];
      if (!项) { return; }
      完成标记.forEach(function (a) {
        if (项[a] !== undefined) { c.setAttribute(a, 项[a]); }
      });
      if (项.out !== undefined) {
        var 出 = c.querySelector('.ssh-out');
        if (出) { 出.textContent = 项.out; 出.hidden = !!项.outHidden; }
      }
      if (项.state !== undefined) {
        var 态 = c.querySelector('.ssh-state');
        if (态) { 态.textContent = 项.state; 态.className = 项.stateCls; }
      }
      // tool-call 卡片：恢复 body 的展开/收起状态和标题图标
      if (项.toolFold !== undefined) {
        var 折 = c.querySelector('.tool-call-body');
        if (折) { 折.hidden = !!项.toolFold; }
        var 折ico = c.querySelector('.tool-call-ico');
        if (折ico) { 折ico.textContent = 项.toolFold ? '▸' : '▾'; }
      }
    });
  }

  /* 统一的气泡写入口。所有重刷一律走这里，
     漏掉任何一处，那一处就会把用户点开的折叠块又关上、
     并且把卡片的已执行标记抹掉导致命令重复执行。 */
  function 写气泡(el, html) {
    if (!el) { return; }
    var 记 = el.__thinkOpen || (el.__thinkOpen = {});
    var 计数 = {};
    el.querySelectorAll('details').forEach(function (d) {
      记[折叠键(d, 计数)] = d.open;
    });
    var 卡态 = 采集卡片态(el);
    el.innerHTML = html;
    回填卡片态(el, 卡态);
    var 计数2 = {};
    el.querySelectorAll('details').forEach(function (d) {
      if (记[折叠键(d, 计数2)]) { d.open = true; }
    });
  }

  /* 判断代码块语言名是不是命令块。
     中转平台会把提示词里的品牌词改写掉（实测 kiro 会变成 claude），
     所以标签统一用不含品牌词的 ssh-exec，同时兼容历史消息里的旧写法。 */
  function isSshLang(lang) {
    var l = String(lang || '').toLowerCase();
    return l === 'ssh-exec' || l === 'kiro-ssh' || l === 'claude-ssh' ||
           l === 'kiro_ssh' || l === 'sshexec';
  }

  /* 判断代码块语言名是不是网页抓取块。
     同样不带品牌词，理由见上面 isSshLang 的注释。 */
  function isWebLang(lang) {
    var l = String(lang || '').toLowerCase();
    return l === 'web-open' || l === 'web_open' || l === 'webopen' ||
           l === 'web-fetch' || l === 'open-url';
  }

  /* 判断代码块语言名是不是代码仓操作块，返回动作名，不是则返回空串。
     同样避开品牌词：中转平台会改写品牌名，标签被改写后前端就认不出来。
     兼容下划线写法，部分模型会把连字符写成下划线。 */
  function repoAct(lang) {
    var l = String(lang || '').toLowerCase().replace(/_/g, '-');
    // 列清单。仓文件清单不进系统提示词（那会污染上游缓存前缀），
    // AI 得先查一次才知道有哪些路径，所以这个标签是 file-* 里的第一步。
    // 兼容几种模型爱写的变体，标签认不出来卡片就渲染不出来。
    if (l === 'file-list' || l === 'file-ls' || l === 'file-tree'
        || l === 'files-list' || l === 'filelist') { return 'list'; }
    if (l === 'file-read')  { return 'read'; }
    if (l === 'file-write') { return 'write'; }
    if (l === 'file-patch') { return 'patch'; }
    if (l === 'file-push')  { return 'push'; }
    // 删本地副本里的文件。只删本地，回传从不删远端，所以线上那份还在。
    if (l === 'file-delete' || l === 'file-del' || l === 'file-rm') { return 'delete'; }
    // 从客户服务器拉代码到本地副本。原本只有前端按钮能触发，
    // AI 被要求「拉到代码仓」时手上没有对应标签，只能回答做不到。
    if (l === 'file-pull' || l === 'file-sync' || l === 'file-fetch') { return 'pull'; }
    return '';
  }

  /* 判断代码块语言名是不是工作中心文件操作块，返回动作名，不是则返回空串。
     工作中心是账号自己的文件库，和客户服务器上的代码仓是两回事：
     ws-* 作用于文件库，file-* 作用于客户代码副本。 */
  function wsAct(lang) {
    var l = String(lang || '').toLowerCase().replace(/_/g, '-');
    if (l === 'ws-list' || l === 'ws-ls') { return 'list'; }
    if (l === 'ws-read')  { return 'read'; }
    if (l === 'ws-write') { return 'write'; }
    if (l === 'ws-patch') { return 'patch'; }
    if (l === 'ws-delete' || l === 'ws-del' || l === 'ws-rm') { return 'delete'; }
    // 打包。兼容 zip-ws / ws-package 等写法，模型经常记错标签顺序或用词
    if (l === 'ws-zip' || l === 'zip-ws' || l === 'ws-package' || l === 'wszip') { return 'zip'; }
    return '';
  }

  /* 判断代码块语言名是不是 PPT 生成块。
     兼容 ppt-make 的反向写法，模型记错顺序的情况不少。 */
  function isPptLang(lang) {
    var l = String(lang || '').toLowerCase().replace(/_/g, '-');
    return l === 'make-ppt' || l === 'ppt-make' || l === 'makeppt';
  }

  /* 判断代码块语言名是不是 SFTP 直连块，返回动作名，不是则返回空串。
     sftp-* 直接落客户线上服务器，file-* 只动本地副本，两者不是一回事，
     所以分开识别、分开渲染、分开计数。 */
  function sftpAct(lang) {
    var l = String(lang || '').toLowerCase().replace(/_/g, '-');
    if (l === 'sftp-list')   { return 'list'; }
    if (l === 'sftp-read')   { return 'read'; }
    if (l === 'sftp-write')  { return 'write'; }
    if (l === 'sftp-patch')  { return 'patch'; }
    if (l === 'sftp-delete' || l === 'sftp-del') { return 'delete'; }
    return '';
  }

  /* HTML 反转义。render() 会先把整段正文转义，
     命令内容要还原成原文再交给 sshCard，否则引号会变成 &quot; 被当成命令的一部分。 */
  function unesc(s) {
    return String(s)
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
      .replace(/&amp;/g, '&');
  }

  /* ---- 代码块增强：语言标签 + 一键复制 + 长代码折叠 + 语法高亮 ---- */
  /* 语言名友好显示映射 */
  var 语言别名 = {
    js: 'JavaScript', javascript: 'JavaScript', ts: 'TypeScript', typescript: 'TypeScript',
    py: 'Python', python: 'Python', php: 'PHP', java: 'Java', go: 'Go', rust: 'Rust',
    c: 'C', cpp: 'C++', 'c++': 'C++', cs: 'C#', csharp: 'C#',
    html: 'HTML', xml: 'XML', css: 'CSS', scss: 'SCSS', less: 'Less',
    json: 'JSON', yaml: 'YAML', yml: 'YAML', toml: 'TOML',
    sql: 'SQL', bash: 'Bash', sh: 'Shell', shell: 'Shell',
    ruby: 'Ruby', kotlin: 'Kotlin', swift: 'Swift', dart: 'Dart',
    r: 'R', lua: 'Lua', perl: 'Perl', scala: 'Scala',
    dockerfile: 'Dockerfile', makefile: 'Makefile', nginx: 'Nginx',
    diff: 'Diff', plaintext: 'Text', text: 'Text', markdown: 'Markdown', md: 'Markdown'
  };
  function 语言标签(lang) {
    if (!lang) return '';
    var l = String(lang).toLowerCase();
    return 语言别名[l] || lang;
  }

  /* 包装普通代码块：加头部（语言标签 + 复制按钮）和可选折叠 */
  function wrapCodeBlock(lang, code, streaming) {
    var lines = code.split('\n');
    var lineCount = lines.length;
    var foldable = !streaming && lineCount > 15;
    var html = '<div class="code-block' + (foldable ? ' foldable collapsed' : '') + '">';
    html += '<div class="code-block-head">';
    html += '<span class="code-lang">' + (lang ? esc(语言标签(unesc(lang))) : '') + '</span>';
    html += '<button class="code-copy" type="button" title="复制代码">复制</button>';
    html += '</div>';
    html += '<div class="code-block-body">';
    var cls = lang ? ' class="language-' + lang + ' hljs"' : ' class="hljs"';
    html += '<pre><code' + cls + '>' + code + '</code></pre>';
    html += '</div>';
    if (foldable) {
      html += '<button class="code-fold" type="button">展开（' + lineCount + ' 行）</button>';
    }
    html += '</div>';
    return html;
  }

  /* 对容器内所有未高亮的代码块应用 highlight.js */
  function highlightCodeBlocks(el) {
    if (!window.hljs || !el) return;
    el.querySelectorAll('pre code:not([data-hl])').forEach(function (block) {
      block.setAttribute('data-hl', '1');
      try { hljs.highlightElement(block); } catch (e) { /* 高亮失败不影响显示 */ }
    });
  }

  function render(t) {
    var out = esc(t);
    // 引用块：先提取占位，等其余渲染做完再还原。
    // 放在最前面是因为引用文本里可能含反引号或 **，
    // 不提前摘出来的话会被后面的代码块/加粗规则改坏。
    var 引用占位 = [];
    out = out.replace(/\[\[QUOTE\]\]\n?([\s\S]*?)\n?\[\[\/QUOTE\]\]\n*/g,
      function (m, inner) {
        引用占位.push(inner.trim());
        return '\u0000Q' + (引用占位.length - 1) + '\u0000';
      });
    // 兜底：流式渲染中途只到了开标记
    out = out.replace(/\[\[QUOTE\]\]\n?([\s\S]*)$/,
      function (m, inner) {
        引用占位.push(inner.trim());
        return '\u0000Q' + (引用占位.length - 1) + '\u0000';
      });
    // 折掉正文里裸露的系统回执标记。放在这里的原因见 折回执 的注释：
    // 必须在代码块渲染之前（那时围栏还是三个反引号，切分才准）
    out = 折回执(out);
    // 思考前言折叠块：[[思考开始]] … [[思考结束]]
    // 先于代码块处理，因为前言后面常紧跟代码块
    out = out.replace(/\[\[思考开始\]\]\n?([\s\S]*?)\n?\[\[思考结束\]\]\n*/g,
      function (m, inner) {
        return '<details class="think"><summary>思考过程</summary>' +
               '<div class="think-body">' + inner.trim() + '</div></details>';
      });
    // 兜底：流式渲染中途可能只到了开标记，此时也先折叠，别把标记裸露出来
    out = out.replace(/\[\[思考开始\]\]\n?([\s\S]*)$/,
      function (m, inner) {
        return '<details class="think"><summary>思考过程</summary>' +
               '<div class="think-body">' + inner.trim() + '</div></details>';
      });
    // 围栏归行：模型有时把开围栏直接接在中文正文末尾，而下面的正则要求
    // 围栏位于行首，粘住就匹配不到，操作卡片不生成、自动执行也不触发，
    // 用户看到的就是一个空灰框加卡住不动的对话。这里先把它顶到独立行。
    out = 围栏归行(out);
    // 语言名允许连字符：ssh-exec 里的 - 不属于 \w，用 \w 会被截成 ssh，卡片就渲染不出来
    // 闭围栏后面不再要求必须是换行或结尾。原先的 (?=\n|$) 在模型于闭围栏后
    // 紧跟标点或说明文字时会落空，整块被当普通文本渲染，卡片就没了。
    // 围栏归行已保证闭围栏位于行首，这里不必再靠后视约束兜。
    out = out.replace(/(?<=^|\n)```([\w-]*)\n?([\s\S]*?)\n```[ \t]*/g, function (m, lang, code) {
      if (isSshLang(lang)) {
        // 关键：out 已被整体转义过，这里必须先还原，否则 &quot; 会混进真实命令
        return sshCard(unesc(code));
      }
      var sa = sftpAct(lang);
      if (sa && window.sftpCard) {
        // 同理，文件正文必须还原成原文，否则写进文件的会是转义后的实体
        return sftpCard(sa, unesc(code));
      }
      var ra = repoAct(lang);
      if (ra && window.repoCard) {
        // 同理，文件正文必须还原成原文，否则写进文件的会是转义后的实体
        return repoCard(ra, unesc(code));
      }
      var wa = wsAct(lang);
      if (wa && window.wsCard) {
        return wsCard(wa, unesc(code));
      }
      if (isPptLang(lang) && window.pptCard) {
        // JSON 里的引号必须还原，否则 JSON.parse 直接失败
        return pptCard(unesc(code));
      }
      if (isWebLang(lang) && window.webCard) {
        // 网址里的 & 会被转义成 &amp;，不还原的话查询参数就错了
        var wc = webCard(unesc(code));
        // 解析不出网址时返回 null，落回普通代码块渲染
        if (wc) { return wc; }
      }
      return wrapCodeBlock(lang, code.replace(/\n$/, ''), false);
    });
    // 流式输出中途还没闭合的代码块：先占位显示，别把 ``` 标记裸露给用户
    out = out.replace(/(?<=^|\n)```([\w-]*)\n?([\s\S]*)$/, function (m, lang, code) {
      if (isSshLang(lang)) {
        return '<div class="ssh-card pend"><div class="ssh-card-head">' +
               '<span class="ssh-ico">▸</span>' +
               '<span class="ssh-title">正在生成命令…</span></div>' +
               '<pre class="ssh-cmd"><code>' + code + '</code></pre></div>';
      }
      if (sftpAct(lang)) {
        return '<div class="repo-card sftp-card-op pend"><div class="repo-card-head">' +
               '<span class="repo-ico">☁</span>' +
               '<span class="repo-title">正在生成服务器文件改动…</span></div>' +
               '<pre class="repo-diff"><code>' + code + '</code></pre></div>';
      }
      if (repoAct(lang)) {
        return '<div class="repo-card pend"><div class="repo-card-head">' +
               '<span class="repo-ico">◧</span>' +
               '<span class="repo-title">正在生成代码改动…</span></div>' +
               '<pre class="repo-diff"><code>' + code + '</code></pre></div>';
      }
      if (wsAct(lang)) {
        return '<div class="repo-card ws-card-op pend"><div class="repo-card-head">' +
               '<span class="repo-ico">🗂</span>' +
               '<span class="repo-title">正在生成工作中心文件…</span></div>' +
               '<pre class="repo-diff"><code>' + code + '</code></pre></div>';
      }
      // PPT 的大纲 JSON 又长又不好看，流式阶段不显示原文，只给一句状态。
      // 注意这里带 pend 类，pptAutoRun 不会挑中它，避免半截 JSON 被拿去生成。
      if (isPptLang(lang)) {
        return '<div class="repo-card pend"><div class="repo-card-head">' +
               '<span class="repo-ico">📊</span>' +
               '<span class="repo-title">正在拟演示文稿大纲…</span></div></div>';
      }
      return wrapCodeBlock(lang, code, true);
    });
    // 内联反引号替换必须避开已经渲染好的代码块与卡片。
    // 原先直接全局替换，会把 ssh 卡片 <textarea class="ssh-raw"> 里的原始命令
    // 也改掉：SELECT `key` 变成 SELECT <code>key</code>，而 ssh_card.js 正是
    // 从这个 textarea 读取待执行命令，于是命令带着 HTML 标签发到服务器，
    // MySQL 报语法错。这里先把 pre / textarea 整块（含内容）占位保护起来。
    var 保护块 = [];
    out = out.replace(/<(pre|textarea)\b[^>]*>[\s\S]*?<\/\1>/gi, function (块) {
      保护块.push(块);
      return '\u0000BLK' + (保护块.length - 1) + '\u0000';
    });
    out = out.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    // 加粗同理：命令里出现 ** 时（例如 shell 通配、SQL 注释）也会被改坏，
    // 所以放在还原之前一起做完。
    out = out.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    out = out.replace(/\u0000BLK(\d+)\u0000/g, function (m, i) {
      return 保护块[Number(i)];
    });
    // 还原引用块
    out = out.replace(/\u0000Q(\d+)\u0000/g, function (m, i) {
      return '<blockquote class="msg-quote">' + 引用占位[Number(i)] + '</blockquote>';
    });
    return out;
  }

  var 跟随 = true;
  function 在底部() { return $msgs.scrollHeight - $msgs.scrollTop - $msgs.clientHeight < 32; }
  function scrollDown() { if (跟随) { $msgs.scrollTop = $msgs.scrollHeight; } }

  /* 强制滚到底并恢复跟随。点「回到底部」、以及自己发消息后调用 */
  function 滚到底() {
    跟随 = true;
    $msgs.scrollTop = $msgs.scrollHeight;
    var b = document.getElementById('btnToBottom');
    if (b) { b.hidden = true; }
  }

  /* 用户一上翻就停止自动跟随，翻回底部再恢复。
     32 像素容差：移动端橡皮筋回弹、页面缩放、子像素行高都会让差值抖动一两像素，
     判定卡太死会出现明明贴底却认为已上翻，跟随再也恢复不了。 */
  $msgs.addEventListener('scroll', function () {
    跟随 = 在底部();
    var b = document.getElementById('btnToBottom');
    if (b) { b.hidden = 跟随; }
    // 快到顶就自动接着往上加载，不用非得点按钮
    if ($msgs.scrollTop < 80) { 加载更早(); }
  }, { passive: true });

  var $toBottom = document.getElementById('btnToBottom');
  if ($toBottom) { $toBottom.addEventListener('click', 滚到底); }

  /* 引用按钮：事件委托，对加载历史和新建消息都生效 */
  $inner.addEventListener('click', function (e) {
    var btn = e.target.closest('.msg-quote-btn');
    if (!btn) return;
    var msgEl = btn.closest('.msg');
    if (!msgEl || !msgEl.__rawText) return;
    setQuote(msgEl.__rawText);
  });

  /* ---- 消息收藏 ---- */
  // 收藏按钮事件委托，对历史消息和新建消息都生效
  $inner.addEventListener('click', function (e) {
    var btn = e.target.closest('.msg-fav-btn');
    if (!btn) return;
    var msgEl = btn.closest('.msg');
    if (!msgEl || !msgEl.dataset.msgId) return;
    var msgId = Number(msgEl.dataset.msgId);
    if (msgId <= 0) return;
    var isFav = btn.classList.contains('on');
    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    if (isFav) {
      // 取消收藏
      fd.append('act', 'remove');
      fd.append('msg_id', String(msgId));
      fetch('/api/favorite.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.ok) {
            btn.classList.remove('on');
            btn.textContent = '☆';
            btn.title = '收藏此回答';
          }
        })
        .catch(function () {});
    } else {
      // 添加收藏
      fd.append('act', 'add');
      fd.append('msg_id', String(msgId));
      fd.append('conv_id', String(convId));
      fetch('/api/favorite.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.ok) {
            btn.classList.add('on');
            btn.textContent = '★';
            btn.title = '取消收藏';
          }
        })
        .catch(function () {});
    }
  });

  // 批量检查当前页消息的收藏状态，给已收藏的按钮打上高亮
  function checkFavStatus() {
    var ids = [];
    $inner.querySelectorAll('.msg[data-msg-id]').forEach(function (el) {
      var id = Number(el.dataset.msgId);
      if (id > 0) ids.push(id);
    });
    if (!ids.length) return;
    fetch('/api/favorite.php?act=check&ids=' + ids.join(','), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok || !j.ids) return;
        var favSet = {};
        j.ids.forEach(function (id) { favSet[id] = true; });
        $inner.querySelectorAll('.msg-fav-btn').forEach(function (btn) {
          var msgEl = btn.closest('.msg');
          if (msgEl && favSet[Number(msgEl.dataset.msgId)]) {
            btn.classList.add('on');
            btn.textContent = '★';
            btn.title = '取消收藏';
          }
        });
      })
      .catch(function () {});
  }

  /* 代码块复制 & 折叠：事件委托，对历史消息和新建消息都生效 */
  $inner.addEventListener('click', function (e) {
    // 复制按钮
    var copyBtn = e.target.closest('.code-copy');
    if (copyBtn) {
      var block = copyBtn.closest('.code-block');
      if (!block) return;
      var codeEl = block.querySelector('pre code');
      if (!codeEl) return;
      var text = codeEl.textContent;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          copyBtn.textContent = '✓ 已复制';
          setTimeout(function () { copyBtn.textContent = '复制'; }, 2000);
        }).catch(function () {
          fallbackCopy(text, copyBtn);
        });
      } else {
        fallbackCopy(text, copyBtn);
      }
      return;
    }
    // 折叠/展开按钮
    var foldBtn = e.target.closest('.code-fold');
    if (foldBtn) {
      var blk = foldBtn.closest('.code-block');
      if (!blk) return;
      var collapsed = blk.classList.toggle('collapsed');
      if (collapsed) {
        var lc = blk.querySelector('pre code').textContent.split('\n').length;
        foldBtn.textContent = '展开（' + lc + ' 行）';
      } else {
        foldBtn.textContent = '折叠';
      }
    }
  });
  /* 旧浏览器兼容：没有 Clipboard API 时用 textarea + execCommand */
  function fallbackCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); btn.textContent = '✓ 已复制';
      setTimeout(function () { btn.textContent = '复制'; }, 2000);
    } catch (e) {}
    document.body.removeChild(ta);
  }

  /* 供 ssh_card.js 调用：命令执行完，把结果直接作为一条消息发给 AI 接着分析。
     两种例外情况不自动发，改成填进输入框由用户决定：
       1. AI 正在回复中（busy），这时发不出去；
       2. 用户输入框里已经打了字，自动发会把他写的东西冲掉。 */
  window.fillSshResult = function (cmd, out, code, 已排版) {
    // 输出太长就截断，省 token，也避免请求体过大
    var 正文 = out === '' ? '（无输出）' : String(out);
    var 上限 = 6000;
    if (正文.length > 上限) {
      正文 = 正文.slice(0, 上限) + '\n…（输出过长，已截断，共 ' + out.length + ' 字符）';
    }
    /* 已排版：调用方（多命令串行时的合并回执）自己拼好了完整正文，
       命令和退出码都已写在里面，这里不要再套一层头部，否则会出现
       「命令执行结果：命令：（空）退出码：0」这种空壳前缀。 */
    var txt = 已排版
      ? 正文
      : '命令执行结果：\n\n命令：' + cmd + '\n退出码：' + code + '\n输出：\n' + 正文;

    // 工具这一步跑完了，把按钮的锁交给下面这轮回执，中间不留空档
    工具结束();
    if (busy) { resultQueue.push({ txt: txt, kind: 'ssh' }); return; }
    send(true, txt, 'ssh');
  };



  /* 供 repo_card.js 调用：把代码仓操作的结果回传给 AI 接着干。
     和 fillSshResult 同样的取舍：AI 正在回复、或用户已经在打字时，
     先排队，等这轮结束再自动发，不碰输入框。 */
  window.fillRepoResult = function (动作, 路径, 正文, 成功) {
    var 上限 = 12000;   // 读文件的正文可能较长，给的额度比命令输出大一些
    var 文 = String(正文 === '' ? '（空）' : 正文);
    if (文.length > 上限) {
      文 = 文.slice(0, 上限) + '\n…（内容过长，已截断，共 ' + 正文.length + ' 字符）';
    }
    var 头 = {
      // 清单不在系统提示词里，这是 AI 唯一的路径来源。措辞上把话说死，
      // 否则它拿到清单后照旧凭印象拼路径，得到「文件不存在」，
      // 而 file-write 猜错路径更糟——会在仓里新建一个多余文件。
      list:  '代码仓文件清单（这就是全部，路径照抄，不要自己拼）：',
      read:  '文件内容（' + 路径 + '）：',
      write: '整文件覆写结果（' + 路径 + '）：',
      patch: '补丁应用结果（' + 路径 + '）：',
      // 一条回复里写了多个文件时，repo_card.js 串行跑完再合成一条回执，
      // 不逐张发。没有这个头的话会落到下面的「操作结果：」，
      // AI 看不出这是一批而不是一个文件。
      batch: '本批文件改动的执行结果：',
      push:  '回传结果：'
    }[动作] || '操作结果：';
    var txt = 头 + '\n\n' + 文;
    if (!成功) {
      // 批量回执的正文里已经逐条标了成败、也点名了被跳过的文件，
      // 再补一句「这一步」会让 AI 以为整批都失败了，反而把成功的那几个也重做一遍。
      txt += 动作 === 'batch'
        ? '\n\n（这一批里有没成功的，按上面的逐条结果处理，已经成功的不要重做。）'
        : '\n\n（这一步没成功，请根据上面的原因调整后再试，不要原样重试。）';
    }

    // 工具这一步跑完了，把按钮的锁交给下面这轮回执，中间不留空档
    工具结束();
    if (busy) { resultQueue.push({ txt: txt, kind: 'repo' }); return; }
    send(true, txt, 'repo');
  };

  /* 供 sftp_card.js 调用：SFTP 直连操作完成，把结果作为下一轮消息发给 AI。
     和 fillRepoResult 同样的取舍：AI 正在回复时先排队，等这轮结束再自动发。
     措辞上要点明「已经生效」，否则 AI 常会追着再问一句要不要回传。 */
  window.fillSftpResult = function (动作, 路径, 正文, 成功) {
    var 上限 = 12000;
    var 文 = String(正文 === '' ? '（空）' : 正文);
    if (文.length > 上限) {
      文 = 文.slice(0, 上限) + '\n…（内容过长，已截断，共 ' + 正文.length + ' 字符）';
    }
    var 头 = {
      list:   '服务器目录（' + 路径 + '）：',
      read:   '服务器文件内容（' + 路径 + '）：',
      write:  '服务器文件已覆写（' + 路径 + '）：',
      patch:  '服务器文件补丁结果（' + 路径 + '）：',
      delete: '服务器文件已删除（' + 路径 + '）：',
      // 一条回复里多个写类块整批跑完后的汇总回执
      batch:  '这一批 SFTP 改动的执行结果：'
    }[动作] || 'SFTP 操作结果：';
    var txt = 头 + '\n\n' + 文;
    if (!成功) {
      txt += '\n\n（这一步没成功。请说明原因并修正参数后重发 sftp 块，'
           + '或请用户处理权限、路径这类前置条件。'
           + '不要原样重试，也不要改用命令行读写文件——那条路一定会被拒绝。）';
    }

    // 工具这一步跑完了，把按钮的锁交给下面这轮回执，中间不留空档
    工具结束();
    if (busy) { resultQueue.push({ txt: txt, kind: 'sftp' }); return; }
    send(true, txt, 'sftp');
  };

  /* 供 ws_card.js 调用：把工作中心文件操作的结果回传给 AI。
     和 fillRepoResult 同样的取舍：AI 正在回复时先排队，等这轮结束再自动发。 */
  window.fillWsResult = function (动作, 名字, 正文, 成功) {
    var 上限 = 12000;
    var 文 = String(正文 === '' ? '（空）' : 正文);
    if (文.length > 上限) {
      文 = 文.slice(0, 上限) + '\n…（内容过长，已截断，共 ' + 正文.length + ' 字符）';
    }
    var 头 = {
      list:  '工作中心现有文件清单（这就是全部，不要再猜文件名）：',
      read:  '工作中心文件内容（' + 名字 + '）：',
      write: '工作中心写入结果（' + 名字 + '）：',
      patch: '工作中心补丁结果（' + 名字 + '）：',
      zip:   '工作中心打包结果（' + 名字 + '）：'
    }[动作] || '工作中心操作结果：';
    var txt = 头 + '\n\n' + 文;
    if (!成功) {
      txt += '\n\n（这一步没成功，请根据上面的原因调整后再试，不要原样重试。）';
    }

    // 工具这一步跑完了，把按钮的锁交给下面这轮回执，中间不留空档
    工具结束();
    if (busy) { resultQueue.push({ txt: txt, kind: 'ws' }); return; }
    send(true, txt, 'ws');
  };

  /* 供 web_card.js 调用：把网页正文回传给 AI。
     正文本身就是 AI 要的东西，所以整段原样发回去，不在前端再截一刀——
     该截的服务端已经按 limit 截过并标了 truncated，这里再动手只会让 AI
     拿到残缺内容却不知道被截了。 */
  window.fillWebResult = function (正文) {
    // 工具这一步跑完了，把按钮的锁交给下面这轮回执，中间不留空档
    工具结束();
    var txt = String(正文);
    if (busy) { resultQueue.push({ txt: txt, kind: 'web' }); return; }
    send(true, txt, 'web');
  };

  /* 供 ppt_card.js 调用：把 PPT 生成结果回传给 AI。
     成功时也回传，因为 AI 需要知道文件名才能在后续回复里正确引用它。 */
  window.fillPptResult = function (名字, 正文, 成功) {
    var txt = 'PPT 生成结果（' + (名字 || '未命名') + '）：\n\n' + String(正文);
    if (!成功) {
      txt += '\n\n（这一步没成功，请根据上面的原因调整大纲后再试，不要原样重试。）';
    }
    // 工具这一步跑完了，把按钮的锁交给下面这轮回执，中间不留空档
    工具结束();
    if (busy) { resultQueue.push({ txt: txt, kind: 'ppt' }); return; }
    send(true, txt, 'ppt');
  };

  function clearWelcome() {
    var w = document.getElementById('welcome');
    if (w) w.remove();
  }

  /* 生成气泡里的图片 HTML；urls 为 /api/img.php?id=xx 数组 */
  function imgsHtml(urls) {
    if (!urls || !urls.length) return '';
    return '<div class="bubble-imgs">' + urls.map(function (u, i) {
      return '<a href="' + esc(u) + '" target="_blank" rel="noopener">' +
        '<img src="' + esc(u) + '" alt="图片 ' + (i + 1) + '" loading="lazy"></a>';
    }).join('') + '</div>';
  }

  /* 把服务端的 'YYYY-MM-DD HH:MM:SS' 或 Date 解析成 Date。
     注意不能直接 new Date(字符串)：带空格的格式在 Safari 上会得到 Invalid Date，
     所以手工拆字段，按本地时区构造。 */
  function 解析时间(v) {
    if (!v) return null;
    if (v instanceof Date) return v;
    var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!m) { var d0 = new Date(v); return isNaN(d0.getTime()) ? null : d0; }
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
  }
  function 补零(n) { return (n < 10 ? '0' : '') + n; }
  /* 气泡上显示的短格式：今天只给时分，昨天加「昨天」，更早带月日，跨年带年份。
     聊天绝大多数是当天的，省掉重复的日期让界面清爽。 */
  function 格式化时间(v) {
    var d = 解析时间(v);
    if (!d) return '';
    var 时分 = 补零(d.getHours()) + ':' + 补零(d.getMinutes());
    var 现 = new Date();
    var 同天 = function (a, b) {
      return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    };
    if (同天(d, 现)) return 时分;
    var 昨 = new Date(现.getFullYear(), 现.getMonth(), 现.getDate() - 1);
    if (同天(d, 昨)) return '昨天 ' + 时分;
    if (d.getFullYear() === 现.getFullYear()) {
      return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + 时分;
    }
    return d.getFullYear() + '-' + 补零(d.getMonth() + 1) + '-' + 补零(d.getDate()) + ' ' + 时分;
  }
  /* 悬停 title 用的完整时间戳，含秒，便于排查问题时对齐日志 */
  function 完整时间(v) {
    var d = 解析时间(v);
    if (!d) return '';
    return d.getFullYear() + '-' + 补零(d.getMonth() + 1) + '-' + 补零(d.getDate()) + ' ' +
      补零(d.getHours()) + ':' + 补零(d.getMinutes()) + ':' + 补零(d.getSeconds());
  }

  /* 只造 DOM 不挂页面。往底部追加和往顶部插旧消息都走这里，免得两份渲染代码走岔 */
  function 建消息节点(role, text, meta, imgUrls, 时间, msgId) {
    var wrap = document.createElement('div');
    wrap.className = 'msg ' + role;
    // 存原文供引用按钮读取：textContent 会丢掉代码块内容，这里存完整文本
    wrap.__rawText = text;
    // 存消息 id 供收藏按钮使用
    if (msgId) { wrap.dataset.msgId = String(msgId); }
    var who = role === 'user' ? '我' : 'AI';
    // 时间挂在角色标签旁边：历史消息用服务端 created_at，新发的用本地当前时间。
    // title 里放完整时间戳，正文只显示短格式，免得挤占气泡宽度。
    var 时文 = 时间 ? 格式化时间(时间) : '';
    // assistant 消息且有 msgId 时显示收藏按钮，和引用按钮并排
    var 收藏按钮 = (role === 'assistant' && msgId)
      ? '<button class="msg-fav-btn" type="button" title="收藏此回答">☆</button>'
      : '';
    wrap.innerHTML =
      '<div class="who">' + who + '</div>' +
      '<div class="body">' +
      '<div class="msg-actions">' +
      '<button class="msg-quote-btn" type="button" title="引用此消息">引用</button>' +
      收藏按钮 +
      '</div>' +
      '<div class="msg-time"' + (时间 ? ' title="' + esc(完整时间(时间)) + '"' : '') + '>' +
        esc(时文) + '</div>' +
      '<div class="bubble"></div>' +
      (meta ? '<div class="meta">' + esc(meta) + '</div>' : '<div class="meta" hidden</div>') +
      '</div>';
    var bubble = wrap.querySelector('.bubble');
    bubble.innerHTML = imgsHtml(imgUrls) + render(text);
    
    highlightCodeBlocks(bubble);
    return wrap;
  }
  function addMsg(role, text, meta, imgUrls, 时间, msgId) {
    clearWelcome();
    // 不传时间就用当前时刻：新发的消息和 AI 正在生成的回复都属于这种
    var wrap = 建消息节点(role, text, meta, imgUrls, 时间 || new Date(), msgId);
    $inner.appendChild(wrap);
    // 消息数变了，「上一条需求」的可点状态和翻页起点都要重置
    if (window.重置需求定位) { window.重置需求定位(); }
    // 自己发的消息一定要看见，强制滚到底并恢复跟随；
    // AI 的消息走 scrollDown，用户正在上翻时不打断他
    if (role === 'user') { 滚到底(); } else { scrollDown(); }
    return wrap;
  }

  /* ---------- 往上翻更早的消息（游标翻页） ---------- */
  // 首条id = 当前已渲染的最早一条消息 id，即下次翻页的游标；0 表示还没加载过
  var 首条id = 0;
  var 还有更早 = false;
  var 翻页中 = false;
  var $earlier = null;
  function 刷新更早按钮() {
    if (!还有更早) {
      if ($earlier) { $earlier.hidden = true; }
      return;
    }
    if (!$earlier) {
      $earlier = document.createElement('div');
      $earlier.className = 'load-earlier';
      $earlier.innerHTML = '<button type="button">加载更早的消息</button>';
      $earlier.querySelector('button').addEventListener('click', 加载更早);
    }
    $earlier.hidden = false;
    $earlier.querySelector('button').textContent = '加载更早的消息';
    // 按钮必须始终是第一个子节点，新插进来的旧消息都排在它后面
    if ($inner.firstChild !== $earlier) { $inner.insertBefore($earlier, $inner.firstChild); }
  }
  function 加载更早() {
    if (翻页中 || !还有更早 || !convId || 首条id <= 0) { return; }
    翻页中 = true;
    if ($earlier) { $earlier.querySelector('button').textContent = '加载中…'; }
    var 游标 = 首条id;
    // 记下发请求时的会话，回来时用户可能已经切走了
    var 本次会话 = convId;
    fetch('/api/conv.php?act=messages&conv_id=' + convId + '&before_id=' + 游标,
          { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        // 会话已经切走，这批是上一个会话的旧消息，插进去会串会话
        if (本次会话 !== convId) { return; }
        if (j.error || !j.list) { return; }
        // 记下插入前的滚动状态：prepend 会把内容整体推下去，
        // 不补偿高度差用户的视线就会被甩走
        var 旧高 = $msgs.scrollHeight;
        var 旧位 = $msgs.scrollTop;
        var 片段 = document.createDocumentFragment();
        j.list.forEach(function (m) {
          var meta = '';
          if (m.role === 'assistant' && Number(m.tokens_out) > 0) { meta = 用量文案(m); }
          片段.appendChild(建消息节点(m.role, m.content, meta, m.img_urls, m.created_at, m.id));
        });
        $inner.insertBefore(片段, $earlier ? $earlier.nextSibling : $inner.firstChild);
        if (j.list.length) { 首条id = Number(j.first_id || 游标); }
        还有更早 = Number(j.has_more || 0) === 1;
        $msgs.scrollTop = 旧位 + ($msgs.scrollHeight - 旧高);
        刷新更早按钮();
        if (window.重置需求定位) { window.重置需求定位(); }
      })
      .catch(function () { /* 网络抖一下就算了，按钮还在，用户可以再点 */ })
      .then(function () {
        翻页中 = false;
        if (本次会话 === convId && $earlier && !$earlier.hidden) {
          $earlier.querySelector('button').textContent = '加载更早的消息';
        }
      });
  }
  /* 按钮只看「这一轮任务有没有结束」，也就是 busy 和 工具执行中 的并集。
     谁改了标志都调它一次，不各自去写 DOM，免得两处判断打架。 */
  function 刷新按钮() {
    var 忙 = busy || 工具执行中;
    $send.disabled = 忙;
    // 忙的时候不锁输入框：用户常常边看边想补一句，或者发现说错了想改。
    // 锁住只能干等，锁开他可以先把下一句敲好，等这轮完了直接发。
    $input.disabled = false;
    $send.textContent = 忙 ? '生成中' : '发送';
    if ($stop) {
      // 整轮任务期间都露出来，包括工具在跑那段。
      // 这里停不了已经发到服务器上的那条命令（它在服务端跑，前端管不着），
      // 但能把整条链掐断——命令回来后不再自动发下一轮。
      // 之前只看 busy，工具阶段按钮消失，用户看着「生成中」却无处可点。
      $stop.hidden = !忙;
    }
  }

  function setBusy(b) {
    busy = b;
    if ($stop && b) {
      // 每轮开始时把暂停按钮复位。上一轮点过暂停的话它停在
      // 「停止中」且 disabled，不复位的话下一轮就是个按不动的死按钮——
      // 这正是「第二轮点暂停没反应」的直接原因。
      $stop.disabled = false;
      $stop.textContent = '暂停';
    }
    刷新按钮();
    if (!b) { setTimeout(processQueue, 100); }
  }

  /* 气泡下方那行用量文案。历史消息、流式收尾、断线重连三处都用它，
     免得改一处漏两处。u 里要有 tokens_in / tokens_out / tokens_cache / tokens_cache_create / cost。 */
  function 用量文案(u) {
    // tokens_in 是输入总量，缓存读、缓存写都已含在里面（见 inc/upstream_claude.php
    // 的 upstream_norm_usage）。直接把总量标成「输入」会让人以为这些全按原价收，
    // 实际缓存读只按 0.1 倍、缓存写按 1.25 倍计价。所以这里减掉两段缓存后单列纯输入，
    // 三段加起来才等于 tokens_in。
    var 总输入 = Number(u.tokens_in || 0);
    var 缓存创建 = Number(u.tokens_cache_create || 0);
    var 缓存命中 = Number(u.tokens_cache || 0);
    // 上游偶尔给的数不自洽（缓存量大于总量），减出负数会更难看，兜底夹到 0。
    var 纯输入 = Math.max(0, 总输入 - 缓存创建 - 缓存命中);
    var 段 = ['输入 ' + 纯输入];
    // ↓缓存 = 命中缓存的输入 token 数（读缓存，最省钱的那部分，放前面）
    if (缓存命中 > 0) { 段.push('↓缓存 ' + 缓存命中); }
    // ↑缓存 = 缓存写入 token 数（5 分钟缓存创建费用，写缓存）
    if (缓存创建 > 0) { 段.push('↑缓存 ' + 缓存创建); }
    段.push('输出 ' + u.tokens_out);
    return 段.join(' · ') + ' tokens · ￥' + u.cost +
           (Number(u.estimated) ? '（估算）' : '');
  }

  function updatePrice() {
    if (!$modelSel || !$priceTip) return;
    var o = $modelSel.options[$modelSel.selectedIndex];
    if (!o) return;
    var 段 = ['输入 ￥' + o.dataset.in, '输出 ￥' + o.dataset.out];
    // 缓存价单列一段，不并进输入价里。价格是 0 表示这个模型不区分缓存价，
    // 命中缓存的部分照输入价收，这时候显示「缓存 ￥0」会让人误以为免费，所以不显示。
    var 缓存 = parseFloat(o.dataset.cache || '0');
    if (缓存 > 0) { 段.push('缓存 ￥' + o.dataset.cache); }
    var 缓存创建 = parseFloat(o.dataset.cacheCreate || '0');
    if (缓存创建 > 0) { 段.push('缓存创建(5m) ￥' + o.dataset.cacheCreate); }
    $priceTip.textContent = 段.join(' · ') + ' / 百万tokens';
  }

  /* ---------- 分屏高度固定 ---------- */
  /* 安卓分屏时dvh会频繁重算导致卡死，初始化时算一次固定下来 */
  (function lockHeight() {
    var h = window.innerHeight;
    document.documentElement.style.setProperty('--vh-lock', h + 'px');
  })();

  /* ---------- 分屏高度固定 ---------- */
  /* 安卓分屏时dvh会频繁重算导致卡死，初始化时算一次固定下来 */
  (function lockHeight() {
    var h = window.innerHeight;
    document.documentElement.style.setProperty('--vh-lock', h + 'px');
  })();

  /* ---------- 分屏高度固定 ---------- */
  /* 安卓分屏时dvh会频繁重算导致卡死，初始化时算一次固定下来 */
  (function lockHeight() {
    var h = window.innerHeight;
    document.documentElement.style.setProperty('--vh-lock', h + 'px');
  })();

  /* ---------- 图片上传 ---------- */
  /* 按当前模型是否支持视觉，显示/隐藏上传按钮 */
  function syncVision() {
    if (!$btnImg || !$modelSel) return;
    var o = $modelSel.options[$modelSel.selectedIndex];
    var ok = !!(o && o.dataset.vision === '1');
    $btnImg.hidden = !ok;
    if (!ok && pending.length) {
      pending = [];
      renderTray();
    }
  }

  function renderTray() {
    if (!$imgTray) return;
    if (!pending.length && !uploading) {
      $imgTray.hidden = true;
      $imgTray.innerHTML = '';
      return;
    }
    $imgTray.hidden = false;
    $imgTray.innerHTML = pending.map(function (p, i) {
      if (p.up) {
        return '<div class="img-tray-item up"><img src="' + p.url + '" alt="上传中">' +
          '<span class="bar"></span></div>';
      }
      return '<div class="img-tray-item"><img src="' + p.url + '" alt="待发送图片 ' + (i + 1) + '">' +
        '<button class="rm" type="button" title="移除" aria-label="移除图片" data-rm="' + i + '">×</button></div>';
    }).join('');
  }

  function compressImage(file, maxKB) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function (e) {
        var img = new Image();
        img.onload = function () {
          var canvas = document.createElement('canvas');
          canvas.width = img.width;
          canvas.height = img.height;
          var ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0);
          var q = 0.9;
          function tryCompress() {
            canvas.toBlob(function (b) {
              if (!b) return reject(new Error('图片导出失败'));
              if (b.size <= maxKB * 1024) {
                resolve(new File([b], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg', lastModified: Date.now() }));
                return;
              }
              if (q <= 0.1) {
                // 只降质量有下限：截图这类纯色块多、文字边缘锐利的图，
                // 质量掉到最低体积也未必达标。此时如实报错，
                // 别把一张既糊又超限的图交给服务端再拒一次。
                reject(new Error(
                  '这张图压到最低质量仍有 ' + (b.size / 1024).toFixed(0) +
                  'KB，超过上限 ' + maxKB + 'KB。请先裁掉不需要的部分，或改用尺寸更小的图。'
                ));
                return;
              }
              q -= 0.1;
              if (q < 0.1) q = 0.1;
              tryCompress();
            }, 'image/jpeg', q);
          }
          tryCompress();
        };
        img.onerror = function () { reject(new Error('Image load failed')); };
        img.src = e.target.result;
      };
      reader.onerror = function () { reject(new Error('File read failed')); };
      reader.readAsDataURL(file);
    });
  }

  function doUpload(file) {
    var maxNum = 4;
    if (pending.length >= maxNum) {
      alert('一次最多发送 ' + maxNum + ' 张图片');
      return;
    }
    var item = { id: 0, url: URL.createObjectURL(file), up: true };
    pending.push(item);
    uploading++;
    renderTray();

    var fd = new FormData();
    fd.append('file', file);
    fd.append('csrf', window.CSRF);

    fetch('/api/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || '上传失败');
        item.id = j.id;
        item.up = false;
      })
      .catch(function (e) {
        var k = pending.indexOf(item);
        if (k >= 0) pending.splice(k, 1);
        alert(e.message || '上传失败');
      })
      .then(function () {
        uploading--;
        renderTray();
      });
  }

  function uploadOne(file) {
    if (file.type && file.type.indexOf('image/') === 0) {
      var maxKB = window.UPLOAD_MAX_IMAGE_SIZE_KB || 300;
      if (file.size > maxKB * 1024) {
        if (confirm('图片大小为 ' + (file.size / 1024).toFixed(1) + 'KB，超过限制 ' + maxKB + 'KB。\n是否压缩到 ' + maxKB + 'KB 后再上传？')) {
          compressImage(file, maxKB).then(function (cf) {
            doUpload(cf);
          }).catch(function (err) {
            alert(err.message || '压缩失败');
          });
        }
        return;
      }
    }
    doUpload(file);
  }

  function pickFiles(files) {
    Array.prototype.slice.call(files).forEach(function (f) {
      if (f && f.type && f.type.indexOf('image/') === 0) uploadOne(f);
    });
  }

  if ($btnImg && $fileInput) {
    $btnImg.addEventListener('click', function () {
      if (busy) return;
      $fileInput.click();
    });
    $fileInput.addEventListener('change', function () {
      var files = $fileInput.files;
      if (!files || files.length === 0) return;
      // 安卓上files是活引用，清空input后立即失效。先复制到数组，再清空
      var arr = Array.prototype.slice.call(files);
      $fileInput.value = '';
      pickFiles(arr);
    });
  }
  if ($imgTray) {
    $imgTray.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rm]');
      if (!b) return;
      pending.splice(Number(b.dataset.rm), 1);
      renderTray();
    });
  }
  /* 支持直接粘贴截图 */
  if ($input) {
    $input.addEventListener('paste', function (e) {
      if (!$btnImg || $btnImg.hidden || busy) return;
      var items = (e.clipboardData || {}).items || [];
      var imgs = [];
      Array.prototype.forEach.call(items, function (it) {
        if (it.kind === 'file' && it.type.indexOf('image/') === 0) {
          var f = it.getAsFile();
          if (f) imgs.push(f);
        }
      });
      if (imgs.length) {
        e.preventDefault();
        imgs.forEach(uploadOne);
      }
    });
  }

  /* 从工作中心挑选的图片，进入对话页后自动带入待发区 */
  function loadPicked() {
    var arr = [];
    try {
      arr = JSON.parse(sessionStorage.getItem('ws_pick') || '[]');
      sessionStorage.removeItem('ws_pick');
    } catch (e) { return; }
    if (!arr || !arr.length) return;
    arr.forEach(function (id) {
      id = Number(id);
      if (id > 0 && pending.length < 4) {
        pending.push({ id: id, url: '/api/img.php?id=' + id, up: false });
      }
    });
    renderTray();
    if (pending.length && $btnImg && $btnImg.hidden) {
      // 当前模型不支持图片时给个提示，别让用户白等
      alert('已带入 ' + pending.length + ' 张图片，但当前模型不支持图片输入，请切换到支持视觉的模型');
    }
  }

  /* ---------- 会话列表（渲染归 project.js，这里只同步高亮） ---------- */
  function markActive() {
    window.currentConvId = convId;
    if (window.项目侧栏) { window.项目侧栏.标记(); }
  }

  function reloadConvs() {
    // 侧栏由 project.js 渲染：刷新当前项目的对话列表与项目计数
    if (!window.项目侧栏) { return Promise.resolve(); }
    var p = window.项目侧栏.当前();
    if (p) { window.项目侧栏.刷新对话(p.id); }
    window.项目侧栏.刷新();
    return Promise.resolve();
  }

  /**
   * 打开会话。
   * @param {number} id 会话 ID
   * @param {boolean} silent 静默模式（页面启动恢复时用）：不弹错误、不动侧栏
   */
  function openConv(id, silent) {
    clearQuote();   // 切会话时清掉引用，避免带到新会话
    fetch('/api/conv.php?act=messages&conv_id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.error) {
          if (silent) { saveLastConv(0); } else { alert(j.error); }
          return;
        }
        convId = id; window.currentConvId = id;
        saveLastConv(convId);
        // 关键修复：会话自带模型，回填下拉框，刷新后不再回弹到第一项。
        // 该模型已下架时保留当前选择，并提示用户重新选。
        if (j.conv && Number(j.conv.model_id) > 0) {
          if (applyModel(j.conv.model_id)) {
            saveLastModel(Number(j.conv.model_id));
          } else {
            showModelTip('该会话原用模型已下架，请重新选择', true);
          }
        }
        // 会话级上下文条数：按该会话保存的设置恢复，没存过才跟随模型默认
        var savedCtx = j.conv ? Number(j.conv.context_limit || 0) : 0;
        setCtxLimit(savedCtx >= 2 && savedCtx <= 60 ? savedCtx : 0);
        $inner.innerHTML = '';
        // 切会话要重置游标，否则会拿上一个会话的 id 去翻这个会话
        $earlier = null;
        首条id = Number(j.first_id || 0);
        还有更早 = Number(j.has_more || 0) === 1;
        j.list.forEach(function (m) {
          var meta = '';
          if (m.role === 'assistant' && Number(m.tokens_out) > 0) {
            meta = 用量文案(m);
          }
          addMsg(m.role, m.content, meta, m.img_urls, m.created_at, m.id);
        });
        if (!j.list.length) addMsg('assistant', '这个会话还没有内容，直接提问吧。', '');
        刷新更早按钮();
        恢复卡片状态();
        // 检查本批消息的收藏状态，给已收藏的消息打上标记
        checkFavStatus();
        markActive();
        // 加载完历史一定落到底部并恢复跟随。
        // 不能只在 silent 分支里滚：跟随状态是全局的，
        // 上个会话如果停在上翻状态，切过来会卡在半空中不动。
        滚到底();
        if (!silent) { closeSide(); }
        试着接回(id);
      })
      .catch(function () { if (silent) saveLastConv(0); });
  }

  // 供 project_ui.js 调用：打开某条对话
  window.打开对话 = function (id) {
    if (busy) { alert('请等待当前回答结束'); return; }
    openConv(Number(id));
  };

  // 供 project_ui.js 调用：清空聊天区（删除对话/项目后）
  window.清空聊天区 = function (提示) {
    convId = 0; window.currentConvId = 0;
    clearQuote();
    resetCtx();   // 对话已不存在，上下文条数跟着回默认
    saveLastConv(0);
    $inner.innerHTML = '<div class="chat-welcome"><h2>' +
      esc(提示 || '选择或新建一条对话') +
      '</h2><p>在左侧项目里点「+ 新对话」开始。</p></div>';
    markActive();
    // 聊天区清空了，按钮要跟着置灰
    if (window.重置需求定位) { window.重置需求定位(); }
  };

  // 供 project_ui.js 查询：是否正在生成回答
  window.对话进行中 = function () { return busy; };
  /* 供多命令串行链查询：用户点了暂停就别再起下一条。
     串行链在 ssh_card.js 里，拿不到这个模块的私有变量，只能这样暴露。
     点暂停的本意是「不要再动作了」，已经跑起来的那条停不下来（得点卡片上的
     停止按钮），但排在后面还没起的必须拦住。 */
  window.链是否已取消 = function () { return 链已取消; };

  /* ---------- 发送 + SSE 流 ----------
     静默 = true 时用于工具回执（SFTP / 命令执行 / 工作中心的操作结果）：
     内容照旧发给 AI 并入库，但不在聊天里渲染成「我」的气泡。
     AI 上一条已经把这次操作讲过一遍，再冒出一条同样内容的用户消息是重复。 */
  function send(静默, 回执文, 回执种类) {
    if (busy) return;
    // 用户点过暂停，这条链上后续的自动回执一律不发。
    // 只挡自动回执，用户自己敲的消息照发（那是他重新开口，等于恢复）。
    if (链已取消 && 回执文 !== undefined && 回执文 !== null) { return; }
    /* 回执文有值就用它，完全不碰输入框。工具回执是机器产生的内容，
       走输入框会把用户正在打的字冲掉。 */
    var 是回执 = 回执文 !== undefined && 回执文 !== null;
    var text = 是回执 ? String(回执文).trim() : $input.value.trim();
    // 引用回复：把引用原文拼到消息前面，AI 能看到引用的是哪条消息
    if (!是回执 && quoteText) {
      text = '[[QUOTE]]' + quoteText + '[[/QUOTE]]\n\n' + text;
      clearQuote();
    }
    if (!text && !pending.length) return;
    if (!$modelSel || !$modelSel.value) { alert('暂无可用模型'); return; }
    if (uploading > 0) { alert('请等待图片上传完成'); return; }
    // 对话必须挂在项目下：没有当前项目就引导用户先建一个
    if (convId === 0) {
      var 当 = window.项目侧栏 ? window.项目侧栏.当前() : null;
      if (!当) {
        alert('请先在左侧新建或选择一个项目，再开始对话。');
        var $建 = document.getElementById('btnNewProj');
        if ($建) { $建.click(); }
        return;
      }
    }

    // 回执不带图：待发区的图是用户为自己那条消息准备的，不能被回执顺走
    var sentImgUrls = 是回执 ? [] : pending.map(function (p) { return '/api/img.php?id=' + p.id; });
    var sentImgIds  = 是回执 ? [] : pending.map(function (p) { return p.id; });
    if (!静默) {
      addMsg('user', text, '', sentImgUrls);
    }
    // 记住这轮发出去的原文。点暂停时回填到输入框，用户改两个字就能重发，
    // 不用自己再敲一遍。回执是给 AI 看的，回填没意义，所以不记。
    本轮原文 = 静默 ? '' : text;
    // 回执没往输入框写东西，自然也不该清它——清了就是把用户打的字弄丢
    if (!是回执) {
      $input.value = '';
      $input.style.height = 'auto';
    }
    setBusy(true);

    var holder = addMsg('assistant', '', '');
    var bubble = holder.querySelector('.bubble');
    var metaEl = holder.querySelector('.meta');
    bubble.innerHTML = '<span class="typing"></span>';

    // 这一轮的版本号。切后台重连后旧流的回调靠它被识别成过期并丢弃
    var 本代 = ++流代;

    var fd = new FormData();
    fd.append('conv_id', convId);
    // 新建对话时告诉后端归属哪个项目
    var 项 = window.项目侧栏 ? window.项目侧栏.当前() : null;
    fd.append('project_id', 项 ? 项.id : 0);
    fd.append('model_id', $modelSel.value);
    // 上下文条数（对话级）：用户在「⚙ 上下文」面板设过值才随请求提交，
    // 覆盖模型默认；没设就不传，后端回落 models.max_context
    if (ctxLimit >= 2) { fd.append('context_limit', String(ctxLimit)); }
    fd.append('content', text);
    fd.append('csrf', window.CSRF);
    // 回执带上 tool_kind：后端据此把内容写进 toolresults 的 txt 而不是 messages 表。
    // 旧版传的是 hidden=1，后端早就改读 tool_kind 了，漏改会让回执又躺回聊天记录里。
    if (静默) { fd.append('tool_kind', 回执种类 || 'other'); }
    sentImgIds.forEach(function (id) { fd.append('images[]', id); });

    // 图片已随消息发出，清空待发区。回执没带图，待发区原样留给用户
    if (!是回执) {
      pending = [];
      renderTray();
    }

    var acc = '';
    var prevAcc = '';   // 已完成轮次的正文，tool_result 时把 acc 搬到这里保留
    var foldPrefix = '';   // 后端判定的思考前言原文，非空则渲染时折叠开头
    var isNew = convId === 0;
    var 已暂停 = false;   // 收到 stopped 事件后置起，done 时据它标注气泡
    /* Function Calling 工具调用的展示 HTML，独立于 acc 累积。
       原来的代码把 HTML 拼进 acc，导致 render() 的 esc() 把它转义成纯文本
       （"工具调用:ssh_exec" 露在正文里），而且污染了 acc 后续的流式渲染
       和 localStorage 缓存。连续多次 tool_result 会让垃圾文本越积越多，
       最终整条消息的卡片渲染全崩。 */
    var toolHtmlAcc = '';
    /* 前几轮的已渲染 HTML 和原文。tool_result 到来时把当前轮的正文和卡片
       存到这里，再清空 acc 开始新一轮。这样多轮工具调用时每轮的正文都保留，
       不会因为 acc 被清零而消失。 */
    var prevRoundsHtml = '';
    var prevRoundsText = '';
    
    /* 根据工具名和参数自动生成一句中文说明。
       原来卡片标题只有 "工具调用: ssh_exec" 这种裸名字，用户看不懂在干什么。
       现在按工具名 + 关键参数拼出一句话，例如「在服务器执行命令: ls -la」。 */
    function 工具说明(toolName, toolArgsRaw) {
      var a = {};
      try { a = JSON.parse(toolArgsRaw || '{}') || {}; } catch (e) { /* 参数解析失败就保持空对象 */ }
      var t = function (s, n) { s = String(s == null ? '' : s); return s.length > n ? s.slice(0, n) + '…' : s; };
      switch (toolName) {
        case 'ssh_exec': return '在服务器执行命令: ' + t(a.cmd, 80);
        case 'sftp_read': return '读取服务器文件: ' + t(a.path, 80);
        case 'sftp_write': return '写入服务器文件: ' + t(a.path, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'sftp_patch': return '修改服务器文件: ' + t(a.path, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'sftp_list': return '查看服务器目录: ' + (a.dir ? t(a.dir, 60) : '部署根目录');
        case 'sftp_delete': return '删除服务器文件: ' + t(a.path, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'file_list': return Number(a.dirty) === 1 ? '查看本地仓有哪些待推送的改动' : '查看本地代码仓的文件清单';
        case 'file_read': return '读取仓库文件: ' + t(a.path, 80);
        case 'file_write': return '写入仓库文件: ' + t(a.path, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'file_patch': return '修改仓库文件: ' + t(a.path, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'file_push': return '把本地仓改动推送到服务器' + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'file_delete': return '删除仓库文件: ' + t(a.path, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'ws_list': return a.q ? '筛选工作中心文件: ' + t(a.q, 40) : '查看工作中心的文件清单';
        case 'ws_read': return '读取工作中心文件: ' + t(a.name, 80);
        case 'ws_write': return '写入工作中心文件: ' + t(a.name, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'ws_patch': return '修改工作中心文件: ' + t(a.name, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'ws_delete': return '删除工作中心文件: ' + t(a.name, 80) + (a.note ? '（' + t(a.note, 40) + '）' : '');
        case 'ws_zip': return '打包工作中心文件: ' + t(a.name, 60);
        case 'web_open': return '抓取网页内容: ' + t(a.url, 90);
        case 'web_search': return '搜索实时信息: ' + t(a.query, 60);
        case 'ppt_generate': return '生成 PPT: ' + t(a.title, 40);
        default: return '工具调用: ' + toolName;
      }
    }

    /* 统一渲染入口：前几轮的 HTML + 当前轮的正文 + 当前轮的工具卡片。
       所有写气泡的调用点改用这个函数，避免遗漏。 */
    function 渲染气泡() {
      var html = prevRoundsHtml + render(foldPrefix ? markFold(acc, foldPrefix) : acc);
      return toolHtmlAcc ? html + toolHtmlAcc : html;
    }

    fetch('/api/chat.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Client-Type': 'web' }
    })
      .then(function (resp) {
        var ct = resp.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') >= 0) {
          return resp.json().then(function (j) { throw new Error(j.error || '请求失败'); });
        }
        var reader = resp.body.getReader();
        var dec = new TextDecoder('utf-8');
        var buf = '';

        function handleEvent(block) {
          var ev = 'message', data = '';
          block.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) ev = line.slice(6).trim();
            else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
          });
          if (!data) return;
          // 这一轮已被切后台重连接管，后到的回调一律丢掉，
          // 否则会往已经删掉的气泡里写内容，或把新流的进度覆盖回旧值
          if (本代 !== 流代) { return; }
          var j;
          try { j = JSON.parse(data); } catch (e) { return; }

          if (ev === 'meta' && j.conv_id) {
            convId = j.conv_id;
            saveLastConv(convId);
            // 记下这轮任务，刷新后靠它找回没看完的回答
            if (j.run_id) {
              saveRun(convId, Number(j.run_id), 0);
            startPartialTimer();
              // 登记这一轮，供切后台回来后判断流是否还活着
              当前流 = {
                代: 本代, conv: convId, run: Number(j.run_id),
                holder: holder, 活: true, 收尾: false
              };
            }
          } else if (ev === 'delta') {
            acc += j.t;
            holder.__rawText = prevRoundsText + acc;   // 更新原文供引用按钮读取
            if (当前流 && 当前流.代 === 本代) { 当前流.活 = true; }
            写气泡(bubble, 渲染气泡());
            scrollDown();
          } else if (ev === 'fold') {
            // 后端判定开头这段是思考自述，把已显示的内容重渲染成折叠块
            foldPrefix = j.t || '';
            if (foldPrefix) {
              写气泡(bubble, 渲染气泡());
              scrollDown();
            }
          } else if (ev === 'retry_sk') {
            // 当前线路失败、后端已自动切换到另一个线路重试，acc 这时还是空的
            // （否则后端不会切线路），临时把提示文字放进气泡，等下一个 delta 来了自然覆盖掉
            bubble.innerHTML = '<span class="text-muted">' + esc(j.t || '正在自动切换线路重试…') +
              '</span><span class="blink-cursor"></span>';
            scrollDown();
          } else if (ev === 'err') {
            bubble.innerHTML = '<span class="text-err">' + esc(j.msg) + '</span>';
          } else if (ev === 'stopped') {
            // 后端确认已停。半截回答留在气泡里，末尾标一下是被停的，
            // 顺手把刚才发的那句话还给输入框，用户改完直接重发。
            已暂停 = true;
            if (本轮原文) {
              $input.value = 本轮原文;
              $input.style.height = 'auto';
              $input.style.height = Math.min($input.scrollHeight, 200) + 'px';
              $input.focus();
            }
          } else if (ev === 'tool_result') {
            // 工具执行完成，将工具调用信息渲染成可折叠卡片
            if (j.calls && Array.isArray(j.calls)) {
              // 先保存当前轮的正文
              if (acc) {
                prevRoundsHtml += render(foldPrefix ? markFold(acc, foldPrefix) : acc);
                prevRoundsText += acc;
                acc = '';
                foldPrefix = '';
              }

              // 收集所有工具卡片的HTML
              j.calls.forEach(function(call) {
                var toolName = (call.function && call.function.name) || '';
                var toolArgs = (call.function && call.function.arguments) || '{}';
                var callId = call.id || '';

                // 通过 tool_call_id 匹配结果，而不是索引
                var result = '';
                if (j.results && Array.isArray(j.results)) {
                  for (var i = 0; i < j.results.length; i++) {
                    if (j.results[i].tool_call_id === callId) {
                      result = j.results[i].content || '';
                      break;
                    }
                  }
                }

                // 参数 JSON 格式化显示，方便查看命令内容
                var 参数文本 = toolArgs;
                try {
                  参数文本 = JSON.stringify(JSON.parse(toolArgs), null, 2);
                } catch (e) { /* 保持原样 */ }

                // 渲染成与 ssh-card 同风格的可折叠卡片
                var toolHtml = '<div class="tool-call">';
                toolHtml += '<div class="tool-call-head">';
                toolHtml += '<span class="tool-call-ico">▸</span>';
                toolHtml += '<span class="tool-call-title">' + esc(工具说明(toolName, toolArgs)) + '</span>';
                toolHtml += '<span class="tool-call-state good">执行完成</span>';
                toolHtml += '</div>';
                toolHtml += '<div class="tool-call-body" hidden>';
                toolHtml += '<div class="tool-call-label">参数</div>';
                toolHtml += '<pre class="tool-call-args"><code>' + esc(参数文本) + '</code></pre>';
                if (result) {
                  toolHtml += '<div class="tool-call-label">执行结果</div>';
                  toolHtml += '<pre class="tool-call-out"><code>' + esc(result) + '</code></pre>';
                }
                toolHtml += '</div>';
                toolHtml += '</div>';

                // 追加到HTML字符串
                prevRoundsHtml += toolHtml;
              });

              // 一次性写入所有工具卡片
              holder.__rawText = prevRoundsText;
              写气泡(bubble, prevRoundsHtml);
              
              scrollDown();
            }
          } else if (ev === 'done') {
            clearRun();   // 这轮收尾了，没有待续的内容
            clearPartial();
            if (当前流 && 当前流.代 === 本代) { 当前流.收尾 = true; }
            // 气泡是开始生成时就建好的，那会儿的时间戳只是「开始时刻」。
            // 长回答一写就是几十秒，刷成完成时刻才对得上「AI 什么时间回的」，
            // 也和刷新后从库里读出的 created_at 一致。
            var $时 = holder.querySelector('.msg-time');
            if ($时) {
              var 此刻 = new Date();
              $时.textContent = 格式化时间(此刻);
              $时.title = 完整时间(此刻);
            }
            if (已暂停) {
              写气泡(bubble, 渲染气泡() +
                '<div class="stop-note">已暂停生成</div>');
            }
            if (Number(j.tokens_out) > 0 || Number(j.tokens_in) > 0) {
              metaEl.hidden = false;
              metaEl.textContent = 用量文案(j);
            }
            var bt = document.getElementById('balTip');
            if (bt) bt.textContent = j.balance;
            document.querySelectorAll('.balance-chip b').forEach(function (el) {
              el.textContent = '￥' + j.balance;
            });
            // 后端在 done 事件返回 msg_id，设置到消息节点上并补上收藏按钮
            if (j.msg_id && j.msg_id > 0) {
              holder.dataset.msgId = String(j.msg_id);
              if (!holder.querySelector('.msg-fav-btn')) {
                var actBox = holder.querySelector('.msg-actions');
                if (actBox) {
                  actBox.insertAdjacentHTML('beforeend',
                    '<button class="msg-fav-btn" type="button" title="收藏此回答">☆</button>');
                }
              }
              checkFavStatus();
            }
            
            if (isNew) reloadConvs(); else markActive();
          }
        }

        function pump() {
          return reader.read().then(function (res) {
            if (res.done) { return; }
            buf += dec.decode(res.value, { stream: true });
            var idx;
            while ((idx = buf.indexOf('\n\n')) >= 0) {
              handleEvent(buf.slice(0, idx));
              buf = buf.slice(idx + 2);
            }
            return pump();
          });
        }
        return pump();
      })
      .catch(function (e) {
        /* 网络中断时不能把已经显示的内容抹掉：
           后端有 ignore_user_abort(true)，断线后照样跑完并按秒把增量写进 chat_runs。
           现在改为自动尝试重连：通过 chat_resume.php 接回后端仍在运行的流，
           用户不需要手动刷新页面。
           acc 为空（一个字都没收到）时才单独显示错误。 */
        if (acc || prevRoundsHtml) {
          savePartial(convId, prevRoundsText + acc);
          写气泡(bubble, 渲染气泡() +
            '<div class="text-err" style="opacity:0.7">⟳ 连接中断，正在自动重连…</div>');
          scrollDown();
          // 自动重连：后端 ignore_user_abort(true) 仍在跑，通过 resume 接口接回
          var 重连runId = (当前流 && 当前流.run) ? 当前流.run : 0;
          var 重连conv = convId;
          if (重连runId > 0 || 重连conv > 0) {
            setTimeout(function () {
              if (本代 !== 流代) { return; }
              tryReconnect(重连conv, 重连runId, acc.length, holder, bubble, 本代,
                function () {
                  // 重连成功后清掉错误提示
                  var errEl = bubble.querySelector('.text-err');
                  if (errEl) { errEl.remove(); }
                },
                function () {
                  // 重连也失败，显示最终错误提示
                  savePartial(convId, prevRoundsText + acc);
                  写气泡(bubble, 渲染气泡() +
                    '<div class="text-err">⚠ 网络中断，已生成的内容不会丢失，刷新页面可恢复</div>');
                });
            }, 1500);
          }
        } else {
          bubble.innerHTML = '<span class="text-err">' + esc(e.message || '网络错误') + '</span>';
        }
      })
      .then(function () {
        // 已被切后台重连接管：那边会自己收尾，这里什么都别做
        if (本代 !== 流代) { return; }
        当前流 = null;
        // acc 有内容时保留 catch 里写好的气泡（含错误提示），不要覆盖
        if (acc === '' && prevRoundsHtml === '' && bubble.querySelector('.typing')) {
          bubble.innerHTML = '<span class="text-err">未收到回复，请重试</span>';
        }
        /* 收尾补围栏：模型偶尔漏写结尾的三反引号（输出被 max_tokens 截断时尤其常见）。
           流已经结束，不会再有内容进来，那个未闭合的块只能停在 pend 占位卡片上，
           而各 AutoRun 的选择器一律带 :not(.pend)，于是命令不执行、回执发不出，
           表现就是 ssh-exec 以纯文本露在正文里、第二条消息始终不来。
           这里在流结束这一刻补一个闭围栏再重绘，让它走正常的 sshCard 渲染。
           网络中断时 catch 已经写了错误提示，补围栏后要把提示也带回去，不能抹掉。 */
        var 网络中断了 = (acc !== '' || prevRoundsHtml !== '') && !!bubble.querySelector('.text-err');
        if (acc && (acc.split('\x60\x60\x60').length - 1) % 2 === 1) {
          acc += '\n\x60\x60\x60';
          // 必须走写气泡：这里直接赋 innerHTML 会抹掉卡片的 data-*-done，
          // 本轮若已有卡片在跑，重绘后会被 AutoRun 再挑一次重复执行。
          var 补围栏html = 渲染气泡();
          if (网络中断了) {
            补围栏html += '<div class="text-err">⚠ 网络中断，已生成的内容不会丢失，刷新页面可恢复</div>';
          }
          写气泡(bubble, 补围栏html);
        }
        // 流式结束，应用语法高亮到最终内容
        highlightCodeBlocks(bubble);
        // 这条回复里有没有等着跑的卡片。有的话先把按钮锁上再解 busy，
        // 两个动作之间没有重绘，按钮不会闪一下「发送」。
        var 有卡片 = !!bubble.querySelector(
          // 选择器和各 AutoRun 里的判断保持完全一致。多一个属性条件就可能
          // 漏判——卡片实际在跑、按钮却没锁，正是要修的那个 bug。
          // 反过来多锁了不要紧，下面「一张都没跑起来」那步会解开。
          '.sftp-card-op:not(.pend):not([data-sftp-done="1"]),' +
          '.repo-card:not(.pend):not([data-repo-done="1"]),' +
          '.ws-card-op:not(.pend):not([data-ws-done="1"]),' +
          '.ssh-card:not(.pend):not([data-ssh-done="1"]),' +
          '.ppt-card-op:not(.pend):not([data-ppt-done="1"])'
        );
        // 暂停发生在 AI 吐字期间：流断了，但这轮已经渲染出来的卡片还在。
        // 不能接着自动跑——用户按暂停就是不想让它继续动作。
        // 卡片留在页面上，他想跑哪张自己点。
        if (链已取消) {
          setBusy(false);
          return;
        }
        if (有卡片) { 工具开始(); }
        setBusy(false);

        /* 等待闸：AI 写了等待标记就停下，不管有没有卡片。
           卡片留在页面上，用户自己决定跑不跑。这样 AI 想让用户选方案时，
           即使不小心输出了演示卡片，也不会因为「有卡片」就被当成在干活而继续跑。 */
        if (等待用户确认(prevRoundsText + acc)) {
          等待确认中 = true;   // 连带冻住 processQueue，堵掉队列那条绕行路
          提示等待确认();
          return;              // 整条自动执行链跳过
        }

        // 回复结束后自动执行这条回复里的命令卡片。
        // 必须放在 setBusy(false) 之后：结果要靠 fillSshResult 发出去，busy 时发不掉。
        // 仓卡片优先：改代码的动作比敲命令更常用，也更安全。
        // 两者不在同一轮同时跑，避免一条回复里触发两次自动执行把上下文搞乱。
        // SFTP 直连最优先：开发网站的读改删都走它，是最常用的一类动作。
        
        // 拆分后的消息：需要在所有拆分气泡中查找卡片并执行
        var 所有气泡 = [bubble];
        if (holder.dataset.已拆分 === '1' && holder.__所有拆分气泡) {
          所有气泡 = holder.__所有拆分气泡.map(function(item) { return item.bubble; });
        }
        
        var 跑了直连 = false;
        if (window.sftpAutoRun) {
          所有气泡.forEach(function(b) {
            if (b.querySelector('.sftp-card-op:not(.pend):not([data-sftp-done="1"])[data-sftp-act]')) {
              跑了直连 = true;
              window.sftpAutoRun(b);
            }
          });
        }
        var 跑了仓 = false;
        if (!跑了直连 && window.repoAutoRun) {
          所有气泡.forEach(function(b) {
            if (b.querySelector('.repo-card:not(.pend):not([data-repo-done="1"])[data-act]')) {
              跑了仓 = true;
              window.repoAutoRun(b);
            }
          });
        }
        // 工作中心文件卡片：仓卡片没跑时才跑，一轮只触发一类，免得上下文被两份结果搅乱
        var 跑了工作区 = false;
        if (!跑了直连 && !跑了仓 && window.wsAutoRun) {
          所有气泡.forEach(function(b) {
            if (b.querySelector('.ws-card-op:not(.pend):not([data-ws-done="1"])')) {
              跑了工作区 = true;
              window.wsAutoRun(b);
            }
          });
        }
        var 跑了命令 = false;
        if (!跑了直连 && !跑了仓 && !跑了工作区 && window.sshAutoRun) {
          所有气泡.forEach(function(b) {
            if (b.querySelector('.ssh-card:not(.pend):not([data-ssh-done="1"])')) {
              跑了命令 = true;
              window.sshAutoRun(b);
            }
          });
        }
        // PPT 卡片：排在最后，因为它是终端产出物（生成完就交付客户了），
        // 不像前几类那样需要把结果喂回去继续干活。
        var 跑了PPT = false;
        if (!跑了直连 && !跑了仓 && !跑了工作区 && !跑了命令 && window.pptAutoRun) {
          所有气泡.forEach(function(b) {
            if (b.querySelector('.ppt-card-op:not([data-ppt-done="1"])')) {
              跑了PPT = true;
              window.pptAutoRun(b);
            }
          });
        }
        // 网页抓取排在最后：前面几类都是改东西的活，抓网页只是取信息，
        // 同一轮里两者都有时先让改动跑完，顺序和 AI 写卡片的先后一致。
        var 跑了网页 = false;
        if (!跑了直连 && !跑了仓 && !跑了工作区 && !跑了命令 && !跑了PPT && window.webAutoRun) {
          跑了网页 = !!bubble.querySelector('.web-card-op:not([data-web-done="1"])');
          window.webAutoRun(bubble);
        }
        // 没有任何卡片要跑，才算这个任务真的做完了——有卡片说明 AI 还要接着
        // 拿结果继续干活，那时候响一声是误报。
        if (!跑了直连 && !跑了仓 && !跑了工作区 && !跑了命令 && !跑了PPT && !跑了网页) {
          // 一张都没真的跑起来（比如卡片缺 raw 数据），把上面预先上的锁解掉，
          // 否则要干等看门狗超时才恢复。
          工具结束();
          // 这里不能再拿 静默 当条件。静默只表示「不渲染用户气泡」，
          // 而「AI 给命令卡 → 执行 → 回执 → AI 总结」这条链里，收尾那轮
          // 必然是回执轮（静默=true），按 静默 过滤等于把最该响的一声滤掉了。
          // 判断任务是否结束只看还有没有卡片要跑，这跟本轮是不是回执无关。
          // 队列里还压着回执时不响：setBusy(false) 会在 100ms 后把它发出去，
          // 那说明活还没干完，这一声属于早报。
          if (!resultQueue.length) { 完成提醒(prevRoundsText + acc); }
        }
      });
  }

  /* ---------- 断线重连：接回没看完的那轮回答 ----------
     进页面或切回某个会话时调用。后端那轮可能还在跑（生成进程不受浏览器影响），
     也可能已经跑完了——两种情况 chat_resume.php 都会把内容补齐再收尾，
     所以这里不需要分情况处理。

     @param {number} conv 会话 ID
     @param {number} run  生成任务 ID
     @param {number} from 已经显示了多少字符，从这个位置往后要 */
  function 接回生成(conv, run, from) {
    if (busy) { return; }
    setBusy(true);

    var holder = addMsg('assistant', '', '');
    var bubble = holder.querySelector('.bubble');
    var metaEl = holder.querySelector('.meta');
    bubble.innerHTML = '<span class="typing"></span>';

    var acc = '';
    var foldPrefix = '';
    var 已暂停 = false;   // 同正常发送那条路，收到 stopped 后据它标注气泡

    // 接回途中再次切后台也要能救回来，所以这一轮同样登记进 当前流
    var 本代 = ++流代;
    当前流 = { 代: 本代, conv: conv, run: run, holder: holder, 活: true, 收尾: false };

    var url = '/api/chat_resume.php?conv_id=' + conv + '&run_id=' + run +
              '&from=' + (from || 0);

    fetch(url, { credentials: 'same-origin' })
      .then(function (resp) {
        var ct = resp.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') >= 0) {
          // 任务找不到了（被清理或不属于本人），当作没有待续内容
          return resp.json().then(function () { clearRun(); holder.remove(); });
        }
        var reader = resp.body.getReader();
        var dec = new TextDecoder('utf-8');
        var buf = '';

        function handle(block) {
          var ev = 'message', data = '';
          block.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) ev = line.slice(6).trim();
            else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
          });
          if (!data) return;
          // 已被更新的一轮接管，过期回调丢掉
          if (本代 !== 流代) { return; }
          var j;
          try { j = JSON.parse(data); } catch (e) { return; }

          if (ev === 'delta') {
            acc += j.t;
            holder.__rawText = acc;   // 更新原文供引用按钮读取
            if (当前流 && 当前流.代 === 本代) { 当前流.活 = true; }
            写气泡(bubble, render(foldPrefix ? markFold(acc, foldPrefix) : acc));
            scrollDown();
          } else if (ev === 'fold') {
            foldPrefix = j.t || '';
            if (foldPrefix) {
              写气泡(bubble, render(markFold(acc, foldPrefix)));
              scrollDown();
            }
          } else if (ev === 'retry_sk') {
            // 当前线路失败、后端已自动切到另一个线路重试，acc 这时还是空的
            bubble.innerHTML = '<span class="text-muted">' + esc(j.t || '正在自动切换线路重试…') +
              '</span><span class="blink-cursor"></span>';
            scrollDown();
          } else if (ev === 'err') {
            // 中断提示追加在已有内容后面，别把前面生成好的部分抹掉
            写气泡(bubble, (acc ? render(foldPrefix ? markFold(acc, foldPrefix) : acc) : '') +
              '<div class="text-err">' + esc(j.msg) + '</div>');
          } else if (ev === 'stopped') {
            // 这轮是被暂停的（可能是在别的标签页点的，也可能点完就切了后台）
            已暂停 = true;
          } else if (ev === 'idle') {
            // 后端盯到上限主动让我们重连，带上已显示的长度接着要
            saveRun(conv, run, acc.length);
            setBusy(false);
            接回生成(conv, run, acc.length);
          } else if (ev === 'done') {
            clearRun();
            if (当前流 && 当前流.代 === 本代) { 当前流.收尾 = true; }
            // 和正常流一致：收到 done 才把时间刷成回复完成时刻
            var $时2 = holder.querySelector('.msg-time');
            if ($时2) {
              var 此刻2 = new Date();
              $时2.textContent = 格式化时间(此刻2);
              $时2.title = 完整时间(此刻2);
            }
            if (已暂停) {
              写气泡(bubble, render(foldPrefix ? markFold(acc, foldPrefix) : acc) +
                '<div class="stop-note">已暂停生成</div>');
            }
            if (Number(j.tokens_out) > 0 || Number(j.tokens_in) > 0) {
              metaEl.hidden = false;
              metaEl.textContent = 用量文案(j);
            }
            if (j.balance) {
              var bt = document.getElementById('balTip');
              if (bt) bt.textContent = j.balance;
              document.querySelectorAll('.balance-chip b').forEach(function (el) {
                el.textContent = '￥' + j.balance;
              });
            }
            // 和正常流一致：done 事件返回 msg_id 后补上收藏按钮
            if (j.msg_id && j.msg_id > 0) {
              holder.dataset.msgId = String(j.msg_id);
              if (!holder.querySelector('.msg-fav-btn')) {
                var actBox2 = holder.querySelector('.msg-actions');
                if (actBox2) {
                  actBox2.insertAdjacentHTML('beforeend',
                    '<button class="msg-fav-btn" type="button" title="收藏此回答">☆</button>');
                }
              }
              checkFavStatus();
            }
            markActive();
          }
        }

        function pump() {
          return reader.read().then(function (res) {
            if (res.done) { return; }
            buf += dec.decode(res.value, { stream: true });
            var idx;
            while ((idx = buf.indexOf('\n\n')) >= 0) {
              handle(buf.slice(0, idx));
              buf = buf.slice(idx + 2);
            }
            return pump();
          });
        }
        return pump();
      })
      .catch(function () {
        // 网络断了：保留已显示的内容，让用户知道还能再试
        if (acc === '') {
          holder.remove();
        } else {
          写气泡(bubble, render(foldPrefix ? markFold(acc, foldPrefix) : acc) +
            '<div class="text-err">⚠ 网络中断，内容不会丢失，刷新可恢复</div>');
        }
      })
      .then(function () {
        // 已被新一轮接管（比如接回途中又切了后台），交给那边收尾
        if (本代 !== 流代) { return; }
        当前流 = null;
        if (acc === '' && bubble.querySelector('.typing')) { holder.remove(); }
        setBusy(false);
        // 补完的这轮里可能带着命令卡片，和正常收尾一样触发自动执行
        if (acc !== '') {
          if (window.sftpAutoRun) { window.sftpAutoRun(bubble); }
          else if (window.repoAutoRun) { window.repoAutoRun(bubble); }
          highlightCodeBlocks(bubble);
          // 接回场景提醒最有用：用户往往切去干别的了，靠这个知道可以回来看
          完成提醒(acc);
        }
      });
  }

  /* 打开会话后判断要不要接回上一轮。
     只有后端确认「还在跑」才接：已经收尾的回答早就落进 messages 了，
     上面的历史渲染已经把它显示出来，再接一次会重复显示同一条。 */
  function 试着接回(conv) {
    var r = readRun();
    if (!r || Number(r.conv) !== Number(conv)) {
      检查本地缓存(conv);   // 没有运行记录，检查本地缓存兜底
      return;
    }

    fetch('/api/chat_resume.php?act=stat&conv_id=' + conv + '&run_id=' + r.run,
          { credentials: 'same-origin' })
      .then(function (resp) { return resp.json(); })
      .then(function (j) {
        if (j.error || j.status !== 'running') {
          clearRun();     // 已收尾或已中断，历史里就是最终结果
          检查本地缓存(conv);   // 运行已结束，检查本地缓存兜底
          return;
        }
        接回生成(conv, Number(r.run), Number(r.from) || 0);
      })
      .catch(function () {
        检查本地缓存(conv);   // 查不到状态，检查本地缓存兜底
      });
  }

  /* ---------- 切后台再回来 ----------
     手机上切到别的应用或锁屏，系统会冻结页面，fetch 的流被掐断且不抛错——
     pump() 就那么悬着，回到前台后既不继续也不报错，界面永远停在打字动画。
     这里在页面重新可见时主动收拾：那轮流已经断了就丢掉半截气泡，
     走已有的接回逻辑从后端补齐（后端有 ignore_user_abort，一直在跑并按秒存进度）。
     
     分屏场景：安卓分屏时visibilityState会在visible和hidden间快速切换，
     需要防抖避免频繁重连导致卡死。只在真正需要恢复时才触发。 */
  var 恢复定时 = 0;
  var 上次隐藏时间 = 0;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      上次隐藏时间 = Date.now();
      // 清掉待执行的恢复，避免分屏抖动时重复触发
      if (恢复定时) { clearTimeout(恢复定时); 恢复定时 = 0; }
      return;
    }

    var 流 = 当前流;
    if (!流 || 流.收尾) { return; }
    
    // 分屏或快速切换时隐藏时长很短，流还活着不用重连。
    // 超过3秒才认为是真的切后台，需要恢复
    var 隐藏时长 = Date.now() - 上次隐藏时间;
    if (隐藏时长 < 3000) {
      // 短暂切换，检查流是否还在活动
      流.活 = false;
      // 1秒后检查：期间收到delta就说明流正常，不重连
      if (恢复定时) { clearTimeout(恢复定时); }
      恢复定时 = setTimeout(function() {
        恢复定时 = 0;
        var 当 = 当前流;
        // 1秒内没收到任何数据才重连
        if (当 && 当.代 === 流.代 && !当.活 && !当.收尾) {
          执行恢复(流);
        }
      }, 1000);
      return;
    }
    
    // 长时间隐藏（超过3秒），直接恢复
    if (恢复定时) { clearTimeout(恢复定时); 恢复定时 = 0; }
    执行恢复(流);
  });
  
  function 执行恢复(流) {
    // 作废旧流：它的回调后面可能还会零星到达，靠版本号让它们全部失效
    流代++;
    当前流 = null;

    fetch('/api/chat_resume.php?act=stat&conv_id=' + 流.conv + '&run_id=' + 流.run,
          { credentials: 'same-origin' })
      .then(function (resp) { return resp.json(); })
      .then(function (j) {
        if (流.holder && 流.holder.parentNode) { 流.holder.remove(); }
        setBusy(false);

        if (j.status === 'running') {
          // 后端还在生成，从头接回到一个新气泡（半截的已经删掉，不会重复）
          接回生成(流.conv, 流.run, 0);
        } else {
          // 冻结期间已经跑完了，最终结果在库里，重载这个会话把它显示出来
          clearRun();
          if (Number(convId) === Number(流.conv)) { openConv(流.conv, true); }
        }
      })
      .catch(function () {
        // 查不到状态：保留半截内容，把状态复位，用户可以自己刷新
        setBusy(false);
      });
  }

  /* 手动发送：把连续自动执行的计数归零。
     不能直接在 send() 里重置，因为命令结果回传也走 send()，那样计数永远清不掉。 */
  function 手动发送() {
    // 工具在跑那段 busy 是 false，send() 自己那道 busy 闸拦不住。
    // 少了这一道，用户在命令执行期间按回车照样能把消息插进去，
    // 输入框跟着被清空——这正是「输入的内容突然消失」。
    if (busy || 工具执行中) { return; }
    // 用户开口了，等待的理由消失，解冻回执队列。
    // 放在这里而不是 send()：回执回传也走 send()，那样刚冻上就被自己解开。
    等待确认中 = false;
    // 上一轮点过暂停的话，取消标记还立着。用户又开口说话，等于开一条新链，
    // 这里清掉，否则新任务里的工具回执会被那个旧标记一直挡住，链接不上去。
    链已取消 = false;
    if (window.sshResetAuto) { window.sshResetAuto(); }
    if (window.repoResetAuto) { window.repoResetAuto(); }
    if (window.sftpResetAuto) { window.sftpResetAuto(); }
    if (window.wsResetAuto) { window.wsResetAuto(); }
    if (window.pptResetAuto) { window.pptResetAuto(); }
    if (window.webResetAuto) { window.webResetAuto(); }
    // 回执已不再经过输入框，手动点发送的一定是用户自己的话
    send(false);
  }

  $send.addEventListener('click', 手动发送);

  /* 「上一条需求」：跳到上一条自己发的消息。
     连续点会继续往更早的翻，翻到第一条就停在那儿。
     长任务往往刷出好几屏，想回看自己提的原始需求得一直往上滚，这个按钮省掉这一步。 */
  var $上条 = document.getElementById('btnPrevAsk');
  if ($上条) {
    // 光标停在第几条用户消息上（从后往前数，0 = 还没开始翻）
    var 定位光标 = 0;

    function 我的消息() {
      return $inner.querySelectorAll('.msg.user');
    }

    // 没有用户消息就禁用，避免点了没反应让人以为坏了
    function 刷新上条按钮() {
      $上条.disabled = 我的消息().length === 0;
    }

    // 切会话、发新消息之后重新从最后一条开始翻
    window.重置需求定位 = function () {
      定位光标 = 0;
      刷新上条按钮();
    };

    $上条.addEventListener('click', function () {
      var 表 = 我的消息();
      if (!表.length) { return; }
      // 每次往前挪一条，到头了就停在第一条
      定位光标 = Math.min(定位光标 + 1, 表.length);
      var 目标 = 表[表.length - 定位光标];
      if (!目标) { return; }

      // 跳过去等于用户主动上翻。滚动本身会触发 scroll 事件，
      // 那个处理器会据「是否在底部」把 跟随 置为 false，
      // 所以这里不用手动关——AI 还在输出时也不会被立刻拽回底部。
      目标.scrollIntoView({ block: 'center', behavior: 'smooth' });

      // 闪一下让用户看清停在哪条。重复触发前先摘掉 class 再加，
      // 否则同一元素上动画不会重播。
      目标.classList.remove('locate-hit');
      void 目标.offsetWidth;
      目标.classList.add('locate-hit');
      setTimeout(function () { 目标.classList.remove('locate-hit'); }, 1800);

      // 翻到最早那条时给个提示，免得用户以为按钮卡住了。
      // 复用底部那行状态提示，2 秒后自动还原成免责文案。
      showModelTip(定位光标 >= 表.length
        ? '已经是第一条需求'
        : '上数第 ' + 定位光标 + ' 条需求');
    });

    刷新上条按钮();
  }

  /* 提示音面板。点喇叭弹出，里面有开关和音量条，设置按用户存在本地。
     面板里的操作都会 stopPropagation：否则冒泡到 document 上那个
     「点空白处关闭」的监听，面板会刚点就关。 */
  var $声 = document.getElementById('btnSound');
  var $面板 = document.getElementById('soundPop');
  if ($声 && $面板) {
    var $开关 = document.getElementById('soundOn');
    var $滑块 = document.getElementById('soundVol');
    var $数字 = document.getElementById('soundVolNum');
    var $试听 = document.getElementById('soundTest');
    var $通知 = document.getElementById('notifyOn');
    var $标题 = document.getElementById('titleOn');
    var $说明 = document.getElementById('soundNote');

    // 旧版把开关存在全局键 chat_sound 里（不分用户）。迁移一次，
    // 让老用户的「已关闭」选择不丢；迁移后删掉旧键，避免反复覆盖新值。
    var 旧键 = localStorage.getItem('chat_sound');
    if (旧键 !== null) {
      if (localStorage.getItem(声键('on')) === null) {
        localStorage.setItem(声键('on'), 旧键 === 'off' ? 'off' : 'on');
      }
      localStorage.removeItem('chat_sound');
    }

    var 刷新声界面 = function () {
      var 开 = 声开着();
      var 量 = 声音量();
      $声.textContent = 开 ? '🔔 提示音' : '🔕 提示音';
      $声.classList.toggle('off', !开);
      $声.title = 开 ? '任务完成提示音：已开启（' + 量 + '%）' : '任务完成提示音：已关闭';
      $开关.checked = 开;
      $滑块.value = 量;
      $数字.textContent = 量 + '%';
      $面板.classList.toggle('muted', !开);

      // 通知勾选框以「存了 on」和「真的授权了」同时成立为准：
      // 用户可能在浏览器设置里把权限撤了，这时存的值还是 on，
      // 照它显示会让用户以为开着，实际收不到。
      var 有权限 = ('Notification' in window) && Notification.permission === 'granted';
      $通知.checked = 通知开着() && 有权限;
      $标题.checked = 标题开着();

      // 说明文案分三种情况，避免用户对着不响的开关瞎猜
      $说明.classList.remove('warn');
      if (!('Notification' in window)) {
        $说明.textContent = '当前浏览器不支持桌面通知；标题闪烁不受影响。';
      } else if (Notification.permission === 'denied') {
        $说明.textContent = '桌面通知已被浏览器阻止，需在地址栏左侧的站点设置里改回「允许」。';
        $说明.classList.add('warn');
      } else {
        $说明.textContent = '桌面通知和标题闪烁只在你切到别的窗口时触发。';
      }
    };

    var 收面板 = function () {
      $面板.hidden = true;
      $声.setAttribute('aria-expanded', 'false');
    };

    刷新声界面();

    $声.addEventListener('click', function (e) {
      e.stopPropagation();
      var 要开 = $面板.hidden;
      $面板.hidden = !要开;
      $声.setAttribute('aria-expanded', 要开 ? 'true' : 'false');
    });

    $面板.addEventListener('click', function (e) { e.stopPropagation(); });

    $开关.addEventListener('change', function () {
      localStorage.setItem(声键('on'), $开关.checked ? 'on' : 'off');
      刷新声界面();
      // 打开时响一声，让用户当场听到音量；这次点击也顺便满足了
      // 浏览器「必须有用户交互才能播音频」的要求
      if ($开关.checked) { 提示音(); }
    });

    // input 事件在拖动过程中连续触发：只更新数字，不出声，
    // 否则拖一下会响几十声。声音留给 change（松手时）。
    $滑块.addEventListener('input', function () {
      $数字.textContent = $滑块.value + '%';
    });
    $滑块.addEventListener('change', function () {
      localStorage.setItem(声键('vol'), String($滑块.value));
      刷新声界面();
      提示音(parseInt($滑块.value, 10));
    });

    $试听.addEventListener('click', function () { 提示音(声音量()); });

    /* 桌面通知开关。勾选时要现场申请授权：
       requestPermission() 必须在用户手势里调，放到别处 Chrome 会直接拒。
       用户点了「阻止」就把勾选弹回去，并在说明里讲清怎么改——
       静默失败的话用户会一直以为开着，然后抱怨没收到通知。 */
    $通知.addEventListener('change', function () {
      if (!$通知.checked) {
        localStorage.setItem(声键('notify'), 'off');
        刷新声界面();
        return;
      }
      if (!('Notification' in window)) { 刷新声界面(); return; }
      if (Notification.permission === 'granted') {
        localStorage.setItem(声键('notify'), 'on');
        刷新声界面();
        return;
      }
      if (Notification.permission === 'denied') {
        // 已经被拒过，浏览器不会再弹授权框，只能引导用户去站点设置
        刷新声界面();
        return;
      }
      Notification.requestPermission().then(function (结果) {
        localStorage.setItem(声键('notify'), 结果 === 'granted' ? 'on' : 'off');
        刷新声界面();
      }).catch(function () { 刷新声界面(); });
    });

    $标题.addEventListener('change', function () {
      localStorage.setItem(声键('title'), $标题.checked ? 'on' : 'off');
      // 关掉时如果正在闪，立刻停下并还原标题
      if (!$标题.checked) { 停止闪烁(); }
      刷新声界面();
    });

    // 点面板外收起。用 document 上的冒泡监听，配合上面的 stopPropagation。
    document.addEventListener('click', function () {
      if (!$面板.hidden) { 收面板(); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !$面板.hidden) { 收面板(); }
    });
  }

  /* 上下文条数面板（会话级，已持久化）。开合交互参照提示音面板：
     按钮和面板内部 stopPropagation，document 冒泡监听点空白收起。
     设置随会话保存到 conversations.context_limit，切换/重开页面自动恢复。 */
  var $ctxBtn = document.getElementById('btnCtx');
  var $ctxPop = document.getElementById('ctxPop');
  if ($ctxBtn && $ctxPop) {
    var $ctxNum = document.getElementById('ctxNum');
    var $ctxApply = document.getElementById('ctxApply');
    var $ctxResetBtn = document.getElementById('ctxReset');

    var closeCtxPop = function () {
      $ctxPop.hidden = true;
      $ctxBtn.setAttribute('aria-expanded', 'false');
    };

    $ctxBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var 要开 = $ctxPop.hidden;
      // 打开时把当前值回填输入框；空表示跟随模型默认
      if (要开) { $ctxNum.value = ctxLimit > 0 ? String(ctxLimit) : ''; }
      $ctxPop.hidden = !要开;
      $ctxBtn.setAttribute('aria-expanded', 要开 ? 'true' : 'false');
      if (要开) { $ctxNum.focus(); }
    });

    $ctxPop.addEventListener('click', function (e) { e.stopPropagation(); });

    $ctxApply.addEventListener('click', function () {
      var raw = $ctxNum.value.trim();
      var v = 0;
      if (raw !== '') {
        v = parseInt(raw, 10);
        if (isNaN(v) || v < 2 || v > 60) {
          alert('上下文条数请填写 2 到 60 之间的整数');
          return;
        }
      }
      ctxLimit = v;               // 留空 = 跟随模型默认
      refreshCtxBtn();
      saveCtxLimit(convId, v);    // 会话级持久化；新对话未建库时留给首条消息
      closeCtxPop();
    });

    $ctxResetBtn.addEventListener('click', function () {
      ctxLimit = 0;
      $ctxNum.value = '';
      refreshCtxBtn();
      saveCtxLimit(convId, 0);    // 恢复默认也要落库，否则下次打开还是旧值
      closeCtxPop();
    });

    // 输入框里按回车直接应用
    $ctxNum.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); $ctxApply.click(); }
    });

    document.addEventListener('click', function () {
      if (!$ctxPop.hidden) { closeCtxPop(); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !$ctxPop.hidden) { closeCtxPop(); }
    });
  }

  /* 暂停：只发一个「请停下」的请求，真正断流由后端做。
     后端最多 1 秒读到这个标记，所以点完可能再吐一点内容，属正常。
     停下后会收到 stopped 事件，那边负责回填输入框。

     按钮不在请求返回时就复位：请求返回只代表「标记已置」，不代表流已经停。
     早早弹回「暂停」会让用户以为没生效而反复点。真正的复位交给 setBusy(false)，
     也就是这一轮彻底收尾的时候。 */
  if ($stop) {
    $stop.addEventListener('click', function () {
      /* 工具在跑、AI 没在吐字：没有流可断，要做的是把整条链掐断。
         服务端那条命令拦不住，但它回来后不会再自动发下一轮。 */
      if (!busy) {
        if (!工具执行中) { return; }
        链已取消 = true;
        resultQueue.length = 0;   // 已排队的回执一并作废
        工具结束();               // 解锁按钮，弹回「发送」
        showModelTip('已停下，命令结果不再自动发给 AI');
        return;
      }
      链已取消 = true;
      var 流 = 当前流;
      // 没有 当前流 说明这轮的登记丢了（比如切后台重连的间隙），
      // 这时仍然按会话去停，别直接 return 让按钮毫无反应。
      var runId = (流 && 流.run) ? 流.run : 0;
      var cid   = (流 && 流.conv) ? 流.conv : convId;
      if (!runId && !cid) { return; }

      $stop.disabled = true;
      $stop.textContent = '停止中';
      var fd = new FormData();
      if (runId) { fd.append('run_id', runId); }
      if (cid)   { fd.append('conv_id', cid); }
      fd.append('csrf', window.CSRF);
      fetch('/api/chat_stop.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (j) {
          // 后端说这轮已经不在跑了：不会再有 stopped/done 事件来复位按钮，
          // 这里自己复位，否则会一直卡在「停止中」。
          if (j && j.status && j.status !== 'stopping') {
            $stop.disabled = false;
            $stop.textContent = '暂停';
          }
        })
        .catch(function () {
          // 请求都没发出去，标记肯定没置上，复位让用户可以再试
          $stop.disabled = false;
          $stop.textContent = '暂停';
        });
    });
  }

  $input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      手动发送();
    }
  });
  $input.addEventListener('input', function () {
    $input.style.height = 'auto';
    $input.style.height = Math.min($input.scrollHeight, 180) + 'px';
  });

  if ($modelSel) {
    $modelSel.addEventListener('change', function () {
      updatePrice();
      syncVision();
      persistModel();     // 切换即保存，下一轮按新模型计费
    });
    // 启动时先套用本地记住的模型；若随后恢复了某个会话，会被会话里的模型覆盖
    applyModel(readLastModel());
    updatePrice();
    syncVision();
    loadPicked();
  }

  /* ---------- 启动：恢复上次的会话 ----------
     会话列表由 PHP 渲染，这里只负责把上次打开的那个重新载入。
     若该会话已被删除或不属于当前用户，服务端会返回错误，此时静默回到新对话。 */
  // 恢复上次的对话。project.js 会先异步拉项目并展开，
  // 所以这里等它把对话渲染出来再判断，最多等 3 秒。
  (function restoreLastConv() {
    var last = readLastConv();
    if (last <= 0) { return; }
    var 次数 = 0;
    var 计时 = setInterval(function () {
      次数++;
      var 有 = $convList && $convList.querySelector('.conv-item[data-id="' + last + '"]');
      if (有) {
        clearInterval(计时);
        openConv(last, true);
      } else if (次数 > 30) {
        clearInterval(计时);
        saveLastConv(0);
      }
    }, 100);
  })();
})();

/* 模型选择：自定义下拉（替代原生 select，避免移动端弹出大弹窗） */
(function () {
  var sel = document.getElementById('modelSel');
  if (!sel) return;

  var opts = [];
  for (var i = 0; i < sel.options.length; i++) {
    var o = sel.options[i];
    opts.push({
      value: o.value,
      text: o.textContent,
      dataIn: o.dataset.in,
      dataOut: o.dataset.out,
      dataVision: o.dataset.vision
    });
  }

  var wrap = document.createElement('div');
  wrap.className = 'model-dropdown';
  wrap.style.cssText = 'position:relative;display:inline-block;';

  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'model-dropdown-btn';
  btn.innerHTML = '<span class="md-label"></span><span class="md-arrow">▾</span>';

  var panel = document.createElement('div');
  panel.className = 'model-dropdown-panel';

  function renderList() {
    var cur = sel.value;
    var html = '';
    for (var i = 0; i < opts.length; i++) {
      var opt = opts[i];
      var seled = opt.value === cur;
      html += '<div class="md-item' + (seled ? ' seled' : '') + '" data-i="' + i + '">';
      html += '<span class="md-item-text">' + opt.text + '</span>';
      if (seled) html += '<span class="md-check">✓</span>';
      html += '</div>';
    }
    panel.innerHTML = html;
  }

  function updateBtn() {
    var cur = sel.value;
    var opt = null;
    for (var i = 0; i < opts.length; i++) {
      if (opts[i].value === cur) { opt = opts[i]; break; }
    }
    btn.querySelector('.md-label').textContent = opt ? opt.text : '';
  }

  var open = false;
  function toggle(show) {
    open = show !== undefined ? show : !open;
    panel.style.display = open ? 'block' : 'none';
    if (open) renderList();
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    toggle();
  });

  panel.addEventListener('click', function (e) {
    var item = e.target.closest('.md-item');
    if (!item) return;
    var idx = Number(item.getAttribute('data-i'));
    if (isNaN(idx) || !opts[idx]) return;
    sel.selectedIndex = idx;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    updateBtn();
    toggle(false);
  });

  document.addEventListener('click', function () {
    if (open) toggle(false);
  });

  wrap.appendChild(btn);
  wrap.appendChild(panel);
  sel.parentNode.insertBefore(wrap, sel);
  sel.style.display = 'none';

  updateBtn();
  sel.addEventListener('change', updateBtn);
})();

/**
 * 恢复卡片状态：刷新页面后，根据消息历史恢复卡片的执行状态。
 * 
 * 逻辑：遍历所有卡片，检查后续消息里是否有对应的系统回执。
 * 如果有回执，说明已执行，提取状态和输出内容，恢复到卡片上。
 */
function 恢复卡片状态() {
  var 所有气泡 = document.querySelectorAll('.msg');
  var 气泡数组 = Array.prototype.slice.call(所有气泡);

  气泡数组.forEach(function (气泡, 索引) {
    // SSH 命令卡片
    var sshCards = 气泡.querySelectorAll('.ssh-card:not(.pend)');
    Array.prototype.forEach.call(sshCards, function (card) {
      if (card.getAttribute('data-ssh-done') === '1') return;
      
      var 回执 = 查找系统回执(气泡数组, 索引, 'ssh');
      if (回执) {
        card.setAttribute('data-ssh-done', '1');
        恢复SSH状态(card, 回执);
      }
    });

    // SFTP 文件操作卡片
    var sftpCards = 气泡.querySelectorAll('.sftp-card-op:not(.pend)');
    Array.prototype.forEach.call(sftpCards, function (card) {
      if (card.getAttribute('data-sftp-done') === '1') return;
      
      var 回执 = 查找系统回执(气泡数组, 索引, 'sftp');
      if (回执) {
        card.setAttribute('data-sftp-done', '1');
        恢复SFTP状态(card, 回执);
      }
    });

    // 本地仓库操作卡片
    var repoCards = 气泡.querySelectorAll('.repo-card:not(.sftp-card-op):not(.web-card-op):not(.ppt-card-op):not(.pend)');
    Array.prototype.forEach.call(repoCards, function (card) {
      if (card.getAttribute('data-repo-done') === '1') return;
      
      var 回执 = 查找系统回执(气泡数组, 索引, 'repo');
      if (回执) {
        card.setAttribute('data-repo-done', '1');
        恢复Repo状态(card, 回执);
      }
    });

    // 工作中心文件卡片
    var wsCards = 气泡.querySelectorAll('.ws-card-op:not(.pend)');
    Array.prototype.forEach.call(wsCards, function (card) {
      if (card.getAttribute('data-ws-done') === '1') return;
      
      var 回执 = 查找系统回执(气泡数组, 索引, 'ws');
      if (回执) {
        card.setAttribute('data-ws-done', '1');
        恢复WS状态(card, 回执);
      }
    });

    // 网页抓取卡片
    var webCards = 气泡.querySelectorAll('.web-card-op:not(.pend)');
    Array.prototype.forEach.call(webCards, function (card) {
      if (card.getAttribute('data-web-done') === '1') return;
      
      var 回执 = 查找系统回执(气泡数组, 索引, 'web');
      if (回执) {
        card.setAttribute('data-web-done', '1');
        恢复Web状态(card, 回执);
      }
    });

    // PPT 生成卡片
    var pptCards = 气泡.querySelectorAll('.ppt-card-op:not(.pend)');
    Array.prototype.forEach.call(pptCards, function (card) {
      if (card.getAttribute('data-ppt-done') === '1') return;
      
      var 回执 = 查找系统回执(气泡数组, 索引, 'ppt');
      if (回执) {
        card.setAttribute('data-ppt-done', '1');
        恢复PPT状态(card, 回执);
      }
    });
  });
}

/**
 * 查找系统回执：从当前气泡之后的消息里找对应的系统回执。
 */
function 查找系统回执(气泡数组, 当前索引, 类型) {
  for (var i = 当前索引 + 1; i < 气泡数组.length; i++) {
    var 气泡 = 气泡数组[i];
    if (!气泡.classList.contains('msg-user')) continue;
    
    var 内容 = 气泡.querySelector('.msg-text');
    if (!内容) continue;
    
    var 文本 = 内容.textContent || 内容.innerText || '';
    
    // 检查是否是系统回执
    if (文本.indexOf('<<<系统回执|非用户发言>>>') === -1) continue;
    if (文本.indexOf('<<<系统回执结束>>>') === -1) continue;
    
    // 根据类型匹配对应的回执
    var 匹配 = false;
    if (类型 === 'ssh' && 文本.indexOf('命令执行结果') > -1) 匹配 = true;
    if (类型 === 'sftp' && 文本.indexOf('SFTP') > -1) 匹配 = true;
    if (类型 === 'repo' && (文本.indexOf('代码仓') > -1 || 文本.indexOf('文件清单') > -1)) 匹配 = true;
    if (类型 === 'ws' && 文本.indexOf('工作中心') > -1) 匹配 = true;
    if (类型 === 'web' && 文本.indexOf('网页内容') > -1) 匹配 = true;
    if (类型 === 'ppt' && 文本.indexOf('PPT') > -1) 匹配 = true;
    
    if (匹配) return 文本;
  }
  return null;
}

/**
 * 恢复各类卡片的状态
 */
function 恢复SSH状态(card, 回执文本) {
  var stateEl = card.querySelector('.ssh-state');
  if (!stateEl) return;
  
  if (回执文本.indexOf('退出码：0') > -1 || 回执文本.indexOf('退出码: 0') > -1) {
    stateEl.textContent = '已完成';
    stateEl.className = 'ssh-state repo-state good';
  } else {
    stateEl.textContent = '执行失败';
    stateEl.className = 'ssh-state repo-state bad';
  }
}

function 恢复SFTP状态(card, 回执文本) {
  var stateEl = card.querySelector('.repo-state');
  if (!stateEl) return;
  
  if (回执文本.indexOf('✓') > -1 || 回执文本.indexOf('成功') > -1) {
    stateEl.textContent = '已完成';
    stateEl.className = 'repo-state good';
  } else {
    stateEl.textContent = '失败';
    stateEl.className = 'repo-state bad';
  }
}

function 恢复Repo状态(card, 回执文本) {
  var stateEl = card.querySelector('.repo-state');
  if (!stateEl) return;
  
  if (回执文本.indexOf('✓') > -1 || 回执文本.indexOf('成功') > -1) {
    stateEl.textContent = '已完成';
    stateEl.className = 'repo-state good';
  } else {
    stateEl.textContent = '失败';
    stateEl.className = 'repo-state bad';
  }
}

function 恢复WS状态(card, 回执文本) {
  var stateEl = card.querySelector('.repo-state');
  if (!stateEl) return;
  
  if (回执文本.indexOf('✓') > -1 || 回执文本.indexOf('成功') > -1) {
    stateEl.textContent = '已完成';
    stateEl.className = 'repo-state good';
  } else {
    stateEl.textContent = '失败';
    stateEl.className = 'repo-state bad';
  }
}

function 恢复Web状态(card, 回执文本) {
  var stateEl = card.querySelector('.web-state');
  if (!stateEl) return;
  
  if (回执文本.indexOf('网页内容') > -1) {
    stateEl.textContent = '已完成';
    stateEl.className = 'web-state repo-state good';
  } else {
    stateEl.textContent = '失败';
    stateEl.className = 'web-state repo-state bad';
  }
}

function 恢复PPT状态(card, 回执文本) {
  var stateEl = card.querySelector('.repo-state');
  if (!stateEl) return;
  
  if (回执文本.indexOf('下载') > -1 || 回执文本.indexOf('成功') > -1) {
    stateEl.textContent = '已生成';
    stateEl.className = 'repo-state good';
  } else {
    stateEl.textContent = '生成失败';
    stateEl.className = 'repo-state bad';
  }
}

/* ---------- 工具开关面板 ---------- */
(function () {
  var btnTools = document.getElementById('btnTools');
  var toolsPop = document.getElementById('toolsPop');
  var toolsOverlay = document.getElementById('toolsOverlay');
  var btnCloseTools = document.getElementById('btnCloseTools');
  var btnSaveTools = document.getElementById('btnSaveTools');
  
  if (!btnTools || !toolsPop) return;

  // 统一开关函数：同时控制弹窗和遮罩
  function setToolsShow(show) {
    toolsPop.setAttribute('data-show', show ? '1' : '0');
    if (toolsOverlay) toolsOverlay.setAttribute('data-show', show ? '1' : '0');
  }
  
  // 21 个工具按钮（data-key 映射到数据库字段名）
  var toolButtons = {};
  var btnEls = toolsPop.querySelectorAll('.tool-btn[data-key]');
  for (var i = 0; i < btnEls.length; i++) {
    toolButtons[btnEls[i].getAttribute('data-key')] = btnEls[i];
  }
  
  // 从服务端输出的初始值设置按钮状态
  if (window.USER_TOOLS) {
    Object.keys(toolButtons).forEach(function (key) {
      var btn = toolButtons[key];
      if (btn && window.USER_TOOLS[key] !== undefined) {
        if (window.USER_TOOLS[key]) btn.classList.add('active');
      }
    });
  }
  // 点击切换开关
  Object.keys(toolButtons).forEach(function (key) {
    var btn = toolButtons[key];
    if (btn) {
      btn.addEventListener('click', function () {
        btn.classList.toggle('active');
      });
    }
  });
  
  // 切换面板显示
  btnTools.addEventListener('click', function (e) {
    e.stopPropagation();
    var show = toolsPop.getAttribute('data-show') === '1';
    setToolsShow(!show);
  });
  
  // 关闭按钮
  if (btnCloseTools) {
    btnCloseTools.addEventListener('click', function () {
      setToolsShow(false);
    });
  }

  // 点击遮罩关闭
  if (toolsOverlay) {
    toolsOverlay.addEventListener('click', function () {
      setToolsShow(false);
    });
  }

  // 点击外部关闭（桌面端无遮罩时生效）
  document.addEventListener('click', function (e) {
    if (toolsPop.getAttribute('data-show') === '1' &&
        !toolsPop.contains(e.target) &&
        e.target !== btnTools &&
        !(toolsOverlay && toolsOverlay.contains(e.target))) {
      setToolsShow(false);
    }
  });
  
  // 保存设置
  if (btnSaveTools) {
    btnSaveTools.addEventListener('click', function () {
      var fd = new FormData();
      fd.append('act', 'set_tools');
      fd.append('csrf', window.CSRF || '');
      
      Object.keys(toolButtons).forEach(function (key) {
        var btn = toolButtons[key];
        if (btn) {
          fd.append(key, btn.classList.contains('active') ? '1' : '0');
        }
      });
      
      btnSaveTools.disabled = true;
      btnSaveTools.textContent = '保存中...';

      fetch('/api/me.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            btnSaveTools.textContent = '已保存';
            setTimeout(function () {
              setToolsShow(false);
              btnSaveTools.disabled = false;
              btnSaveTools.textContent = '保存';
            }, 800);
          } else {
            alert('保存失败：' + (j.error || '未知错误'));
            btnSaveTools.disabled = false;
            btnSaveTools.textContent = '保存';
          }
        })
        .catch(function () {
          alert('保存失败：网络错误');
          btnSaveTools.disabled = false;
          btnSaveTools.textContent = '保存';
        });
    });
  }

  /* ---- 工具调用卡片（tool-call）展开/收起交互 ----
     和 ssh-card 一样点击头部切换 body 的显示。
     事件委托到 document：tool_result 事件是动态加进气泡的，委托才覆盖得到。
     注意：这段必须放在顶层（不在任何按钮回调里），页面加载即生效。 */
  document.addEventListener('click', function (e) {
    var head = e.target.closest('.tool-call-head');
    if (!head) { return; }
    var card = head.closest('.tool-call');
    if (!card) { return; }
    var body = card.querySelector('.tool-call-body');
    var ico = card.querySelector('.tool-call-ico');
    if (!body || !ico) { return; }
    if (body.hidden) {
      body.hidden = false;
      ico.textContent = '▾';
    } else {
      body.hidden = true;
      ico.textContent = '▸';
    }
  });
})();
