(function () {
  'use strict';

  /* 从 local-write 代码块正文中解析 path、note 和文件内容 */
  function 解析(code) {
    const lines = code.split('\n');
    let path = '';
    let bodyStart = -1;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      if (line.startsWith('path:')) {
        path = line.slice(5).trim();
      } else if (line === '---') {
        bodyStart = i;
        break;
      }
    }
    if (!path || bodyStart === -1) return null;
    const body = lines.slice(bodyStart + 1).join('\n').trim();
    return { path, body };
  }

  /* 写入本地文件夹。目录来自当前项目的本地文件夹设置 */
  function 写(卡片) {
    const 项目 = (window.态 && window.态.当前项目) ? 
      (window.态.项目.find(p => p.id === window.态.当前项目) || null) : null;
    const 目录 = 项目 && 项目.本地文件夹;
    if (!目录) return;
    const 路径 = 卡片.dataset.path;
    const 内容 = 卡片.querySelector('.repo-diff code')?.textContent || '';
    if (!路径 || !内容) return;
    window.后端.写入本地文件(目录, 路径, 内容).then(res => {
      if (res.ok) {
        卡片.classList.add('本地已写');
      }
    });
  }

  /* 渲染卡片 */
  window.localCard = function (code) {
    const 结果 = 解析(code);
    if (!结果) return null;
    const { path, body } = 结果;
    const escBody = body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const 卡片 = document.createElement('div');
    卡片.className = 'repo-card local-card-op';
    卡片.dataset.path = path;
    卡片.innerHTML =
      '<div class="repo-card-head">' +
      '<span class="repo-ico">💻</span>' +
      '<span class="repo-title">本地文件：' + path + '</span>' +
      '</div>' +
      '<pre class="repo-diff"><code>' + escBody + '</code></pre>';
    /* 插入 DOM 后再写入，确保 MutationObserver 能捕获 */
    setTimeout(() => 写(卡片), 50);
    return 卡片.outerHTML;
  };

  /* 监听页面内新增的本地卡片，自动执行写入 */
  new MutationObserver(function (mutations) {
    for (const m of mutations) {
      for (const n of m.addedNodes) {
        if (n.nodeType === 1) {
          if (n.classList.contains('local-card-op')) 写(n);
          n.querySelectorAll('.local-card-op').forEach(写);
        }
      }
    }
  }).observe(document, { childList: true, subtree: true });
})();