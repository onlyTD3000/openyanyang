/**
 * 卡片适配层：让网页端那五个 *_card.js 在客户端里跑起来。
 *
 * 两端的差异只有网络层：
 *   网页端 —— fetch('/api/xxx.php') + FormData + window.CSRF（靠 Cookie 会话鉴权）
 *   客户端 —— 渲染层碰不到网络，必须走 window.后端.调接口()，鉴权用 Bearer 密钥
 *
 * 所以这里做两件事：
 *   1. 垫一个假的 window.CSRF（客户端不用它，但卡片脚本会读，缺了会拼出 undefined）
 *   2. 劫持 fetch，把发往 /api/ 的请求转成主进程调用
 *
 * 这样卡片脚本可以从网页端原样复制过来，不改一行，
 * 以后网页端改了卡片逻辑，直接覆盖过来就行，不必两边各维护一份。
 */
(function () {
  'use strict';
  // 卡片脚本会往 FormData 里塞这个值。客户端走 Bearer，服务端不校验它，
  // 但不能是 undefined——那会变成字符串 "undefined" 混进请求。
  window.CSRF = '';
  /* 项目上下文。
     repo_card.js / sftp_card.js 读的是 window.当前项目.id —— 那是网页端的形状，
     由网页端的 project.js 维护成一个对象。客户端没有 project.js，项目 id 存在
     window.态.当前项目 上，而且是个数字，两边对不上：window.当前项目 从来没人赋值，
     于是 当前项目id() 恒返回 0，file-list / file-read / file-write 全都报「未选项目」。
     这里补一个 getter 把 态.当前项目 包成卡片要的 {id} 形状。
     用 getter 不用赋值：项目会切换（选会话、建会话都会改 态.当前项目），
     赋一次值立刻就过期了，getter 每次读到的都是当下的值。
     没选项目时返回 null，卡片那边 Number(null && null.id) || 0 得 0，
     仍然走「未选项目」提示，行为不变。 */
  (function() {
    var _存项目 = null; // app.js 通过 set 放入的完整项目信息
    Object.defineProperty(window, '当前项目', {
      get: function () {
        if (_存项目) return _存项目;
        var id = Number(window.态 && window.态.当前项目) || 0;
        return id ? { id: id } : null;
      },
      set: function (v) {
        _存项目 = v;
      },
      configurable: true
    });
  })();
  /* 把 FormData 摊平成普通对象，好交给 调接口。
     卡片传的都是标量（命令、路径、正文），没有文件上传，摊平是安全的。 */
  function 摊平(body) {
    var 参数 = {};
    if (!body) { return 参数; }
    if (typeof body.forEach === 'function') {
      body.forEach(function (v, k) { 参数[k] = v; });
    }
    return 参数;
  }
  var 原fetch = window.fetch ? window.fetch.bind(window) : null;
  /**
   * 只接管 /api/ 开头的请求，其余（如果有）交回原生 fetch。
   * 返回值要装成 Response 的样子，因为卡片脚本统一写的是 r.json()。
   */
  window.fetch = function (url, opt) {
    var 路径 = String(url || '');
    if (路径.indexOf('/api/') !== 0) {
      return 原fetch ? 原fetch(url, opt) : Promise.reject(new Error('不支持的请求'));
    }
    if (!window.后端 || !window.后端.调接口) {
      return Promise.resolve(假响应({ error: '客户端通道未就绪' }));
    }
    opt = opt || {};
    var 方法 = (opt.method || 'POST').toUpperCase();
    /* 有两处 GET 卡片把参数写在查询串里（repo.php?act=list、ws.php?act=list…）。
       GET 没有 body，只摊平 body 会把这些参数全丢掉，清单就拉不出来。
       这里把查询串解析出来合并进参数，路径只留 ? 前面那段，
       免得主进程 GET 分支再拼一个 ? 出来变成两个问号。 */
    var 参数 = 摊平(opt.body);
    var 问号 = 路径.indexOf('?');
    if (问号 >= 0) {
      路径.slice(问号 + 1).split('&').forEach(function (对) {
        if (!对) { return; }
        var i = 对.indexOf('=');
        var k = i < 0 ? 对 : 对.slice(0, i);
        var v = i < 0 ? '' : 对.slice(i + 1);
        try { k = decodeURIComponent(k); v = decodeURIComponent(v); } catch (e) {}
        // body 里已有的同名参数优先，查询串只做补充
        if (!(k in 参数)) { 参数[k] = v; }
      });
      路径 = 路径.slice(0, 问号);
    }
    // 密钥存在渲染层的 态 里，登录时写入。取不到就让服务端返回未授权，
    // 卡片会把错误显示在自己的输出区，不会静默失败。
    var 密钥 = (window.态 && window.态.密钥) || '';
    return window.后端.调接口(路径, 参数, 密钥, 方法)
      .then(function (r) { return 假响应(r); })
      .catch(function (e) { return 假响应({ error: String(e && e.message || e) }); });
  };
  /* 装成 Response：卡片只用到 .json()，其余属性给个合理默认值。 */
  function 假响应(数据) {
    return {
      ok: true,
      status: 200,
      json: function () { return Promise.resolve(数据); },
      text: function () { return Promise.resolve(JSON.stringify(数据)); }
    };
  }
})();
