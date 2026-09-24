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
    return '<div class="ssh-card collapsed" id="' + id + '" data-host="' + esc(host) + '">' +
      '<div class="ssh-card-head">' +
        '<span class="ssh-ico" aria-hidden="true">▸</span>' +
        '<span class="ssh-title">SSH 命令执行</span>' +
        '<span class="ssh-state">待执行</span>' +
        '<button type="button" class="ssh-stop" hidden>停止</button>' +
      '</div>' +
      '<div class="ssh-card-body" hidden>' +
        '<pre class="ssh-cmd"><code>' + esc(command) + '</code></pre>' +
        '<pre class="ssh-out" hidden tabindex="0"></pre>' +
      '</div>' +
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

  // 卡片展开/收起交互
  document.addEventListener('click', function (e) {
    var head = e.target.closest('.ssh-card-head');
    if (!head) { return; }
    // 点停止按钮不触发折叠
    if (e.target.closest('.ssh-stop')) { return; }
    
    var card = head.closest('.ssh-card');
    if (!card) { return; }
    
    var body = card.querySelector('.ssh-card-body');
    var ico = card.querySelector('.ssh-ico');
    if (!body || !ico) { return; }
    
    if (body.hidden) {
      body.hidden = false;
      ico.textContent = '▾';
      card.classList.remove('collapsed');
    } else {
      body.hidden = true;
      ico.textContent = '▸';
      card.classList.add('collapsed');
    }
  });

  function showOut(card, txt) {
    var box = card.querySelector('.ssh-out');
    if (!box) { return; }
    box.textContent = txt;
    box.hidden = false;
  }

  /* 轮询节奏：前几秒跟得紧，让短命令几乎是即时返回；
     确认是长任务之后放慢，避免几十分钟的编译把请求打成上千次。 */
  function 下次间隔(已轮询次数) {
    if (已轮询次数 < 6)  { return 500; }    // 前 3 秒：每 0.5 秒
    if (已轮询次数 < 20) { return 1500; }   // 到 24 秒：每 1.5 秒
    return 3000;                            // 之后：每 3 秒
  }
  /* 超过这个时长还没结束，就认定是长任务，把卡片切成进度视图。
     阈值取 5 秒：日常命令都在这之内跑完，用户根本看不到进度条出现。 */
  var 转后台阈值 = 5000;
  function 时长文本(毫秒) {
    var 秒 = Math.round(毫秒 / 1000);
    if (秒 < 60) { return 秒 + ' 秒'; }
    var 分 = Math.floor(秒 / 60);
    return 分 + ' 分 ' + (秒 % 60) + ' 秒';
  }
  function 尾部追加(card, 片段) {
    var box = card.querySelector('.ssh-out');
    if (!box || !片段) { return; }
    // 用户正在往上翻看历史输出时不要把他拽回底部
    var 贴底 = box.scrollHeight - box.scrollTop - box.clientHeight < 40;
    box.textContent += 片段;
    box.hidden = false;
    if (贴底) { box.scrollTop = box.scrollHeight; }
  }
  /**
   * 执行一张卡片上的命令。
   *
   * 命令一律丢到远端后台跑，前端轮询进度。这么做是因为同步执行绕不过
   * nginx/FPM 的 300 秒上限，而下载、编译、装依赖动辄十几分钟。
   *
   * 对用户来说短命令的观感没变：5 秒内跑完的话，进度条和停止按钮都不会露面，
   * 就是「执行中…」直接跳到结果。超过 5 秒才切进度视图，显示已跑时长和实时输出。
   */
  function 执行(card, 回调) {
    var 完成回调 = function (结果) {
      if (typeof 回调 === 'function') { 回调(结果); return; }
      // 没传回调（手动点按钮重跑）时按老路直接发回执。
      // 结果为 null 表示这张卡片没跑起来（已执行过或结构不全），无回执可发。
      if (结果 && window.fillSshResult) {
        window.fillSshResult(结果.cmd, 结果.out, 结果.code);
      }
    };
    if (card.getAttribute('data-ssh-done') === '1') { 完成回调(null); return; }
    card.setAttribute('data-ssh-done', '1');
    var rawEl = card.querySelector('.ssh-raw');
    if (!rawEl) { 完成回调(null); return; }
    var command = rawEl.value;
    var hostId  = card.getAttribute('data-host');
    var convId  = (window.currentConvId || 0);
    var $stop   = card.querySelector('.ssh-stop');
    setState(card, '执行中…', '');
    var 起始 = Date.now();
    var 偏移 = 0;          // 已经收到的字节数，下次从这里往后取
    var 次数 = 0;
    var 已转后台 = false;
    var 已收尾 = false;
    var 任务号 = '';
    var 定时器 = null;
    /* 收尾只允许发生一次。轮询失败、用户点停止、任务正常结束
       这几条路都会走到这儿，重复回执会让 chat.js 的队列错位。 */
  /* 判断一段输出是不是 base64 编码的 gzip 数据。
     gzip 魔数 1f 8b 经 base64 后固定以 H4sI 开头，这是最可靠的特征。
     只在「整段几乎都是 base64 字符」时才认，避免把正常日志里偶然出现
     的 H4sI 字样误判成压缩数据。 */
  function 是压缩输出(文本) {
    var s = String(文本 || '').replace(/\s+/g, '');
    if (s.length < 64) { return false; }
    if (s.indexOf('H4sI') === -1) { return false; }
    // 取出第一个 H4sI 之后的部分来判断，前面可能有行数之类的杂项
    var i = s.indexOf('H4sI');
    var 主体 = s.slice(i);
    if (主体.length < 64) { return false; }
    // base64 合法字符占比要够高，否则不是一整段编码数据
    var 合法 = 主体.replace(/[^A-Za-z0-9+/=]/g, '').length;
    return 合法 / 主体.length > 0.95;
  }
  /* 把压缩输出换成一行人能看懂的摘要。原文太长没有阅读价值，
     真要看内容应该在命令里自己解压，这里给出提示。 */
  function 压缩摘要(文本) {
    var s = String(文本 || '').replace(/\s+/g, '');
    // base64 每 4 个字符编码 3 个原始字节，据此还原压缩包的真实体积
    var 字节 = Math.floor(s.length / 4 * 3);
    var 体积 = 字节 < 1024 ? 字节 + ' 字节'
             : (字节 / 1024).toFixed(1) + ' KB';
    return '[gzip 压缩数据，约 ' + 体积 + '，已折叠]\n'
         + '这段输出是压缩后的二进制经 base64 编码，直接看没有意义。\n'
         + '要看内容请在命令里自行解压，例如：命令 | base64 -d | gunzip';
  }

    /* 收尾不再直接发回执，改成把结果交回给调用方（见 window.sshAutoRun）。
       原因：一条回复里可能有多个 ssh-exec 块，需要全部串行跑完、把结果合成
       一条回执再发。若每张卡片各发一条，会连着触发多轮对话，而提示词是按
       「一条命令一个结果」写的，上下文会乱。
       手动点按钮重跑走的是同一条路，那边只有一张卡片，行为不变。 */
    function 收尾(状态文本, 样式, 回执文本, 退出码) {
      if (已收尾) { return; }
      已收尾 = true;
      if (定时器) { clearTimeout(定时器); 定时器 = null; }
      if ($stop) { $stop.hidden = true; }
      setState(card, 状态文本, 样式);
      完成回调({ cmd: command, out: 回执文本, code: 退出码 });
    }
    function 轮询() {
      if (已收尾) { return; }
      次数++;
      post({ act: 'tail', host_id: hostId, job: 任务号,
             from: 偏移, command: command, conv_id: convId })
        .then(function (x) {
          if (已收尾) { return; }
          if (!x || !x.ok) {
            var 因 = (x && x.error) || '读取进度失败';
            收尾('执行失败', 'bad', '任务进度读取失败：' + 因, -1);
            尾部追加(card, '\n[' + 因 + ']');
            return;
          }
          if (x.out) { 尾部追加(card, x.out); }
          /* 偏移用后端给的 next，不在前端数字节：后端可能因为 UTF-8 边界
             把返回内容截短一点，两边各算一次必然对不上，会丢字符或重复。 */
          if (typeof x.next === 'number' && x.next >= 偏移) { 偏移 = x.next; }
          var 已跑 = Date.now() - 起始;
          if (Number(x.done) === 1) {
            var 码 = Number(x.exit);
            var box = card.querySelector('.ssh-out');
            var 全文 = box ? box.textContent : '';
            if (全文 === '') {
              showOut(card, '（命令无输出）');
              全文 = '（命令无输出）';
            } else if (是压缩输出(全文)) {
              /* 压缩数据铺满整屏没人看得懂，页面和回执都换成摘要。
                 原文不保留：真要看内容应该在命令里解压，留着只是占地方和 token。 */
              全文 = 压缩摘要(全文);
              showOut(card, 全文);
            }
            if (码 === 0) {
              收尾('执行完成（' + 时长文本(已跑) + '）', 'good', 全文, 0);
            } else if (码 === -2) {
              // 进程没了但没留下退出码：被外部杀掉或机器重启
              收尾('已中断', 'warn',
                   全文 + '\n[任务中断：进程已不存在，未取到退出码]', -2);
            } else {
              收尾('退出码 ' + 码 + '（' + 时长文本(已跑) + '）', 'warn', 全文, 码);
            }
            return;
          }
          // 还在跑：超过阈值就切进度视图
          if (!已转后台 && 已跑 >= 转后台阈值) {
            已转后台 = true;
            if ($stop) { $stop.hidden = false; }
            var b = card.querySelector('.ssh-out');
            if (b) { b.hidden = false; }
          }
          if (已转后台) {
            setState(card, '后台执行中 · 已跑 ' + 时长文本(已跑)
                     + ' · 输出 ' + (x.size || 0) + ' 字节', '');
          }
          定时器 = setTimeout(轮询, 下次间隔(次数));
        })
        .catch(function (e) {
          if (已收尾) { return; }
          /* 单次轮询失败不算任务失败：可能只是网络抖一下，任务在服务器上还在跑。
             连续失败才放弃，否则一次抖动就把长任务判死太可惜。 */
          if (次数 % 5 !== 0) {
            定时器 = setTimeout(轮询, 3000);
            return;
          }
          收尾('执行失败', 'bad',
               '任务进度读取失败：' + ((e && e.message) || e), -1);
        });
    }
    if ($stop) {
      $stop.addEventListener('click', function () {
        if (已收尾) { return; }
        $stop.disabled = true;
        $stop.textContent = '正在停止…';
        post({ act: 'kill', host_id: hostId, job: 任务号 }).then(function () {
          // 杀完不立刻收尾：让下一次轮询把最后那截输出取回来，
          // 这样用户能看到命令停在哪一步，而不是输出突然截断。
          if (定时器) { clearTimeout(定时器); }
          定时器 = setTimeout(轮询, 300);
        }).catch(function () {
          收尾('已停止', 'warn', '任务已被用户终止（终止请求未确认）', -2);
        });
      });
    }
    return post({ act: 'start', host_id: hostId, command: command, conv_id: convId })
      .then(function (x) {
        if (x && x.ok && x.job) {
          任务号 = x.job;
          定时器 = setTimeout(轮询, 400);   // 给命令一点先跑起来的时间
          return;
        }
        var 错 = (x && x.error) || '未知错误';
        收尾('执行失败', 'bad', '执行失败：' + 错, -1);
        showOut(card, 错);
      })
      .catch(function (e) {
        // 连启动请求都没发出去。必须回执，否则 chat.js 的按钮会一直锁着。
        var 因 = '请求发送失败：' + ((e && e.message) || e);
        收尾('执行失败', 'bad', 因, -1);
        showOut(card, 因);
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
    var 待跑 = [];
    容器.querySelectorAll('.ssh-card:not(.pend):not([data-ssh-done="1"])')
      .forEach(function (c) {
        if (c.querySelector('.ssh-raw')) { 待跑.push(c); }
      });
    if (!待跑.length) { return; }
    var 结果集 = [];
    /* 串行跑：一张收尾了才起下一张。
       不并发的理由——多条命令常有先后依赖（先 cd 再看、先装再跑），
       并发跑顺序不可控；而且同一台机器上并发几条重命令容易把负载打起来。 */
    function 跑下一张() {
      /* 用户点了暂停：剩下的不再起。
         已经跑完的结果照常回传，否则那几条命令白跑了，
         而且按钮的锁要靠回执来交接，不发的话会一直锁着。 */
      if (window.链是否已取消 && window.链是否已取消()) {
        待跑.forEach(function (剩) { setState(剩, '未执行（已暂停）', 'warn'); });
        待跑 = [];
        发合并回执();
        return;
      }
      if (!待跑.length) { 发合并回执(); return; }
      var card = 待跑.shift();
      执行(card, function (结果) {
        if (结果) { 结果集.push(结果); }
        // 给按钮锁的看门狗续期：回执只在最后一张跑完才发，
        // 中间不经过 工具结束，长命令连着跑会被 300 秒的看门狗误判成卡死。
        if (window.工具心跳) { window.工具心跳(); }
        /* 前一条失败就停下，后面的不跑。
           多条命令通常是一条链，前一步没成后面几步的结果没有意义，
           跑了反而给 AI 一堆误导性输出。已跑的结果照常回传，
           未跑的在回执里列出来，让 AI 知道链条断在哪一步。 */
        if (结果 && Number(结果.code) !== 0) {
          待跑.forEach(function (剩) {
            setState(剩, '未执行（前一条命令失败）', 'warn');
            var raw = 剩.querySelector('.ssh-raw');
            结果集.push({ cmd: raw ? raw.value : '', out: null, code: null });
          });
          待跑 = [];
        }
        跑下一张();
      });
    }
    /* 把这一轮所有命令的结果合成一条回执发出去。
       只发一条是关键：fillSshResult 每次调用都会触发一轮新对话，
       发 N 条就会连着起 N 轮，而提示词是按「一条命令一个结果」写的。 */
    function 发合并回执() {
      if (!结果集.length || !window.fillSshResult) { return; }
      if (结果集.length === 1) {
        // 单条走原路径，回执格式和以前完全一致，不影响绝大多数场景
        var 单 = 结果集[0];
        window.fillSshResult(单.cmd, 单.out, 单.code);
        return;
      }
      /* 每条单独截断，而不是合并后整体截断。
         整体截断的话，第一条输出几万字就会把后面几条全挤掉，
         AI 只看到第一条的结果，等于白跑。这里按条均分额度。 */
      var 每条上限 = Math.max(1200, Math.floor(6000 / 结果集.length));
      var 段 = 结果集.map(function (r, i) {
        var 头 = '【第 ' + (i + 1) + ' 条】命令：' + r.cmd;
        if (r.code === null) {
          return 头 + '\n未执行：前面有命令失败，这条被跳过。';
        }
        var 出 = (r.out === '' || r.out === null) ? '（无输出）' : String(r.out);
        if (出.length > 每条上限) {
          出 = 出.slice(0, 每条上限)
             + '\n…（这条输出过长已截断，共 ' + String(r.out).length + ' 字符）';
        }
        return 头 + '\n退出码：' + r.code + '\n输出：\n' + 出;
      });
      var 文 = '本轮共 ' + 结果集.length + ' 条命令，执行结果如下：\n\n'
             + 段.join('\n\n---\n\n')
             + '\n\n（提醒：一次只提一条命令，看到结果再提下一条。'
             + '这轮多条是你一次输出的，已按顺序串行执行。）';
      // 第四个参数表示正文已排版好，不要再套「命令执行结果」头部
      window.fillSshResult('', 文, 0, true);
    }
    跑下一张();
  };
})();
