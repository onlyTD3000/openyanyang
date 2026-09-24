<?php /* @name: 三端首页模板1 */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($siteName) ?> · 能动手的 AI 开发助手</title>
<meta name="description" content="聚合多家大模型，能直连服务器执行命令、改代码、查日志，配桌面客户端与独立 SSH 终端。">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="<?= asset('/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<style>
  /* ---------- 通用骨架 ---------- */
  body { margin: 0; background: #ffffff; }
  .wrap { max-width: 1140px; margin: 0 auto; padding: 0 24px; }
  .sec { padding: 88px 0; }
  .sec-alt { background: #f7f8fa; }
  .sec-head { text-align: center; max-width: 680px; margin: 0 auto 48px; }
  .sec-tag { display: inline-block; font-size: 13px; font-weight: 600; letter-spacing: .5px;
    color: var(--c-primary); background: var(--c-primary-soft);
    padding: 5px 12px; border-radius: 999px; margin-bottom: 16px; }
  .sec-title { font-size: 32px; font-weight: 700; color: var(--c-text); margin: 0 0 14px; line-height: 1.3; }
  .sec-sub { font-size: 16px; color: var(--c-text-2); line-height: 1.7; margin: 0; }
  /* ---------- 顶部导航 ---------- */
  .nav { position: sticky; top: 0; z-index: 50; background: rgba(255,255,255,.88);
    backdrop-filter: saturate(180%) blur(12px); border-bottom: 1px solid var(--c-border-soft); }
  .nav-in { max-width: 1140px; margin: 0 auto; padding: 14px 24px;
    display: flex; align-items: center; justify-content: space-between; }
  .brand { display: flex; align-items: center; gap: 9px; font-size: 17px; font-weight: 600; color: var(--c-text); }
  .brand .dot { width: 10px; height: 10px; border-radius: 50%;
    background: linear-gradient(135deg, var(--c-primary), #4a7bff); }
  .nav-links { display: flex; align-items: center; gap: 22px; }
  .nav-links a { color: var(--c-text-2); text-decoration: none; font-size: 14px; }
  .nav-links a:hover { color: var(--c-primary); }
  .nav-cta { padding: 7px 16px; border-radius: 7px; background: var(--c-primary);
    color: #fff !important; font-weight: 500; }
  .nav-cta:hover { background: var(--c-primary-dark); }
  /* ---------- 首屏 ---------- */
  .hero { padding: 92px 0 78px; text-align: center;
    background: radial-gradient(1100px 480px at 50% -12%, #eaf0ff 0%, rgba(255,255,255,0) 68%); }
  .hero-badge { display: inline-flex; align-items: center; gap: 8px; font-size: 13px;
    color: var(--c-text-2); background: #fff; border: 1px solid var(--c-border);
    padding: 6px 14px; border-radius: 999px; margin-bottom: 26px; box-shadow: var(--shadow); }
  .hero-badge b { color: var(--c-primary); font-weight: 600; }
  .hero-title { font-size: 50px; font-weight: 700; color: #111827;
    margin: 0 0 22px; line-height: 1.16; letter-spacing: -.5px; }
  .hero-title span { background: linear-gradient(135deg, var(--c-primary), #4a7bff);
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
  .hero-sub { font-size: 18px; color: var(--c-text-2); line-height: 1.75;
    max-width: 720px; margin: 0 auto 36px; }
  .hero-actions { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
  .btn-xl { display: inline-flex; align-items: center; gap: 8px; padding: 13px 30px;
    border-radius: 9px; font-size: 15.5px; font-weight: 500; text-decoration: none;
    border: 1px solid transparent; cursor: pointer; font-family: inherit; }
  .btn-fill { background: linear-gradient(135deg, var(--c-primary), #4a7bff); color: #fff;
    box-shadow: 0 6px 18px rgba(31, 94, 255, .28); }
  .btn-fill:hover { filter: brightness(1.06); }
  .btn-line { background: #fff; color: var(--c-text); border-color: var(--c-border); }
  .btn-line:hover { border-color: var(--c-primary); color: var(--c-primary); }
  .hero-tip { margin-top: 16px; font-size: 13px; color: var(--c-muted); }
  /* ---------- 数据条 ---------- */
  .stats { display: flex; justify-content: center; flex-wrap: wrap; gap: 0;
    margin: 56px auto 0; max-width: 820px; background: #fff;
    border: 1px solid var(--c-border); border-radius: 14px; box-shadow: var(--shadow); overflow: hidden; }
  .stat { flex: 1 1 25%; min-width: 130px; padding: 22px 14px; text-align: center;
    border-right: 1px solid var(--c-border-soft); }
  .stat:last-child { border-right: none; }
  .stat-num { font-size: 27px; font-weight: 700; color: var(--c-text); line-height: 1.2; }
  .stat-label { font-size: 13px; color: var(--c-muted); margin-top: 5px; }
  /* ---------- 客户端专区 ---------- */
  .cli { display: grid; grid-template-columns: 1.05fr 1fr; gap: 56px; align-items: center; }
  .cli-copy h2 { font-size: 32px; font-weight: 700; color: var(--c-text); margin: 0 0 16px; line-height: 1.3; }
  .cli-copy p { font-size: 16px; color: var(--c-text-2); line-height: 1.75; margin: 0 0 26px; }
  .cli-list { display: grid; gap: 18px; margin-bottom: 30px; }
  .cli-item { display: flex; gap: 13px; align-items: flex-start; }
  .cli-ico { flex: none; width: 38px; height: 38px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    background: var(--c-primary-soft); color: var(--c-primary); }
  .cli-item h4 { margin: 1px 0 4px; font-size: 15px; font-weight: 600; color: var(--c-text); }
  .cli-item div.d { font-size: 13.5px; color: var(--c-text-2); line-height: 1.65; }
  /* 终端截图占位：用 CSS 画一个假终端，比放张图更省事也不会失真 */
  .term { background: #12141a; border-radius: 12px; overflow: hidden;
    box-shadow: 0 18px 50px rgba(16, 24, 40, .28); border: 1px solid #23262e; }
  .term-bar { display: flex; align-items: center; gap: 7px; padding: 11px 14px;
    background: #1a1d24; border-bottom: 1px solid #23262e; }
  .term-dot { width: 11px; height: 11px; border-radius: 50%; }
  .term-title { margin-left: 8px; font-size: 12.5px; color: #8b93a1;
    font-family: ui-monospace, Menlo, Consolas, monospace; }
  .term-body { padding: 16px 18px 22px; font-size: 13px; line-height: 1.85;
    font-family: ui-monospace, Menlo, Consolas, monospace; color: #cfd5e1;
    white-space: pre-wrap; word-break: break-all; }
  .term-body .p { color: #5ec27e; }
  .term-body .c { color: #eaeef6; }
  .term-body .o { color: #8b93a1; }
  .term-body .k { color: #f0a020; }
  .term-cursor { display: inline-block; width: 7px; height: 14px; background: #5ec27e;
    vertical-align: -2px; animation: blink 1.1s steps(1) infinite; }
  @keyframes blink { 50% { opacity: 0; } }
  /* ---------- 能力网格 ---------- */
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
  .card { background: #fff; border: 1px solid var(--c-border); border-radius: 12px;
    padding: 24px 20px; transition: box-shadow .18s, border-color .18s, transform .18s; }
  .card:hover { border-color: #c9d6ff; box-shadow: var(--shadow-lg); transform: translateY(-2px); }
  .card-ico { width: 42px; height: 42px; border-radius: 10px; margin-bottom: 15px;
    display: flex; align-items: center; justify-content: center;
    background: var(--c-primary-soft); color: var(--c-primary); }
  .card h3 { margin: 0 0 8px; font-size: 15.5px; font-weight: 600; color: var(--c-text); }
  .card p { margin: 0; font-size: 13.5px; color: var(--c-text-2); line-height: 1.7; }
  /* ---------- 三步流程 ---------- */
  .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
  .step { position: relative; background: #fff; border: 1px solid var(--c-border);
    border-radius: 12px; padding: 28px 24px; }
  .step-no { width: 30px; height: 30px; border-radius: 8px; background: var(--c-primary);
    color: #fff; font-size: 14px; font-weight: 600; margin-bottom: 15px;
    display: flex; align-items: center; justify-content: center; }
  .step h3 { margin: 0 0 9px; font-size: 16px; font-weight: 600; color: var(--c-text); }
  .step p { margin: 0; font-size: 14px; color: var(--c-text-2); line-height: 1.7; }
  /* ---------- 收尾行动区 ---------- */
  .cta { text-align: center; padding: 80px 0;
    background: linear-gradient(135deg, #1f2937 0%, #1a2540 100%); }
  .cta h2 { font-size: 31px; font-weight: 700; color: #fff; margin: 0 0 14px; }
  .cta p { font-size: 16px; color: #b6bdca; margin: 0 0 32px; line-height: 1.7; }
  .cta .btn-line { background: transparent; color: #fff; border-color: rgba(255,255,255,.3); }
  .cta .btn-line:hover { border-color: #fff; color: #fff; background: rgba(255,255,255,.08); }
  /* ---------- 页脚 ---------- */
  .foot { padding: 40px 0 34px; text-align: center; font-size: 13px;
    background: #f7f8fa; border-top: 1px solid var(--c-border-soft); }
  .foot-row { display: flex; flex-wrap: wrap; gap: 8px 20px; justify-content: center; margin-bottom: 10px; }
  .foot-row span, .foot-row a { color: var(--c-text-2); }
  .foot-row a { text-decoration: none; }
  .foot-row a:hover { color: var(--c-primary); text-decoration: underline; }
  .foot-copy { color: var(--c-muted); }
  /* ---------- 下载浮层 ---------- */
  .dl-wrap { position: relative; display: inline-block; }
  .dl-panel { position: absolute; top: calc(100% + 10px); left: 50%; transform: translateX(-50%);
    background: #fff; border: 1px solid var(--c-border); border-radius: 10px; padding: 6px;
    box-shadow: var(--shadow-lg); min-width: 210px; z-index: 30; text-align: left; }
  .dl-panel[hidden] { display: none; }
  .dl-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px;
    border-radius: 8px; color: var(--c-text); text-decoration: none; font-size: 14px; white-space: nowrap; }
  .dl-item:hover { background: #f3f4f6; color: var(--c-primary); }
  .dl-item .dl-icon { font-size: 17px; line-height: 1; }
  /* ---------- 响应式 ---------- */
  /* ---------- 客户端界面示意图 ---------- */
  .cliui { background: #fff; border: 1px solid var(--c-border); border-radius: 14px;
    box-shadow: 0 22px 50px -18px rgba(15,23,42,.28); overflow: hidden; }
  .cliui-bar { display: flex; align-items: center; gap: 7px; padding: 11px 14px;
    background: #f4f5f8; border-bottom: 1px solid var(--c-border-soft); }
  .cliui-title { margin-left: 8px; font-size: 12px; color: var(--c-muted); font-weight: 600; }
  .cliui-body { display: grid; grid-template-columns: 148px 1fr; min-height: 322px; }
  .cliui-side { background: #fafbfc; border-right: 1px solid var(--c-border-soft); padding: 14px 11px; }
  .cliui-side-h { font-size: 10px; font-weight: 700; letter-spacing: .8px; color: #9aa3b2;
    text-transform: uppercase; margin: 4px 0 7px; }
  .cliui-side-h + .cliui-side-h, .cliui-file + .cliui-side-h { margin-top: 16px; }
  .cliui-proj, .cliui-file { display: flex; align-items: center; gap: 7px; font-size: 12px;
    color: var(--c-text-2); padding: 6px 8px; border-radius: 7px; margin-bottom: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cliui-proj.on { background: var(--c-primary-soft); color: var(--c-primary); font-weight: 600; }
  .cliui-dot { width: 6px; height: 6px; border-radius: 50%; background: #34d399; flex: none; }
  .cliui-dot.gray { background: #cbd5e1; }
  .cliui-file { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; }
  .cliui-main { padding: 16px 16px 14px; display: flex; flex-direction: column; gap: 9px; }
  .cliui-msg { font-size: 12.5px; line-height: 1.6; padding: 9px 12px; border-radius: 11px; max-width: 88%; }
  .cliui-msg.me { align-self: flex-end; background: var(--c-primary); color: #fff;
    border-bottom-right-radius: 4px; }
  .cliui-msg.ai { align-self: flex-start; background: #f4f5f8; color: var(--c-text);
    border-bottom-left-radius: 4px; }
  .cliui-msg.ai code { font-size: 11px; background: #fff; padding: 1px 5px; border-radius: 4px;
    border: 1px solid var(--c-border-soft); }
  .cliui-card { display: flex; align-items: center; gap: 7px; margin-top: 8px; background: #fff;
    border: 1px solid var(--c-border); border-radius: 8px; padding: 7px 10px; font-size: 11.5px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .cliui-card-ico { display: inline-flex; color: var(--c-primary); }
  .cliui-card-ico svg { width: 13px; height: 13px; }
  .cliui-card em { font-style: normal; color: #16a34a; margin-left: 8px; font-weight: 600; }
  .cliui-paste { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
  .cliui-shot { display: inline-flex; align-items: center; gap: 5px; font-size: 11px;
    background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.35);
    border-radius: 6px; padding: 4px 8px; }
  .cliui-shot svg { width: 12px; height: 12px; }
  .cliui-input { margin-top: auto; display: flex; align-items: center; justify-content: space-between;
    border: 1px solid var(--c-border); border-radius: 9px; padding: 9px 11px;
    font-size: 12px; color: #a8b0bd; background: #fff; }
  .cliui-send { display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 6px; background: var(--c-primary); color: #fff; flex: none; }
  .cliui-send svg { width: 13px; height: 13px; }
  /* ---------- 顶部版本悬挂条 ---------- */
  .verbar { background: linear-gradient(90deg, #0f172a 0%, #1e293b 55%, #0f172a 100%);
    color: #e2e8f0; font-size: 13px; position: relative; z-index: 60; }
  .verbar-in { max-width: 1180px; margin: 0 auto; padding: 8px 24px;
    display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
  .verbar-lead { display: inline-flex; align-items: center; gap: 7px;
    color: #94a3b8; letter-spacing: .3px; }
  .verbar-lead svg { width: 15px; height: 15px; }
  .verbar-items { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-left: auto; }
  .vchip { display: inline-flex; align-items: center; gap: 7px;
    background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14);
    border-radius: 999px; padding: 4px 13px 4px 10px; color: #e2e8f0;
    text-decoration: none; transition: background .18s, border-color .18s, transform .18s; }
  .vchip:hover { background: rgba(255,255,255,.13); border-color: rgba(255,255,255,.3);
    transform: translateY(-1px); color: #fff; }
  .vchip svg { width: 14px; height: 14px; opacity: .8; }
  .vchip b { font-weight: 600; letter-spacing: .2px; }
  .vchip .vnum { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px; color: #7dd3fc; }
  .vchip-live::before { content: ''; width: 6px; height: 6px; border-radius: 50%;
    background: #34d399; box-shadow: 0 0 0 3px rgba(52,211,153,.2); }
  /* ---------- 三端分区 ---------- */
  .plats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
  .plat-dots { display: none; justify-content: center; gap: 8px; margin-top: 18px; }
  .plat-dot { width: 7px; height: 7px; padding: 0; border: none; border-radius: 50%;
    background: #cbd5e1; cursor: pointer; transition: width .25s, background .25s; }
  .plat-dot.on { width: 20px; border-radius: 4px; background: var(--c-primary); }
  .plat { background: #fff; border: 1px solid var(--c-border); border-radius: 16px;
    padding: 30px 26px 28px; box-shadow: var(--shadow); position: relative;
    display: flex; flex-direction: column; transition: transform .2s, box-shadow .2s, border-color .2s; }
  .plat:hover { transform: translateY(-3px); box-shadow: 0 14px 40px rgba(15,23,42,.09);
    border-color: #c7d7ec; }
  .plat-top { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
  .plat-ico { flex: none; width: 44px; height: 44px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    background: var(--c-primary-soft); color: var(--c-primary); }
  .plat h3 { font-size: 19px; font-weight: 700; color: var(--c-text); margin: 0; line-height: 1.3; }
  .plat-tag { font-size: 12px; color: var(--c-muted); margin-top: 2px; }
  .plat-desc { font-size: 14px; color: var(--c-text-2); line-height: 1.75; margin: 6px 0 20px; }
  .plat-feats { list-style: none; padding: 0; margin: 0 0 22px; display: grid; gap: 14px; }
  .plat-feats li { display: flex; gap: 11px; align-items: flex-start; }
  .plat-feats .fi { flex: none; width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: #f1f5f9; color: #475569; }
  .plat-feats .fi svg { width: 17px; height: 17px; }
  .plat-feats .ft { font-size: 14px; font-weight: 600; color: var(--c-text); line-height: 1.45; }
  .plat-feats .fd { font-size: 13px; color: var(--c-muted); line-height: 1.65; margin-top: 2px; }
  .plat-foot { margin-top: auto; }
  .plat-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 11px 18px; border-radius: 10px; font-size: 14px; font-weight: 600;
    text-decoration: none; transition: background .18s, border-color .18s, color .18s; }
  .plat-btn svg { width: 17px; height: 17px; }
  .plat-btn-fill { background: var(--c-primary); color: #fff; border: 1px solid var(--c-primary); }
  .plat-btn-fill:hover { background: var(--c-primary-dark); border-color: var(--c-primary-dark); }
  .plat-btn-line { background: #fff; color: var(--c-text); border: 1px solid var(--c-border); }
  .plat-btn-line:hover { border-color: var(--c-primary); color: var(--c-primary); }
  .plat-ver { font-size: 12px; color: var(--c-muted); text-align: center; margin-top: 9px; }
  .plat-ver code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    color: var(--c-primary); background: var(--c-primary-soft);
    padding: 1px 6px; border-radius: 5px; }
  /* 主推那一列做视觉抬升 */
  .plat-hero { border-color: #bfdbfe;
    background: linear-gradient(180deg, #f8fbff 0%, #fff 42%); }
  .plat-hero::after { content: '主推'; position: absolute; top: 16px; right: 16px;
    font-size: 11px; font-weight: 700; letter-spacing: .5px; color: #fff;
    background: linear-gradient(135deg, #2563eb, #0ea5e9);
    padding: 3px 10px; border-radius: 999px; }
  /* ---------- SSH 重点强调块 ---------- */
  .ssh { background: linear-gradient(160deg, #0b1220 0%, #111c31 48%, #0b1220 100%);
    border-radius: 20px; padding: 46px 44px; color: #e2e8f0; position: relative; overflow: hidden; }
  .ssh::before { content: ''; position: absolute; inset: 0;
    background: radial-gradient(700px 300px at 12% 0%, rgba(56,189,248,.16), transparent 60%),
                radial-gradient(600px 260px at 88% 100%, rgba(37,99,235,.18), transparent 62%);
    pointer-events: none; }
  .ssh-in { position: relative; display: grid; grid-template-columns: 1fr 1.02fr;
    gap: 46px; align-items: center; }
  .ssh-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 12px;
    font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #7dd3fc;
    background: rgba(125,211,252,.1); border: 1px solid rgba(125,211,252,.24);
    padding: 5px 13px; border-radius: 999px; margin-bottom: 18px; }
  .ssh-eyebrow svg { width: 15px; height: 15px; }
  .ssh h2 { font-size: 33px; font-weight: 700; color: #fff; margin: 0 0 16px; line-height: 1.28; }
  .ssh h2 em { font-style: normal;
    background: linear-gradient(90deg, #38bdf8, #818cf8);
    -webkit-background-clip: text; background-clip: text; color: transparent; }
  .ssh > .ssh-in > div > p { font-size: 15.5px; color: #94a3b8; line-height: 1.8; margin: 0 0 26px; }
  .ssh-pts { list-style: none; padding: 0; margin: 0 0 30px; display: grid; gap: 17px; }
  .ssh-pts li { display: flex; gap: 13px; align-items: flex-start; }
  .ssh-pts .si { flex: none; width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(56,189,248,.13); color: #38bdf8; border: 1px solid rgba(56,189,248,.22); }
  .ssh-pts .si svg { width: 18px; height: 18px; }
  .ssh-pts .st { font-size: 15px; font-weight: 600; color: #f1f5f9; line-height: 1.45; }
  .ssh-pts .sd { font-size: 13.5px; color: #94a3b8; line-height: 1.7; margin-top: 3px; }
  .ssh-btn { display: inline-flex; align-items: center; gap: 9px;
    background: #fff; color: #0f172a; font-size: 15px; font-weight: 600;
    padding: 12px 24px; border-radius: 10px; text-decoration: none;
    transition: transform .18s, box-shadow .18s; }
  .ssh-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(56,189,248,.28); }
  .ssh-btn svg { width: 18px; height: 18px; }
  /* 深色底上的终端窗口 */
  .ssh-term { background: #05080f; border: 1px solid rgba(148,163,184,.2);
    border-radius: 13px; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.45); }
  .ssh-term .term-bar { background: #0d1424; border-bottom: 1px solid rgba(148,163,184,.16); }
  .ssh-term .term-title { color: #94a3b8; }
  .ssh-term .term-body { background: #05080f; color: #cbd5e1; }
  .ssh-tabs { display: flex; gap: 6px; padding: 8px 12px 0; background: #0d1424; }
  .ssh-tab { font-size: 12px; color: #94a3b8; padding: 5px 12px; border-radius: 7px 7px 0 0;
    background: rgba(148,163,184,.08); border: 1px solid transparent; border-bottom: none; }
  .ssh-tab.on { color: #e2e8f0; background: #05080f; border-color: rgba(148,163,184,.18); }
  @media (max-width: 1024px) {
    .grid { grid-template-columns: repeat(2, 1fr); }
    .cli { grid-template-columns: 1fr; gap: 40px; }
    .steps { grid-template-columns: 1fr; }
    .plats { grid-template-columns: 1fr; }
    .ssh-in { grid-template-columns: 1fr; gap: 36px; }
    .ssh { padding: 38px 26px; }
  }
  @media (max-width: 768px) {
    .sec { padding: 62px 0; }
    .hero { padding: 66px 0 56px; }
    .hero-title { font-size: 33px; }
    .hero-sub { font-size: 16px; }
    .sec-title, .cli-copy h2, .cta h2 { font-size: 25px; }
    .grid { grid-template-columns: 1fr; }
    .stat { flex-basis: 50%; border-bottom: 1px solid var(--c-border-soft); }
    .nav-links { gap: 14px; }
    .nav-links .hide-sm { display: none; }
    .verbar-in { padding: 8px 16px; gap: 10px; }
    .verbar-lead { display: none; }
    .verbar-items { margin-left: 0; width: 100%; justify-content: center; }
    .ssh h2 { font-size: 25px; }
    .ssh { padding: 30px 20px; }
    .plats { display: flex; align-items: stretch;
      overflow-x: auto; scroll-snap-type: x mandatory;
      -webkit-overflow-scrolling: touch; scrollbar-width: none;
      gap: 14px; padding: 4px 16px 6px; margin: 0 -20px; }
    .plats::-webkit-scrollbar { display: none; }
    .plats > .plat { flex: 0 0 86%; scroll-snap-align: center;
      padding: 26px 22px 24px; }
    .plat:hover { transform: none; }
    .plat-dots { display: flex; }
    .cliui-body { grid-template-columns: 112px 1fr; min-height: 300px; }
    .cliui-side { padding: 11px 8px; }
    .cliui-proj, .cliui-file { font-size: 11px; }
    .cliui-main { padding: 12px 12px 11px; gap: 8px; }
    .cliui-msg { font-size: 11.5px; max-width: 94%; }
    .cliui-card { font-size: 10.5px; }
    .cliui-input { padding: 7px 9px; font-size: 11px; }
  }
</style>
</head>
<body>
<?php if ($客户端版本 || $安卓版本): ?>
<!-- 顶部版本悬挂条：版本号由客户端 package.json 和 apk 目录自动读出 -->
<div class="verbar">
  <div class="verbar-in">
    <span class="verbar-lead">
      <?= 首页图标('zap') ?>全平台同步更新，客户端与安卓端已就绪
    </span>
    <div class="verbar-items">
      <?php if ($客户端版本): ?>
        <a class="vchip vchip-live" href="#客户端">
          <?= 首页图标('desktop') ?><b>桌面客户端</b><span class="vnum">v<?= h($客户端版本) ?></span>
        </a>
      <?php endif; ?>
      <?php if ($安卓版本): ?>
        <a class="vchip vchip-live" href="<?= $安卓包 ? h($安卓包) : '#安卓端' ?>"<?= $安卓包 ? ' download' : '' ?>>
          <?= 首页图标('phone') ?><b>安卓端</b><span class="vnum">v<?= h($安卓版本) ?></span>
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<nav class="nav">
  <div class="nav-in">
    <div class="brand"><span class="dot"></span><?= h($siteName) ?></div>
    <div class="nav-links">
      <a href="#能力" class="hide-sm">平台能力</a>
      <a href="#三端" class="hide-sm">三端支持</a>
      <a href="#ssh" class="hide-sm">SSH 终端</a>
      <a href="#客户端" class="hide-sm">桌面客户端</a>
      <?php if ($当前用户): ?>
        <a href="/chat.php" class="nav-cta">进入对话</a>
        <a href="/logout.php">退出</a>
      <?php else: ?>
        <a href="/login.php">登录</a>
        <?php if (setting_get('allow_register', '1') === '1'): ?>
          <a href="/register.php" class="nav-cta">免费注册</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</nav>
<header class="hero">
  <div class="wrap">
    <?php if ($客户端版本 !== '' && $downloads): ?>
      <div class="hero-badge">
        <b>桌面客户端 v<?= h($客户端版本) ?></b> 已发布 · 内置独立 SSH 终端
      </div>
    <?php endif; ?>
    <h1 class="hero-title">会动手的 <span>AI Agent</span><br>从一句话直达生产环境</h1>
    <p class="hero-sub">
      聚合 Claude、GPT、DeepSeek、Kimi、Gemini 等 <?= $厂商数 ?> 家厂商的模型，
      能直连你的服务器跑命令、改代码、查日志，也能生成 PPT、预览文档、维护工作区文件。
      配上桌面客户端，AI 执行的同时你自己开一个 SSH 终端盯着，改完当场验证。
    </p>
    <?php if ($notice !== ''): ?>
      <div class="alert alert-info" style="text-align:left; max-width:720px; margin:0 auto 30px;">
        <?= nl2br(h($notice)) ?>
      </div>
    <?php endif; ?>
    <div class="hero-actions">
      <?php if ($downloads): ?>
        <div class="dl-wrap">
          <button type="button" class="btn-xl btn-fill" id="dlBtn"
                  aria-haspopup="true" aria-expanded="false">
            <?= 首页图标('download') ?> 下载桌面客户端
          </button>
          <div class="dl-panel" id="dlPanel" role="menu" hidden>
            <?php foreach ($downloads as $d): ?>
              <!-- 下载链接可能指向站外，加 rel=noopener 避免目标页拿到 window.opener -->
              <a class="dl-item" role="menuitem" href="<?= h($d['url']) ?>"
                 rel="noopener" target="_blank">
                <span class="dl-icon"><?= h($d['icon']) ?></span><span><?= h($d['name']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <a href="<?= $当前用户 ? '/chat.php' : '/login.php' ?>" class="btn-xl btn-line">
          <?= $当前用户 ? '进入对话' : '在浏览器里试用' ?>
        </a>
      <?php else: ?>
        <a href="<?= $当前用户 ? '/chat.php' : '/login.php' ?>" class="btn-xl btn-fill">
          <?= $当前用户 ? '进入对话' : '立即开始' ?>
        </a>
      <?php endif; ?>
    </div>
    <?php if ($downloads): ?>
      <div class="hero-tip">客户端支持 Windows 64 位，安装包约 75 MB，可自选安装目录</div>
    <?php endif; ?>
    <div class="stats">
      <div class="stat"><div class="stat-num"><?= $用户数 ?></div><div class="stat-label">注册用户</div></div>
      <div class="stat"><div class="stat-num"><?= $模型数 ?></div><div class="stat-label">可选模型</div></div>
      <div class="stat"><div class="stat-num"><?= $厂商数 ?></div><div class="stat-label">模型厂商</div></div>
      <div class="stat"><div class="stat-num"><?= count($能力列表) ?></div><div class="stat-label">核心能力</div></div>
    </div>
  </div>
</header>

<!-- 三端支持：网页端 / 桌面客户端 / 安卓端 -->
<section class="sec" id="三端">
  <div class="wrap">
    <div class="sec-head">
      <span class="sec-tag">三端覆盖</span>
      <h2 class="sec-title">一个账号，三端同源</h2>
      <p class="sec-sub">浏览器里开工，客户端里深挖，手机上随时接管。项目、上下文、产出物三端共用一套数据，换设备不断线。</p>
    </div>
    <div class="plats">

      <!-- 网页端 -->
      <div class="plat">
        <div class="plat-top">
          <span class="plat-ico"><?= 首页图标('globe') ?></span>
          <div>
            <h3>网页端</h3>
            <div class="plat-tag">浏览器直接用，免安装</div>
          </div>
        </div>
        <p class="plat-desc">打开就能用的完整平台，绑服务器、改代码、跑命令、出文档，全部在浏览器里完成。</p>
        <ul class="plat-feats">
          <?php foreach ($网页端特性 as [$图, $名, $说明]): ?>
          <li>
            <span class="fi"><?= 首页图标($图) ?></span>
            <div><div class="ft"><?= h($名) ?></div><div class="fd"><?= h($说明) ?></div></div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="plat-foot">
          <?php if ($当前用户): ?>
            <a href="/chat.php" class="plat-btn plat-btn-fill"><?= 首页图标('chat') ?> 进入对话</a>
          <?php else: ?>
            <a href="/login.php" class="plat-btn plat-btn-line"><?= 首页图标('globe') ?> 登录网页端</a>
          <?php endif; ?>
          <div class="plat-ver">无需下载，随开随用</div>
        </div>
      </div>

      <!-- 桌面客户端：主推 -->
      <div class="plat plat-hero">
        <div class="plat-top">
          <span class="plat-ico"><?= 首页图标('desktop') ?></span>
          <div>
            <h3>桌面客户端</h3>
            <div class="plat-tag">独立 SSH 终端窗口</div>
          </div>
        </div>
        <p class="plat-desc">网页端全部能力都在，额外多出真正的原生终端：AI 在跑命令，你在旁边同一台机器上盯着。</p>
        <ul class="plat-feats">
          <?php foreach (array_slice($客户端特性, 0, 4) as [$图, $名, $说明]): ?>
          <li>
            <span class="fi"><?= 首页图标($图) ?></span>
            <div><div class="ft"><?= h($名) ?></div><div class="fd"><?= h($说明) ?></div></div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="plat-foot">
          <?php if ($downloads): ?>
            <a href="<?= h($downloads[0]['url']) ?>" rel="noopener" class="plat-btn plat-btn-fill">
              <?= 首页图标('download') ?> 下载 <?= h($downloads[0]['name']) ?> 版
            </a>
          <?php else: ?>
            <a href="#客户端" class="plat-btn plat-btn-fill"><?= 首页图标('desktop') ?> 了解客户端</a>
          <?php endif; ?>
          <div class="plat-ver">
            <?php if ($客户端版本): ?>当前版本 <code>v<?= h($客户端版本) ?></code><?php else: ?>Windows / macOS / Linux<?php endif; ?>
          </div>
        </div>
      </div>

      <!-- 安卓端 -->
      <div class="plat" id="安卓端">
        <div class="plat-top">
          <span class="plat-ico"><?= 首页图标('phone') ?></span>
          <div>
            <h3>安卓端</h3>
            <div class="plat-tag">手机上接管运维</div>
          </div>
        </div>
        <p class="plat-desc">把服务器装进口袋。出门在外收到告警，掏出手机连上去看日志、重启服务，几分钟搞定。</p>
        <ul class="plat-feats">
          <?php foreach ($安卓端特性 as [$图, $名, $说明]): ?>
          <li>
            <span class="fi"><?= 首页图标($图) ?></span>
            <div><div class="ft"><?= h($名) ?></div><div class="fd"><?= h($说明) ?></div></div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="plat-foot">
          <?php if ($安卓包): ?>
            <a href="<?= h($安卓包) ?>" download class="plat-btn plat-btn-line"><?= 首页图标('download') ?> 下载 APK</a>
          <?php else: ?>
            <a href="/login.php" class="plat-btn plat-btn-line"><?= 首页图标('phone') ?> 了解安卓端</a>
          <?php endif; ?>
          <div class="plat-ver">
            <?php if ($安卓版本): ?>当前版本 <code>v<?= h($安卓版本) ?></code><?php else: ?>Android 8.0 及以上<?php endif; ?>
          </div>
        </div>
      </div>

    </div>
    <div class="plat-dots" aria-hidden="true">
      <button type="button" class="plat-dot on" data-i="0" aria-label="网页端"></button>
      <button type="button" class="plat-dot" data-i="1" aria-label="桌面客户端"></button>
      <button type="button" class="plat-dot" data-i="2" aria-label="安卓端"></button>
    </div>
  </div>
</section>

<!-- SSH 终端：重点强调 -->
<section class="sec sec-alt" id="ssh">
  <div class="wrap">
    <div class="ssh">
      <div class="ssh-in">
        <div>
          <span class="ssh-eyebrow"><?= 首页图标('terminal') ?>客户端核心能力</span>
          <h2>真正的 SSH 终端，<br><em>和 AI 并肩坐在同一台服务器前</em></h2>
          <p>不是网页里模拟的伪终端。点一下就开出独立的原生窗口，能拖到第二块屏幕上常驻。AI 那边执行命令，你这边同一台主机实时盯着，改完立刻验证。</p>
          <ul class="ssh-pts">
            <li>
              <span class="si"><?= 首页图标('window') ?></span>
              <div>
                <div class="st">独立窗口，可多开可跨屏</div>
                <div class="sd">每台主机一个窗口，随意拖动摆放；同一台重复点只聚焦已开的那个，不会开出一堆重复窗口。</div>
              </div>
            </li>
            <li>
              <span class="si"><?= 首页图标('plug') ?></span>
              <div>
                <div class="st">人机共用一条通道</div>
                <div class="sd">AI 的执行记录和你手敲的命令落在同一台机器上，它说改好了，你当场 <code style="color:#7dd3fc;">systemctl status</code> 一把验。</div>
              </div>
            </li>
            <li>
              <span class="si"><?= 首页图标('zap') ?></span>
              <div>
                <div class="st">长任务不掉线</div>
                <div class="sd">编译、装依赖、拉镜像这类几十分钟的活儿放心跑，进度实时回传，中途也能随时叫停。</div>
              </div>
            </li>
            <li>
              <span class="si"><?= 首页图标('lock') ?></span>
              <div>
                <div class="st">凭据本地加密落盘</div>
                <div class="sd">主机密码和登录令牌交给系统级 safeStorage 加密保存，不写明文配置文件，也不经过第三方。</div>
              </div>
            </li>
          </ul>
          <?php if ($downloads): ?>
            <a href="<?= h($downloads[0]['url']) ?>" rel="noopener" class="ssh-btn">
              <?= 首页图标('download') ?> 下载客户端体验 SSH<?php if ($客户端版本): ?> · v<?= h($客户端版本) ?><?php endif; ?>
            </a>
          <?php endif; ?>
        </div>
        <div class="ssh-term" aria-hidden="true">
          <div class="ssh-tabs">
            <span class="ssh-tab on">web-01</span>
            <span class="ssh-tab">db-master</span>
            <span class="ssh-tab">cache-02</span>
          </div>
          <div class="term-bar">
            <span class="term-dot" style="background:#ff5f57"></span>
            <span class="term-dot" style="background:#febc2e"></span>
            <span class="term-dot" style="background:#28c840"></span>
            <span class="term-title">SSH · root@web-01 · 已连接</span>
          </div>
<div class="term-body"><span class="p">root@web-01</span>:<span class="o">~</span># <span class="c">docker compose up -d --build</span>
<span class="o">[+] Building 34.2s (14/14) FINISHED</span>
<span class="o">[+] Running 3/3</span>
 <span class="k">✔</span><span class="o"> Container api-gateway   Started</span>
 <span class="k">✔</span><span class="o"> Container worker-node   Started</span>
 <span class="k">✔</span><span class="o"> Container redis-cache   Started</span>

<span class="p">root@web-01</span>:<span class="o">~</span># <span class="c">curl -s -o /dev/null -w "%{http_code}" localhost/health</span>
<span class="k">200</span>

<span class="p">root@web-01</span>:<span class="o">~</span># <span class="c">systemctl is-active nginx</span>
<span class="k">active</span>

<span class="p">root@web-01</span>:<span class="o">~</span># <span class="term-cursor"></span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="sec sec-alt" id="客户端">
  <div class="wrap">
    <div class="cli">
      <div class="cli-copy">
        <span class="sec-tag">桌面客户端<?= $客户端版本 !== '' ? ' v' . h($客户端版本) : '' ?></span>
        <h2>装在桌面上，用起来更趁手</h2>
        <p>
          同一个账号、同一批项目，客户端只是换了个更顺手的外壳。
          截图按 Ctrl+V 就贴进对话，文件从资源管理器拖进来就上传，
          凭据交给系统钥匙串保管，启动时顺手比对新版本。
        </p>
        <div class="cli-list">
          <?php foreach ($客户端特性 as [$图, $标题, $说明]): ?>
            <div class="cli-item">
              <div class="cli-ico"><?= 首页图标($图) ?></div>
              <div>
                <h4><?= h($标题) ?></h4>
                <div class="d"><?= h($说明) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($downloads): ?>
          <a href="<?= h($downloads[0]['url']) ?>" rel="noopener" class="btn-xl btn-fill">
            <?= 首页图标('download') ?> 下载 <?= h($downloads[0]['name']) ?> 版
          </a>
        <?php endif; ?>
      </div>
      <div class="cliui" aria-hidden="true">
        <div class="cliui-bar">
          <span class="term-dot" style="background:#ff5f57"></span>
          <span class="term-dot" style="background:#febc2e"></span>
          <span class="term-dot" style="background:#28c840"></span>
          <span class="cliui-title"><?= h($siteName) ?><?= $客户端版本 !== '' ? ' v' . h($客户端版本) : '' ?></span>
        </div>
        <div class="cliui-body">
          <div class="cliui-side">
            <div class="cliui-side-h">项目</div>
            <div class="cliui-proj on"><span class="cliui-dot"></span>系统迁移维护</div>
            <div class="cliui-proj"><span class="cliui-dot gray"></span>官网重构</div>
            <div class="cliui-proj"><span class="cliui-dot gray"></span>数据同步脚本</div>
            <div class="cliui-side-h">工作中心</div>
            <div class="cliui-file">部署手册.md</div>
            <div class="cliui-file">deploy.sh</div>
          </div>
          <div class="cliui-main">
            <div class="cliui-msg me">帮我把 nginx 配置里的超时调到 120s</div>
            <div class="cliui-msg ai">
              已定位到 <code>server</code> 段，改完会先备份原文件。
              <div class="cliui-card">
                <span class="cliui-card-ico"><?= 首页图标('edit') ?></span>
                <span>nginx/site.conf<em>+2 -1</em></span>
              </div>
            </div>
            <div class="cliui-msg me cliui-paste">
              <span class="cliui-shot"><?= 首页图标('eye') ?>截图.png</span>
              这个报错是什么原因
            </div>
            <div class="cliui-input"><span>说点什么…</span><span class="cliui-send"><?= 首页图标('zap') ?></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<section class="sec" id="能力">
  <div class="wrap">
    <div class="sec-head">
      <span class="sec-tag">平台能力</span>
      <h2 class="sec-title">不只是给建议，是把活干完</h2>
      <p class="sec-sub">从对话到执行、从改文件到验证结果，整条链路都在一个地方完成，不用在几个工具之间来回倒。</p>
    </div>
    <div class="grid">
      <?php foreach ($能力列表 as [$图, $标题, $说明]): ?>
        <div class="card">
          <div class="card-ico"><?= 首页图标($图) ?></div>
          <h3><?= h($标题) ?></h3>
          <p><?= h($说明) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<section class="sec sec-alt">
  <div class="wrap">
    <div class="sec-head">
      <span class="sec-tag">怎么用</span>
      <h2 class="sec-title">三步就能让 AI 上手干活</h2>
      <p class="sec-sub">不用配环境、不用装插件，绑好机器直接说需求。</p>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-no">1</div>
        <h3>注册并装客户端</h3>
        <p>浏览器里就能开始用。想要独立 SSH 终端和截图直贴，就装一下桌面客户端。</p>
      </div>
      <div class="step">
        <div class="step-no">2</div>
        <h3>绑定服务器和项目</h3>
        <p>填上主机地址、登录用户和部署目录。之后聊到这个项目，AI 默认就用这台机器和这个路径。</p>
      </div>
      <div class="step">
        <div class="step-no">3</div>
        <h3>说需求，看它做</h3>
        <p>「查一下昨晚 502 是什么原因」「把测试邮件那个按钮修好」。它自己排查、改文件、跑验证，改前自动留备份。</p>
      </div>
    </div>
  </div>
</section>
<section class="cta">
  <div class="wrap">
    <h2>现在就让它动手</h2>
    <p>已有 <?= $用户数 ?> 位用户在用<?= $模型数 > 0 ? '，' . $模型数 . ' 个模型随时可选' : '' ?>。</p>
    <div class="hero-actions">
      <a href="<?= $当前用户 ? '/chat.php' : '/register.php' ?>" class="btn-xl btn-fill">
        <?= $当前用户 ? '进入对话' : '免费注册' ?>
      </a>
      <?php if ($downloads): ?>
        <a href="<?= h($downloads[0]['url']) ?>" rel="noopener" class="btn-xl btn-line">
          下载客户端
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>
<footer class="foot">
  <div class="wrap">
    <?php
    // 联系方式行：拼成 [标签 => 值] 再过滤空值，省掉四段几乎一样的 if
    $联系 = array_filter([
        'QQ'   => $foot['contact_qq'],
        '微信' => $foot['contact_wechat'],
        '邮箱' => $foot['contact_email'],
        '电话' => $foot['contact_phone'],
    ], fn($v) => $v !== '');
    ?>
    <?php if ($联系): ?>
      <div class="foot-row">
        <?php foreach ($联系 as $标签 => $值): ?>
          <span><?= h($标签) ?>：<?= h($值) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($foot['terms_privacy'] !== '' || $foot['terms_service'] !== ''
              || $foot['icp_no'] !== ''): ?>
      <div class="foot-row">
        <?php if ($foot['terms_privacy'] !== ''): ?>
          <a href="/legal.php?t=privacy">隐私条款</a>
        <?php endif; ?>
        <?php if ($foot['terms_service'] !== ''): ?>
          <a href="/legal.php?t=service">服务条款</a>
        <?php endif; ?>
        <?php if ($foot['icp_no'] !== ''): ?>
          <?php if ($foot['icp_url'] !== ''): ?>
            <a href="<?= h($foot['icp_url']) ?>" target="_blank"
               rel="noopener nofollow"><?= h($foot['icp_no']) ?></a>
          <?php else: ?>
            <span><?= h($foot['icp_no']) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="foot-copy">© <?= date('Y') ?> <?= h($siteName) ?>. All rights reserved.</div>
  </div>
</footer>
<?php if ($downloads): ?>
<script>
/* 下载客户端：点按钮展开平台列表，点外部或按 Esc 收起 */
(function () {
  var 按钮 = document.getElementById('dlBtn');
  var 面板 = document.getElementById('dlPanel');
  if (!按钮 || !面板) { return; }
  function 开(是否) {
    面板.hidden = !是否;
    按钮.setAttribute('aria-expanded', 是否 ? 'true' : 'false');
  }
  按钮.addEventListener('click', function (e) {
    e.stopPropagation();   // 否则会立刻被下面的 document 处理器关掉
    开(面板.hidden);
  });
  document.addEventListener('click', function (e) {
    if (!面板.hidden && !面板.contains(e.target) && e.target !== 按钮) { 开(false); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !面板.hidden) { 开(false); 按钮.focus(); }
  });
})();
</script>
<?php endif; ?>
<script>
/* 导航锚点平滑滚动，并让 sticky 导航不挡住标题 */
document.querySelectorAll('.nav-links a[href^="#"]').forEach(function (链接) {
  链接.addEventListener('click', function (e) {
    var 目标 = document.querySelector(链接.getAttribute('href'));
    if (!目标) { return; }
    e.preventDefault();
    var 顶 = 目标.getBoundingClientRect().top + window.pageYOffset - 64;
    window.scrollTo({ top: 顶, behavior: 'smooth' });
  });
});
</script>
<script>
/* 三端覆盖：移动端横向滑动 + 自动轮播 */
(function () {
  var 容器 = document.querySelector('.plats');
  var 点组 = document.querySelectorAll('.plat-dot');
  if (!容器 || !点组.length) { return; }

  var 卡片 =容器.querySelectorAll('.plat');
  var 当前 = 0;
  var 定时器 = null;
  var 暂停到 = 0;
  var 移动端 = function () { return window.matchMedia('(max-width: 768px)').matches; };

  /* 把指示点状态同步成第 n 个 */
  function 标记(n) {
    if (n === 当前) { return; }
    当前 = n;
    点组.forEach(function (点, i) { 点.classList.toggle('on', i === n); });
  }

  /* 滚到第 n 张，居中对齐 */
  function 滚到(n, 平滑) {
    var 卡 = 卡片[n];
    if (!卡) { return; }
    var 左 = 卡.offsetLeft - (容器.clientWidth - 卡.clientWidth) / 2;
    容器.scrollTo({ left: 左, behavior: 平滑 === false ? 'auto' : 'smooth' });
  }

  /* 依据滚动位置反推当前是第几张 */
  var 节流 = null;
  容器.addEventListener('scroll', function () {
    if (节流) { return; }
    节流 = setTimeout(function () {
      节流 = null;
      var 中线 = 容器.scrollLeft + 容器.clientWidth / 2;
      var 最近 = 0, 最小 = Infinity;
      卡片.forEach(function (卡, i) {
        var 差 = Math.abs(卡.offsetLeft + 卡.clientWidth / 2 - 中线);
        if (差 < 最小) { 最小 = 差; 最近 = i; }
      });
      标记(最近);
    }, 90);
  }, { passive: true });

  /* 手动介入后先安静一会儿，别跟用户抢 */
  function 推迟(毫秒) { 暂停到 = Date.now() + (毫秒 || 8000); }
  ['touchstart', 'pointerdown', 'wheel'].forEach(function (名) {
    容器.addEventListener(名, function () { 推迟(); }, { passive: true });
  });

  点组.forEach(function (点, i) {
    点.addEventListener('click', function () { 推迟(); 滚到(i); 标记(i); });
  });

  function 走一步() {
    if (!移动端() || Date.now() < 暂停到) { return; }
    if (document.hidden) { return; }
    var 下一个 = (当前 + 1) % 卡片.length;
    滚到(下一个);
    标记(下一个);
  }

  function 开始() { if (!定时器) { 定时器 = setInterval(走一步, 4000); } }
  function 停止() { if (定时器) { clearInterval(定时器); 定时器 = null; } }

  /* 只在这块露脸的时候转，划走了就歇着 */
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (记录) {
      记录[0].isIntersecting ? 开始() : 停止();
    }, { threshold: 0.25 }).observe(容器);
  } else {
    开始();
  }

  document.addEventListener('visibilitychange', function () {
    document.hidden ? 停止() : 开始();
  });

  /* 转屏回到桌面布局时把滚动位置清干净 */
  window.addEventListener('resize', function () {
    if (!移动端()) { 容器.scrollLeft = 0; 标记(0); }
  });
})();
</script>
</body>
</html>
