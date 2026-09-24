<?php
// 模型清单接口：供桌面端等外部客户端拉取可用模型。
// 网页端 chat.php 是页面内直连数据库渲染 select，没有走 API，桌面端只能自己查一次。
// 过滤与排序跟 chat.php 保持一致：模型和渠道都要启用，否则会列出后台已停用的模型。
require_once __DIR__ . "/../inc/helpers.php";
api_error_guard();

$me = require_login_api();
// 只读接口，不做 csrf_check，跟 conv.php 的 list/messages 一致

$list = db_all(
    "SELECT m.id, m.display_name, m.model_name, m.price_in, m.price_out,
            m.price_cache, m.price_cache_create, m.vision, m.max_context, m.max_tokens" .
    " FROM models m JOIN channels c ON c.id = m.channel_id
      WHERE m.status = 1 AND c.status = 1
      ORDER BY m.sort DESC, m.id ASC"
);

json_out(["ok" => 1, "list" => $list]);
