package cn.bot77.yanyang.data
import cn.bot77.yanyang.net.接口
import org.json.JSONObject
class 仓库(private val 密钥源: () -> String) {
    fun 取我的信息(): Result<我的信息> {
        val r = 接口.调("/api/me.php", 方法 = "GET", 密钥 = 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(
            我的信息(
                id = r.optInt("id"),
                用户名 = r.optString("username"),
                邮箱 = r.optString("email"),
                角色 = r.optString("role"),
                余额 = r.optString("balance"),
                token配额 = r.optInt("token_quota"),
                已用token = r.optInt("used_tokens"),
                总花费 = r.optString("total_cost"),
                公告 = r.optString("notice"),
                图片上限KB = r.optInt("upload_max_kb"),
                有效 = true,
                tool_ssh_exec = r.optInt("tool_ssh_exec", 1),
                tool_sftp_read = r.optInt("tool_sftp_read", 1),
                tool_sftp_write = r.optInt("tool_sftp_write", 1),
                tool_sftp_list = r.optInt("tool_sftp_list", 1),
                tool_sftp_delete = r.optInt("tool_sftp_delete", 1),
                tool_sftp_patch = r.optInt("tool_sftp_patch", 1),
                tool_file_list = r.optInt("tool_file_list", 1),
                tool_file_read = r.optInt("tool_file_read", 1),
                tool_file_write = r.optInt("tool_file_write", 1),
                tool_file_delete = r.optInt("tool_file_delete", 1),
                tool_file_push = r.optInt("tool_file_push", 1),
                tool_file_patch = r.optInt("tool_file_patch", 1),
                tool_ws_list = r.optInt("tool_ws_list", 1),
                tool_ws_read = r.optInt("tool_ws_read", 1),
                tool_ws_write = r.optInt("tool_ws_write", 1),
                tool_ws_delete = r.optInt("tool_ws_delete", 1),
                tool_ws_patch = r.optInt("tool_ws_patch", 1),
                tool_ws_zip = r.optInt("tool_ws_zip", 1),
                tool_web_open = r.optInt("tool_web_open", 1),
                tool_web_search = r.optInt("tool_web_search", 1),
                tool_ppt_generate = r.optInt("tool_ppt_generate", 1)
            )
        )
    }
    fun 取模型表(): List<模型项> {
        val r = 接口.调("/api/models.php", 方法 = "GET", 密钥 = 密钥源())
        val 数组 = r.optJSONArray("list") ?: r.optJSONArray("models") ?: return emptyList()
        return (0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            val 标识 = o.optString("id")
            if (标识.isBlank()) null
            else 模型项(
                标识 = 标识,
                名称 = o.optString("display_name")
                    .ifBlank { o.optString("model_name") }
                    .ifBlank { "模型 " + 标识 },
                视觉 = o.optInt("vision", 0) == 1,
                // 价格字段数据库里是 decimal，接口原样吐字符串，这里不转数字，
                // 免得精度或补零跟网页端后台配的不一致
                输入价 = o.optString("price_in"),
                输出价 = o.optString("price_out"),
                缓存读价 = o.optString("price_cache"),
                缓存写价 = o.optString("price_cache_create")
            )
        }
    }
    fun 取项目表(): List<项目> {
        val r = 接口.调("/api/project.php", mapOf("act" to "list"), "GET", 密钥源())
        val 数组 = r.optJSONArray("list") ?: return emptyList()
        return (0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            项目(
                id = o.optString("id"),
                名称 = o.optString("name").ifBlank { "未命名项目" },
                简介 = o.optString("intro"),
                技术栈 = o.optString("stack"),
                主机id = o.optString("host_id"),
                部署目录 = o.optString("deploy_dir"),
                站点地址 = o.optString("site_url"),
                颜色 = o.optString("color")
            )
        }
    }
    fun 取会话表(): List<会话> {
        val r = 接口.调("/api/conv.php", mapOf("act" to "list"), "GET", 密钥源())
        val 数组 = r.optJSONArray("list") ?: return emptyList()
        return (0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            会话(
                id = o.optString("id"),
                标题 = o.optString("title").ifBlank { "新对话" },
                项目id = o.optString("project_id")
            )
        }
    }
    /**
     * 返回 Pair(上下文条数, 消息列表)。
     * 上下文条数取自后端 messages 接口随响应带出的 conv 对象，0 = 跟随模型默认。
     */
    fun 取消息表(会话id: String): Pair<Int, List<消息>> {
        val r = 接口.调(
            "/api/conv.php",
            mapOf("act" to "messages", "conv_id" to 会话id),
            "GET", 密钥源()
        )
        val 条数 = r.optJSONObject("conv")?.optInt("context_limit") ?: 0
        val 数组 = r.optJSONArray("list") ?: return 条数 to emptyList()
        return 条数 to (0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            消息(
                角色 = o.optString("role").ifBlank { "assistant" },
                正文 = o.optString("content"),
                思考 = o.optString("fold")
            )
        }
    }
    /** 会话级上下文条数：0 = 跟随模型默认，2..60 = 自定义，落库持久保存 */
    fun 设上下文条数(会话id: String, 条数: Int): Boolean {
        val r = 接口.调(
            "/api/conv.php",
            mapOf("act" to "set_context_limit", "conv_id" to 会话id, "context_limit" to 条数.toString()),
            "POST", 密钥源()
        )
        return r.optInt("ok") == 1
    }
    fun 新建会话(项目id: String): String? {
        val 参数 = mutableMapOf("act" to "new")
        if (项目id.isNotBlank()) 参数["project_id"] = 项目id
        val r = 接口.调("/api/conv.php", 参数, "POST", 密钥源())
        return r.optString("conv_id").ifBlank { r.optString("id").ifBlank { null } }
    }
    fun 删会话(会话id: String): Boolean {
        val r = 接口.调("/api/conv.php", mapOf("act" to "del", "conv_id" to 会话id), "POST", 密钥源())
        return r.optString("error").isBlank()
    }
    fun 改会话名(会话id: String, 新名: String): Boolean {
        val r = 接口.调(
            "/api/conv.php",
            mapOf("act" to "rename", "conv_id" to 会话id, "title" to 新名),
            "POST", 密钥源()
        )
        return r.optString("error").isBlank()
    }
    fun 停输出(会话id: String, 运行号: String) {
        接口.调(
            "/api/chat_stop.php",
            mapOf("conv_id" to 会话id, "run_id" to 运行号),
            "POST", 密钥源()
        )
    }
    fun 取仓文件表(项目id: String, 仅改动: Boolean = false): Result<List<仓文件>> {
        val 参数 = mutableMapOf("act" to "tree", "project_id" to 项目id)
        if (仅改动) 参数["dirty"] = "1"
        val r = 接口.调("/api/repo.php", 参数, "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val 数组 = r.optJSONArray("list") ?: return Result.success(emptyList())
        return Result.success((0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            仓文件(
                路径 = o.optString("path"),
                大小文本 = o.optString("size_text"),
                是文本 = o.optInt("is_text") == 1,
                状态 = o.optString("state")
            )
        })
    }
    fun 读仓文件(项目id: String, 路径: String): Result<String> {
        val r = 接口.调(
            "/api/repo.php",
            mapOf("act" to "read", "project_id" to 项目id, "path" to 路径),
            "GET", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("content"))
    }
    fun 取仓概况(项目id: String): Result<仓概况> {
        val r = 接口.调("/api/repo.php", mapOf("act" to "info", "project_id" to 项目id), "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val o = r.optJSONObject("repo") ?: return Result.failure(Exception("返回格式异常"))
        return Result.success(
            仓概况(
                项目名 = o.optString("project"),
                远程目录 = o.optString("remote_dir"),
                主机 = o.optString("host"),
                改动数 = o.optInt("dirty")
            )
        )
    }
    /**
     * 删项目。项目下还挂着对话时服务端会拒绝并给出原因，
     * 这里把原话透出去，不自己编一套说法。
     */
    fun 删项目(项目id: String): Result<Unit> {
        val r = 接口.调("/api/project.php", mapOf("act" to "del", "id" to 项目id), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    fun 保存项目(项目: 项目): Result<项目> {
        val 参数 = mutableMapOf(
            "act" to "save",
            "name" to 项目.名称,
            "intro" to 项目.简介,
            "stack" to 项目.技术栈,
            "host_id" to 项目.主机id,
            "deploy_dir" to 项目.部署目录,
            "site_url" to 项目.站点地址,
            "color" to 项目.颜色
        )
        if (项目.id.isNotBlank()) 参数["id"] = 项目.id
        val r = 接口.调("/api/project.php", 参数, "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(项目.copy(id = r.optString("id").ifBlank { 项目.id }))
    }
    fun 传图(字节: ByteArray, 文件名: String, 类型: String): Result<String> {
        val r = 接口.传文件("/api/upload.php", 字节, 文件名, 类型, 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val id = r.optString("id")
        return if (id.isBlank()) Result.failure(Exception("上传成功但没拿到图片 id"))
        else Result.success(id)
    }
    fun 取服务器列表(): List<SSHHost> {
        val r = 接口.调("/api/ssh_hosts.php", mapOf("act" to "list"), "GET", 密钥源())
        val 数组 = r.optJSONArray("hosts") ?: return emptyList()
        return (0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            SSHHost(
                id = o.optString("id"),
                名称 = o.optString("name"),
                地址 = o.optString("host"),
                端口 = o.optInt("port", 22),
                用户名 = o.optString("username"),
                认证方式 = o.optString("auth_type"),
                状态 = o.optString("status"),
                指纹 = o.optString("fingerprint"),
                最近连通 = o.optString("last_ok_at"),
                错误信息 = o.optString("last_error")
            )
        }
    }
    fun 保存服务器(表单: SSHHostForm): Result<Unit> {
        val 参数 = mutableMapOf(
            "act" to "save",
            "name" to 表单.名称,
            "host" to 表单.地址,
            "port" to 表单.端口,
            "username" to 表单.用户名,
            "auth_type" to 表单.认证方式
        )
        // secret 字段兼两用：密码认证时装密码，密钥认证时装私钥内容；key_pass 是私钥口令
        if (表单.认证方式 == "key") {
            参数["secret"] = 表单.凭据
            参数["key_pass"] = 表单.口令
        } else {
            参数["secret"] = 表单.口令
        }
        if (表单.id.isNotBlank()) 参数["id"] = 表单.id
        val r = 接口.调("/api/ssh_hosts.php", 参数, "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    fun 删服务器(id: String): Boolean {
        val r = 接口.调("/api/ssh_hosts.php", mapOf("act" to "del", "id" to id), "POST", 密钥源())
        return r.optString("error").isBlank()
    }
    fun 测试服务器(id: String): Result<String> {
        val r = 接口.调("/api/ssh_hosts.php", mapOf("act" to "test", "id" to id), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("msg"))
    }
    // ================= 命令执行 =================
    /** 起一个后台任务，返回任务号。长命令不会因 HTTP 超时被切断。 */
    fun 起命令(主机id: String, 会话id: String, 命令: String): Result<String> {
        val r = 接口.调(
            "/api/ssh_run.php",
            mapOf("act" to "start", "host_id" to 主机id, "conv_id" to 会话id, "command" to 命令),
            "POST", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val job = r.optString("job")
        if (job.isBlank()) return Result.failure(Exception("后端没返回任务号"))
        return Result.success(job)
    }
    /** 读任务进度。from 是已收到的字节数，只取新增部分。 */
    fun 读命令进度(主机id: String, 会话id: String, 任务号: String, 起点: Int, 命令: String): Result<命令进度> {
        val r = 接口.调(
            "/api/ssh_run.php",
            mapOf(
                "act" to "tail", "host_id" to 主机id, "conv_id" to 会话id,
                "job" to 任务号, "from" to 起点.toString(), "command" to 命令
            ),
            "POST", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(
            命令进度(
                完成 = r.optInt("done", 0) == 1,
                退出码 = r.optInt("exit", -1),
                输出 = r.optString("out"),
                下一个起点 = r.optInt("next", 起点)
            )
        )
    }
    // ================= 用量 =================
    fun 取用量概览(): Result<用量概览> {
        val r = 接口.调("/api/usage.php", mapOf("act" to "summary"), "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(
            用量概览(
                余额 = r.optString("balance"),
                总花费 = r.optString("total_cost"),
                token配额 = r.optInt("token_quota"),
                已用token = r.optInt("used_tokens"),
                配额剩余 = if (r.isNull("quota_left")) null else r.optInt("quota_left"),
                今日花费 = r.optString("today_cost"),
                今日次数 = r.optInt("today_calls"),
                累计tokens = r.optInt("total_tokens"),
                累计次数 = r.optInt("total_calls")
            )
        )
    }
    fun 取调用明细(页码: Int = 1): Result<List<调用记录>> {
        val r = 接口.调("/api/usage.php", mapOf("act" to "logs", "p" to 页码.toString()), "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val 数组 = r.optJSONArray("list") ?: return Result.success(emptyList())
        return Result.success((0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            调用记录(
                id = o.optInt("id"),
                模型名 = o.optString("model_name"),
                输入token = o.optInt("tokens_in"),
                输出token = o.optInt("tokens_out"),
                费用 = o.optString("cost"),
                状态 = o.optString("status"),
                估算 = o.optInt("is_estimated") == 1,
                时间 = o.optString("created_at")
            )
        })
    }
    // ================= 个人资料 =================
    fun 取资料概览(): Result<资料概览> {
        val r = 接口.调("/api/profile.php", mapOf("act" to "info"), "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val 密钥数组 = r.optJSONArray("api_keys")
        val 密钥表 = if (密钥数组 == null) emptyList() else (0 until 密钥数组.length()).mapNotNull { i ->
            val o = 密钥数组.optJSONObject(i) ?: return@mapNotNull null
            API密钥(
                id = o.optInt("id"),
                名称 = o.optString("name"),
                创建时间 = o.optString("created_at"),
                最近使用 = o.optString("last_used_at")
            )
        }
        val 流水数组 = r.optJSONArray("balance_logs")
        val 流水表 = if (流水数组 == null) emptyList() else (0 until 流水数组.length()).mapNotNull { i ->
            val o = 流水数组.optJSONObject(i) ?: return@mapNotNull null
            余额流水(
                id = o.optInt("id"),
                金额 = o.optString("amount"),
                变动后余额 = o.optString("balance_after"),
                类型 = o.optString("type"),
                类型名 = o.optString("type_name"),
                备注 = o.optString("note"),
                时间 = o.optString("created_at")
            )
        }
        return Result.success(
            资料概览(
                用户名 = r.optString("username"),
                邮箱 = r.optString("email"),
                余额 = r.optString("balance"),
                总花费 = r.optString("total_cost"),
                会话数 = r.optInt("conv_count"),
                调用数 = r.optInt("call_count"),
                密钥表 = 密钥表,
                流水表 = 流水表
            )
        )
    }
    fun 改密码(旧密码: String, 新密码: String, 新密码2: String): Result<Unit> {
        val r = 接口.调(
            "/api/profile.php",
            mapOf("act" to "change_password", "old_password" to 旧密码,
                  "new_password" to 新密码, "new_password2" to 新密码2),
            "POST", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    fun 改邮箱(邮箱: String): Result<String> {
        val r = 接口.调("/api/profile.php", mapOf("act" to "change_email", "email" to 邮箱), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("msg"))
    }
    /** 新建密钥，成功时返回明文 token，仅此一次可见 */
    fun 新建密钥(名称: String): Result<String> {
        val r = 接口.调("/api/profile.php", mapOf("act" to "key_create", "name" to 名称), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("token"))
    }
    fun 删密钥(密钥id: Int): Result<Unit> {
        val r = 接口.调("/api/profile.php", mapOf("act" to "key_delete", "key_id" to 密钥id.toString()), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    /** 重置密钥，成功时返回新明文 token */
    fun 重置密钥(密钥id: Int): Result<String> {
        val r = 接口.调("/api/profile.php", mapOf("act" to "key_reset", "key_id" to 密钥id.toString()), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("token"))
    }
    /** 保存工具开关配置 */
    fun 保存工具开关(工具配置: Map<String, Int>): Result<Unit> {
        val 参数 = mapOf("act" to "set_tools") + 工具配置.mapValues { it.value.toString() }
        val r = 接口.调("/api/me.php", 参数, "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    // ================= 充值 =================
    /* 面额表之外把自定义金额的开关和区间一起带回来。
       服务端 pay_custom_on 关掉时输入框就不该出现，这个判断只能由服务端给，
       客户端写死会出现「后台关了，App 还显示」。 */
    fun 取充值选项(): Result<充值选项> {
        val r = 接口.调("/api/pay.php", mapOf("act" to "options"), "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val 数组 = r.optJSONArray("amounts")
        val 表 = if (数组 == null) emptyList() else (0 until 数组.length()).mapNotNull { i ->
            val o = 数组.optJSONObject(i) ?: return@mapNotNull null
            充值面额(
                到账 = o.optString("credit"),
                实付 = o.optString("pay"),
                折扣 = o.optString("rate")
            )
        }
        // 兜底值跟服务端 setting_get 的默认值保持一致，字段缺失时行为不跑偏
        return Result.success(
            充值选项(
                面额表 = 表,
                自定义开 = r.optInt("custom_on", 0) == 1,
                自定义下限 = r.optDouble("custom_min", 1.0),
                自定义上限 = r.optDouble("custom_max", 10000.0)
            )
        )
    }
    /** 下单充值，scene 传 qr 走扫码，否则服务端按设备自动选形态 */
    fun 充值下单(到账金额: String, 扫码: Boolean = false): Result<充值订单> {
        val 参数 = mutableMapOf("act" to "create", "credit" to 到账金额)
        if (扫码) 参数["scene"] = "qr"
        val r = 接口.调("/api/pay.php", 参数, "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(
            充值订单(
                订单号 = r.optString("order_no"),
                类型 = r.optString("type"),
                地址 = r.optString("url"),
                到账 = r.optString("credit"),
                实付 = r.optString("pay")
            )
        )
    }
    /** 查询订单状态，paid 时附带最新余额 */
    fun 查充值单(订单号: String): Result<Pair<String, String?>> {
        val r = 接口.调("/api/pay.php", mapOf("act" to "query", "order_no" to 订单号), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val 状态 = r.optString("status")
        val 余额 = if (状态 == "paid") r.optString("balance") else null
        return Result.success(状态 to 余额)
    }
    /** 取支付二维码图片字节，图不是 JSON，走单独的取字节接口 */
    fun 取充值二维码(订单号: String): ByteArray? {
        return 接口.取字节("/api/qrcode.php", mapOf("order_no" to 订单号), 密钥源())
    }
    // ---------- 工作中心文件库 ----------
    // 全部走 /api/ws.php，接口按登录账号隔离，不传也不该传 user_id。
    // Bearer 鉴权的请求服务端会跳过 CSRF 校验，所以写操作也能直接发。
    /**
     * 列文件。
     * @param 类 all / text / image / bin，对应网页端的类型筛选
     * @param 搜 文件名关键词，空串表示不筛
     */
    fun 取工作文件表(页码: Int = 1, 类: String = "all", 搜: String = ""): Result<工作中心页> {
        val r = 接口.调(
            "/api/ws.php",
            mapOf(
                "act" to "list", "page" to 页码.toString(), "size" to "40",
                "kind" to 类, "q" to 搜
            ),
            "GET", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        val 数组 = r.optJSONArray("list")
        val 表 = (0 until (数组?.length() ?: 0)).mapNotNull { i ->
            val o = 数组?.optJSONObject(i) ?: return@mapNotNull null
            val 标识 = o.optString("id")
            if (标识.isBlank()) null
            else 工作文件(
                id = 标识,
                名字 = o.optString("name"),
                类型 = o.optString("kind").ifBlank { "text" },
                大小文本 = o.optString("size_text"),
                地址 = o.optString("url"),
                来源 = o.optString("source"),
                版本 = o.optInt("ver", 1),
                可预览 = o.optInt("previewable", 0) == 1
            )
        }
        return Result.success(
            工作中心页(
                文件表 = 表,
                页码 = r.optInt("page", 1),
                总页数 = r.optInt("pages", 1),
                已用文本 = r.optString("used_text"),
                配额文本 = r.optString("quota_text"),
                百分比 = r.optDouble("percent", 0.0)
            )
        )
    }
    /**
     * 读文本文件正文。
     * @return 正文 到 是否被截断。截断了就不该让用户保存，否则后半段会丢。
     */
    fun 读工作文件(id: String): Result<Pair<String, Boolean>> {
        val r = 接口.调(
            "/api/ws.php",
            mapOf("act" to "read", "id" to id),
            "GET", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("text") to (r.optInt("truncated", 0) == 1))
    }
    /** 写文本文件。名字不存在就新建，存在则覆盖，服务端覆盖前会留快照 */
    fun 写工作文件(名字: String, 正文: String, 备注: String = "手机端编辑"): Result<Unit> {
        val r = 接口.调(
            "/api/ws.php",
            mapOf(
                "act" to "write", "name" to 名字, "text" to 正文,
                "note" to 备注, "source" to "user"
            ),
            "POST", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    /** 删文件。删掉找不回来，界面那层必须先弹确认 */
    fun 删工作文件(id: String): Result<Unit> {
        val r = 接口.调("/api/ws.php", mapOf("act" to "del", "id" to id), "POST", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(Unit)
    }
    /** 重命名。可以带目录，如 docs/说明.md。返回服务端规整后的名字 */
    fun 改工作文件名(id: String, 新名: String): Result<String> {
        val r = 接口.调(
            "/api/ws.php",
            mapOf("act" to "rename", "id" to id, "name" to 新名),
            "POST", 密钥源()
        )
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optString("name").ifBlank { 新名 })
    }
    /**
     * Office 文档预览：服务端把每页转成 PNG，这里先问一共几页。
     * 首次转换要几秒，调用方自己给个加载提示。
     */
    fun 取预览页数(id: String): Result<Int> {
        val r = 接口.调("/api/ws.php", mapOf("act" to "pvinfo", "id" to id), "GET", 密钥源())
        r.optString("error").takeIf { it.isNotBlank() }?.let { return Result.failure(Exception(it)) }
        return Result.success(r.optInt("pages", 0))
    }
    /** 取预览的第 页 张 PNG，失败返回 null */
    fun 取预览图(id: String, 页: Int): ByteArray? = 接口.取字节(
        "/api/ws.php",
        mapOf("act" to "pvpage", "id" to id, "p" to 页.toString()),
        密钥源()
    )
    /** 下载原文件字节，图片查看和「保存到手机」都用它 */
    fun 下载工作文件(id: String): ByteArray? = 接口.取字节(
        "/api/ws.php",
        mapOf("act" to "down", "id" to id),
        密钥源()
    )

    // ================= SFTP 直连读改客户服务器文件 =================
    // 这几个方法直接落到客户的线上服务器，不经过本地副本。
    // 写类动作服务端每次都会先备份再写，所以这里不额外做保护，
    // 但也正因为立即生效，调用方要确保路径是 AI 明确给出的那个。
    //
    // 鉴权走 Bearer 密钥，服务端的 csrf_check() 对 token 请求直接放行，
    // 所以安卓端不需要额外取 CSRF token。

    /** 统一收口：把服务端返回拍平成 直改结果，错误原话透传给 AI */
    private fun 直改调用(
        动作: String,
        参数: Map<String, String>,
        方法: String,
        成功正文: (JSONObject) -> String
    ): 直改结果 {
        val 路径 = 参数["path"] ?: 参数["dir"] ?: ""
        val r = 接口.调("/api/sftp.php", 参数 + ("act" to 动作), 方法, 密钥源())
        val 错 = r.optString("error")
        if (错.isNotBlank()) {
            return 直改结果(动作, 路径, 错, false)
        }
        return 直改结果(动作, r.optString("path", 路径), 成功正文(r), true)
    }

    /** 列远端目录，只列一层 */
    fun 直改列目录(项目id: String, 子目录: String): 直改结果 = 直改调用(
        "list",
        mapOf("project_id" to 项目id, "dir" to 子目录),
        "GET"
    ) { r ->
        val 表 = r.optJSONArray("list")
        if (表 == null || 表.length() == 0) {
            "（这个目录是空的）"
        } else {
            buildString {
                append("目录：").append(r.optString("dir")).append("\n")
                append("共 ").append(r.optInt("count")).append(" 项")
                if (r.optInt("truncated") == 1) append("（已截断）")
                append("\n\n")
                for (i in 0 until 表.length()) {
                    val o = 表.optJSONObject(i) ?: continue
                    val 是目录 = o.optInt("is_dir") == 1 || o.optBoolean("is_dir")
                    append(if (是目录) "[目录] " else "[文件] ")
                    append(o.optString("name"))
                    if (!是目录) append("  ").append(o.optString("size_text", ""))
                    append("\n")
                }
            }
        }
    }

    /** 读远端文件正文 */
    fun 直改读文件(项目id: String, 路径: String): 直改结果 = 直改调用(
        "read",
        mapOf("project_id" to 项目id, "path" to 路径),
        "GET"
    ) { r ->
        val 正文 = r.optString("text")
        if (r.optInt("truncated") == 1) {
            正文 + "\n…（文件过长，正文已截断）"
        } else {
            正文
        }
    }

    /** 整文件覆写远端文件 */
    fun 直改写文件(项目id: String, 路径: String, 内容: String, 说明: String, 会话id: String): 直改结果 =
        直改调用(
            "write",
            mapOf(
                "project_id" to 项目id, "path" to 路径,
                "content" to 内容, "note" to 说明, "conv_id" to 会话id
            ),
            "POST"
        ) { r -> r.optString("msg", "已写入 " + r.optString("path")) }

    /** 按内容定位做局部替换 */
    fun 直改补丁(
        项目id: String, 路径: String,
        原片段: String, 新片段: String,
        说明: String, 会话id: String
    ): 直改结果 = 直改调用(
        "patch",
        mapOf(
            "project_id" to 项目id, "path" to 路径,
            "find" to 原片段, "replace" to 新片段,
            "note" to 说明, "conv_id" to 会话id
        ),
        "POST"
    ) { r -> r.optString("msg", "已修改 " + r.optString("path")) }

    /** 删远端单个文件，删前服务端必定留备份 */
    fun 直改删文件(项目id: String, 路径: String, 说明: String, 会话id: String): 直改结果 =
        直改调用(
            "delete",
            mapOf(
                "project_id" to 项目id, "path" to 路径,
                "note" to 说明, "conv_id" to 会话id
            ),
            "POST"
        ) { r -> r.optString("msg", "已删除 " + r.optString("path")) }

    // ==================== 代码仓（file-*）====================
    // 走 /api/repo.php，操作的是拉到本地副本的仓，不直连服务器。
    // 跟 SFTP 系列共用 直改结果 结构和 直改调用() 的收口方式，接口路径不同而已。

    private fun 仓调用(
        动作: String,
        参数: Map<String, String>,
        方法: String,
        成功正文: (JSONObject) -> String
    ): 直改结果 {
        val 路径 = 参数["path"] ?: ""
        val r = 接口.调("/api/repo.php", 参数 + ("act" to 动作), 方法, 密钥源())
        val 错 = r.optString("error")
        if (错.isNotBlank()) {
            return 直改结果(动作, 路径, 错, false)
        }
        return 直改结果(动作, r.optString("path", 路径), 成功正文(r), true)
    }

    /** 本地仓清单，AI 动手改之前先看有哪些文件 */
    fun 仓查清单(项目id: String, 仅改动: Boolean): 直改结果 = 仓调用(
        "tree",
        mapOf("project_id" to 项目id) + if (仅改动) mapOf("dirty" to "1") else emptyMap(),
        "GET"
    ) { r ->
        val 表 = r.optJSONArray("list")
        if (表 == null || 表.length() == 0) {
            "（仓是空的，还没拉取过。请先让用户在项目里绑定服务器和部署目录、然后拉取代码）"
        } else {
            buildString {
                append("共 ").append(r.optInt("total")).append(" 个文件：\n\n")
                for (i in 0 until 表.length()) {
                    val o = 表.optJSONObject(i) ?: continue
                    append(o.optString("path"))
                    append("（").append(o.optString("size_text")).append("）")
                    val 态 = o.optString("state")
                    if (态.isNotBlank() && 态 != "clean") append(" [").append(态).append("]")
                    append("\n")
                }
            }
        }
    }

    /** 读本地仓里一个文件的正文 */
    fun 仓查读文件(项目id: String, 路径: String): 直改结果 = 仓调用(
        "read",
        mapOf("project_id" to 项目id, "path" to 路径),
        "GET"
    ) { r ->
        val 正文 = r.optString("text")
        if (r.optInt("truncated") == 1) 正文 + "\n…（文件过长，正文已截断，改用补丁方式）" else 正文
    }

    /** 整文件覆写本地仓文件 */
    fun 仓查写文件(项目id: String, 路径: String, 内容: String, 说明: String): 直改结果 = 仓调用(
        "write",
        mapOf("project_id" to 项目id, "path" to 路径, "text" to 内容, "note" to 说明),
        "POST"
    ) { r -> r.optString("msg", "已写入本地仓 " + r.optString("path")) }

    /** 按内容定位做局部替换 */
    fun 仓查补丁(项目id: String, 路径: String, 原片段: String, 新片段: String, 说明: String): 直改结果 =
        仓调用(
            "patch",
            mapOf(
                "project_id" to 项目id, "path" to 路径,
                "find" to 原片段, "replace" to 新片段, "note" to 说明
            ),
            "POST"
        ) { r -> r.optString("msg", "已修改本地仓 " + r.optString("path")) }

    /** 把本地仓改动回传到客户服务器 */
    fun 仓查回传(项目id: String): 直改结果 = 仓调用(
        "push",
        mapOf("project_id" to 项目id),
        "POST"
    ) { r -> r.optString("msg", "回传完成") }

    /** 从客户服务器拉取代码到本地副本 */
    fun 仓库拉取(项目id: String): 直改结果 = 仓调用(
        "pull",
        mapOf("project_id" to 项目id),
        "POST"
    ) { r -> r.optString("msg", "拉取完成") }

    /** 删除单个仓文件 */
    fun 仓查删文件(项目id: String, 路径: String): 直改结果 = 仓调用(
        "del",
        mapOf("project_id" to 项目id, "path" to 路径),
        "POST"
    ) { r -> r.optString("msg", "已从本地副本删除") }

    /** 批量删除仓文件 */
    fun 仓查批量删(项目id: String, 路径们: List<String>): 直改结果 {
        if (路径们.isEmpty()) {
            return 直改结果("del", "", "没有选中文件", false)
        }
        val json = org.json.JSONArray(路径们).toString()
        return 仓调用(
            "del",
            mapOf("project_id" to 项目id, "paths" to json),
            "POST"
        ) { r -> 
            val 成功 = r.optInt("成功", 0)
            val 失败 = r.optInt("失败", 0)
            r.optString("msg", "删除 $成功 个，失败 $失败 个")
        }
    }

    /** 上传文件到仓 */
    fun 仓上传文件(
        项目id: String, 
        字节: ByteArray, 
        文件名: String,
        目录前缀: String = "",
        模式: String = "keep",  // keep=原样收下 extract=解压
        完整: Boolean = true
    ): Result<String> {
        return try {
            val 参数 = mutableMapOf(
                "act" to "upload",
                "project_id" to 项目id,
                "name" to 文件名,
                "mode" to 模式,
                "full" to if (完整) "1" else "0"
            )
            if (目录前缀.isNotBlank()) {
                参数["dir"] = 目录前缀
            }
            val r = 接口.传文件("/api/repo.php", 字节, 文件名, "application/octet-stream", 密钥源(), 参数)
            val 错 = r.optString("error")
            if (错.isNotBlank()) {
                return Result.failure(Exception(错))
            }
            val msg = r.optString("msg", "上传成功")
            val warn = r.optString("warn")
            Result.success(if (warn.isNotBlank()) "$msg\n注意：$warn" else msg)
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    // ==================== 工作中心（ws-*）====================
    // 走 /api/ws.php，账号内持久文件库，不挂项目、不需要 project_id。

    private fun 工作调用(
        动作: String,
        参数: Map<String, String>,
        方法: String,
        成功正文: (JSONObject) -> String
    ): 直改结果 {
        val 名 = 参数["name"] ?: ""
        val r = 接口.调("/api/ws.php", 参数 + ("act" to 动作), 方法, 密钥源())
        val 错 = r.optString("error")
        if (错.isNotBlank()) {
            return 直改结果(动作, 名, 错, false)
        }
        return 直改结果(动作, r.optString("name", 名), 成功正文(r), true)
    }

    /** 工作中心文件清单，支持按名字关键词筛选 */
    fun 工作查清单(关键词: String): 直改结果 = 工作调用(
        "list",
        if (关键词.isNotBlank()) mapOf("q" to 关键词) else emptyMap(),
        "GET"
    ) { r ->
        val 表 = r.optJSONArray("list")
        if (表 == null || 表.length() == 0) {
            "（工作中心目前没有文件）"
        } else {
            buildString {
                for (i in 0 until 表.length()) {
                    val o = 表.optJSONObject(i) ?: continue
                    append(o.optString("name"))
                    append("（").append(o.optString("size_text", "")).append("）\n")
                }
            }
        }
    }

    /** 读工作中心一个文件的正文 */
    fun 工作查读文件(名: String): 直改结果 = 工作调用(
        "read",
        mapOf("name" to 名),
        "GET"
    ) { r ->
        val 正文 = r.optString("text")
        if (r.optInt("truncated") == 1) 正文 + "\n…（文件过长，正文已截断，改用补丁方式）" else 正文
    }

    /** 整文件写入工作中心，同名会覆盖（服务端自动留旧版本快照） */
    fun 工作查写文件(名: String, 内容: String, 说明: String): 直改结果 = 工作调用(
        "write",
        mapOf("name" to 名, "text" to 内容, "note" to 说明, "source" to "ai"),
        "POST"
    ) { r -> r.optString("msg", "已写入工作中心 " + r.optString("name")) }

    /** 按内容定位做局部替换 */
    fun 工作查补丁(名: String, 原片段: String, 新片段: String, 说明: String): 直改结果 = 工作调用(
        "patch",
        mapOf("name" to 名, "find" to 原片段, "replace" to 新片段, "note" to 说明),
        "POST"
    ) { r -> r.optString("msg", "补丁已应用到工作中心文件 " + r.optString("name")) }

    // ================= PPT 生成 =================
    /**
     * 生成 PPT。大纲 JSON 原样转发给服务端，服务端存进工作中心并返回下载信息。
     * @param 名字 从大纲里读出的文件名，留空时服务端会按标题自动取名
     */
    fun 生成PPT(大纲json: String, 名字: String): 直改结果 {
        val 参数 = mutableMapOf("outline" to 大纲json)
        if (名字.isNotBlank()) 参数["name"] = 名字
        val r = 接口.调("/api/ppt.php", 参数, "POST", 密钥源())
        val 错 = r.optString("error")
        if (错.isNotBlank()) return 直改结果("ppt", 名字, 错, false)
        val 文件名 = r.optString("name", 名字)
        val 正文 = "已生成 " + r.optInt("slides") + " 页，" + r.optString("size_text") +
            "，已存入工作中心（" + 文件名 + "），可在工作中心下载。"
        return 直改结果("ppt", 文件名, 正文, true)
    }

    // ================= 网页抓取 =================
    /** 一次网页抓取的结果，字段对齐 api/web.php 的返回 */
    data class 网页抓取结果(
        val url: String,
        val 状态码: Int,
        val 类型: String,
        val 标题: String,
        val 正文: String,
        val 链接表: List<Pair<String, String>>,
        val 大小文本: String,
        val 字数: Int,
        val 已截断: Boolean,
        val 耗时毫秒: Long,
        val 跳转次数: Int
    )
    /** 抓一个网页。上限<=0 或 链接数<0 表示不传，交给服务端用后台配置的默认值 */
    fun 抓网页(网址: String, 上限: Int, 链接数: Int): Result<网页抓取结果> {
        val 参数 = mutableMapOf("url" to 网址)
        if (上限 > 0) 参数["limit"] = 上限.toString()
        if (链接数 >= 0) 参数["links"] = 链接数.toString()
        val r = 接口.调("/api/web.php", 参数, "POST", 密钥源())
        val 错 = r.optString("error")
        if (错.isNotBlank()) return Result.failure(Exception(错))
        val 链接数组 = r.optJSONArray("links")
        val 链接表 = mutableListOf<Pair<String, String>>()
        if (链接数组 != null) {
            for (i in 0 until 链接数组.length()) {
                val o = 链接数组.optJSONObject(i) ?: continue
                链接表.add(o.optString("text") to o.optString("url"))
            }
        }
        val 跳数 = r.optJSONArray("hops")?.length() ?: 0
        return Result.success(
            网页抓取结果(
                url = r.optString("url", 网址),
                状态码 = r.optInt("status"),
                类型 = r.optString("type"),
                标题 = r.optString("title"),
                正文 = r.optString("text"),
                链接表 = 链接表,
                大小文本 = r.optString("size_text"),
                字数 = r.optInt("chars"),
                已截断 = r.optInt("truncated") == 1,
                耗时毫秒 = r.optLong("ms"),
                跳转次数 = 跳数
            )
        )
    }
}
