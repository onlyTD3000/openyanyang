/**
 * 客户端的正文渲染层。
 *
 * 客户端原来用 textContent 塞纯文本，没有 markdown 管线，AI 输出的代码块
 * 只会显示成一堆原文，卡片脚本再怎么加载也无处可挂。这个文件补上宿主。
 *
 * 正文逐字取自网页端 assets/js/chat.js 的 render() 及其辅助函数，
 * 只做了三处适配：函数挂到 window、思考块类名改成客户端的中文类名、
 * 调卡片前加存在性判断。语言标签判定必须和网页端保持一致，
 * 少认一种写法（比如 file_write 的下划线变体）卡片就渲染不出来。
 */
(function () {
  'use strict';
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
  function markFold(t, prefix) {
    var s = t.replace(/^\s+/, '');
    if (!prefix || s.indexOf(prefix) !== 0) return t;
    var rest = s.slice(prefix.length).replace(/^\s+/, '');
    return '[[思考开始]]\n' + prefix + '\n[[思考结束]]\n\n' + rest;
  }

  /* 判断代码块语言名是不是命令块。
     中转平台会把提示词里的品牌词改写掉（实测 kiro 会变成 claude），
     所以标签统一用不含品牌词的 ssh-exec，同时兼容历史消息里的旧写法。 */
  function isSshLang(lang) {
    var l = String(lang || '').toLowerCase();
    return l === 'ssh-exec' || l === 'kiro-ssh' || l === 'claude-ssh' ||
           l === 'kiro_ssh' || l === 'sshexec';
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

  /* 判断代码块语言名是不是本地文件夹写入块。 */
  function isLocalLang(lang) {
    var l = String(lang || '').toLowerCase().replace(/_/g, '-');
    return l === 'local-write';
  }

  /* 判断代码块语言名是不是网页抓取块。
     和上面一样先把下划线归一成中划线，这样 web_open 不用单独列。
     认的标签集合必须和网页端 chat.js 的 isWebLang 一致，
     少认一种写法，同一条 AI 回复在客户端就渲染不出卡片。 */
  function isWebLang(lang) {
    var l = String(lang || '').toLowerCase().replace(/_/g, '-');
    return l === 'web-open' || l === 'webopen' ||
           l === 'web-fetch' || l === 'open-url';
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

  window.渲染正文 = function (t) {
    var out = esc(t);
    // 思考前言折叠块：[[思考开始]] … [[思考结束]]
    // 先于代码块处理，因为前言后面常紧跟代码块
    out = out.replace(/\[\[思考开始\]\]\n?([\s\S]*?)\n?\[\[思考结束\]\]\n*/g,
      function (m, inner) {
        return '<details class="思考"><summary>思考过程</summary>' +
               '<div class="思考体">' + inner.trim() + '</div></details>';
      });
    // 兜底：流式渲染中途可能只到了开标记，此时也先折叠，别把标记裸露出来
    out = out.replace(/\[\[思考开始\]\]\n?([\s\S]*)$/,
      function (m, inner) {
        return '<details class="思考"><summary>思考过程</summary>' +
               '<div class="思考体">' + inner.trim() + '</div></details>';
      });
    // 语言名允许连字符：ssh-exec 里的 - 不属于 \w，用 \w 会被截成 ssh，卡片就渲染不出来
    out = out.replace(/```([\w-]*)\n?([\s\S]*?)```/g, function (m, lang, code) {
      if (isSshLang(lang) && window.sshCard) {
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
      if (isLocalLang(lang) && window.localCard) {
        return localCard(unesc(code));
      }
      if (isWebLang(lang) && window.webCard) {
        // 网址里的 & 会被转义成 &amp;，不还原查询参数就错了
        var wc = webCard(unesc(code));
        // 解析不出网址时返回 null，落回下面的普通代码块
        if (wc) { return wc; }
      }
      return '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>';
    });
    // 流式输出中途还没闭合的代码块：先占位显示，别把 ``` 标记裸露给用户
    out = out.replace(/```([\w-]*)\n?([\s\S]*)$/, function (m, lang, code) {
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
      // 本地写入：流式状态只显示占位
      if (isLocalLang(lang)) {
        return '<div class="repo-card local-card-op pend"><div class="repo-card-head">' +
               '<span class="repo-ico">💻</span>' +
               '<span class="repo-title">正在生成本地文件写入…</span></div></div>';
      }
      // 带 pend 类，webAutoRun 选不中它，免得流式阶段半截网址被拿去抓
      if (isWebLang(lang)) {
        return '<div class="repo-card pend"><div class="repo-card-head">' +
               '<span class="repo-ico">🌐</span>' +
               '<span class="repo-title">正在准备打开网页…</span></div></div>';
      }
      return '<pre><code>' + code + '</code></pre>';
    });
    out = out.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    out = out.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    return out;
  };
})();
