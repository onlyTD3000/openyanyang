'use strict';

/* 界面状态。密钥只在内存里留一份，不写 localStorage。 */
const 态 = {
  密钥: '',
  项目: [],
  当前会话: 0,
  当前项目: 0,
  模型表: [],
  流号: 0,
  在生成: false,
  当前run: 0,
  待发图: [],  // 已上传、等着随下一条消息发出去的图片：{ id, 预览 }
  用户id: 0,   // 提示音等设置按用户隔离存 localStorage，登录后填
  主机们: [],  // 服务器列表缓存，服务器面板和项目下拉框共用
  编辑主机: 0, // 主机表单正在改哪一台，0 表示新建
  编辑项目: 0, // 项目面板正在改哪个项目，0 表示新建
  回看位: null // 回看需求的光标，null 表示还没开始翻
};

/* 暴露给卡片适配层用：它要读 态.密钥 才能带上 Bearer 头。
   卡片脚本是独立 script，作用域碰不到这里的 const。 */
window.态 = 态;
/* 卡片脚本请求时要带 conv_id，它读的是全局 window.currentConvId（网页端就叫这名）。
   当前会话在多处被赋值，逐处同步容易漏，这里改成属性代理：
   往 态.当前会话 写值时自动同步过去，卡片那边永远拿到最新的会话号。
   之前这个全局量根本没设置，卡片会带着 conv_id=0 去请求。 */
(function () {
  let 会话号 = 态.当前会话;
  Object.defineProperty(态, '当前会话', {
    get: function () { return 会话号; },
    set: function (v) { 会话号 = Number(v) || 0; window.currentConvId = 会话号; },
    enumerable: true
  });
  window.currentConvId = 会话号;
})();
const 取 = (id) => document.getElementById(id);
const 元 = {
  登录页: 取('登录页'), 主界面: 取('主界面'),
  密钥框: 取('密钥框'), 记住我: 取('记住我'),
  登录按钮: 取('登录按钮'), 登录提示: 取('登录提示'),
  项目列表: 取('项目列表'), 新建项目: 取('新建项目'),
  余额: 取('余额'), 退出: 取('退出'), 服务器钮: 取('服务器钮'),
  检查更新钮: 取('检查更新钮'),
  /* 预览栏。webview 不在这里取，它是打开时才建的 */
  预览钮: 取('预览钮'), 预览栏: 取('预览栏'), 预览体: 取('预览体'),
  预览地址: 取('预览地址'), 预览后退: 取('预览后退'), 预览前进: 取('预览前进'),
  预览刷新: 取('预览刷新'), 预览外开: 取('预览外开'), 预览关闭: 取('预览关闭'),
  预览空态: 取('预览空态'), 预览加载: 取('预览加载'), 预览把手: 取('预览把手'),
  预览设备: 取('预览设备'), 预览旋转: 取('预览旋转'), 预览诊断: 取('预览诊断'),
  尺寸遮罩: 取('尺寸遮罩'), 尺寸关: 取('尺寸关'), 尺寸取消: 取('尺寸取消'),
  尺寸确定: 取('尺寸确定'), 尺寸宽: 取('尺寸宽'), 尺寸高: 取('尺寸高'),
  尺寸比: 取('尺寸比'), 尺寸移动: 取('尺寸移动'),
  预览舞台: 取('预览舞台'), 预览尺寸: 取('预览尺寸'),
  /* 自定义标题栏的窗口控制。主进程 frame: false 之后系统不画边框了 */
  最小化钮: 取('最小化钮'), 最大化钮: 取('最大化钮'), 关闭钮: 取('关闭钮'),
  最大化图: 取('最大化图'), 还原图: 取('还原图'),
  标题: 取('标题'), 模型选择: 取('模型选择'), 跟随语言: 取('跟随语言'),
  消息区: 取('消息区'), 输入框: 取('输入框'),
  发送: 取('发送'), 停止: 取('停止'),
  上一条需求: 取('上一条需求'),
  /* 充值面板 */
  支付遮罩: 取('支付遮罩'), 支付关: 取('支付关'),
  支付当前余额: 取('支付当前余额'),
  支付渠道行: 取('支付渠道行'), 支付渠道: 取('支付渠道'),
  支付面额: 取('支付面额'),
  支付自定义行: 取('支付自定义行'), 支付自定义: 取('支付自定义'),
  支付码区: 取('支付码区'), 支付码图: 取('支付码图'),
  支付状态: 取('支付状态'), 支付提示: 取('支付提示'),
  支付下单: 取('支付下单'), 支付已付: 取('支付已付'),
  /* 服务器登记面板 */
  服务器遮罩: 取('服务器遮罩'), 服务器关: 取('服务器关'),
  主机列表: 取('主机列表'), 加主机: 取('加主机'),
  主机表单: 取('主机表单'), 主机提示: 取('主机提示'),
  主机取消: 取('主机取消'),
  主机全局提示: 取('主机全局提示'),  // 表单收起时也能显示的提示行
  h_name: 取('h_name'), h_host: 取('h_host'), h_port: 取('h_port'),
  h_user: 取('h_user'), h_auth: 取('h_auth'), h_secret: 取('h_secret'),
  h_secret_名: 取('h_secret_名'), h_kpass: 取('h_kpass'), h_kpass_行: 取('h_kpass_行'),
  /* 项目面板 */
  项目遮罩: 取('项目遮罩'), 项目关: 取('项目关'), 项目取消: 取('项目取消'),
  项目表单: 取('项目表单'), 项目提示: 取('项目提示'),
  项目面板标题: 取('项目面板标题'),
  p_name: 取('p_name'), p_intro: 取('p_intro'), p_stack: 取('p_stack'),
  p_host: 取('p_host'), p_dir: 取('p_dir'), p_url: 取('p_url'),
  /* 提示音面板 */
  声音钮: 取('声音钮'), 声音遮罩: 取('声音遮罩'), 声音关: 取('声音关'),
  声开关: 取('声开关'), 声滑块: 取('声滑块'), 声数值: 取('声数值'),
  声试听: 取('声试听')
};

/* 统一走主进程发请求。渲染层碰不到 https 模块，
   token 每次由这里传进去，不在主进程缓存，免得切账号时串了。 */
const 调 = (路径, 参数, 方法) => window.后端.调接口(路径, 参数 || {}, 态.密钥, 方法);

/* 往界面塞文本一律用 textContent。
   AI 回答里可能带 HTML 或脚本片段，用 innerHTML 就等于让它执行。 */
function 建元素(标签, 类名, 文本) {
  const e = document.createElement(标签);
  if (类名) e.className = 类名;
  if (文本 !== undefined) e.textContent = 文本;
  return e;
}

/* ---------- 登录 ---------- */
async function 登录(密钥) {
  const k = (密钥 || '').trim();
  if (!k) { 元.登录提示.textContent = '密钥不能为空'; return; }

  元.登录按钮.disabled = true;
  元.登录提示.textContent = '正在验证…';
  态.密钥 = k;

  // 用 me.php 验密钥。它同时把余额带回来，省一次请求。
  const 我 = await 调('/api/me.php', {}, 'GET');
  if (我.error) {
    态.密钥 = '';
    元.登录提示.textContent = 我.error;
    元.登录按钮.disabled = false;
    return;
  }

  if (元.记住我.checked) {
    const r = await window.后端.存密钥(k);
    // 系统不支持加密时只提示，不拦着用，本次会话照样能用
    if (!r.ok) console.warn(r.msg);
  } else {
    await window.后端.清密钥();
  }

  元.登录提示.textContent = '';
  元.登录按钮.disabled = false;
  元.登录页.classList.add('隐藏');
  元.主界面.classList.remove('隐藏');
  // 服务器登记按钮在标题栏上，登录页也看得见。未登录点它会调接口失败，
  // 所以登录成功才露出来。
  if (元.服务器钮) 元.服务器钮.classList.remove('隐藏');
  if (元.预览钮) 元.预览钮.classList.remove('隐藏');
  显示余额(我);
  await Promise.all([载入模型(), 载入项目()]);
  await 恢复上次会话();
}
/* 重启后回到上次那个会话。
   以前登录完就停在空界面，「跟随提问语言」这类会话级设置只在 打开会话()
   里才会从接口读回来——不打开会话，勾选框就一直是 HTML 里的默认未选中，
   看着像设置没保存，其实库里存着，只是没人去读。 */
async function 恢复上次会话() {
  const 末 = 读末次会话();
  if (!末) return;
  // 会话可能已被删除或不属于当前账号，先在已载入的项目树里找一下，
  // 找不到就当没这回事，不要硬调接口触发一个「会话不存在」的错误提示。
  let 命中 = null;
  for (const p of 态.项目 || []) {
    for (const c of p.convs || p.conversations || []) {
      if (Number(c.id) === 末.c) { 命中 = { 会话: c, 项目: p.id }; break; }
    }
    if (命中) break;
  }
  if (!命中) return;
  await 打开会话(命中.会话, 命中.项目);
}

function 显示余额(我) {
  const 余 = 我.balance !== undefined ? 我.balance : 我.余额;
  元.余额.textContent = 余 === undefined ? '—' : '余额 ' + 余;
  // 顺手记下用户 id：提示音设置按用户隔离存 localStorage，切账号才不会串
  const id = 我.id !== undefined ? 我.id : 我.user_id;
  if (id !== undefined && id !== null) {
    if (态.用户id !== +id) {
      态.用户id = +id;
      更新声音钮();   // 换账号了，按钮状态要跟着新用户的设置走
      // 「跟随提问语言」同理：键名带用户 id，拿到 id 才能读对人的设置。
      // 放在这里而不是启动时，就是因为启动那会儿 态.用户id 还是 0。
      元.跟随语言.checked = 读跟随语言();
    }
  }
}

async function 退出登录() {
  await window.后端.清密钥();
  态.密钥 = '';
  态.当前会话 = 0;
  态.当前项目 = 0;
  // 换账号后残留的图片 id 属于上一个用户，
  // 发出去会被 claim_uploads() 的归属校验拒掉，这里清干净
  清待发图();
  // 用户 id 也要清。末次会话的 localStorage 键带用户 id，
  // 不清的话退出后若再触发一次记录，会写到上一个账号的键里去。
  态.用户id = 0;
  元.密钥框.value = '';
  元.主界面.classList.add('隐藏');
  元.登录页.classList.remove('隐藏');
  if (元.服务器钮) 元.服务器钮.classList.add('隐藏');   // 回到登录页，藏起来
  if (元.预览钮) 元.预览钮.classList.add('隐藏');
  关预览栏();   // 退出登录要把 guest 进程也销毁，别让它在后台继续跑
}

/* ---------- 模型 ---------- */

/* 价格按每百万 token 计费（后端 calc_cost 里是除以 1000000），
   这里原样展示数值，不擅自加货币符号。 */
function 模型标签(m) {
  let s = m.display_name || m.model_name || ('#' + m.id);
  const 入 = Number(m.price_in);
  const 出 = Number(m.price_out);
  if (isFinite(入) && isFinite(出) && (入 > 0 || 出 > 0)) {
    s += '  |  入 ' + 入 + ' / 出 ' + 出 + ' 每百万';
  }
  if (Number(m.vision) === 1) s += '  |  识图';
  return s;
}

async function 载入模型() {
  const r = await 调('/api/models.php', {}, 'GET');
  if (r.error || !r.list) return;
  态.模型表 = r.list;
  元.模型选择.textContent = '';
  r.list.forEach((m) => {
    const o = 建元素('option', '', 模型标签(m));
    o.value = m.id;
    元.模型选择.appendChild(o);
  });
}

元.登录按钮.addEventListener('click', () => 登录(元.密钥框.value));
元.密钥框.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') 登录(元.密钥框.value);
});
元.退出.addEventListener('click', 退出登录);

/* ---------- 项目与会话 ---------- */

/* 原来这里调的是 conv.php?act=list，拿回的是平铺的对话数组，
   而画侧栏() 要的是「项目里套对话」的嵌套结构，两边对不上：
   项目名渲染成空白、数量恒为 0，态.项目[0].id 还是对话 id，
   当成 project_id 传给后端就被 project_of() 挡下来，返回 400。
   改成：项目列表走 project.php，对话列表走 conv.php，前端按 project_id 归组。
   两个请求并发发，不按项目逐个查，省得项目多了发一堆请求。 */
async function 载入项目() {
  const [项目结果, 对话结果] = await Promise.all([
    调('/api/project.php', { act: 'list' }, 'GET'),
    调('/api/conv.php', { act: 'list' }, 'GET')
  ]);

  if (项目结果.error) { 提示错误(项目结果.error); return; }
  if (对话结果.error) { 提示错误(对话结果.error); return; }

  const 项目表 = 项目结果.list || [];
  const 对话表 = 对话结果.list || [];

  // 按 project_id 归组，一次遍历搞定
  const 归组 = new Map();
  对话表.forEach((c) => {
    const pid = Number(c.project_id || 0);
    if (!归组.has(pid)) 归组.set(pid, []);
    归组.get(pid).push(c);
  });

  // 这些字段项目面板编辑时要回填，别只留 id 和 name，
  // 否则改项目会把没回填的配置清空。
  态.项目 = 项目表.map((p) => ({
    id: Number(p.id),
    name: p.name || '未命名项目',
    intro: p.intro || '',
    stack: p.stack || '',
    host_id: Number(p.host_id || 0),
    deploy_dir: p.deploy_dir || '',
    site_url: p.site_url || '',
    convs: 归组.get(Number(p.id)) || []
  }));

  画侧栏();
}

function 画侧栏() {
  元.项目列表.textContent = '';
  if (!态.项目.length) {
    元.项目列表.appendChild(建元素('li', '空态', '还没有项目'));
    return;
  }

  态.项目.forEach((p) => {
    const li = 建元素('li');
    const 头 = 建元素('div', '项目名');
    头.appendChild(建元素('span', '', p.name));
    头.appendChild(建元素('span', '数量', String(p.convs.length)));
    // 新建对话挪到项目行上：对话必须挂在某个项目下，
    // 从项目自己的按钮进来，project_id 不会猜错。
    const 项操作 = 建元素('span', '项目操作');
    const 新话钮 = 建元素('button', '项目钮', '＋');
    const 设置钮 = 建元素('button', '项目钮', '⚙');
    const 删项钮 = 建元素('button', '项目钮 危险', '✕');
    新话钮.title = '在这个项目下新建对话';
    设置钮.title = '项目设置与服务器绑定';
    删项钮.title = '删除这个项目';
    项操作.appendChild(新话钮);
    项操作.appendChild(设置钮);
    项操作.appendChild(删项钮);
    头.appendChild(项操作);
    li.appendChild(头);
    // 按钮在折叠区域的点击范围里，不拦冒泡会顺手把项目折叠掉
    新话钮.addEventListener('click', (ev) => {
      ev.stopPropagation();
      新建对话(Number(p.id));
    });
    设置钮.addEventListener('click', (ev) => {
      ev.stopPropagation();
      开项目面板(p);
    });
    删项钮.addEventListener('click', (ev) => {
      ev.stopPropagation();
      删项目(p);
    });

    const ul = 建元素('ul', '会话列表');
    p.convs.forEach((c) => {
      const 项 = 建元素("li", "会话项");
      const 标题 = 建元素("span", "会话标题", c.title || "新对话");
      const 操作 = 建元素("span", "会话操作");
      const 改名钮 = 建元素("button", "会话钮", "✎");
      const 删除钮 = 建元素("button", "会话钮 危险", "✕");
      改名钮.title = "重命名";
      删除钮.title = "删除会话";
      操作.appendChild(改名钮);
      操作.appendChild(删除钮);
      项.appendChild(标题);
      项.appendChild(操作);
      项.dataset.id = c.id;
      if (Number(c.id) === 态.当前会话) 项.classList.add("选中");
      项.addEventListener("click", () => 打开会话(c, p.id));
      改名钮.addEventListener("click", (ev) => { ev.stopPropagation(); 改会话名(c, 项); });
      删除钮.addEventListener("click", (ev) => { ev.stopPropagation(); 删会话(c); });
      ul.appendChild(项);
    });
    li.appendChild(ul);

    // 点项目名折叠该项目下的会话，项目多了才好找。
    // 顺手记住当前项目，这样新建对话会落在你正看的这个项目下。
    头.addEventListener('click', () => {
      态.当前项目 = Number(p.id);
      ul.classList.toggle('隐藏');
    });
    元.项目列表.appendChild(li);
  });
}

/* 上次开着哪个会话。按用户隔离，跟提示音设置一个套路，
   否则换账号后会去开别人的会话 id，接口直接返回「会话不存在」。 */
const 末次键 = () => 'chat_last_conv_' + (态.用户id || 0);
function 记住会话(会话id, 项目id) {
  try {
    localStorage.setItem(末次键(), JSON.stringify({ c: +会话id || 0, p: +项目id || 0 }));
  } catch (_) {}   // 隐私模式下 localStorage 可能抛异常，记不住不影响主流程
}
function 读末次会话() {
  try {
    const v = JSON.parse(localStorage.getItem(末次键()) || 'null');
    return v && v.c ? v : null;
  } catch (_) { return null; }
}
async function 打开会话(会话, 项目id) {
  if (态.在生成) return;   // 生成中切会话会把流式内容画到错的地方
  态.当前会话 = Number(会话.id);
  态.当前项目 = Number(项目id || 0);
  记住会话(态.当前会话, 态.当前项目);   // 下次启动回到这个会话
  元.标题.textContent = 会话.title || '新对话';
  画侧栏();

  const r = await 调('/api/conv.php', { act: 'messages', conv_id: 态.当前会话 }, 'GET');
  if (r.error) { 提示错误(r.error); return; }

  // 模型和语言开关以接口返回的 conv 为准。
  // 侧栏列表里没有 model_id / lang_follow 这两个字段，
  // 只看传进来的会话对象会把模型选择框重置成第一个模型。
  const c = r.conv || 会话;
  if (c.model_id) 元.模型选择.value = String(c.model_id);
  const 消息们 = r.list || r.messages || [];
  // 空会话（一条消息都还没有）不拿库里的 0 覆盖界面：新建的会话
  // lang_follow 一律是 0，直接盖会把用户刚勾上的开关又打回去。
  // 这种情况用本地记住的偏好，并立刻落库，让它成为这个会话的初始值。
  if (!消息们.length) {
    元.跟随语言.checked = 读跟随语言();
    if (元.跟随语言.checked) {
      调('/api/conv.php', {
        act: 'set_lang_follow', conv_id: 态.当前会话,
        on: 1
      });
    }
  } else {
    // 聊过的会话以库里的值为准：用户当初在这个会话里怎么设的，就还原成那样。
    元.跟随语言.checked = Number(c.lang_follow || 0) === 1;
  }
  if (c.title) 元.标题.textContent = c.title;

  画消息(消息们);
}

/* 删除会话。后端 del 带 user_id 条件，删不掉别人的。
   删的是当前打开的会话时，顺手把界面清空回空态。 */
async function 删会话(会话) {
  if (态.在生成) { 提示错误("正在生成，先停止再删"); return; }
  const 名 = 会话.title || "新对话";
  if (!window.confirm("删除「" + 名 + "」？\n会话里的消息会一起删掉，删了找不回来。")) return;

  const r = await 调("/api/conv.php", { act: "del", conv_id: 会话.id });
  if (r.error) { 提示错误(r.error); return; }

  if (Number(会话.id) === 态.当前会话) {
    态.当前会话 = 0;
    态.当前run = 0;
    元.标题.textContent = "岩羊Ai";
    元.消息区.textContent = "";
    元.消息区.appendChild(建元素("div", "空态", "会话已删除，新建一个继续聊。"));
  }
  await 载入项目();
}

/* 重命名。Electron 里 prompt() 拿不到返回值，所以用行内输入框。
   回车提交，Esc 或失焦取消。 */
function 改会话名(会话, 项元素) {
  const 标题 = 项元素 && 项元素.querySelector(".会话标题");
  if (!标题 || 项元素.querySelector(".改名框")) return;

  const 原名 = 会话.title || "新对话";
  const 框 = document.createElement("input");
  框.className = "改名框";
  框.value = 原名;
  标题.replaceWith(框);
  框.focus();
  框.select();

  let 收了 = false;
  const 收 = async (提交) => {
    if (收了) return;
    收了 = true;
    const 新名 = 框.value.trim();
    if (!提交 || !新名 || 新名 === 原名) { await 载入项目(); return; }

    const r = await 调("/api/conv.php", { act: "rename", conv_id: 会话.id, title: 新名 });
    if (r.error) { 提示错误(r.error); }
    else if (Number(会话.id) === 态.当前会话) { 元.标题.textContent = 新名; }
    await 载入项目();
  };

  框.addEventListener("click", (ev) => ev.stopPropagation());
  框.addEventListener("blur", () => 收(false));
  框.addEventListener("keydown", (ev) => {
    ev.stopPropagation();
    if (ev.key === "Enter") { ev.preventDefault(); 收(true); }
    else if (ev.key === "Escape") { ev.preventDefault(); 收(false); }
  });
}

function 画消息(各条) {
  元.消息区.textContent = '';
  态.回看位 = null;   // 换了对话，回看光标跟着重置
  if (!各条.length) {
    元.消息区.appendChild(建元素('div', '空态', '这个对话还是空的，说点什么吧。'));
    刷回看钮();
    return;
  }
  各条.forEach((m) => {
    const 节点 = 加气泡(m.role, m.content, m.reasoning, m.img_urls);
    // 历史里的助手消息也渲染成卡片，否则旧对话里全是纯文本代码块。
    // 注意只渲染、不调 自动执行卡片：打开旧对话不能把里面的命令重跑一遍。
    // 卡片脚本认得历史卡片，会显示手动按钮供用户主动重跑。
    if (m.role === 'assistant') 渲染气泡(节点);
  });
  元.消息区.scrollTop = 元.消息区.scrollHeight;
  刷回看钮();
}

/* 加一条消息气泡，返回正文节点，流式时往里追加文本。 */
function 加气泡(角色, 正文, 思考, 图址) {
  const 行 = 建元素('div', '消息' + (角色 === 'user' ? ' 我' : ''));
  const 泡 = 建元素('div', '气泡');

  if (思考) {
    const d = 建元素('details', '思考');
    d.appendChild(建元素('summary', '', '思考过程'));
    d.appendChild(建元素('div', '', 思考));
    泡.appendChild(d);
  }

  const 文 = 建元素('div', '', 正文 || '');
  泡.appendChild(文);

  // 历史消息里的图片。/api/img.php 要 Bearer 鉴权，
  // <img src> 发不出请求头，得让主进程取回再转成 data URL。
  // 通道还没接时这里是空操作，不会报错。
  if (Array.isArray(图址) && 图址.length && window.后端 && window.后端.取图) {
    const 图区 = 建元素('div', '图区');
    泡.appendChild(图区);
    图址.slice(0, 8).forEach((u) => {
      window.后端.取图(u, 态.密钥).then((d) => {
        if (!d || !d.data) return;
        const img = document.createElement('img');
        img.className = '缩图';
        img.src = d.data;
        图区.appendChild(img);
      }).catch(() => {});
    });
  }

  行.appendChild(泡);
  元.消息区.appendChild(行);
  元.消息区.scrollTop = 元.消息区.scrollHeight;
  return { 泡, 文 };
}

/**
 * 回看上一条自己发的需求。
 * 光标从最后一条往前走，连续点就一路往更早翻，翻到头按钮置灰。
 * 记录的是序号而不是元素本身，因为重画消息后旧元素就失效了。
 */
function 回看上一条() {
  const 各条 = [...元.消息区.querySelectorAll('.消息.我')];
  if (!各条.length) return;
  // 首次点击从最后一条开始，之后每点一次往前一条
  if (态.回看位 === null || 态.回看位 > 各条.length) {
    态.回看位 = 各条.length;
  }
  态.回看位 -= 1;
  if (态.回看位 < 0) 态.回看位 = 0;
  const 目标 = 各条[态.回看位];
  目标.scrollIntoView({ behavior: 'smooth', block: 'center' });
  // 闪一下，让用户看清跳到哪条了
  目标.classList.add('高亮');
  setTimeout(() => 目标.classList.remove('高亮'), 1200);
  刷回看钮();
}
/** 按当前光标位置更新回看按钮的可用状态。 */
function 刷回看钮() {
  const 条数 = 元.消息区.querySelectorAll('.消息.我').length;
  // 没有自己发过的消息，或光标已到最早那条，就没得再翻
  元.上一条需求.disabled = 条数 === 0 || 态.回看位 === 0;
}
function 提示错误(文本) {
  const 行 = 建元素('div', '消息');
  const 泡 = 建元素('div', '气泡');
  泡.appendChild(建元素('div', '用量', 文本));
  行.appendChild(泡);
  元.消息区.appendChild(行);
  元.消息区.scrollTop = 元.消息区.scrollHeight;
}

/* 新建对话。改成收 project_id 的函数，由侧栏每个项目的「＋」调用。
   原来挂在侧栏顶部那个全局按钮上，得靠「当前项目」猜归属；
   现在按钮就在项目行里，id 是明确的，不会建到别的项目下。 */
async function 新建对话(项目id) {
  项目id = Number(项目id || 态.当前项目 || (态.项目[0] && 态.项目[0].id) || 0);
  if (!项目id) { 提示错误('请先建一个项目'); return; }
  const r = await 调('/api/conv.php', { act: 'new', project_id: 项目id });
  if (r.error) { 提示错误(r.error); return; }
  await 载入项目();
  const 新id = r.conv_id || r.id;
  if (新id) 打开会话({ id: 新id, title: r.title || '新对话' }, 项目id);
}

/* ---------- 图片上传 ---------- */

/* 上传要走主进程：/api/upload.php 是 multipart 请求，
   且需要 Bearer 头，渲染层不碰网络。
   通道没接时这里整块不启用，其余功能照常，不会报错。 */
const 能传图 = !!(window.后端 && window.后端.传图);

// 预览条和「＋」按钮都是脚本建的，不用改 HTML
let 元预览 = null;
function 建上传入口() {
  if (!能传图 || !元.输入框 || !元.输入框.parentNode) return;

  元预览 = 建元素('div', '待发图区');
  元.输入框.parentNode.insertBefore(元预览, 元.输入框);

  const 选文件 = document.createElement('input');
  选文件.type = 'file';
  选文件.accept = 'image/jpeg,image/png,image/gif,image/webp';
  选文件.multiple = true;
  选文件.className = '隐藏';

  // 回形针比「＋ 图片」文字按钮更省地方，样式见 app.css 的 .加图按钮
  const 按钮 = 建元素('button', '加图按钮', '📎');
  按钮.type = 'button';
  按钮.title = '添加图片（也可直接粘贴或拖进来）';
  按钮.addEventListener('click', () => 选文件.click());

  选文件.addEventListener('change', async () => {
    await 传一批(Array.from(选文件.files || []));
    选文件.value = '';   // 清掉才能重复选同一个文件
  });

  元.输入框.parentNode.insertBefore(按钮, 元.输入框);
  元.输入框.parentNode.appendChild(选文件);

  // 粘贴：截图后直接 Ctrl+V。
  // 分两层拿图：先走标准的 clipboardData（从文件夹复制的图片文件、浏览器里复制的图走这条），
  // 拿不到再问主进程读系统剪贴板。Windows 系统截图放的是 CF_DIB 位图，
  // clipboardData.items 里根本没有 image/* 项，只有 Electron 的 clipboard.readImage() 读得到。
  元.输入框.addEventListener('paste', async (e) => {
    const 项 = Array.from((e.clipboardData && e.clipboardData.items) || []);
    const 图 = 项.filter((i) => i.type && i.type.indexOf('image/') === 0)
                 .map((i) => i.getAsFile())
                 .filter(Boolean);
    if (图.length) { e.preventDefault(); 传一批(图); return; }

    // 剪贴板里有文字就按普通粘贴处理，不去读图，省一次 IPC
    const 有文字 = 项.some((i) => i.kind === 'string');
    if (有文字 || !window.后端 || !window.后端.读剪贴板图) return;

    // 到这里剪贴板里既没有 image/* 项也没有文字，默认粘贴不会插入任何东西，
    // 所以先拦下来（await 之后再调 preventDefault 已经过了默认行为阶段，无效）
    e.preventDefault();
    const r = await window.后端.读剪贴板图();
    if (!r) return;
    if (r.error) { 提示错误(r.error); return; }
    // 主进程给的是 PNG 字节，包成 File 复用现成的上传链路
    const 名 = '截图_' + new Date().toISOString().replace(/[:.]/g, '-') + '.png';
    const 文件 = new File([new Uint8Array(r.字节)], 名, { type: 'image/png' });
    传一批([文件]);
  });

  // 拖拽：拖到输入框上松手
  元.输入框.addEventListener('dragover', (e) => e.preventDefault());
  元.输入框.addEventListener('drop', (e) => {
    const 图 = Array.from((e.dataTransfer && e.dataTransfer.files) || [])
                 .filter((f) => f.type && f.type.indexOf('image/') === 0);
    if (图.length) { e.preventDefault(); 传一批(图); }
  });
}

/* 服务端限制未发送图片的堆积数量（默认 20），这里也拦一道，
   免得用户一次拖几十张全打过去挨个报错。 */
async function 传一批(文件们) {
  for (const f of 文件们) {
    if (态.待发图.length >= 8) {
      提示错误('一条消息最多带 8 张图片');
      break;
    }
    await 传一张(f);
  }
}

async function 传一张(文件) {
  // 字节数组交给主进程，主进程组 multipart 发出去
  let 字节;
  try {
    字节 = new Uint8Array(await 文件.arrayBuffer());
  } catch (e) {
    提示错误('读取文件失败：' + (文件.name || ''));
    return;
  }

  const r = await window.后端.传图({
    name: 文件.name || 'image.png',
    type: 文件.type || 'application/octet-stream',
    字节
  }, 态.密钥);

  if (!r || r.error) {
    提示错误((r && r.error) || '上传失败');
    return;
  }

  // 预览直接用本地文件，不用再从服务器取回来
  态.待发图.push({ id: r.id, 预览: URL.createObjectURL(文件) });
  画待发图();
}

function 画待发图() {
  if (!元预览) return;
  元预览.textContent = '';
  元预览.classList.toggle('隐藏', 态.待发图.length === 0);

  态.待发图.forEach((图, i) => {
    const 格 = 建元素('div', '待发图');
    const img = document.createElement('img');
    img.src = 图.预览;
    格.appendChild(img);

    const x = 建元素('button', '删图', '×');
    x.type = 'button';
    x.title = '移除这张';
    x.addEventListener('click', () => {
      URL.revokeObjectURL(图.预览);
      态.待发图.splice(i, 1);
      画待发图();
    });
    格.appendChild(x);
    元预览.appendChild(格);
  });
}

function 清待发图() {
  态.待发图.forEach((图) => URL.revokeObjectURL(图.预览));
  态.待发图 = [];
  画待发图();
}

/* ---------- 发送与流式接收 ---------- */
let 当前节点 = null; // 正在生成的那条气泡

/**
 * 发送一条消息。
 * @param {string} 外部文本 传了就用它，不读输入框。工具回执走这条路。
 * @param {string} 工具类 回执来源（ssh/repo/sftp/ws/ppt）。非空时服务端会把这条
 *        写进 toolresults 的 txt，而不是当成用户发言存进 messages。缺了它回执会被
 *        误当用户消息——这正是之前客户端看不到回执的直接原因。
 */
async function 发送(外部文本, 工具类) {
  if (态.在生成) return;
  const 是回执 = !!工具类;
  const 文本 = 是回执 ? String(外部文本 || '') : 元.输入框.value.trim();
  const 图们 = 是回执 ? [] : 态.待发图.slice();   // 回执不带图
  if (!文本 && !图们.length) return;
  if (!态.当前会话) { if (!是回执) 提示错误('先选一个对话，或者新建一个'); return; }

  if (!是回执) {
    元.输入框.value = '';
    元.输入框.style.height = 'auto';
  }

  // 回执是机器数据不是用户发言，不画用户气泡（网页端同样不画）。
  if (!是回执) {
    // 自己的气泡用本地预览地址，不必等服务端回图。
    // 注意这里不能 revoke，气泡还在引用这些 URL。
    const 我的气泡 = 加气泡('user', 文本);
    态.回看位 = null;   // 多了一条新需求，光标重新从最新一条算
    刷回看钮();
    if (图们.length) {
      const 图区 = 建元素('div', '图区');
      图们.forEach((图) => {
        const img = document.createElement('img');
        img.className = '缩图';
        img.src = 图.预览;
        图区.appendChild(img);
      });
      我的气泡.泡.appendChild(图区);
    }
    // 清列表但保留 URL，交给上面的气泡继续用
    态.待发图 = [];
    画待发图();
  }

  // 先建空的助手气泡，增量到了直接往里追加
  当前节点 = 加气泡('assistant', '');
  当前节点.文.classList.add('光标');

  态.流号 += 1;
  态.在生成 = true;
  态.当前run = 0;
  清看门狗();   // 新流起来了，上一轮的兜底定时器作废
  切换发送态(true);

  const 参数 = {
    conv_id: 态.当前会话,
    content: 文本,
    model_id: 元.模型选择.value,
    lang_follow: 元.跟随语言.checked ? 1 : 0
  };
  // chat.php 收 images[]，元素是 uploads 表的 id
  if (图们.length) 参数.images = 图们.map((图) => 图.id);
  // 关键：带上 tool_kind，服务端才会把这条写进 toolresults 的 txt。
  // 不带的话它会被当成普通用户消息落进 messages，AI 读不到回执。
  if (是回执) 参数.tool_kind = 工具类;

  const r = await window.后端.开始对话(态.流号, 参数, 态.密钥);

  if (r && r.error) { 收尾(r.error); }
}

function 切换发送态(生成中) {
  元.发送.classList.toggle('隐藏', 生成中);
  元.停止.classList.toggle('隐藏', !生成中);
  元.输入框.disabled = 生成中;
}
/* ---------- 工具回执回传 ----------
   卡片脚本（ssh_card.js 等）执行完动作后调这里的 window.fillXxxResult，
   把结果作为下一轮消息发回给 AI 接着分析。
   这一族函数原先客户端整个缺失，卡片里的 if (window.fillSshResult) 恒为假，
   结果只显示在卡片输出区、从不回传，服务端 toolresults 的 txt 永远是空的，
   AI 就「看不到回执」了。逻辑与网页端 assets/js/chat.js 保持一致。
   AI 正在回复时（态.在生成）发不出去，先入队，等这一轮结束再自动发。 */
const 回执队列 = [];
/** 一轮生成结束后调用：把排队的回执发出去，一次发一条。 */
function 冲回执队列() {
  if (态.在生成 || !回执队列.length) return;
  const 项 = 回执队列.shift();
  发送(项.文, 项.类);
}
/** 统一入口：能发就发，不能发就排队。 */
function 交回执(文, 类) {
  if (态.在生成) { 回执队列.push({ 文: 文, 类: 类 }); return; }
  发送(文, 类);
}
/** 超长输出截断，省 token 也避免请求体过大。 */
function 截文(正文, 上限) {
  const 文 = String(正文 === '' || 正文 == null ? '（无输出）' : 正文);
  if (文.length <= 上限) return 文;
  return 文.slice(0, 上限) + '\n…（内容过长，已截断，共 ' + 文.length + ' 字符）';
}
/* ssh_card.js：命令执行结果 */
window.fillSshResult = function (cmd, out, code) {
  const 正文 = out === '' ? '（无输出）' : 截文(out, 6000);
  交回执('命令执行结果：\n\n命令：' + cmd + '\n退出码：' + code + '\n输出：\n' + 正文, 'ssh');
};
/* repo_card.js：客户代码仓操作结果 */
window.fillRepoResult = function (动作, 路径, 正文, 成功) {
  const 头 = {
    // 清单不在系统提示词里，这是 AI 唯一的路径来源，措辞要把话说死，
    // 否则它照旧凭印象拼路径，file-write 猜错会在仓里新建多余文件。
    list:  '代码仓文件清单（这就是全部，路径照抄，不要自己拼）：',
    read:  '文件内容（' + 路径 + '）：',
    write: '整文件覆写结果（' + 路径 + '）：',
    patch: '补丁应用结果（' + 路径 + '）：',
    push:  '回传结果：'
  }[动作] || '操作结果：';
  let 文 = 头 + '\n\n' + 截文(正文 === '' ? '（空）' : 正文, 12000);
  if (!成功) 文 += '\n\n（这一步没成功，请根据上面的原因调整后再试，不要原样重试。）';
  交回执(文, 'repo');
};
/* sftp_card.js：SFTP 直连操作结果 */
window.fillSftpResult = function (动作, 路径, 正文, 成功) {
  const 头 = {
    list:   '服务器目录（' + 路径 + '）：',
    read:   '服务器文件内容（' + 路径 + '）：',
    write:  '服务器文件已覆写（' + 路径 + '）：',
    patch:  '服务器文件补丁结果（' + 路径 + '）：',
    delete: '服务器文件已删除（' + 路径 + '）：'
  }[动作] || 'SFTP 操作结果：';
  let 文 = 头 + '\n\n' + 截文(正文 === '' ? '（空）' : 正文, 12000);
  if (!成功) {
    文 += '\n\n（这一步没成功。请说明原因并修正参数后重发 sftp 块，'
        + '或请用户处理权限、路径这类前置条件。'
        + '不要原样重试，也不要改用命令行读写文件——那条路一定会被拒绝。）';
  }
  交回执(文, 'sftp');
};
/* ws_card.js：工作中心文件操作结果 */
window.fillWsResult = function (动作, 名字, 正文, 成功) {
  const 头 = {
    list:  '工作中心现有文件清单（这就是全部，不要再猜文件名）：',
    read:  '工作中心文件内容（' + 名字 + '）：',
    write: '工作中心写入结果（' + 名字 + '）：',
    patch: '工作中心补丁结果（' + 名字 + '）：',
    zip:   '工作中心打包结果（' + 名字 + '）：'
  }[动作] || '工作中心操作结果：';
  let 文 = 头 + '\n\n' + 截文(正文 === '' ? '（空）' : 正文, 12000);
  if (!成功) 文 += '\n\n（这一步没成功，请根据上面的原因调整后再试，不要原样重试。）';
  交回执(文, 'ws');
};
/* ppt_card.js：PPT 生成结果。成功也要回传，AI 得知道文件名才能正确引用。 */
window.fillPptResult = function (名字, 正文, 成功) {
  let 文 = 'PPT 生成结果（' + (名字 || '未命名') + '）：\n\n' + String(正文);
  if (!成功) 文 += '\n\n（这一步没成功，请根据上面的原因调整大纲后再试，不要原样重试。）';
  交回执(文, 'ppt');
};

/* ---------- 任务完成提醒 ----------
   搬自网页版 assets/js/chat.js，声学参数保持一致：
   Web Audio 现场合成，不打包音频文件，省得 asar 里多一个资源还可能丢。
   和网页版的差别：
     标题闪烁 -> 任务栏闪烁（Electron 有 flashFrame，比改 document.title 直观）
     桌面通知 -> 原生 Notification（客户端不用申请授权）
   设置按用户隔离，沿用网页版键名规则 chat_sound_*_UID。 */
let 音频上下文 = null;
const 声键 = (名) => 'chat_sound_' + 名 + '_' + (态.用户id || 0);
/** 是否开启。默认开。 */
const 声开着 = () => localStorage.getItem(声键('on')) !== 'off';
/** 音量百分比（5~100），默认 45。 */
function 声音量() {
  const v = parseInt(localStorage.getItem(声键('vol')), 10);
  return isFinite(v) ? Math.min(100, Math.max(5, v)) : 45;
}
/* 百分比换增益。上限 0.9：再高指数衰减起点会贴到削波边缘而失真。 */
const 声增益 = () => 声音量() / 100 * 0.9;
/**
 * 响一声。
 * @param {number} [试听音量] 传了就按这个百分比试听并忽略开关状态
 *        （拖滑块要实时听，关闭状态下点试听也得出声）。
 */
function 提示音(试听音量) {
  if (试听音量 == null && !声开着()) return;
  try {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    if (!音频上下文) 音频上下文 = new AC();
    // 必须等上下文 running 再排音符：挂起时 currentTime 是冻结的，
    // 照它算的时间点等恢复后已成过去时，音符会被直接丢掉。
    const 发声 = () => {
      const 起 = 音频上下文.currentTime;
      const 峰值 = 试听音量 == null ? 声增益()
                 : Math.min(100, Math.max(5, 试听音量)) / 100 * 0.9;
      // 2.4~3.4kHz：人耳灵敏度峰值区。音乐能量主要在 200~4000Hz 低中段，
      // 这个高段相对空，不易被背景音盖住。急促三连比两声叮咚更容易察觉。
      [[2400, 0], [2900, 0.11], [3400, 0.22]].forEach(([频, 偏]) => {
        const 振 = 音频上下文.createOscillator();
        const 量 = 音频上下文.createGain();
        // 三角波比正弦多奇次谐波，同音量更易听见，又不像方波那样尖。
        振.type = 'triangle';
        振.frequency.value = 频;
        振.connect(量);
        量.connect(音频上下文.destination);
        const t = 起 + 偏;
        // 指数衰减收尾，直接停会有爆音。起音压到 8ms 让声音更脆。
        量.gain.setValueAtTime(0.0001, t);
        量.gain.exponentialRampToValueAtTime(峰值, t + 0.008);
        量.gain.exponentialRampToValueAtTime(0.0001, t + 0.1);
        振.start(t);
        振.stop(t + 0.12);
      });
    };
    if (音频上下文.state === 'suspended' && 音频上下文.resume) {
      const p = 音频上下文.resume();
      if (p && typeof p.then === 'function') p.then(发声).catch(() => {});
      else 发声();
    } else {
      发声();
    }
  } catch (e) { /* 出声失败不影响正事 */ }
}
/** 桌面通知开关。默认关：弹窗比响一声干扰大，交给用户决定。 */
const 通知开着 = () => localStorage.getItem(声键('notify')) === 'on';
/* 桌面通知。只在窗口没聚焦时弹——用户正看着就没必要再挡一层。 */
function 桌面通知(正文) {
  if (!通知开着()) return;
  if (document.hasFocus()) return;
  try {
    const n = new Notification('回答完成', {
      body: (正文 || '').slice(0, 120) || '任务已结束'
    });
    n.onclick = () => {
      try { window.后端.唤起窗口 && window.后端.唤起窗口(); } catch (e) {}
      n.close();
    };
    setTimeout(() => { try { n.close(); } catch (e) {} }, 8000);
  } catch (e) { /* 通知失败不影响正事 */ }
}
/** 任务栏闪烁开关。默认开：零打扰，挂后台等结果时最有用。 */
const 闪烁开着 = () => localStorage.getItem(声键('flash')) !== 'off';
/* 任务栏图标闪烁。窗口已聚焦就不闪。 */
function 任务栏闪烁() {
  if (!闪烁开着()) return;
  if (document.hasFocus()) return;
  try { window.后端.闪任务栏 && window.后端.闪任务栏(); } catch (e) {}
}
/** 响铃时压低其他程序音量的开关。默认关：它会去改别的程序的音量，
    虽然响完就还原，但毕竟动了应用之外的东西，交给用户显式勾选。 */
const 压媒体开着 = () => localStorage.getItem(声键('duck')) === 'on';
/* 提示音三个音符排在 0、0.11、0.22 秒，末音 0.12 秒收尾，整段约 0.34 秒。
   留到 900 毫秒再恢复：多出的余量是给音符尾巴和 IPC 往返的，
   收太早会在提示音还没落地时就把音乐推回原音量，最后一声照样被盖住。 */
const 恢复延时 = 900;
let 恢复计时 = null;
/* 压低其他程序的音量。只在提示音真要响的时候做——
   静音状态下去动别人的音量纯属捣乱。 */
function 压低媒体() {
  if (!压媒体开着()) return;
  if (!声开着()) return;
  if (!window.后端 || !window.后端.压低音量) return;
  try {
    window.后端.压低音量(0.2);   // 压到两成，够让提示音穿出来，又不是骤然静音
  } catch (e) { return; }
  // 连续两次任务先后完成时，后一次的压低会重置计时，
  // 恢复统一由最后那次负责，中间不会提前弹回去。
  if (恢复计时) clearTimeout(恢复计时);
  恢复计时 = setTimeout(() => {
    恢复计时 = null;
    try { window.后端.恢复音量 && window.后端.恢复音量(); } catch (e) {}
  }, 恢复延时);
}
/* 四条通道统一入口。加通道只改这里，不用翻调用点。
   压低媒体排在提示音之前：压低要走一趟 IPC，先发指令音量才来得及降下来。 */
function 完成提醒(正文) {
  压低媒体();
  提示音();
  桌面通知(正文);
  任务栏闪烁();
}
/* ---------- 提示音面板 ---------- */
function 画声音面板() {
  元.声开关.checked = 声开着();
  元.声滑块.value = 声音量();
  元.声数值.textContent = 声音量() + '%';
  const 通 = 取('声通知');
  if (通) 通.checked = 通知开着();
  const 闪 = 取('声闪烁');
  if (闪) 闪.checked = 闪烁开着();
  const 压 = 取('声压媒体');
  if (压) 压.checked = 压媒体开着();
  更新声音钮();
}
/* 按钮上直接反映开关状态，不用打开面板才知道。 */
function 更新声音钮() {
  if (!元.声音钮) return;
  const 开 = 声开着();
  元.声音钮.textContent = 开 ? '🔔 提示音' : '🔕 提示音';
  元.声音钮.title = 开 ? '任务完成提示音：已开启（' + 声音量() + '%）'
                       : '任务完成提示音：已关闭';
}
function 绑提示音() {
  if (!元.声音钮) return;
  元.上一条需求.addEventListener('click', 回看上一条);
  元.声音钮.addEventListener('click', () => {
    画声音面板();
    元.声音遮罩.classList.remove('隐藏');
  });
  const 关 = () => 元.声音遮罩.classList.add('隐藏');
  元.声音关 && 元.声音关.addEventListener('click', 关);
  元.声音遮罩.addEventListener('click', (e) => { if (e.target === 元.声音遮罩) 关(); });
  // 开关：打开时响一声，让用户当场知道音量效果
  元.声开关.addEventListener('change', () => {
    localStorage.setItem(声键('on'), 元.声开关.checked ? 'on' : 'off');
    更新声音钮();
    if (元.声开关.checked) 提示音();
  });
  // 拖动只更新数字，松手才出声：input 触发很密，一路拖会连成噪音
  元.声滑块.addEventListener('input', () => {
    元.声数值.textContent = 元.声滑块.value + '%';
    localStorage.setItem(声键('vol'), 元.声滑块.value);
    更新声音钮();
  });
  元.声滑块.addEventListener('change', () => {
    提示音(parseInt(元.声滑块.value, 10));
  });
  元.声试听 && 元.声试听.addEventListener('click', () => 提示音(声音量()));
  const 通 = 取('声通知');
  通 && 通.addEventListener('change', () => {
    localStorage.setItem(声键('notify'), 通.checked ? 'on' : 'off');
    // 首次开启要授权。Electron 里通常直接 granted，但别假设。
    if (通.checked && window.Notification && Notification.permission === 'default') {
      try { Notification.requestPermission(); } catch (e) {}
    }
  });
  const 闪 = 取('声闪烁');
  闪 && 闪.addEventListener('change', () => {
    localStorage.setItem(声键('flash'), 闪.checked ? 'on' : 'off');
  });
  // 勾上就当场演示一次：压低 + 响铃，跟真实触发时一模一样。
  // 用户能立刻听出音乐是不是被压下去又弹回来，不用等下一次任务完成。
  const 压 = 取('声压媒体');
  压 && 压.addEventListener('change', () => {
    // 先写 localStorage 再调 压低媒体()——它内部要读这个开关，
    // 顺序反了刚勾上的这次演示会被自己的判断挡掉。
    localStorage.setItem(声键('duck'), 压.checked ? 'on' : 'off');
    if (压.checked) {
      压低媒体();
      提示音(声音量());   // 传音量：提示音总开关关着时也让这次演示出声
    }
  });
}
/* 收尾。返回 true 表示这一轮还挂着要自动执行的卡片，
   任务链没结束——调用方别放完成提醒，也别把按钮解锁。 */
function 收尾(错误文本) {
  态.在生成 = false;
  const 节点 = 当前节点;
  if (节点) {
    节点.文.classList.remove('光标');
    if (错误文本) {
      节点.泡.appendChild(建元素('div', '用量', 错误文本));
    }
    // 流式期间是纯文本追加，到这里才把正文渲染成卡片。
    // 不渲染的话 ssh-exec 之类的代码块只是一段文字，没有卡片可执行，
     // 也就永远不会有回执——这是之前客户端「看不到回执」的上游原因。
    渲染气泡(节点);
  }
  当前节点 = null;
  // 这一轮是否还挂着要自动执行的卡片。有的话任务链没完：
  // 卡片会执行、回执会发出去、AI 接着回下一轮。
  // 此时不能解锁按钮也不能报完成，否则用户看到「发送」和完成提示，
  // 过一会儿界面又自己动起来，像是出了故障。
  const 有后续 = !!(节点 && !错误文本 && 有待执行卡片(节点));
  if (有后续) {
    // 兜底：卡片万一执行失败又没回执（比如脚本抛异常），
    // 按钮会一直锁着让人没法输入。20 秒内没起新流就自动解锁。
    清看门狗();
    看门狗 = setTimeout(function () {
      看门狗 = null;
      if (!态.在生成) { 切换发送态(false); 元.输入框.focus(); }
    }, 20000);
  } else {
    清看门狗();
    切换发送态(false);
    元.输入框.focus();
  }
  // 卡片渲染完才能自动执行；执行完卡片会调 fillXxxResult 把结果回传。
  // 放在 态.在生成 = false 之后，否则回执发不出去只能排队。
  if (节点 && !错误文本) 自动执行卡片(节点);
  // 上一轮排队的回执在这里放行
  冲回执队列();
  return 有后续;
}
/* 任务链看门狗。保持锁定后没能起新流时用它解锁，避免界面卡死。 */
let 看门狗 = null;
function 清看门狗() {
  if (看门狗) { clearTimeout(看门狗); 看门狗 = null; }
}
/* 这条气泡里还有没有等着自动跑的卡片。
   判断口径和各 *_card.js 的 xxxAutoRun 选择器保持一致：
   带 pend 的是流式中途的占位（内容不完整，不会被执行），
   带 data-*-done 的是已经跑过的。两类都不算后续。 */
function 有待执行卡片(节点) {
  const 容器 = 节点 && 节点.泡;
  if (!容器) return false;
  // ssh 卡片认 .ssh-raw
  const s = 容器.querySelector('.ssh-card:not(.pend):not([data-ssh-done="1"])');
  if (s && s.querySelector('.ssh-raw')) return true;
  // 仓/SFTP/工作中心/PPT 四类共用 .repo-card 外壳，各自的 done 标记不同。
  // 它们的 AutoRun 还要求卡里有 .repo-raw 才执行，没有的直接跳过，
  // 这里口径必须一致，否则会把不会执行的卡片当成后续，白锁 20 秒。
  const 卡 = 容器.querySelectorAll('.repo-card:not(.pend)'
    + ':not([data-repo-done="1"]):not([data-ws-done="1"])'
    + ':not([data-sftp-done="1"]):not([data-ppt-done="1"])');
  for (let i = 0; i < 卡.length; i++) {
    if (卡[i].querySelector('.repo-raw')) return true;
  }
  return false;
}

/**
 * 把一条助手气泡的纯文本正文渲染成带卡片的 HTML。
 * 渲染器由 卡片渲染.js 提供；没加载上就保持纯文本，不至于整条消息空掉。
 */
function 渲染气泡(节点) {
  if (!节点 || !节点.文 || typeof window.渲染正文 !== 'function') return;
  const 原文 = 节点.文.textContent;
  if (!原文) return;
  try {
    节点.文.innerHTML = window.渲染正文(原文);
    // 原文留一份，卡片脚本和调试都可能要回看
    节点.文.dataset.raw = 原文;
  } catch (e) {
    // 渲染出错就维持纯文本，宁可不好看也不能把内容弄丢
    console.error('卡片渲染失败', e);
  }
}

/**
 * 依次唤起各类卡片的自动执行入口。
 * 每个 *_card.js 自己判断有没有待执行的卡片，没有就直接返回，
 * 所以这里无条件全叫一遍是安全的。
 */
function 自动执行卡片(节点) {
  const 容器 = 节点 && 节点.泡;
  if (!容器) return;
  ['sshAutoRun', 'repoAutoRun', 'sftpAutoRun', 'wsAutoRun', 'pptAutoRun'].forEach(function (名) {
    if (typeof window[名] === 'function') {
      try { window[名](容器); } catch (e) { console.error(名 + ' 失败', e); }
    }
  });
}

/* 订阅主进程转发的流事件。事件名在主进程已从后端的
   meta/delta/fold/err/done 映射成中文。 */
window.后端.收流((流号, 事件, 数据) => {
  // 老流的残包可能晚到，流号不对就丢掉，免得画进新对话里
  if (流号 !== 态.流号) return;

  if (事件 === '开始') {
    态.当前run = 数据.run_id || 0;
    return;
  }

  if (事件 === '增量' && 当前节点) {
    当前节点.文.textContent += (数据.t || 数据.text || 数据.delta || '');
    元.消息区.scrollTop = 元.消息区.scrollHeight;
    return;
  }

  if (事件 === '思考' && 当前节点) {
    // 思考内容折叠着放在正文上面，默认收起
    let d = 当前节点.泡.querySelector('.思考');
    if (!d) {
      d = 建元素('details', '思考');
      d.appendChild(建元素('summary', '', '思考过程'));
      d.appendChild(建元素('div', '', ''));
      当前节点.泡.insertBefore(d, 当前节点.文);
    }
    d.lastChild.textContent += (数据.t || 数据.text || 数据.delta || '');
    return;
  }

  if (事件 === '完成') {
    // done 事件是平铺字段（tokens_in / tokens_out / cost / balance），没有 usage 对象
    if (当前节点 && 数据 && (数据.tokens_in || 数据.tokens_out)) {
      当前节点.泡.appendChild(建元素('div', '用量',
        '输入 ' + (数据.tokens_in || 0) + ' / 输出 ' + (数据.tokens_out || 0) +
        (数据.cost !== undefined ? ' · 花费 ' + 数据.cost : '')));
    }
    // 首轮问完后端会自动起标题，刷一下侧栏才看得到
    载入项目();
    // 余额随 done 一起回来了，不必再多发一次 me.php
    if (数据 && 数据.balance !== undefined) 显示余额(数据);
    else 刷余额();
    return;
  }

  if (事件 === '已停') { 收尾('已停止'); return; }
  if (事件 === '错误') { 收尾(数据.msg || '生成失败'); return; }
  if (事件 === '结束') {
    if (态.在生成) {
      // 取正文末尾一段做通知摘要，让用户扫一眼就知道回答了什么
      const 摘 = 当前节点 && 当前节点.文 ? 当前节点.文.textContent.slice(-120) : '';
      // 收尾返回 true 说明这轮还挂着待执行的卡片，任务链没到头。
      // 那种情况不提醒——命令跑完 AI 还会接着回，真正结束的是最后那一轮。
      if (!收尾()) 完成提醒(摘);
    }
    return;
  }
});

async function 刷余额() {
  const 我 = await 调('/api/me.php', {}, 'GET');
  if (!我.error) 显示余额(我);
}

/* ---------- 充值 ----------
   面额和折扣全部由服务端算好回传，客户端只负责画和选。
   自己算折扣的话后台一改规则两边就不一致了。 */
const 支付态 = {
  选中: 0,        // 当前选的到账金额
  订单: '',       // 下单后的订单号
  轮询: null,     // 轮询定时器，关面板时必须清掉
  可扫码: false   // 后台是否开了扫码，决定下单后是画码还是开收银台窗口
};
function 支付提示(话, 是错) {
  if (!话) { 元.支付提示.classList.add('隐藏'); return; }
  元.支付提示.textContent = 话;
  元.支付提示.classList.toggle('错', !!是错);
  元.支付提示.classList.remove('隐藏');
}
async function 开支付面板() {
  停支付轮询();
  支付态.选中 = 0;
  支付态.订单 = '';
  元.支付码区.classList.add('隐藏');
  元.支付已付.classList.add('隐藏');
  元.支付下单.disabled = false;
  元.支付下单.textContent = '去支付';
  元.支付面额.textContent = '';
  支付提示('');
  元.支付当前余额.textContent = 元.余额.textContent.replace(/^余额\s*/, '') || '—';
  元.支付遮罩.classList.remove('隐藏');
  const r = await 调('/api/pay.php', { act: 'options' });
  if (r.error) { 支付提示(r.error, true); return; }
  // 支付方式：只有一个就不必让用户选
  const 渠道 = r.channels || {};
  const 键们 = Object.keys(渠道);
  if (键们.length > 1) {
    元.支付渠道.textContent = '';
    键们.forEach((k) => {
      const o = document.createElement('option');
      o.value = k;
      o.textContent = 渠道[k];
      元.支付渠道.appendChild(o);
    });
    元.支付渠道行.classList.remove('隐藏');
  } else {
    元.支付渠道行.classList.add('隐藏');
    元.支付渠道.textContent = '';
    if (键们.length === 1) {
      const o = document.createElement('option');
      o.value = 键们[0];
      元.支付渠道.appendChild(o);
    }
  }
  if (!键们.length) { 支付提示('管理员还没有配置支付渠道', true); 元.支付下单.disabled = true; return; }
  // 面额按钮
  (r.amounts || []).forEach((项, i) => {
    const b = 建元素('button', '面额钮');
    b.type = 'button';
    const 打折 = 项.rate > 0;
    b.appendChild(建元素('span', '面额值', '¥' + 项.credit));
    b.appendChild(建元素('span', '面额付', 打折 ? '实付 ¥' + 项.pay : ''));
    if (打折) b.appendChild(建元素('span', '面额折', 项.rate + '% off'));
    b.addEventListener('click', () => {
      元.支付面额.querySelectorAll('.面额钮').forEach((x) => x.classList.remove('选中'));
      b.classList.add('选中');
      元.支付自定义.value = '';
      支付态.选中 = 项.credit;
      支付提示('');
    });
    元.支付面额.appendChild(b);
    if (i === 0) b.click();   // 默认选第一档
  });
  // 没开扫码时走收银台窗口，文案统一叫「去支付」，反正都在应用内完成
  支付态.可扫码 = !!r.qr_on;
  元.支付下单.textContent = '去支付';
  // 自定义金额
  if (r.custom_on) {
    元.支付自定义.min = r.custom_min;
    元.支付自定义.max = r.custom_max;
    元.支付自定义.placeholder = r.custom_min + ' ~ ' + r.custom_max;
    元.支付自定义行.classList.remove('隐藏');
  } else {
    元.支付自定义行.classList.add('隐藏');
  }
}
function 停支付轮询() {
  if (支付态.轮询) { clearInterval(支付态.轮询); 支付态.轮询 = null; }
}
/* 下单：金额优先取自定义框，空了才用选中的面额。
   实付金额不往上传，服务端按自己的配置重算，这里传了也没用。 */
async function 支付下单() {
  const 自定 = parseFloat(元.支付自定义.value);
  const 额 = !isNaN(自定) && 自定 > 0 ? 自定 : 支付态.选中;
  if (!额 || 额 <= 0) { 支付提示('请先选择或填写充值金额', true); return; }
  元.支付下单.disabled = true;
  元.支付下单.textContent = '下单中…';
  支付提示('');
  const r = await 调('/api/pay.php', {
    act: 'create',
    channel: 元.支付渠道.value || 'alipay',
    credit: 额
    // 不指定 scene：没签当面付，服务端会按 UA 落到 page 形态，返回收银台链接
  });
  if (r.error) {
    元.支付下单.disabled = false;
    元.支付下单.textContent = '去支付';
    支付提示(r.error, true);
    return;
  }
  支付态.订单 = r.order_no;
  元.支付下单.textContent = '已下单';
  元.支付当前余额.parentNode.title = '订单号 ' + r.order_no;
  if (r.type === 'qr') {
    await 画支付码(r.order_no);
  } else if (r.url) {
    // 拿到的是收银台跳转链接，开在应用内窗口里，二维码由支付宝页面自己渲染
    const w = window.后端.开支付窗 ? await window.后端.开支付窗(r.url)
            : (window.后端.开外链 ? (window.后端.开外链(r.url), { ok: true }) : { ok: false });
    元.支付码区.classList.remove('隐藏');
    元.支付码图.classList.add('隐藏');
    元.支付状态.textContent = (w && w.ok)
      ? '已打开支付宝收银台，扫码或登录付款，付完点「我已付款」'
      : '支付页打开失败，请重试或联系客服';
  }
  元.支付已付.classList.remove('隐藏');
  开支付轮询();
}
/* 二维码图要 Bearer 头，img 的 src 带不了，所以走主进程取回 data URL。 */
async function 画支付码(单号) {
  const d = await window.后端.取图('/api/qrcode.php?order_no=' + encodeURIComponent(单号), 态.密钥);
  if (d && d.ok) {
    元.支付码图.src = d.data;
    元.支付码图.classList.remove('隐藏');
    元.支付码区.classList.remove('隐藏');
    元.支付状态.textContent = '等待付款…';
  } else {
    支付提示((d && d.error) || '二维码加载失败', true);
  }
}
/* 轮询查单。回调可能没打进来，服务端 query 会主动问一次支付宝。
   3 秒一次，5 分钟没付上就停，免得开着面板一直打接口。 */
function 开支付轮询() {
  停支付轮询();
  let 次数 = 0;
  支付态.轮询 = setInterval(async () => {
    次数 += 1;
    if (次数 > 100) { 停支付轮询(); 元.支付状态.textContent = '等待超时，付完请点「我已付款」'; return; }
    await 查一次支付(true);
  }, 3000);
}
async function 查一次支付(静默) {
  if (!支付态.订单) return;
  const r = await 调('/api/pay.php', { act: 'query', order_no: 支付态.订单 });
  if (r.error) { if (!静默) 支付提示(r.error, true); return; }
  if (r.status === 'paid') {
    停支付轮询();
    元.支付状态.textContent = '支付成功，已到账';
    元.支付码图.classList.add('隐藏');
    支付提示('充值成功，余额已更新');
    if (r.balance !== undefined) 显示余额({ balance: r.balance });
    元.支付当前余额.textContent = String(r.balance);
    元.支付已付.classList.add('隐藏');
  } else if (!静默) {
    支付提示('还没查到付款，稍等几秒再试', true);
  }
}
元.余额.addEventListener('click', 开支付面板);
元.支付关.addEventListener('click', () => {
  停支付轮询();
  元.支付遮罩.classList.add('隐藏');
});
元.支付下单.addEventListener('click', 支付下单);
元.支付已付.addEventListener('click', () => 查一次支付(false));
/* 支付窗被关掉时立刻查一次单：用户可能已经付完才关的窗，
   不等下一轮轮询能让余额更快到账。只在有在途订单时查。 */
if (window.后端 && window.后端.支付窗关闭时) {
  window.后端.支付窗关闭时(() => { if (支付态.订单) 查一次支付(true); });
}
// 填了自定义金额就把面额选中态清掉，避免看着像选了两个
元.支付自定义.addEventListener('input', () => {
  if (元.支付自定义.value) {
    元.支付面额.querySelectorAll('.面额钮').forEach((x) => x.classList.remove('选中'));
    支付态.选中 = 0;
  }
});
元.发送.addEventListener('click', 发送);
元.停止.addEventListener('click', async () => {
  await window.后端.停止对话(态.流号, 态.当前run, 态.当前会话, 态.密钥);
  收尾('已停止');
});

// Enter 发送，Shift+Enter 换行
元.输入框.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); 发送(); }
});

// 输入框跟着内容长高，上限由 CSS 的 max-height 管着
元.输入框.addEventListener('input', () => {
  元.输入框.style.height = 'auto';
  元.输入框.style.height = 元.输入框.scrollHeight + 'px';
});

元.模型选择.addEventListener('change', () => {
  if (态.当前会话) {
    调('/api/conv.php', {
      act: 'set_model', conv_id: 态.当前会话, model_id: 元.模型选择.value
    });
  }
});

/* ---------- 跟随提问语言：本地记忆 ----------
   库里的 conversations.lang_follow 是权威值（换设备也一致），
   但没打开会话时无处可落，本地再记一份用作新对话的初始态。
   键名跟网页版对齐（chat_lang_follow_UID），同一账号两端看到的默认值一致。
   按用户 id 隔离，换账号不会串。 */
const 语言键 = () => 'chat_lang_follow_' + (态.用户id || 0);
function 存跟随语言(开) {
  try { localStorage.setItem(语言键(), 开 ? '1' : '0'); } catch (_) {}   // 隐私模式忽略
}
function 读跟随语言() {
  try { return localStorage.getItem(语言键()) === '1'; } catch (_) { return false; }
}
元.跟随语言.addEventListener('change', () => {
  // 先记本地：没打开会话时这是唯一的落点。
  // 原来整段包在 if (态.当前会话) 里，空界面上勾选直接被丢掉，
  // 看着就是「存不住」——一开会话又被库里的 0 覆盖回去。
  存跟随语言(元.跟随语言.checked);
  if (态.当前会话) {
    调('/api/conv.php', {
      act: 'set_lang_follow', conv_id: 态.当前会话,
      on: 元.跟随语言.checked ? 1 : 0
    });
  }
});

/* ==================== 服务器登记 ====================
   主机列表缓存在 态.主机们，项目面板的下拉框也用它，
   省得开一次面板就重查一遍。增删改后都要刷新这份缓存。 */
/** 拉主机列表。失败返回空数组，让界面显示空态而不是崩掉。 */
async function 载入主机() {
  const r = await 调('/api/ssh_hosts.php', { act: 'list' }, 'GET');
  态.主机们 = (r && r.hosts) || [];
  return 态.主机们;
}
/** 画主机列表。每行带改和删两个操作。 */
function 画主机列表() {
  const ul = 元.主机列表;
  ul.textContent = '';
  if (!态.主机们.length) {
    const 空 = 建元素('li', '空态', '还没登记服务器。登记后 AI 才能连上去执行命令。');
    ul.appendChild(空);
    return;
  }
  态.主机们.forEach((h) => {
    const li = 建元素('li', '主机项');
    const 左 = 建元素('div', '主机行1');
    左.appendChild(建元素('div', '主机名', h.name || '(未命名)'));
    // 编号要显示：用户在对话里说「用 8 号机」靠的就是这个
    左.appendChild(建元素('div', '主机址',
      '编号 ' + h.id + ' · ' + h.username + '@' + h.host + ':' + h.port));
    li.appendChild(左);
    const 右 = 建元素('div', '主机操作');
    // SSH 放最前面：登记好之后最常用的就是开终端上去看看
    if (window.后端 && window.后端.开终端) {
      const 终 = 建元素('button', '次按钮 小', 'SSH');
      终.type = 'button';
      终.title = '打开终端窗口，可拖到桌面任意位置';
      终.addEventListener('click', () => 开终端窗口(h));
      右.appendChild(终);
    }
    const 测 = 建元素('button', '次按钮 小', '测试');
    测.type = 'button';
    测.addEventListener('click', () => 测主机(h, 测));
    右.appendChild(测);
    const 改 = 建元素('button', '次按钮 小', '改');
    改.type = 'button';
    改.addEventListener('click', () => 填主机表单(h));
    右.appendChild(改);
    const 删 = 建元素('button', '次按钮 小 危险', '删');
    删.type = 'button';
    删.addEventListener('click', () => 删主机(h));
    右.appendChild(删);
    li.appendChild(右);
    // 连接状态：有报错优先显示报错，否则显示上次连通时间
    if (h.last_error) {
      li.appendChild(建元素('div', '主机状态 坏', '上次失败：' + h.last_error));
    } else if (h.last_ok_at) {
      li.appendChild(建元素('div', '主机状态 好', '上次连通：' + h.last_ok_at));
    } else {
      li.appendChild(建元素('div', '主机状态', '还没连过'));
    }
    ul.appendChild(li);
  });
}
/** 认证方式切换时，改标签文案并显示/隐藏私钥口令行。 */
function 同步认证行() {
  const 是私钥 = 元.h_auth.value === 'key';
  元.h_secret_名.textContent = 是私钥 ? '私钥内容' : '登录密码';
  元.h_kpass_行.classList.toggle('隐藏', !是私钥);
  元.h_secret.rows = 是私钥 ? 4 : 1;
}
/**
 * 把主机数据填进表单。传空对象就是新建。
 * 凭据永远不回填——服务端不返回明文，留空表示不改动。
 */
function 填主机表单(h) {
  h = h || {};
  态.编辑主机 = h.id || 0;
  元.主机表单.classList.remove('隐藏');
  元.主机提示.textContent = '';
  元.h_name.value = h.name || '';
  元.h_host.value = h.host || '';
  元.h_port.value = h.port || 22;
  元.h_user.value = h.username || 'root';
  元.h_auth.value = h.auth_type || 'key';
  元.h_secret.value = '';
  元.h_kpass.value = '';
  元.h_secret.placeholder = h.id
    ? '留空表示不改动已保存的凭据'
    : (h.auth_type === 'password' ? '登录密码' : '粘贴 -----BEGIN ... 私钥全文');
  同步认证行();
  元.h_name.focus();
}
/** 收起表单。 */
function 收主机表单() {
  元.主机表单.classList.add('隐藏');
  态.编辑主机 = 0;
  元.主机提示.textContent = '';
}
/** 保存主机。 */
async function 存主机() {
  const 参数 = {
    act: 'save',
    id: 态.编辑主机 || 0,
    name: 元.h_name.value.trim(),
    host: 元.h_host.value.trim(),
    port: 元.h_port.value || 22,
    username: 元.h_user.value.trim(),   // 服务端字段叫 username，不是 user
    auth_type: 元.h_auth.value
  };
  // 凭据留空就不传这两个字段，服务端据此判断「不改动」
  const 凭据 = 元.h_secret.value;
  if (凭据) {
    参数.secret = 凭据;
    if (元.h_auth.value === 'key') { 参数.key_pass = 元.h_kpass.value; }
  } else if (!态.编辑主机) {
    元.主机提示.textContent = '新登记必须填' + (元.h_auth.value === 'key' ? '私钥' : '密码');
    return;
  }
  元.主机提示.textContent = '保存中…';
  const r = await 调('/api/ssh_hosts.php', 参数);
  if (!r || r.error) {
    元.主机提示.textContent = (r && r.error) || '保存失败';
    return;
  }
  收主机表单();
  await 载入主机();
  画主机列表();
}
/** 开 SSH 终端窗口。终端是独立窗口，开完这边就不管了，
    连接进度和报错都在那个窗口自己的状态条上显示。 */
async function 开终端窗口(h) {
  if (!态.密钥) { 提示错误('请先登录'); return; }
  const r = await window.后端.开终端(h.id, h.name || ('编号 ' + h.id), 态.密钥);
  if (r && r.error) { 提示错误('打开终端失败：' + r.error); }
}

/** 删主机。绑着项目的删不掉，服务端会拦，这里把原因显示出来。 */
async function 删主机(h) {
  if (!confirm('删除服务器「' + (h.name || h.host) + '」？\n绑定了这台机器的项目会失去连接。')) {
    return;
  }
  const r = await 调('/api/ssh_hosts.php', { act: 'del', id: h.id });
  if (!r || r.error) {
    元.主机全局提示.textContent = (r && r.error) || '删除失败';
    return;
  }
  await 载入主机();
  画主机列表();
}
/**
 * 测试连通性。会真的连上去跑一条只读命令，慢的话要等几秒，
 * 所以按钮先禁用并改文案，避免用户重复点。
 */
async function 测主机(h, 按钮) {
  const 原文 = 按钮.textContent;
  按钮.disabled = true;
  按钮.textContent = '连接中…';
  const r = await 调('/api/ssh_hosts.php', { act: 'test', id: h.id });
  按钮.disabled = false;
  按钮.textContent = 原文;
  if (!r || r.error) {
    元.主机全局提示.textContent = '连接失败：' + ((r && r.error) || '未知错误');
  } else {
    元.主机全局提示.textContent = '连接正常：' + (r.out || '').trim().replace(/\s+/g, ' ');
  }
  // 服务端刚回写了 last_ok_at / last_error，重拉一次让状态行同步
  await 载入主机();
  画主机列表();
}
/** 打开服务器面板。 */
async function 开服务器面板() {
  元.服务器遮罩.classList.remove('隐藏');
  元.主机全局提示.textContent = '';
  收主机表单();
  元.主机列表.textContent = '';
  元.主机列表.appendChild(建元素('li', '空态', '读取中…'));
  await 载入主机();
  画主机列表();
}
/** 绑服务器面板的事件。只在启动时绑一次。 */
function 绑服务器面板() {
  元.服务器钮.addEventListener('click', 开服务器面板);
  元.服务器关.addEventListener('click', () => 元.服务器遮罩.classList.add('隐藏'));
  // 点遮罩空白处关闭，但点面板内部不关
  元.服务器遮罩.addEventListener('click', (e) => {
    if (e.target === 元.服务器遮罩) { 元.服务器遮罩.classList.add('隐藏'); }
  });
  元.加主机.addEventListener('click', () => 填主机表单(null));
  元.主机取消.addEventListener('click', 收主机表单);
  元.h_auth.addEventListener('change', 同步认证行);
  元.主机表单.addEventListener('submit', (e) => { e.preventDefault(); 存主机(); });
}
/* ==================== 项目面板 ====================
   新建和编辑共用一套表单，靠 态.编辑项目 区分：0 是新建，>0 是改。 */
/** 把主机填进下拉框。只列 status=1 的，服务端保存时也只认这些。 */
function 填主机下拉(选中id) {
  const sel = 元.p_host;
  sel.textContent = '';
  sel.appendChild(new Option('不绑定', '0'));
  态.主机们.forEach((h) => {
    if (Number(h.status) !== 1) { return; }
    const 文 = (h.name || h.host) + '（编号 ' + h.id + '）';
    sel.appendChild(new Option(文, String(h.id)));
  });
  sel.value = String(选中id || 0);
}
/**
 * 打开项目面板。
 * @param {object} [项目] 传了就是编辑，不传是新建
 */
async function 开项目面板(项目) {
  项目 = 项目 || {};
  态.编辑项目 = 项目.id || 0;
  元.项目面板标题.textContent = 项目.id ? '编辑项目' : '新建项目';
  元.项目提示.textContent = '';
  元.p_name.value = 项目.name || '';
  元.p_intro.value = 项目.intro || '';
  元.p_stack.value = 项目.stack || '';
  元.p_dir.value = 项目.deploy_dir || '';
  元.p_url.value = 项目.site_url || '';
  元.项目遮罩.classList.remove('隐藏');
  // 主机列表可能还没拉过（没开过服务器面板），这里补一次
  if (!态.主机们.length) { await 载入主机(); }
  填主机下拉(项目.host_id);
  元.p_name.focus();
}
/** 保存项目。 */
async function 存项目() {
  const 名 = 元.p_name.value.trim();
  if (!名) {
    元.项目提示.textContent = '项目名称不能空';
    return;
  }
  // 绑了服务器就必须填部署目录，否则 AI 连不上具体路径。
  // 服务端也会校验，这里先拦一道，省一次往返。
  const 主机id = Number(元.p_host.value) || 0;
  const 目录 = 元.p_dir.value.trim();
  if (主机id > 0 && !目录) {
    元.项目提示.textContent = '绑定了服务器，请填部署目录';
    return;
  }
  if (目录 && (目录.charAt(0) !== '/' || 目录.indexOf('..') >= 0)) {
    元.项目提示.textContent = '部署目录要填绝对路径，且不能包含 ..';
    return;
  }
  元.项目提示.textContent = '保存中…';
  const r = await 调('/api/project.php', {
    act: 'save',
    id: 态.编辑项目 || 0,
    name: 名,
    intro: 元.p_intro.value.trim(),
    stack: 元.p_stack.value.trim(),
    host_id: 主机id,
    deploy_dir: 目录,
    site_url: 元.p_url.value.trim()
  });
  if (!r || r.error) {
    元.项目提示.textContent = (r && r.error) || '保存失败';
    return;
  }
  元.项目遮罩.classList.add('隐藏');
  态.编辑项目 = 0;
  await 载入项目();   // 内部会画侧栏，不用再调
}
/** 删项目。会连着里面的对话一起删，所以要问一次。 */
async function 删项目(项目) {
  if (!confirm('删除项目「' + 项目.name + '」？\n里面的对话也会一起删掉，删了找不回来。')) {
    return;
  }
  const r = await 调('/api/project.php', { act: 'del', id: 项目.id });
  if (!r || r.error) {
    提示错误((r && r.error) || '删除失败');
    return;
  }
  // 删的正好是当前项目，把聊天区清空
  if (态.当前项目 === 项目.id) {
    态.当前项目 = 0;
    态.当前会话 = 0;
  }
  await 载入项目();   // 内部会画侧栏，不用再调
}
/** 绑项目面板的事件。只在启动时绑一次。 */
function 绑项目面板() {
  元.新建项目.addEventListener('click', () => 开项目面板(null));
  元.项目关.addEventListener('click', () => 元.项目遮罩.classList.add('隐藏'));
  元.项目取消.addEventListener('click', () => 元.项目遮罩.classList.add('隐藏'));
  元.项目遮罩.addEventListener('click', (e) => {
    if (e.target === 元.项目遮罩) { 元.项目遮罩.classList.add('隐藏'); }
  });
  元.项目表单.addEventListener('submit', (e) => { e.preventDefault(); 存项目(); });
}
/* 「检查更新」按钮。
   查更新和下载都在主进程做，弹的是系统原生对话框，
   所以这里只负责发起、防重复点、把进度显示在按钮上。 */
let 上次百分 = -1;   // 上次写进按钮的整数百分比，用来跳过重复的进度回调
function 绑更新钮() {
  const $钮 = 元.检查更新钮;
  if (!$钮) { return; }
  // 版本号挂在 title 上，鼠标悬停能看到当前版本
  window.后端.当前版本().then((v) => {
    $钮.title = '检查更新（当前 ' + v + '）';
  }).catch(() => {});
  let 在查 = false;
  // 还原时用固定字面量，不要用点击前读到的 textContent：
  // 上一轮下载可能把按钮文字留成了「37%」，那样还原出来就是错的
  const 常态文案 = '检查更新';
  $钮.addEventListener('click', async () => {
    if (在查) { return; }   // 下载可能要几十秒，防止连点开多个下载
    在查 = true;
    $钮.disabled = true;
    上次百分 = -1;          // 新一轮下载，进度重新开始比
    $钮.textContent = '检查中…';
    try {
      await window.后端.检查更新();
    } finally {
      在查 = false;
      $钮.disabled = false;
      $钮.textContent = 常态文案;
    }
  });
  /* 下载进度显示在按钮上。主进程那边同时也在画任务栏进度条，
     两处都给是因为窗口最小化时只看得到任务栏。
     只在整数百分比真的变了才写 DOM。进度回调是按数据块触发的，
     一秒能来几十次，每次都写会让按钮文字疯狂重绘，看起来就是数字在乱跳。 */
  window.后端.收更新进度(({ 已下, 总长 }) => {
    if (!(总长 > 0)) { return; }
    const 百分 = Math.floor(已下 / 总长 * 100);
    if (百分 === 上次百分) { return; }
    上次百分 = 百分;
    $钮.textContent = 百分 + '%';
  });
}
/* ---------- 预览栏 ----------
   夹在项目栏和聊天区中间，用 <webview> 装目标站点。
   webview 是懒创建的：不开预览就不建 guest 进程，省一份内存。 */
const 预览宽键 = '预览栏宽度';   // 宽度记在 localStorage，下次打开还是这么宽
const 预览设备键 = '预览设备';
const 预览自定键 = '预览自定尺寸';
/* 设备规格表。ua 为空表示用 Electron 自带的桌面 UA，不改。
   dpr 只用于显示尺寸标签，不再下发给 guest。
   实测 dpr 不影响布局宽度（1 和 3 两次 innerWidth 都一样），
   之前把它当成「只画一小条」的原因是判断错了。
   现在视口靠 webview 元素的 CSS 尺寸决定，guest 的 devicePixelRatio
   跟宿主窗口一致，改这里不会影响渲染，所以填回真机值备用。
   移动端 UA 用 iOS/Android 的真实串，很多站点靠正则认这几个关键字，
   编一个假的会被判成桌面端。 */
const 预览设备表 = {
  '电脑':   { 宽: 0, 高: 0, dpr: 0, 移动: false, ua: '' },
  '手机':   { 宽: 390, 高: 844, dpr: 3, 移动: true,
              ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' },
  '手机大': { 宽: 430, 高: 932, dpr: 3, 移动: true,
              ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' },
  '平板':   { 宽: 820, 高: 1180, dpr: 2, 移动: true,
              ua: 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' },
  '自定义': { 宽: 375, 高: 667, dpr: 2, 移动: true,
              ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36' }
};
let 预览当前设备 = '电脑';
let 预览横屏 = false;   // 旋转只交换宽高，不换 UA
/** 取当前设备的有效规格，已把自定义尺寸和横竖屏算进去。 */
function 预览规格() {
  const 底 = 预览设备表[预览当前设备] || 预览设备表['电脑'];
  const 出 = { 宽: 底.宽, 高: 底.高, dpr: 底.dpr, 移动: 底.移动, ua: 底.ua };
  if (预览当前设备 === '自定义') {
    const 存 = 读自定尺寸();
    出.宽 = 存.宽; 出.高 = 存.高; 出.dpr = 存.dpr;
  }
  if (预览横屏 && 出.宽 > 0) {
    const t = 出.宽; 出.宽 = 出.高; 出.高 = t;
  }
  return 出;
}
/** 自定义尺寸存在 localStorage，读的时候夹一遍范围，防止存进过的坏值把布局搞崩。 */
function 读自定尺寸() {
  let 宽 = 375, 高 = 667, dpr = 2, 移动 = true;
  try {
    const o = JSON.parse(localStorage.getItem(预览自定键) || '{}');
    if (o && o.宽 > 0) { 宽 = o.宽; }
    if (o && o.高 > 0) { 高 = o.高; }
    if (o && o.dpr > 0) { dpr = o.dpr; }
    if (o && o.移动 === false) { 移动 = false; }
  } catch (e) {}
  return {
    宽: Math.min(Math.max(Math.round(宽), 200), 3000),
    高: Math.min(Math.max(Math.round(高), 200), 3000),
    dpr: Math.min(Math.max(Number(dpr) || 2, 1), 4),
    移动: 移动
  };
}
/** 把用户输入的东西补成一个能用的 http(s) 地址。
 *  用户习惯直接敲 example.com 或 localhost:3000，都要能认。
 *  返回 '' 表示实在认不出来，调用方据此提示。 */
function 规范网址(输入) {
  let t = String(输入 || '').trim();
  if (t === '') { return ''; }
  // 只认 http/https。javascript: 和 file: 是注入和读本机文件的口子，
  // 主进程的 will-attach-webview 也会拦，这里先挡一层，省得白跑一趟
  if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(t)) {
    if (!/^https?:\/\//i.test(t)) { return ''; }
  } else {
    t = 'http://' + t;   // 没写协议，按 http 补
  }
  try {
    const u = new URL(t);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') { return ''; }
    if (!u.hostname) { return ''; }
    return u.href;
  } catch (e) {
    return '';
  }
}
/* ===== 预览承载：主进程的 WebContentsView =====
   $预览开着 表示视图已建。渲染进程这边没有任何 <webview> 元素了，
   预览舞台只是个占位 div，负责量出该把视图摆在哪儿。
   为什么这么改：<webview> 的 guest 绘制表面只认 attach 那一刻的尺寸，
   之后改元素宽高、CSS zoom/transform、emulation 的 scale 都改不到它
   （实测 guest 恒为 299x150），页面按大视口布局却只有一小条画布。 */
let $预览开着 = false;
let $预览退订 = [];     // 事件退订函数，关栏时逐个调掉，防止重复订阅
let $预览定位帧 = 0;    // requestAnimationFrame 句柄，合并连续的定位请求
let $预览被遮 = false;  // 有弹窗开着时视图要让位
/** 量出舞台在窗口里的位置，报给主进程。
    getBoundingClientRect 给的是相对视口左上角的坐标，而 setBounds 用的是
    相对窗口内容区左上角——这个窗口是 frame:false，两者原点重合，不用补偏移。 */
function 贴预览位置() {
  if (!$预览开着 || !元.预览舞台) { return; }
  if ($预览被遮) { return; }
  const 栏藏了 = 元.预览栏 && 元.预览栏.classList.contains('隐藏');
  if (栏藏了) { window.后端.预览藏(); return; }
  const b = 元.预览舞台.getBoundingClientRect();
  // 舞台还没布局出来（宽或高为 0）就先别摆，等下一次
  if (b.width < 1 || b.height < 1) { return; }
  window.后端.预览定位({ x: b.left, y: b.top, 宽: b.width, 高: b.height });
}
/** 合并定位请求。拖动把手时 mousemove 一秒能来几十次，
    每次都发 IPC 会把主进程刷满，用 rAF 压到每帧一次。 */
function 排预览定位() {
  if ($预览定位帧) { return; }
  $预览定位帧 = requestAnimationFrame(() => {
    $预览定位帧 = 0;
    贴预览位置();
  });
}
/** 建视图并接好事件。只在预览栏第一次要显示内容时调。 */
async function 建预览视图(网址) {
  const 结果 = await window.后端.预览建(网址);
  if (!结果 || !结果.ok) {
    显预览空态('预览打不开', (结果 && 结果.错) || '主进程没能建起预览视图。');
    return false;
  }
  $预览开着 = true;
  const 订 = (名, fn) => { $预览退订.push(window.后端.预览事件(名, fn)); };
  订('加载中', (是) => {
    元.预览加载.classList.toggle('隐藏', !是);
  });
  // 地址栏跟着实际跳转变，用户点了站内链接也能看出现在在哪一页
  订('地址', (网址) => { 元.预览地址.value = 网址 || ''; });
  订('按钮', (态) => {
    元.预览后退.disabled = !(态 && 态.后);
    元.预览前进.disabled = !(态 && 态.前);
    元.预览刷新.disabled = false;
  });
  订('失败', (信) => {
    元.预览加载.classList.add('隐藏');
    显预览空态('打不开这个地址',
      '错误 ' + (信 && 信.码) + '。检查网站是否已启动、地址是否写对。');
  });
  订('崩了', () => {
    元.预览加载.classList.add('隐藏');
    显预览空态('预览进程崩溃了', '点刷新重新加载。');
  });
  await 应用预览设备(false);
  贴预览位置();
  return true;
}
/** 显示空态提示。视图要藏掉，否则原生层会盖在提示文字上面。 */
function 显预览空态(主文, 小字) {
  元.预览空态.innerHTML = '';
  const p1 = document.createElement('p');
  p1.textContent = 主文;
  元.预览空态.appendChild(p1);
  if (小字) {
    const p2 = document.createElement('p');
    p2.className = '预览空态小';
    p2.textContent = 小字;
    元.预览空态.appendChild(p2);
  }
  元.预览空态.classList.remove('隐藏');
  // WebContentsView 是原生层，永远浮在 HTML 之上，不藏就看不见空态
  if ($预览开着) { window.后端.预览藏(); }
}
/** 前进后退按钮的可用状态。视图没建好时全禁掉。 */
async function 刷预览按钮() {
  if (!$预览开着) {
    元.预览后退.disabled = true;
    元.预览前进.disabled = true;
    元.预览刷新.disabled = true;
    return;
  }
  元.预览刷新.disabled = false;
  const 态 = await window.后端.预览按钮态();
  元.预览后退.disabled = !(态 && 态.后);
  元.预览前进.disabled = !(态 && 态.前);
}
/** 在预览栏里打开一个地址。栏没开会先开。 */
async function 预览打开(输入) {
  const 网址 = 规范网址(输入);
  if (网址 === '') {
    显预览空态('这个地址认不出来', '只支持 http 和 https，例如 example.com 或 http://localhost:3000');
    return;
  }
  元.预览空态.classList.add('隐藏');
  元.预览地址.value = 网址;
  if ($预览开着) {
    // 视图已在，跳转就行，重建要重开一个渲染进程，慢得多
    await window.后端.预览导航('跳转', 网址);
    贴预览位置();
  } else {
    await 建预览视图(网址);
  }
  刷预览按钮();
}
/** 开预览栏。没传地址就用当前项目的 site_url。 */
function 开预览栏(网址) {
  元.预览栏.classList.remove('隐藏');
  元.预览钮.setAttribute('aria-pressed', 'true');
  // 恢复上次拖到的宽度
  const 存的 = parseInt(localStorage.getItem(预览宽键), 10);
  if (存的 > 0) { 定预览宽(存的); }
  // 态.当前项目 存的是 id 数字，不是对象，得去 态.项目 里查那条记录
  const 项 = (态.项目 || []).filter(
    (x) => Number(x.id) === Number(态.当前项目))[0];
  const 目标 = 网址 || (项 && 项.site_url) || '';
  if (目标) {
    预览打开(目标);
  } else if (!$预览开着) {
    // 项目没填站点地址，留空态让用户自己敲，别自作主张跳到某个页面
    显预览空态('这个项目还没填站点地址', '在项目设置里填「站点地址」，或直接在上面输入网址。');
    刷预览按钮();
  }
}
/** 关预览栏。视图必须真销毁，只藏起来的话页面还在后台跑，占内存也继续联网。 */
async function 关预览栏() {
  if (!元.预览栏) { return; }
  元.预览栏.classList.add('隐藏');
  if (元.预览钮) { 元.预览钮.setAttribute('aria-pressed', 'false'); }
  if ($预览开着) {
    // 先退订再销毁：反过来的话销毁触发的事件会打到已经没用的处理函数上
    $预览退订.forEach((退) => { try { 退(); } catch (e) {} });
    $预览退订 = [];
    $预览开着 = false;
    await window.后端.预览销毁();
  }
  if (元.预览舞台) { 元.预览舞台.classList.remove('模拟'); }
  if (元.预览尺寸) { 元.预览尺寸.classList.add('隐藏'); }
  if (元.预览旋转) { 元.预览旋转.classList.add('隐藏'); }
  if (元.预览诊断) { 元.预览诊断.classList.add('隐藏'); }
  if (元.预览加载) { 元.预览加载.classList.add('隐藏'); }
  刷预览按钮();
}
/** 把当前设备规格应用到预览视图上。
    重载参数为真时会重新加载页面——UA 是请求头，只有下次请求才生效，
    不 reload 的话服务端还按上一个 UA 返回内容。 */
async function 应用预览设备(重载) {
  if (!元.预览舞台) { return; }
  const 规 = 预览规格();
  const 桌面 = !规.移动 || 规.宽 <= 0;
  // 旋转和诊断按钮只在模拟设备时有意义
  if (元.预览旋转) { 元.预览旋转.classList.toggle('隐藏', 桌面); }
  if (元.预览诊断) { 元.预览诊断.classList.toggle('隐藏', 桌面); }
  元.预览舞台.classList.toggle('模拟', !桌面);
  if (元.预览尺寸) {
    元.预览尺寸.classList.toggle('隐藏', 桌面);
    if (!桌面) { 元.预览尺寸.textContent = 规.宽 + ' × ' + 规.高; }
  }
  if (!$预览开着) { return; }
  // UA 先设：它是请求头，reload 之前设好才能在这次请求里带上
  await window.后端.预览设UA(规.ua || '');
  await 应用预览模拟();
  if (重载) { await window.后端.预览导航('刷新'); }
}
/** 设备模拟。视图的实际画布就是舞台那块区域，设备视口比它大就按比例缩。
    缩放交给 Chromium 的 emulation scale：它在渲染层等比缩小，
    宿主这边不做任何 CSS 变换，所以不存在「元素尺寸和绘制表面对不上」的问题——
    那正是 <webview> 时代只画出一小条的原因。 */
async function 应用预览模拟() {
  if (!$预览开着) { return; }
  const 规 = 预览规格();
  const 桌面 = !规.移动 || 规.宽 <= 0;
  if (桌面) {
    await window.后端.预览停模拟();
    贴预览位置();
    return;
  }
  const 台 = 元.预览舞台.getBoundingClientRect();
  /* 缩放比例按舞台和设备尺寸的较小比算，取 1 为上限：
     设备比舞台小的时候不放大，放大了看着虚，也不符合真机像素。 */
  let 缩 = 1;
  if (台.width > 1 && 台.height > 1) {
    缩 = Math.min(1, Math.min(台.width / 规.宽, 台.height / 规.高));
  }
  const 果 = await window.后端.预览设模拟({
    宽: 规.宽, 高: 规.高, 缩放: 缩, 移动: 规.移动
  });
  /* 视图的 bounds 收成「设备尺寸乘以缩放」那么大，并在舞台里居中。
     不铺满舞台的原因：铺满的话设备边界看不出来，
     用户分不清哪部分是手机屏、哪部分是灰底衬垫。 */
  if (果 && 果.ok) {
    const 宽 = Math.round(规.宽 * 缩);
    const 高 = Math.round(规.高 * 缩);
    window.后端.预览定位({
      x: 台.left + Math.max(0, (台.width - 宽) / 2),
      y: 台.top + Math.max(0, (台.height - 高) / 2),
      宽: 宽, 高: 高
    });
    if (元.预览尺寸) {
      // 缩了就把比例标出来，否则用户以为自己看的是 1:1
      元.预览尺寸.textContent = 规.宽 + ' × ' + 规.高 +
        (缩 < 0.999 ? '　' + Math.round(缩 * 100) + '%' : '');
    }
  }
}
/** 设预览栏宽度。夹在合理区间里，别让用户拖到看不见或者把聊天区挤没。 */
function 定预览宽(宽) {
  // 上限留 420px 给聊天区，不然输入框和消息都挤成一条
  const 最大 = Math.max(300, window.innerWidth - 260 - 420);
  const 值 = Math.min(Math.max(宽, 260), 最大);
  元.预览栏.style.setProperty('--预览宽', 值 + 'px');
  return 值;
}
/* 弹窗让位。WebContentsView 是原生层，永远浮在所有 HTML 之上，
   遮罩再高的 z-index 也压不住它。所以只要有任何一个遮罩打开，
   就把视图临时藏掉，全关了再贴回来。
   盯的是 class 变化：项目里开关弹窗都是加减「隐藏」这个类。 */
function 绑预览让位() {
  const 遮罩们 = Array.prototype.slice.call(document.querySelectorAll('.遮罩'));
  if (!遮罩们.length) { return; }
  const 查一遍 = () => {
    const 有开的 = 遮罩们.some((d) => !d.classList.contains('隐藏'));
    if (有开的 === $预览被遮) { return; }   // 状态没变就别折腾视图
    $预览被遮 = 有开的;
    if (!$预览开着) { return; }
    if (有开的) {
      window.后端.预览藏();
    } else {
      // 关掉弹窗后重新量位置：期间窗口可能被缩放过
      贴预览位置();
      应用预览模拟();
    }
  };
  const 观 = new MutationObserver(查一遍);
  遮罩们.forEach((d) => 观.observe(d, { attributes: true, attributeFilter: ['class'] }));
}
function 绑预览栏() {
  if (!元.预览钮 || !元.预览栏) { return; }
  // 标题栏那个「预览」按钮：开着就关，关着就开
  元.预览钮.addEventListener('click', () => {
    if (元.预览栏.classList.contains('隐藏')) { 开预览栏(); } else { 关预览栏(); }
  });
  元.预览关闭.addEventListener('click', 关预览栏);
  // 地址栏回车跳转
  元.预览地址.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      预览打开(元.预览地址.value);
    }
  });
  元.预览后退.addEventListener('click', () => {
    if ($预览开着) { window.后端.预览导航('后退'); }
  });
  元.预览前进.addEventListener('click', () => {
    if ($预览开着) { window.后端.预览导航('前进'); }
  });
  元.预览刷新.addEventListener('click', () => {
    if ($预览开着) {
      // 刷新前把空态收掉：上次失败留的提示不清会一直压在视图上面
      元.预览空态.classList.add('隐藏');
      window.后端.预览导航('刷新');
      贴预览位置();
    } else {
      预览打开(元.预览地址.value);   // 上次失败过，视图没建起来，重新试
    }
  });
  // 用系统浏览器打开：调试要用 DevTools 时比内嵌方便
  元.预览外开.addEventListener('click', () => {
    const 网址 = 规范网址(元.预览地址.value);
    if (网址 && window.后端 && window.后端.开外链) { window.后端.开外链(网址); }
  });
  /* 拖动把手改宽度。
     mousemove 挂在 document 上而不是把手上：鼠标拖快了会离开那 6px，
     挂在把手上就会中途丢事件。 */
  let 起点X = 0, 起始宽 = 0;
  const 拖动中 = (e) => {
    定预览宽(起始宽 + (e.clientX - 起点X));
    // 视图是原生层，不跟 CSS 走，拖的过程中得不停把新位置报上去。
    // 走节流版：mousemove 触发太密，每次都同步 IPC 会卡手。
    排预览定位();
  };
  const 松手 = () => {
    document.removeEventListener('mousemove', 拖动中);
    document.removeEventListener('mouseup', 松手);
    document.body.classList.remove('在拖预览');
    元.预览把手.classList.remove('拖着');
    // 记住宽度。读的是实际生效值，不是鼠标位置，所以夹紧后的值才会被存
    const 现宽 = parseInt(getComputedStyle(元.预览栏).width, 10);
    if (现宽 > 0) { localStorage.setItem(预览宽键, String(现宽)); }
    // 节流会漏掉最后一帧，松手时补一次准确的
    应用预览模拟();
    贴预览位置();
  };
  元.预览把手.addEventListener('mousedown', (e) => {
    e.preventDefault();   // 不让它选中文字
    起点X = e.clientX;
    起始宽 = parseInt(getComputedStyle(元.预览栏).width, 10) || 420;
    document.body.classList.add('在拖预览');
    元.预览把手.classList.add('拖着');
    document.addEventListener('mousemove', 拖动中);
    document.addEventListener('mouseup', 松手);
  });
  // 键盘也能调宽度，方向键一次 20px
  元.预览把手.addEventListener('keydown', (e) => {
    const 现宽 = parseInt(getComputedStyle(元.预览栏).width, 10) || 420;
    if (e.key === 'ArrowLeft') { e.preventDefault(); localStorage.setItem(预览宽键, String(定预览宽(现宽 - 20))); }
    if (e.key === 'ArrowRight') { e.preventDefault(); localStorage.setItem(预览宽键, String(定预览宽(现宽 + 20))); }
  });
  // 窗口变小后原来的宽度可能已经超限，重新夹一次
  window.addEventListener('resize', () => {
    if (!元.预览栏.classList.contains('隐藏')) {
      定预览宽(parseInt(getComputedStyle(元.预览栏).width, 10) || 420);
      /* 视图的位置是主进程按像素定的，不受 CSS 布局影响，
         所以窗口一缩放必须重新量一次报上去，否则视图会留在原地。 */
      应用预览模拟();
      排预览定位();
    }
  });
  /* 设备切换。恢复上次选的设备——每次开预览都要重新选一遍很烦。 */
  if (元.预览设备) {
    const 存的设备 = localStorage.getItem(预览设备键);
    if (存的设备 && 预览设备表[存的设备]) {
      预览当前设备 = 存的设备;
      元.预览设备.value = 存的设备;
    }
    const 落实设备 = (选) => {
      预览当前设备 = 选;
      预览横屏 = false;   // 换设备回到竖屏，横屏是临时状态
      localStorage.setItem(预览设备键, 选);
      // 传 true：UA 变了必须重新请求，否则服务端还按上一个 UA 给内容
      应用预览设备(true);
    };
    元.预览设备.addEventListener('change', () => {
      const 选 = 元.预览设备.value;
      if (选 === '自定义') {
        /* 面板是异步的，不能像 prompt 那样等返回值。
           用户取消就把下拉退回原来那档，否则会停在「自定义」上但没生效，
           看起来像点了没反应。 */
        开尺寸面板((确定了) => {
          if (确定了) { 落实设备('自定义'); }
          else { 元.预览设备.value = 预览当前设备; }
        });
        return;
      }
      落实设备(选);
    });
  }
  /* 诊断。把宿主侧和 guest 侧各自的实测数字摆在一起。
     两边尺寸一致说明布局没问题，画不全就是合成表面的事；
     不一致说明设备模拟没吃进去。这两种成因的修法完全不同，
     而服务器上没有图形环境，只能靠这里的实测数据判断。 */
  if (元.预览诊断) {
    元.预览诊断.addEventListener('click', async () => {
      if (!$预览开着) { 提示('预览未开'); return; }
      const 规 = 预览规格();
      const 行 = [];
      if (元.预览舞台) {
        const s2 = 元.预览舞台.getBoundingClientRect();
        行.push('舞台 ' + Math.round(s2.width) + '×' + Math.round(s2.height));
      }
      行.push('规格 ' + 规.宽 + '×' + 规.高 + (规.移动 ? ' 移动' : ' 桌面'));
      const 诊 = await window.后端.预览诊断();
      if (!诊 || !诊.ok) {
        行.push('视图 取不到：' + ((诊 && 诊.错) || '未知'));
      } else {
        const r = 诊.范围;
        if (r) {
          行.push('视图 ' + r.width + '×' + r.height +
                  ' @(' + r.x + ',' + r.y + ')');
        }
        if (诊.页面) {
          const o = 诊.页面;
          行.push('页面 ' + o.w + '×' + o.h + ' @' + o.d + 'x');
          行.push('文档 宽' + o.cw + ' 体高' + o.bh +
                  ' 触摸=' + (o.coarse ? '是' : '否'));
          /* 关键判断：页面实测宽度应该等于规格宽度。
             对不上就是设备模拟没生效，而不是画不全。 */
          if (规.宽 > 0) {
            行.push(o.w === 规.宽 ? '✓ 模拟生效' :
              '✗ 模拟没生效（页面宽 ' + o.w + '，应为 ' + 规.宽 + '）');
          }
        } else {
          行.push('页面 读取失败：' + (诊.页面错 || '未知'));
        }
      }
      const 文 = 行.join('\n');
      console.log('[预览诊断]\n' + 文);
      /* 不用 alert：Electron 渲染进程里它是同步阻塞调用，实测会把进程卡住。
         直接在预览区盖一层浮层，文字可选中复制，点一下就关。 */
      let 层 = document.getElementById('预览诊断层');
      if (层) { 层.remove(); return; }
      层 = document.createElement('div');
      层.id = '预览诊断层';
      层.style.cssText = 'position:absolute;left:8px;top:8px;z-index:99;' +
        'background:rgba(20,22,26,.94);color:#e8eaed;padding:10px 12px;' +
        'border-radius:8px;font:12px/1.6 ui-monospace,Consolas,monospace;' +
        'white-space:pre;user-select:text;cursor:pointer;max-width:calc(100% - 16px);' +
        'box-shadow:0 4px 16px rgba(0,0,0,.4)';
      层.textContent = 文 + '\n\n（点此关闭）';
      层.addEventListener('click', () => 层.remove());
      const 容 = 元.预览体 || 元.预览舞台;
      if (容) {
        // 浮层用绝对定位，容器必须是定位上下文，否则会跑到页面左上角
        if (getComputedStyle(容).position === 'static') { 容.style.position = 'relative'; }
        容.appendChild(层);
      }
    });
  }
  /* 横竖屏。只交换宽高，UA 不动——真机转屏 UA 也不会变。 */
  if (元.预览旋转) {
    元.预览旋转.addEventListener('click', () => {
      预览横屏 = !预览横屏;
      // 不重载：视口尺寸变化靠 CSS 媒体查询和 resize 事件响应，页面自己会重排
      应用预览设备(false);
    });
  }
}
/* 自定义尺寸面板。
   Electron 渲染进程里 window.prompt 是空实现——调了不弹窗、直接返回 undefined，
   用户点「自定义」会觉得按钮坏了。所以这里自己做面板。
   面板是异步的，拿不到返回值，用回调把「确定」之后的动作传进来。 */
let 尺寸确认回调 = null;
function 开尺寸面板(确认后) {
  if (!元.尺寸遮罩) { return; }
  const 旧 = 读自定尺寸();
  元.尺寸宽.value = 旧.宽;
  元.尺寸高.value = 旧.高;
  元.尺寸比.value = String(旧.dpr);
  元.尺寸移动.checked = 旧.移动 !== false;
  尺寸确认回调 = 确认后 || null;
  元.尺寸遮罩.classList.remove('隐藏');
  元.尺寸宽.focus();
  元.尺寸宽.select();
}
function 关尺寸面板(取消了) {
  if (!元.尺寸遮罩) { return; }
  元.尺寸遮罩.classList.add('隐藏');
  // 用户放弃时要让调用方知道，好把下拉退回原来那一档
  const 回调 = 尺寸确认回调;
  尺寸确认回调 = null;
  if (取消了 && 回调) { 回调(false); }
}
/** 读面板里的值存起来。值不合法返回 false，不关面板，让用户改。 */
function 存尺寸面板() {
  const 宽 = parseInt(元.尺寸宽.value, 10);
  const 高 = parseInt(元.尺寸高.value, 10);
  const dpr = parseFloat(元.尺寸比.value) || 2;
  // 上限 3000：再大就超出任何真实设备，而且 dpr 一乘上去很吃内存
  if (!(宽 >= 200 && 宽 <= 3000) || !(高 >= 200 && 高 <= 3000)) {
    元.尺寸宽.classList.toggle('输入错', !(宽 >= 200 && 宽 <= 3000));
    元.尺寸高.classList.toggle('输入错', !(高 >= 200 && 高 <= 3000));
    return false;
  }
  元.尺寸宽.classList.remove('输入错');
  元.尺寸高.classList.remove('输入错');
  localStorage.setItem(预览自定键, JSON.stringify({
    宽: 宽, 高: 高, dpr: Math.min(Math.max(dpr, 1), 4),
    移动: !!元.尺寸移动.checked
  }));
  return true;
}
function 绑尺寸面板() {
  if (!元.尺寸遮罩) { return; }
  元.尺寸关.addEventListener('click', () => 关尺寸面板(true));
  元.尺寸取消.addEventListener('click', () => 关尺寸面板(true));
  // 点遮罩空白处也算取消，跟其他面板一致
  元.尺寸遮罩.addEventListener('click', (e) => {
    if (e.target === 元.尺寸遮罩) { 关尺寸面板(true); }
  });
  元.尺寸确定.addEventListener('click', () => {
    if (!存尺寸面板()) { return; }
    const 回调 = 尺寸确认回调;
    尺寸确认回调 = null;
    元.尺寸遮罩.classList.add('隐藏');
    if (回调) { 回调(true); }
  });
  // 两个数字框里按回车等于点应用，不用去摸鼠标
  [元.尺寸宽, 元.尺寸高].forEach((框) => {
    框.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); 元.尺寸确定.click(); }
    });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !元.尺寸遮罩.classList.contains('隐藏')) {
      关尺寸面板(true);
    }
  });
}
/* ---------- 自定义标题栏的窗口控制 ----------
   主进程用了 frame: false，最小化/最大化/关闭都得自己发 IPC。
   标题栏在登录页也显示，所以这里跟登录状态无关，只绑一次。
   通道没接时（比如直接用浏览器打开 index.html 调样式）整块跳过，
   按钮点了没反应但不报错，其余功能照常。 */
function 绑窗口控制() {
  const 后 = window.后端;
  if (!后 || !后.最小化窗口) return;
  if (元.最小化钮) {
    元.最小化钮.addEventListener('click', () => 后.最小化窗口());
  }
  if (元.最大化钮) {
    元.最大化钮.addEventListener('click', async () => {
      const r = await 后.最大化切换();
      画最大化图(r && r.最大化);
    });
  }
  if (元.关闭钮) {
    元.关闭钮.addEventListener('click', () => 后.关闭窗口());
  }
  // 双击标题栏空白处也切换最大化，这是窗口的通行操作，
  // 少了它用户会觉得这条标题栏「不对劲」。
  const 条 = document.getElementById('标题栏');
  if (条) {
    条.addEventListener('dblclick', async (e) => {
      // 点在按钮上时不处理，否则双击按钮会顺带把窗口最大化
      if (e.target.closest('button')) return;
      const r = await 后.最大化切换();
      画最大化图(r && r.最大化);
    });
  }
  // 拖到屏幕顶端、Win+↑ 这些路径不经过我们的按钮，
  // 得靠主进程广播才能把图标切对
  if (后.收最大化变化) 后.收最大化变化(画最大化图);
  // 启动时问一次：窗口可能是以最大化状态恢复的
  if (后.是否最大化) {
    后.是否最大化().then((r) => 画最大化图(r && r.最大化));
  }
}
/** 按当前状态显示「最大化」或「还原」图标，两个 svg 换着藏。 */
function 画最大化图(最大化) {
  if (!元.最大化图 || !元.还原图) return;
  元.最大化图.classList.toggle('隐藏', !!最大化);
  元.还原图.classList.toggle('隐藏', !最大化);
  if (元.最大化钮) {
    元.最大化钮.title = 最大化 ? '还原' : '最大化';
    元.最大化钮.setAttribute('aria-label', 最大化 ? '还原' : '最大化');
  }
}
/* 启动时试着用记住的密钥自动进去。
   上传入口要在这里建，DOM 就绪前 元.输入框.parentNode 还取不到。 */
window.addEventListener('DOMContentLoaded', async () => {
  建上传入口();
  // 面板事件和登录无关，只绑一次。放在登录成功后绑会重复叠加监听器。
  绑窗口控制();   // 标题栏在登录页也要能用，跟登录状态无关
  绑提示音();
  更新声音钮();
  绑更新钮();
  绑服务器面板();
  绑项目面板();
  绑预览栏();
  绑预览让位();   // 必须在 绑预览栏 之后：要等遮罩节点都在了才能观察
  绑尺寸面板();
  const 存的 = await window.后端.读密钥();
  if (存的) { 元.密钥框.value = 存的; 登录(存的); }
});
