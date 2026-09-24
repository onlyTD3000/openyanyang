package cn.bot77.yanyang.data
data class 我的信息(
    val id: Int = 0,
    val 用户名: String = "",
    val 邮箱: String = "",
    val 角色: String = "",
    val 余额: String = "",
    val token配额: Int = 0,
    val 已用token: Int = 0,
    val 总花费: String = "",
    /** 站点公告，后台设置的那段文字，可能是多行 */
    val 公告: String = "",
    /**
     * 图片上传体积上限（KB），来自后台设置，跟服务端 api/upload.php 用的是同一个值。
     * 0 表示后台没设上限，客户端此时不拦。
     */
    val 图片上限KB: Int = 0,
    val 有效: Boolean = false,
    // 工具开关
    val tool_ssh_exec: Int = 1,
    val tool_sftp_read: Int = 1,
    val tool_sftp_write: Int = 1,
    val tool_sftp_list: Int = 1,
    val tool_sftp_delete: Int = 1,
    val tool_sftp_patch: Int = 1,
    val tool_file_list: Int = 1,
    val tool_file_read: Int = 1,
    val tool_file_write: Int = 1,
    val tool_file_delete: Int = 1,
    val tool_file_push: Int = 1,
    val tool_file_patch: Int = 1,
    val tool_ws_list: Int = 1,
    val tool_ws_read: Int = 1,
    val tool_ws_write: Int = 1,
    val tool_ws_delete: Int = 1,
    val tool_ws_patch: Int = 1,
    val tool_ws_zip: Int = 1,
    val tool_web_open: Int = 1,
    val tool_web_search: Int = 1,
    val tool_ppt_generate: Int = 1
)
data class 模型项(
    val 标识: String,
    val 名称: String,
    val 视觉: Boolean = false,
    // 以下四个是每百万 token 的单价（人民币），来自后台配置，字符串按原样展示，
    // 空字符串表示后端没配这项价格
    val 输入价: String = "",
    val 输出价: String = "",
    val 缓存读价: String = "",
    val 缓存写价: String = ""
)
data class 项目(
    val id: String,
    val 名称: String,
    val 简介: String = "",
    val 技术栈: String = "",
    val 主机id: String = "",
    val 部署目录: String = "",
    val 站点地址: String = "",
    val 颜色: String = ""
)
data class 会话(
    val id: String,
    val 标题: String,
    val 项目id: String = "",
    /** 会话级上下文条数：0 = 跟随模型默认，2..60 = 自定义 */
    val 上下文条数: Int = 0,
    /** 当前运行中已执行的工具ID集合，用于断点重续 */
    val 已执行工具: Set<String> = emptySet()
)
/**
 * 一次 SFTP 直连操作的结果。
 *
 * 五种动作（list/read/write/patch/delete）共用这一个类型：
 * 成败看 成功，给 AI 看的正文放 正文，路径用来拼回执标题。
 * 服务端对写类动作已经返回了现成的 msg，这里直接透传，不自己另编说法。
 */
data class 直改结果(
    val 动作: String,
    val 路径: String,
    val 正文: String,
    val 成功: Boolean
)
data class 消息(
    val 角色: String,
    var 正文: String,
    var 思考: String = "",
    var 在输出: Boolean = false,
    var 用量: String = "",
    /** true 表示这条是工具回执（命令输出等），不是用户真的说了这句话 */
    val 是回执: Boolean = false,
    /** 稳定序号，给 Compose key 用的。流式输出时正文在变，不能拿长度当 key */
    val 序号: Int = -1,
    /** 工具调用列表，用于 Function Calling 渲染工具卡片 */
    val 工具调用: List<工具调用项> = emptyList(),
    /** 工具执行结果列表 */
    val 工具结果: List<工具结果项> = emptyList(),
    /** 当前运行 ID，用于断点重续 */
    var 运行号: String = ""
) {
    val 是用户: Boolean get() = 角色 == "user"
    val 有思考: Boolean get() = 思考.isNotBlank()
    val 有工具: Boolean get() = 工具调用.isNotEmpty()
}

/**
 * 一次工具调用的信息。
 */
data class 工具调用项(
    val id: String,
    val 名称: String,
    val 参数: String
)

/**
 * 一次工具执行的结果。
 */
data class 工具结果项(
    val id: String,
    val 内容: String
)

data class 仓文件(
    val 路径: String,
    val 大小文本: String = "",
    val 是文本: Boolean = true,
    val 状态: String = "clean"
) {
    val 文件名: String get() = 路径.substringAfterLast('/')
    val 目录: String get() = 路径.substringBeforeLast('/', "")
    val 有改动: Boolean get() = 状态 == "edited" || 状态 == "new"
}
data class 仓概况(
    val 项目名: String = "",
    val 远程目录: String = "",
    val 主机: String = "",
    val 改动数: Int = 0
)
sealed interface 流事件 {
    data class 开始(val 运行号: String) : 流事件
    data class 增量(val 文本: String) : 流事件
    data class 思考(val 文本: String) : 流事件
    data class 工具执行(
        val 调用列表: List<工具调用项>,
        val 结果列表: List<工具结果项>
    ) : 流事件
    data class 完成(
        val 余额: String = "",
        val 输入token: Int = 0,
        val 输出token: Int = 0,
        // 命中缓存读的输入量（按 0.1 倍计价）和缓存创建量（按 1.25 倍计价）。
        // 服务端 tokens_in 已经把这两段都含进去了，展示时要减掉单独列出，
        // 口径跟网页端 chat.js 的 用量文案() 保持一致。
        val 缓存读token: Int = 0,
        val 缓存写token: Int = 0,
        val 花费: String = ""
    ) : 流事件
    data class 错误(val 提示: String) : 流事件
    data object 已停 : 流事件
    data object 结束 : 流事件
}
data class 待发图(
    val id: String,
    val 本地uri: String,
    val 文件名: String = ""
)
data class SSHHost(
    val id: String,
    val 名称: String,
    val 地址: String,
    val 端口: Int = 22,
    val 用户名: String,
    val 认证方式: String = "key",
    val 状态: String = "",
    val 指纹: String = "",
    val 最近连通: String = "",
    val 错误信息: String = ""
)
data class SSHHostForm(
    val id: String = "",
    val 名称: String = "",
    val 地址: String = "",
    val 端口: String = "22",
    val 用户名: String = "",
    val 认证方式: String = "key",
    val 凭据: String = "",
    val 口令: String = ""
)
/** 用量概览：对应网页端 usage.php 顶部四个统计卡 */
data class 用量概览(
    val 余额: String = "",
    val 总花费: String = "",
    val token配额: Int = 0,
    val 已用token: Int = 0,
    val 配额剩余: Int? = null, // null 表示不限
    val 今日花费: String = "",
    val 今日次数: Int = 0,
    val 累计tokens: Int = 0,
    val 累计次数: Int = 0
)
/** 一条调用明细，对应 usage_logs 一行 */
data class 调用记录(
    val id: Int,
    val 模型名: String,
    val 输入token: Int,
    val 输出token: Int,
    val 费用: String,
    val 状态: String,
    val 估算: Boolean,
    val 时间: String
)
/** 一枚 API 密钥（不含明文，明文只在生成/重置那一刻单独拿到） */
data class API密钥(
    val id: Int,
    val 名称: String,
    val 创建时间: String,
    val 最近使用: String
)
/** 一条余额流水 */
data class 余额流水(
    val id: Int,
    val 金额: String,
    val 变动后余额: String,
    val 类型: String,
    val 类型名: String,
    val 备注: String,
    val 时间: String
)
/** 个人资料页整体数据 */
data class 资料概览(
    val 用户名: String = "",
    val 邮箱: String = "",
    val 余额: String = "",
    val 总花费: String = "",
    val 会话数: Int = 0,
    val 调用数: Int = 0,
    val 密钥表: List<API密钥> = emptyList(),
    val 流水表: List<余额流水> = emptyList()
)
/** 充值面额选项，服务端已按折扣算好实付金额 */
data class 充值面额(
    val 到账: String,
    val 实付: String,
    val 折扣: String
)
/**
 * 充值选项整体。除了面额表，还带着自定义金额的开关和区间。
 *
 * 自定义金额由服务端的 pay_custom_on 控制，关掉时安卓端不该显示输入框，
 * 所以这三个字段必须原样带上来，不能在客户端写死。
 * 区间同理：后台改了 pay_custom_min / max，客户端跟着变，不用发版。
 */
data class 充值选项(
    val 面额表: List<充值面额> = emptyList(),
    val 自定义开: Boolean = false,
    val 自定义下限: Double = 1.0,
    val 自定义上限: Double = 10000.0
)
/** 充值下单结果 */
data class 充值订单(
    val 订单号: String,
    val 类型: String, // qr 或 url，跟支付宝返回一致
    val 地址: String, // 跳转链接或二维码内容地址
    val 到账: String,
    val 实付: String
)
/** 后台命令任务的一次进度快照 */
data class 命令进度(
    val 完成: Boolean,
    val 退出码: Int,
    val 输出: String,
    val 下一个起点: Int
)
/**
 * 工作中心里的一个文件。
 *
 * 对齐 /api/ws.php?act=list 返回的字段：kind 分 text/image/bin 三类，
 * 只有 text 能在线编辑，可预览标记由服务端算好（Office 文档才为 true）。
 */
data class 工作文件(
    val id: String,
    val 名字: String,
    val 类型: String = "text",
    val 大小文本: String = "",
    val 地址: String = "",
    val 来源: String = "",
    val 版本: Int = 1,
    val 可预览: Boolean = false
) {
    val 是文本: Boolean get() = 类型 == "text"
    val 是图片: Boolean get() = 类型 == "image"
    val 是AI产出: Boolean get() = 来源 == "ai"
}
/** 工作中心列表一页的结果，带空间用量 */
data class 工作中心页(
    val 文件表: List<工作文件> = emptyList(),
    val 页码: Int = 1,
    val 总页数: Int = 1,
    val 已用文本: String = "",
    val 配额文本: String = "",
    val 百分比: Double = 0.0
)