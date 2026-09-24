/**
 * 对话里的 PPT 生成卡片。
 *
 * AI 输出 make-ppt 代码块，里面是 JSON 大纲。这里渲染成卡片、自动执行，
 * 生成成功后直接挂一个下载按钮，客户点一下就拿到 .pptx。
 *
 * 和 ws_card.js 的区别：那边写的是文本文件，正文即结果；
 * 这边正文只是大纲，真正的产出物要服务端跑 python-pptx 才有，
 * 所以卡片摘要展示的是「页数 + 每页标题」这种大纲概览。
 */
(function () {
  'use strict';

  var seq = 0;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * 解析大纲 JSON。
   *
   * AI 偶尔会在 JSON 前后带上说明文字，或者把 JSON 包在 ```json 里，
   * 所以先截取第一个 { 到最后一个 } 再解析，容错一点。
   */
  function 解析(raw) {
    var 文 = String(raw).trim();
    var 起 = 文.indexOf('{');
    var 止 = 文.lastIndexOf('}');
    if (起 < 0 || 止 <= 起) { return null; }
    try {
      return JSON.parse(文.slice(起, 止 + 1));
    } catch (e) {
      return null;
    }
  }

  /** 生成卡片 HTML。解析不出大纲就退回普通代码块，不要显示一个坏卡片。 */
  window.pptCard = function (raw) {
    var d = 解析(raw);
    if (!d || !d.title || !d.slides || !d.slides.length) {
      return '<pre><code>' + esc(raw) + '</code></pre>';
    }

    var id = 'pptc' + (++seq);
    var 页表 = d.slides.filter(function (p) {
      return p && (typeof p === 'string' || p.title);
    });

    // 大纲概览：列前 8 页的标题，多了折叠成一行提示
    var 条 = 页表.slice(0, 8).map(function (p, i) {
      var t = typeof p === 'string' ? p : p.title;
      var n = (p && p.bullets && p.bullets.length) ? p.bullets.length : 0;
      return '<div class="ppt-li">' +
        '<span class="ppt-no">' + (i + 1) + '</span>' +
        '<span class="ppt-t">' + esc(t) + '</span>' +
        (n ? '<span class="repo-dim">' + n + ' 条要点</span>' : '') +
        '</div>';
    }).join('');
    if (页表.length > 8) {
      条 += '<div class="ppt-li ppt-more">…… 另有 ' + (页表.length - 8) + ' 页</div>';
    }

    var 副 = d.subtitle ? '<div class="repo-note">' + esc(d.subtitle) + '</div>' : '';

    return '<div class="repo-card ppt-card-op collapsed" id="' + id + '">' +
      '<div class="repo-card-head">' +
        '<span class="repo-ico" aria-hidden="true">▸</span>' +
        '<span class="repo-title">生成演示文稿</span>' +
        '<span class="repo-state">待生成</span>' +
      '</div>' +
      '<div class="repo-card-body" hidden>' +
        '<div class="repo-path">' + esc(d.title) +
          ' <span class="repo-dim">' + 页表.length + ' 页内容</span></div>' +
        副 +
        '<div class="ppt-outline">' + 条 + '</div>' +
        '<pre class="repo-out" hidden tabindex="0"></pre>' +
      '</div>' +
      '<textarea class="repo-raw" hidden aria-hidden="true">' + esc(raw) + '</textarea>' +
      '</div>';
  };

  function setState(card, txt, cls) {
    var el = card.querySelector('.repo-state');
    if (!el) { return; }
    el.textContent = txt;
    el.className = 'repo-state' + (cls ? ' ' + cls : '');
  }

  // 卡片头部点击展开/收起
  document.addEventListener('click', function (e) {
    var head = e.target.closest('.ppt-card-op .repo-card-head');
    if (!head) { return; }
    var card = head.closest('.repo-card');
    if (!card) { return; }
    
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

  function 执行(card) {
    if (card.getAttribute('data-ppt-done') === '1') { return; }
    card.setAttribute('data-ppt-done', '1');

    var rawEl = card.querySelector('.repo-raw');
    if (!rawEl) { return; }
    var d = 解析(rawEl.value);
    if (!d) {
      setState(card, '大纲解析失败', 'bad');
      return;
    }

    setState(card, '生成中…', '');

    var fd = new FormData();
    fd.append('csrf', window.CSRF);
    fd.append('outline', JSON.stringify(d));
    if (d.name) { fd.append('name', d.name); }

    return fetch('/api/ppt.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { error: '服务端返回异常' }; });
      })
      .then(function (r) {
        if (!r || r.error) {
          setState(card, '失败', 'bad');
          var 因 = (r && r.error) || '未知错误';
          showOut(card, 因);
          // 把失败原因回传给 AI，让它自己改大纲重试
          if (window.fillPptResult) {
            window.fillPptResult(d.title || '', '失败：' + 因, false);
          }
          return;
        }

        setState(card, '已生成', 'good');

        // 挂下载按钮到头部。用 a 标签直接指向下载接口，不用 JS 触发，
        // 这样右键另存、复制链接都正常。
        var head = card.querySelector('.repo-card-head');
        if (head && !head.querySelector('.ppt-dl')) {
          var a = document.createElement('a');
          a.className = 'ppt-dl';
          a.href = r.url;
          a.textContent = '下载';
          a.title = r.name + '（' + r.slides + ' 页 / ' + r.size_text + '）';
          // download 属性让浏览器直接存盘而不是尝试预览
          a.setAttribute('download', r.name);
          // 阻止冒泡，避免点下载时触发展开/收起
          a.addEventListener('click', function(e) { e.stopPropagation(); });
          head.appendChild(a);
        }

        if (window.fillPptResult) {
          window.fillPptResult(r.name,
            '已生成 ' + r.slides + ' 页，' + r.size_text +
            '，已存入工作中心（' + r.name + '），客户可在卡片上直接下载。', true);
        }
      })
      .catch(function (e) {
        setState(card, '失败', 'bad');
        showOut(card, '请求出错：' + (e && e.message ? e.message : e));
      });
  }

  /* 自动执行不再计数限流，这个函数保留是因为 chat.js 在用户发消息时会调它。 */
  window.pptResetAuto = function () {};
  /* 自动执行：和其他卡片一致，出现即执行，不等人确认。 */
  window.pptAutoRun = function (根) {
    // 排除 pend：流式阶段的占位卡片不带 ppt-card-op，理论上选不中，
    // 这里再挡一层，防止将来占位样式改动时半截 JSON 被拿去生成。
    var 表 = (根 || document).querySelectorAll('.ppt-card-op:not(.pend)');
    Array.prototype.forEach.call(表, function (card) {
      if (card.getAttribute('data-ppt-done') === '1') { return; }
      执行(card);
    });
  };
})();
