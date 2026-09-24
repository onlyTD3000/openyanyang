package cn.bot77.yanyang.ui
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import cn.bot77.yanyang.data.*
import cn.bot77.yanyang.net.对话流
import cn.bot77.yanyang.net.版本检查
import cn.bot77.yanyang.net.版本信息
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
/** 界面在哪一屏 */
enum class 屏幕 { 登录, 对话, 代码仓, 工作中心, 服务器登记, 用量, 个人资料, 充值 }
/**
 * 全局状态。
 *
 * 所有网络调用都在 IO 线程，状态更新回主线程。
 * 消息列表用 mutableStateListOf，流式输出时只改最后一条的正文，
 * 不整体替换列表，否则每来一个字都要重组整个列表，长对话会卡。
 */
class 主状态(private val 密钥仓: 密钥仓) : ViewModel() {
    private val 仓库 = 仓库 { 密钥仓.读() }
    private val 流 = 对话流()
    /** 给每条消息发一个稳定序号，专供 Compose key 使用，不持久化 */
    private var 消息序号 = 0
    private fun 造消息(角色: String, 正文: String, 在输出: Boolean = false, 是回执: Boolean = false) =
        消息(角色 = 角色, 正文 = 正文, 在输出 = 在输出, 是回执 = 是回执, 序号 = 消息序号++)
    // ---- 界面状态 ----
    var 当前屏 = mutableStateOf(屏幕.登录)
    /**
     * 页面返回栈，存的是「来路」，不含当前页。
     *
     * 系统返回键优先弹这里，弹空了才退出应用，这样返回键的行为
     * 和用户心里的「上一页」一致，不会从二级页一脚踩回桌面。
     * 登录页不入栈：退登后按返回不该能回到已登录的界面。
     */
    val 返回栈 = mutableStateListOf<屏幕>()
    // ---- 版本更新 ----
    /**
     * 启动时查到的版本信息。
     *
     * 放在主状态而不是侧栏组件里：侧栏的检查只有拉开抽屉才会跑，
     * 强制更新必须开机就弹，跟用户有没有开抽屉无关。
     */
    var 版本 = mutableStateOf<版本信息?>(null)
    /** 强制更新弹窗是否显示。强制时置 true 且不给关，用户只能更新或退出 */
    var 显强制更新 = mutableStateOf(false)
    // ---- 工作中心 ----
    /** 当前页的文件表 */
    val 工作文件表 = mutableStateListOf<工作文件>()
    /** 列表页码、总页数，跟网页端一样一页 40 条 */
    var 工作页码 = mutableStateOf(1)
    var 工作总页数 = mutableStateOf(1)
    /** 类型筛选：all / text / image / bin */
    var 工作类型 = mutableStateOf("all")
    /** 文件名搜索词 */
    var 工作搜索 = mutableStateOf("")
    /** 空间用量文字，形如「已用 1.2 MB / 200 MB（0.6%）」 */
    var 工作用量 = mutableStateOf("")
    /** 正在打开的那个文件，null 表示还在列表 */
    var 工作打开的 = mutableStateOf<工作文件?>(null)
    /** 文本文件正文（可编辑），以及打开时的原文用来判断有没有改动 */
    var 工作正文 = mutableStateOf("")
    var 工作原文 = mutableStateOf("")
    /** 服务端说正文被截断了：这时候保存会丢后半截，界面要禁掉保存 */
    var 工作已截断 = mutableStateOf(false)
    /** Office 预览的总页数，0 表示还没转或不是可预览类型 */
    var 工作预览页数 = mutableStateOf(0)
    /** 预览生成中 / 失败原因 */
    var 工作预览中 = mutableStateOf(false)
    var 工作预览错 = mutableStateOf("")
    /** 打开文件时的读取中标志，跟列表的 加载中 分开，互不干扰 */
    var 工作读取中 = mutableStateOf(false)
    var 工作保存中 = mutableStateOf(false)
    val 服务器表 = mutableStateListOf<SSHHost>()
    var 显项目表单 = mutableStateOf(false)
    var 项目表单数据 = mutableStateOf(项目(id = "", 名称 = ""))
    var 加载中 = mutableStateOf(false)
    var 提示 = mutableStateOf("")
    // ---- 用户 ----
    var 我的 = mutableStateOf(我的信息())
    var 输入的密钥 = mutableStateOf("")
    // ---- 侧栏 ----
    val 项目表 = mutableStateListOf<项目>()
    val 会话表 = mutableStateListOf<会话>()
    var 当前会话 = mutableStateOf<会话?>(null)
    /** 侧栏里展开的项目 id */
    val 已展开 = mutableStateListOf<String>()
    // ---- 模型 ----
    val 模型表 = mutableStateListOf<模型项>()
    var 当前模型 = mutableStateOf("")
    // ---- 消息 ----
    val 消息表 = mutableStateListOf<消息>()
    var 在生成 = mutableStateOf(false)
    /**
     * 「该响提示音了」的信号，每次自增一次。
     *
     * 为什么用计数器而不是 Boolean：Boolean 置 true 后还得找地方置回 false，
     * 中间那一小段时间里重组会重复响。计数器只增不减，UI 层比对上次的值就知道
     * 是不是新的一次，不用回置。
     *
     * 提示音放在 UI 层响而不是这里：主状态是普通 ViewModel，手上没有 Context，
     * 改成 AndroidViewModel 会牵动所有创建它的地方。UI 层拿 Context 是顺手的。
     */
    val 提示音信号 = mutableStateOf(0)
    /**
     * 已经响过的信号值。存在 ViewModel 里而不是 Composable 的 remember 里：
     * remember 会随着页面切换、屏幕旋转被丢弃再重建，重建时初值就是当下的
     * 信号值，中间漏掉的那一声永远补不回来。放这儿能跨页面、跨重建活着。
     */
    var 响过号 = 0
    private var 当前运行号 = ""
    /** 增量缓冲，把连续到来的增量攒到 100ms 再一次性写入消息表 */
    private val 增量缓冲 = StringBuilder()
    /** 当前是否有节流任务在跑，防止重复排队 */
    private var 增量节流活跃 = false
    /** 思考文本待跳过长度：fold事件到达时，对应的delta可能还没flush，记下要跳过的字符数 */
    private var 待跳过长度 = 0
    /** 已通过delta收到的思考文本长度，用于计算待跳过 */
    private var 已收思考delta长度 = 0
    /** 连续自动执行的轮数，防止 AI 自己跟自己无限循环下去（现在由后端控制，安卓端仅保留计数用于重置） */
    private var 连续轮数 = 0
    /** 当前正在接收内容的AI消息位置，工具执行后会创建新消息并更新此位置 */
    private var 当前AI消息位置 = -1
    private var 自动继续计数 = 0
    // ---- 代码仓 ----
    val 仓文件表 = mutableStateListOf<仓文件>()
    var 仓概况 = mutableStateOf<仓概况?>(null)
    var 仓所属项目 = mutableStateOf<项目?>(null)
    var 仓选中文件 = mutableStateListOf<String>()  // 批量操作时选中的文件路径
    var 仓编辑模式 = mutableStateOf(false)  // 是否处于批量选择模式
    var 只看改动 = mutableStateOf(false)
    var 打开的文件 = mutableStateOf<String?>(null)
    var 文件内容 = mutableStateOf("")
    var 文件编辑模式 = mutableStateOf(false)  // 文件预览的编辑模式
    var 编辑中内容 = mutableStateOf("")  // 编辑时的临时内容
    // ---- 待发图片 ----
    /** 已上传成功、还没随消息发出去的图片。发送后清空 */
    val 待发图片 = mutableStateListOf<待发图>()
    var 在传图 = mutableStateOf(false)
    /** 当前模型是否支持图片，界面据此显示或禁用图片按钮 */
    val 当前模型支持图片: Boolean
        get() = 模型表.find { it.标识 == 当前模型.value }?.视觉 == true
    // ---- 用量 ----
    var 用量数据 = mutableStateOf<用量概览?>(null)
    val 调用明细表 = mutableStateListOf<调用记录>()
    var 明细页码 = mutableStateOf(1)
    var 明细总页数 = mutableStateOf(1)
    // ---- 个人资料 ----
    var 资料数据 = mutableStateOf<资料概览?>(null)
    var 刚生成的密钥 = mutableStateOf<String?>(null) // 明文只显示一次，读过就清
    // ---- 充值 ----
    val 充值面额表 = mutableStateListOf<充值面额>()
    // 自定义金额的开关和区间，由服务端下发（pay_custom_on / min / max）。
    // 关掉时充值页不显示输入框，不在客户端写死判断
    var 自定义金额开 = mutableStateOf(false)
    var 自定义金额下限 = mutableStateOf(1.0)
    var 自定义金额上限 = mutableStateOf(10000.0)
    var 当前订单 = mutableStateOf<充值订单?>(null)
    var 充值中 = mutableStateOf(false)
    // 手动点「我已支付，立即查询」时置真，给按钮一个进行中的反馈
    var 查单中 = mutableStateOf(false)
    var 二维码字节 = mutableStateOf<ByteArray?>(null)
    private var 轮询任务: kotlinx.coroutines.Job? = null
    init {
        // 有存过密钥就直接验，省得每次都要登录
        if (密钥仓.有密钥()) {
            输入的密钥.value = 密钥仓.读()
            登录()
        }
    }
    // ================= 登录 =================
    fun 登录() {
        val 密钥 = 输入的密钥.value.trim()
        if (密钥.isBlank()) {
            提示.value = "请填写密钥"
            return
        }
        密钥仓.存(密钥)
        加载中.value = true
        提示.value = ""
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.取我的信息() }
            加载中.value = false
            结果.onSuccess { 信息 ->
                我的.value = 信息
                // 登录成功后对话页是新的根，清栈让返回键直接退出应用，
                // 而不是退回登录页（那时已经登录了，回去没有意义）
                返回栈.clear()
                当前屏.value = 屏幕.对话
        提示.value = ""
                载入侧栏()
                载入模型表()
            }.onFailure { e ->
                提示.value = e.message ?: "登录失败"
                密钥仓.清()
            }
        }
    }
    fun 退出登录() {
        流.停()
        密钥仓.清()
        输入的密钥.value = ""
        我的.value = 我的信息()
        项目表.clear(); 会话表.clear(); 消息表.clear(); 模型表.clear()
        当前会话.value = null
        返回栈.clear()
        当前屏.value = 屏幕.登录
    }
    // ================= 侧栏 =================
    fun 载入侧栏() {
        viewModelScope.launch {
            val (项目们, 会话们) = withContext(Dispatchers.IO) {
                仓库.取项目表() to 仓库.取会话表()
            }
            项目表.clear(); 项目表.addAll(项目们)
            会话表.clear(); 会话表.addAll(会话们)
        }
    }
    private fun 载入模型表() {
        viewModelScope.launch {
            val 模型们 = withContext(Dispatchers.IO) { 仓库.取模型表() }
            模型表.clear(); 模型表.addAll(模型们)
            // 没选过就默认第一个
            if (当前模型.value.isBlank() && 模型们.isNotEmpty()) {
                当前模型.value = 模型们.first().标识
            }
        }
    }
    fun 切项目展开(项目id: String) {
        if (已展开.contains(项目id)) 已展开.remove(项目id) else 已展开.add(项目id)
    }
    /** 某项目下的会话 */
    fun 项目下会话(项目id: String) = 会话表.filter { it.项目id == 项目id }
    /** 不属于任何项目的会话 */
    fun 散会话() = 会话表.filter { 会 ->
        会.项目id.isBlank() || 项目表.none { it.id == 会.项目id }
    }
    fun 选会话(会: 会话) {
        if (在生成.value) 停生成()
        当前会话.value = 会
        消息表.clear()
        加载中.value = true
        viewModelScope.launch {
            val (条数, 消息们) = withContext(Dispatchers.IO) { 仓库.取消息表(会.id) }
            消息表.addAll(消息们.map { it.copy(序号 = 消息序号++) })
            当前会话.value = 会.copy(上下文条数 = 条数)
            加载中.value = false
        }
    }
    fun 新建会话(项目id: String = "") {
        viewModelScope.launch {
            val id = withContext(Dispatchers.IO) { 仓库.新建会话(项目id) }
            if (id == null) {
                提示.value = "新建会话失败"
                return@launch
            }
            val 新会 = 会话(id = id, 标题 = "新对话", 项目id = 项目id)
            会话表.add(0, 新会)
            当前会话.value = 新会
            消息表.clear()
        }
    }
    fun 删会话(会: 会话) {
        viewModelScope.launch {
            val 成 = withContext(Dispatchers.IO) { 仓库.删会话(会.id) }
            if (成) {
                会话表.remove(会)
                if (当前会话.value?.id == 会.id) {
                    当前会话.value = null
                    消息表.clear()
                }
            } else 提示.value = "删除失败"
        }
    }
    fun 改会话名(会: 会话, 新名: String) {
        if (新名.isBlank()) return
        viewModelScope.launch {
            val 成 = withContext(Dispatchers.IO) { 仓库.改会话名(会.id, 新名) }
            if (成) {
                val i = 会话表.indexOfFirst { it.id == 会.id }
                if (i >= 0) 会话表[i] = 会话表[i].copy(标题 = 新名)
            } else 提示.value = "改名失败"
        }
    }
    // ================= 发消息 =================
    fun 发送(正文: String) {
        // 手动发消息意味着新任务开始，自动执行的轮数从头计
        连续轮数 = 0
        val 内容 = 正文.trim()
        // 只发图不打字也是合法的，所以两个都空才拦
        if ((内容.isBlank() && 待发图片.isEmpty()) || 在生成.value) return
        val 会 = 当前会话.value
        if (会 == null) {
            // 没选会话就先建一个，建完再发
            viewModelScope.launch {
                val id = withContext(Dispatchers.IO) { 仓库.新建会话("") }
                if (id == null) {
                    提示.value = "新建会话失败"
                    return@launch
                }
                val 新会 = 会话(id = id, 标题 = 内容.take(20), 项目id = "")
                会话表.add(0, 新会)
                当前会话.value = 新会
                正常发送(新会.id, 内容)
            }
            return
        }
        正常发送(会.id, 内容)
    }
    /**
     * @param 回执种类 非空表示这条是工具回执而不是用户发言。后端据此把内容
     *   写进工具结果、并裹上「系统回执」标记，不会污染聊天记录。
     */
    private fun 正常发送(会话id: String, 内容: String, 重置计数: Boolean = true) {
        if (重置计数) {
            连续轮数 = 0
            自动继续计数 = 0
            // 重置计数时清空已执行工具（开始新任务）
            当前会话.value?.let { 会 ->
                当前会话.value = 会.copy(已执行工具 = emptySet())
            }
        }
        val userMsg = 造消息(角色 = "user", 正文 = 内容)
        消息表.add(userMsg)
        val aiMsg = 造消息(角色 = "assistant", 正文 = "")
        消息表.add(aiMsg)
        val aiIndex = 消息表.lastIndex
        当前AI消息位置 = aiIndex
        在生成.value = true
        待跳过长度 = 0
        已收思考delta长度 = 0
        // 事件回调必须切回 Main 再动 消息表：那是 Compose 的快照状态，
        // 后台线程写、主线程同时读会把快照锁死，界面就冻在原地不动了。
        // 增量缓冲（StringBuilder）也不是线程安全的，统一在 Main 上碰它。
        viewModelScope.launch(Dispatchers.Main) {
            launch(Dispatchers.IO) {
                try {
                    val 参数 = mutableMapOf(
                        "conv_id" to 会话id,
                        "content" to 内容,
                        "model_id" to 当前模型.value
                    )
                    // 会话级上下文条数：有合法自定义值才下发，0 由后端回落模型默认
                    val 上下文条数 = 当前会话.value?.上下文条数 ?: 0
                    if (上下文条数 in 2..60) 参数["context_limit"] = 上下文条数.toString()
                    
                    // 断点重续：携带已执行的工具ID列表
                    val 已执行工具 = 当前会话.value?.已执行工具 ?: emptySet()
                    if (已执行工具.isNotEmpty()) {
                        参数["executed_tools"] = 已执行工具.joinToString(",")
                    }
                    
                    流.开始(
                        参数 = 参数,
                        密钥 = 密钥仓.读()
                    ) { event ->
                        launch(Dispatchers.Main) { 处理事件(event) }
                    }
                } catch (e: Exception) {
                    launch(Dispatchers.Main) {
                        在生成.value = false
                        提示.value = e.message ?: "发送失败"
                    }
                }
            }
        }
    }

    private fun 真发送(会话id: String, 内容: String, 回执种类: String = "") {
        viewModelScope.launch(Dispatchers.Main) {
            // 本地插入回执
            val 回执消息 = 造消息(角色 = "assistant", 正文 = 内容, 是回执 = true)
            消息表.add(回执消息)
            // 追加一条空白 AI 消息用于接收后续回复
            val aiMsg = 造消息(角色 = "assistant", 正文 = "")
            消息表.add(aiMsg)
            val aiIndex = 消息表.lastIndex
            当前AI消息位置 = aiIndex
            在生成.value = true
            待跳过长度 = 0
            已收思考delta长度 = 0
            // 在 Main 线程内启动 IO 协程，确保索引已获取
            launch(Dispatchers.IO) {
                try {
                    流.开始(
                        参数 = mapOf("conv_id" to 会话id, "content" to 内容, "model_id" to 当前模型.value,
                            "tool_kind" to 回执种类),
                        密钥 = 密钥仓.读()
                    ) { event ->
                        // 在 Main 线程更新 UI
                        launch(Dispatchers.Main) { 处理事件(event) }
                    }
                } catch (e: Exception) { /* 忽略 */ }
            }
        }
    }
    private fun 处理事件(事件: 流事件) {
        val 位置 = 当前AI消息位置
        if (位置 !in 消息表.indices) return
        when (事件) {
            is 流事件.开始 -> {
                当前运行号 = 事件.运行号
                // 保存 run_id 到消息中，用于断点重续
                消息表[位置] = 消息表[位置].copy(运行号 = 事件.运行号)
            }
            is 流事件.增量 -> {
                增量缓冲.append(事件.文本)
                if (!增量节流活跃) {
                    增量节流活跃 = true
                    viewModelScope.launch {
                        // 等 100ms 再把攒下来的增量一次性写入
                        kotlinx.coroutines.delay(100)
                        刷新增量(位置)
                        增量节流活跃 = false
                    }
                }
            }
            is 流事件.思考 -> {
                // 后端发思考内容时同时发了 fold 和 delta 两个事件：
                // fold 带累积思考全文，delta 带增量。delta 有 100ms 节流缓冲，
                // fold 是立即处理的，所以 fold 到达时正文里往往还没有对应的 delta 文本。
                // 旧的 indexOf 方案因此找不到、剥不掉，思考内容在折叠卡片和正文里各出现一遍。
                //
                // 修复策略：先刷新缓冲把已到的 delta 写进正文，再用 startsWith 判断前缀剥离。
                // 对于还没到达的 delta（当前 chunk 的 delta 在 fold 之后才发），记下待跳过长度，
                // 后续刷新增量时自动跳过这部分思考文本。
                刷新增量(位置)
                val 当前 = 消息表[位置]
                val 思考全文 = 事件.文本
                val 思考长 = 思考全文.length
                val 正文 = 当前.正文

                val 新正文 = when {
                    // 情况1：启发式场景，正文开头包含完整思考文本，直接剥离
                    思考长 > 0 && 正文.startsWith(思考全文) -> {
                        已收思考delta长度 = 思考长
                        待跳过长度 = 0
                        正文.substring(思考长).trimStart('\n', '\r', ' ')
                    }
                    // 情况2：API思考场景，正文是思考的前缀（部分delta已到达并显示在正文中）
                    思考长 > 0 && 正文.isNotEmpty() && 思考全文.startsWith(正文) -> {
                        已收思考delta长度 = 正文.length
                        待跳过长度 = 思考长 - 正文.length
                        ""
                    }
                    // 情况3：API思考后续fold，正文已被之前的fold清空，用已收长度计算待跳过
                    思考长 > 0 && 正文.isEmpty() -> {
                        待跳过长度 = maxOf(0, 思考长 - 已收思考delta长度)
                        ""
                    }
                    // 情况4：不匹配，不改动正文
                    else -> {
                        待跳过长度 = 0
                        正文
                    }
                }
                消息表[位置] = 当前.copy(
                    思考 = 思考全文,
                    正文 = 新正文
                )
            }
            is 流事件.工具执行 -> {
                // 收到工具执行事件，将工具调用和结果保存到消息中
                // 后续会由消息条.kt渲染成工具卡片
                // 模型可能分多轮连续调用工具，后端每轮发一个 tool_result 事件、
                // 只带当轮的调用。这里必须按 id 去重合并而不是整体覆盖，
                // 否则后面的卡片会把前面已显示的卡片顶掉，只剩最后一张。
                刷新增量(位置)
                // 工具执行后后端开始新一轮生成，思考追踪从头计算
                待跳过长度 = 0
                已收思考delta长度 = 0
                val 当前 = 消息表[位置]
                val 旧调用 = 当前.工具调用
                val 旧结果 = 当前.工具结果
                
                // 检查是否有新的工具调用（避免重复事件创建多个气泡）
                val 有新调用 = 事件.调用列表.any { 新 -> 旧调用.none { 旧 -> 旧.id == 新.id } }

                android.util.Log.d("工具执行", "位置=$位置, 有新调用=$有新调用, 调用数=${事件.调用列表.size}, 结果数=${事件.结果列表.size}")

                消息表[位置] = 当前.copy(
                    工具调用 = 旧调用 + 事件.调用列表.filter { 新 -> 旧调用.none { 旧 -> 旧.id == 新.id } },
                    工具结果 = 旧结果 + 事件.结果列表.filter { 新 -> 旧结果.none { 旧 -> 旧.id == 新.id } },
                    在输出 = false
                )

                // 记录已执行的工具ID到会话（用于断点重续）
                当前会话.value?.let { 会 ->
                    val 新已执行 = 会.已执行工具 + 事件.调用列表.map { it.id }
                    当前会话.value = 会.copy(已执行工具 = 新已执行)
                }

                // 不再创建新消息，所有内容（工具卡片+后续文本+用量）都在同一条消息里
                android.util.Log.d("工具执行", "当前AI消息位置=$当前AI消息位置, 消息表大小=${消息表.size}")
            }
            is 流事件.完成 -> {
                if (事件.余额.isNotBlank()) {
                    我的.value = 我的.value.copy(余额 = 事件.余额)
                }
                // 拼用量文案，口径对齐网页端 chat.js 的 用量文案()：
                // 服务端 输入token 已经把缓存读、缓存写都算进去了，
                // 这里减掉两段缓存后单列纯输入，三段加起来才等于 输入token。
                val 用量 = buildString {
                    val 缓存读 = 事件.缓存读token
                    val 缓存写 = 事件.缓存写token
                    // 上游偶尔给的数不自洽（缓存量大于总量），减出负数更难看，兜底夹到 0
                    val 纯输入 = (事件.输入token - 缓存读 - 缓存写).coerceAtLeast(0)
                    if (事件.输入token > 0 || 事件.输出token > 0) {
                        append("输入 $纯输入")
                        // ↓缓存 = 命中缓存的输入 token 数（读缓存，最省钱的那部分，放前面）
                        if (缓存读 > 0) append(" · ↓缓存 $缓存读")
                        // ↑缓存 = 缓存写入 token 数（5 分钟缓存创建费用，写缓存）
                        if (缓存写 > 0) append(" · ↑缓存 $缓存写")
                        append(" / 输出 ${事件.输出token}")
                    }
                    if (事件.花费.isNotBlank()) append(" · 花费 ${事件.花费}")
                }
                android.util.Log.d("完成事件", "位置=$位置, 用量=$用量, 输入=${事件.输入token}, 输出=${事件.输出token}, 花费=${事件.花费}")
                消息表[位置] = 消息表[位置].copy(用量 = 用量)
                // 首轮后端会自动起标题，刷侧栏才看得到
                载入侧栏()
                刷新增量(位置)
                收尾(位置)
            }
            is 流事件.错误 -> {
                提示.value = 事件.提示
                刷新增量(位置)
                收尾(位置)
                // 出错也要响：用户可能切后台等结果，不响他会一直等下去
                提示音信号.value++
            }
            流事件.已停 -> {
                提示.value = "已停止"
                刷新增量(位置)
                收尾(位置)
            }
            流事件.结束 -> {
                刷新增量(位置)
                收尾(位置)
                // 工具执行已由后端通过tool_use机制自动完成，这里只需响提示音
                提示音信号.value++
            }
        }
    }
    /** 把 增量缓冲 里的内容一次性刷入消息表 */
    private fun 刷新增量(位置: Int) {
        if (增量缓冲.isEmpty()) return
        if (位置 !in 消息表.indices) {
            增量缓冲.clear()
            return
        }
        val 追加 = 增量缓冲.toString()
        增量缓冲.clear()

        // 如果有待跳过的思考文本（fold已到但对应delta还在缓冲里），
        // 从追加内容开头跳过对应长度，只把非思考部分写进正文
        val 实际追加 = if (待跳过长度 > 0) {
            val 跳过 = minOf(追加.length, 待跳过长度)
            已收思考delta长度 += 跳过
            待跳过长度 -= 跳过
            追加.substring(跳过)
        } else {
            追加
        }

        if (实际追加.isNotEmpty()) {
            消息表[位置] = 消息表[位置].copy(
                正文 = 消息表[位置].正文 + 实际追加
            )
        }
    }

    private fun 收尾(位置: Int) {
        在生成.value = false
        if (位置 in 消息表.indices) {
            消息表[位置] = 消息表[位置].copy(在输出 = false)
        }
    }
    
    fun 停生成() {
        val 会 = 当前会话.value ?: return
        流.停()
        在生成.value = false
        if (当前运行号.isNotBlank()) {
            viewModelScope.launch {
                withContext(Dispatchers.IO) { 仓库.停输出(会.id, 当前运行号) }
            }
        }
        消息表.indexOfLast { it.在输出 }.takeIf { it >= 0 }?.let { 收尾(it) }
    }
    // ================= 图片 =================
    /**
     * 选好图后调这里。字节由界面从 ContentResolver 读出来传进来，
     * ViewModel 不碰 Android 的 Uri，方便单测也免得持有 Context。
     */
    fun 加图(字节: ByteArray, 文件名: String, 类型: String, 本地uri: String) {
        if (在传图.value) return
        if (!当前模型支持图片) {
            提示.value = "当前模型不支持图片，请换一个支持视觉的模型"
            return
        }
        在传图.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.传图(字节, 文件名, 类型) }
            在传图.value = false
            结果.onSuccess { id ->
                待发图片.add(待发图(id = id, 本地uri = 本地uri, 文件名 = 文件名))
            }.onFailure { e ->
                提示.value = e.message ?: "上传失败"
            }
        }
    }
    fun 删图(id: String) {
        待发图片.removeAll { it.id == id }
    }
    // ================= 项目 =================
    fun 新建项目(项目: 项目) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.保存项目(项目) }
            结果.onSuccess { 项 ->
                项目表.add(0, 项)
                已展开.add(项.id)
            }.onFailure { e ->
                提示.value = e.message ?: "保存项目失败"
            }
        }
    }
    /** 改项目。保存项目带 id 时后端走 UPDATE，所以复用同一个接口。 */
    fun 改项目(项: 项目) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.保存项目(项) }
            结果.onSuccess { 新项 ->
                val i = 项目表.indexOfFirst { it.id == 新项.id }
                if (i >= 0) 项目表[i] = 新项
                // 代码仓正开着这个项目时同步一份，否则里面还是旧的部署目录
                if (仓所属项目.value?.id == 新项.id) 仓所属项目.value = 新项
            }.onFailure { e ->
                提示.value = e.message ?: "保存项目失败"
            }
        }
    }
    // ================= 项目删除 =================
    /**
     * 删项目。项目下还有对话时服务端会拒绝，把它的原话显示出来
     * （提示里会说清还剩几条对话），用户自己决定先去删对话还是算了。
     */
    fun 删项目(项: 项目, 完成: (Boolean) -> Unit = {}) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.删项目(项.id) }
            结果.onSuccess {
                项目表.removeAll { it.id == 项.id }
                已展开.remove(项.id)
                // 当前会话正属于这个项目就退回空态，否则界面还挂着一个已删项目下的对话
                if (当前会话.value?.项目id == 项.id) {
                    当前会话.value = null
                    消息表.clear()
                }
                会话表.removeAll { it.项目id == 项.id }
                提示.value = "已删除项目「${项.名称}」"
                完成(true)
            }.onFailure { e ->
                提示.value = e.message ?: "删除项目失败"
                完成(false)
            }
        }
    }
    // ================= 工作中心 =================
    /** 从侧栏进工作中心，顺手拉第一页 */
    fun 开工作中心() {
        去(屏幕.工作中心)
        工作打开的.value = null
        载入工作中心(1)
    }
    /**
     * 拉某一页文件表。
     * 筛选和搜索词直接读状态，调用方改完状态再调这里就行。
     */
    fun 载入工作中心(页: Int = 工作页码.value) {
        加载中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) {
                仓库.取工作文件表(页, 工作类型.value, 工作搜索.value.trim())
            }
            加载中.value = false
            结果.onSuccess { 页数据 ->
                工作文件表.clear()
                工作文件表.addAll(页数据.文件表)
                工作页码.value = 页数据.页码
                工作总页数.value = 页数据.总页数
                工作用量.value = if (页数据.已用文本.isBlank()) ""
                    else "已用 ${页数据.已用文本} / ${页数据.配额文本}（${页数据.百分比}%）"
            }.onFailure { e ->
                提示.value = e.message ?: "读取工作中心失败"
                工作文件表.clear()
            }
        }
    }
    /** 切类型筛选，切完回到第一页 */
    fun 切工作类型(类: String) {
        if (工作类型.value == 类) return
        工作类型.value = 类
        载入工作中心(1)
    }
    /** 搜索框输入。这里只改状态，什么时候发请求由界面控制（防抖） */
    fun 改工作搜索(词: String) { 工作搜索.value = 词 }
    fun 搜工作中心() { 载入工作中心(1) }
    /**
     * 打开一个文件。
     *
     * 文本 → 读正文进编辑器；
     * Office 文档 → 问服务端转成几页 PNG；
     * 图片和其它二进制 → 不预读，界面直接用 down 接口显示或提示下载。
     */
    fun 开工作文件(文件: 工作文件) {
        工作打开的.value = 文件
        工作正文.value = ""
        工作原文.value = ""
        工作已截断.value = false
        工作预览页数.value = 0
        工作预览错.value = ""
        if (文件.是文本) {
            工作读取中.value = true
            viewModelScope.launch {
                val 结果 = withContext(Dispatchers.IO) { 仓库.读工作文件(文件.id) }
                工作读取中.value = false
                结果.onSuccess { (正文, 截断) ->
                    工作正文.value = 正文
                    工作原文.value = 正文
                    工作已截断.value = 截断
                }.onFailure { e ->
                    提示.value = e.message ?: "读取失败"
                    工作打开的.value = null
                }
            }
        } else if (文件.可预览) {
            工作预览中.value = true
            viewModelScope.launch {
                val 结果 = withContext(Dispatchers.IO) { 仓库.取预览页数(文件.id) }
                工作预览中.value = false
                结果.onSuccess { 工作预览页数.value = it }
                    .onFailure { e ->
                        工作预览错.value = e.message ?: "预览生成失败"
                    }
            }
        }
    }
    fun 关工作文件() {
        工作打开的.value = null
        工作正文.value = ""
        工作原文.value = ""
        工作预览页数.value = 0
        工作预览错.value = ""
    }
    /** 编辑器里改字，只更新状态 */
    fun 改工作正文(新文: String) { 工作正文.value = 新文 }
    /** 有没有未保存的改动，界面用它决定退出时是否拦一下 */
    val 工作有改动: Boolean get() = 工作打开的.value?.是文本 == true && 工作正文.value != 工作原文.value
    /** 保存当前打开的文本文件 */
    fun 保存工作文件() {
        val 文件 = 工作打开的.value ?: return
        if (!文件.是文本) return
        if (工作已截断.value) {
            提示.value = "正文过长已被截断，保存会丢掉后半部分，这里不允许保存"
            return
        }
        if (工作正文.value == 工作原文.value) {
            提示.value = "内容没有变化，不用保存"
            return
        }
        工作保存中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) {
                仓库.写工作文件(文件.名字, 工作正文.value)
            }
            工作保存中.value = false
            结果.onSuccess {
                工作原文.value = 工作正文.value
                提示.value = "已保存"
                载入工作中心()
            }.onFailure { e ->
                提示.value = e.message ?: "保存失败"
            }
        }
    }
    /** 新建一个空文本文件，建完直接打开编辑 */
    fun 新建工作文件(名字: String) {
        val 名 = 名字.trim()
        if (名.isBlank()) return
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.写工作文件(名, "", "手机端新建") }
            结果.onSuccess {
                提示.value = "已新建"
                // 先把列表拉回来，再按名字找到那条真实记录打开。
                // 不能拿空 id 直接开：读正文要用 id，空的必然失败。
                val 页数据 = withContext(Dispatchers.IO) {
                    仓库.取工作文件表(1, 工作类型.value, 工作搜索.value.trim())
                }.getOrNull()
                if (页数据 != null) {
                    工作文件表.clear()
                    工作文件表.addAll(页数据.文件表)
                    工作页码.value = 页数据.页码
                    工作总页数.value = 页数据.总页数
                    页数据.文件表.firstOrNull { it.名字 == 名 }?.let { 开工作文件(it) }
                } else {
                    载入工作中心(1)
                }
            }.onFailure { e ->
                提示.value = e.message ?: "新建失败"
            }
        }
    }
    /** 删文件。界面必须先弹确认再调 */
    fun 删工作文件(文件: 工作文件) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.删工作文件(文件.id) }
            结果.onSuccess {
                提示.value = "已删除 ${文件.名字}"
                if (工作打开的.value?.id == 文件.id) 关工作文件()
                载入工作中心()
            }.onFailure { e ->
                提示.value = e.message ?: "删除失败"
            }
        }
    }
    /** 重命名，可以带目录 */
    fun 改工作文件名(文件: 工作文件, 新名: String) {
        val 名 = 新名.trim()
        if (名.isBlank() || 名 == 文件.名字) return
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.改工作文件名(文件.id, 名) }
            结果.onSuccess { 规整后 ->
                提示.value = "已改名为 $规整后"
                if (工作打开的.value?.id == 文件.id) {
                    工作打开的.value = 文件.copy(名字 = 规整后)
                }
                载入工作中心()
            }.onFailure { e ->
                提示.value = e.message ?: "改名失败"
            }
        }
    }
    /** 取预览的某一页图，界面按需调用（懒加载） */
    suspend fun 取预览图(id: String, 页: Int): ByteArray? = withContext(Dispatchers.IO) {
        仓库.取预览图(id, 页)
    }
    /** 下载原文件字节，交给界面写进「下载」目录 */
    suspend fun 下载工作文件(id: String): ByteArray? = withContext(Dispatchers.IO) {
        仓库.下载工作文件(id)
    }
    // ================= 服务器登记 =================
    fun 登记服务器() {
        去(屏幕.服务器登记)
        取服务器列表()
    }

    // ================= 代码仓 =================
    fun 开代码仓(项: 项目) {
        仓所属项目.value = 项
        去(屏幕.代码仓)
        打开的文件.value = null
        载入仓()
    }
    fun 载入仓() {
        val 项 = 仓所属项目.value ?: return
        加载中.value = true
        viewModelScope.launch {
            val (概况, 文件们) = withContext(Dispatchers.IO) {
                仓库.取仓概况(项.id) to 仓库.取仓文件表(项.id, 只看改动.value)
            }
            加载中.value = false
            概况.onSuccess { 仓概况.value = it }
            文件们.onSuccess {
                仓文件表.clear(); 仓文件表.addAll(it)
            }.onFailure { e ->
                提示.value = e.message ?: "读取代码仓失败"
                仓文件表.clear()
            }
        }
    }
    fun 切只看改动() {
        只看改动.value = !只看改动.value
        载入仓()
    }
    fun 开文件(路径: String) {
        val 项 = 仓所属项目.value ?: return
        打开的文件.value = 路径
        文件内容.value = ""
        加载中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.读仓文件(项.id, 路径) }
            加载中.value = false
            结果.onSuccess { 文件内容.value = it }
                .onFailure { e ->
                    提示.value = e.message ?: "读取失败"
                    打开的文件.value = null
                }
        }
    }
    fun 关文件() {
        打开的文件.value = null
        文件内容.value = ""
        文件编辑模式.value = false
        编辑中内容.value = ""
    }
    fun 切文件编辑模式() {
        文件编辑模式.value = !文件编辑模式.value
        if (文件编辑模式.value) {
            编辑中内容.value = 文件内容.value
        }
    }
    fun 保存文件编辑() {
        val 项 = 仓所属项目.value ?: return
        val 路径 = 打开的文件.value ?: return
        加载中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) {
                仓库.仓查写文件(项.id, 路径, 编辑中内容.value, "手机端编辑")
            }
            加载中.value = false
            if (结果.成功) {
                文件内容.value = 编辑中内容.value
                文件编辑模式.value = false
                提示.value = "保存成功"
                载入仓()  // 刷新文件列表，显示改动标记
            } else {
                提示.value = "保存失败：${结果.正文}"
            }
        }
    }
    fun 切仓编辑模式() {
        仓编辑模式.value = !仓编辑模式.value
        if (!仓编辑模式.value) {
            仓选中文件.clear()
        }
    }
    fun 切文件选中(路径: String) {
        if (仓选中文件.contains(路径)) {
            仓选中文件.remove(路径)
        } else {
            仓选中文件.add(路径)
        }
    }
    fun 全选仓文件() {
        仓选中文件.clear()
        仓选中文件.addAll(仓文件表.map { it.路径 })
    }
    fun 删仓文件(路径: String) {
        val 项 = 仓所属项目.value ?: return
        加载中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 
                仓库.仓查删文件(项.id, 路径) 
            }
            加载中.value = false
            if (结果.成功) {
                提示.value = 结果.正文
                载入仓()
            } else {
                提示.value = "删除失败：${结果.正文}"
            }
        }
    }
    fun 批量删仓文件() {
        val 项 = 仓所属项目.value ?: return
        if (仓选中文件.isEmpty()) {
            提示.value = "没有选中文件"
            return
        }
        加载中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 
                仓库.仓查批量删(项.id, 仓选中文件.toList())
            }
            加载中.value = false
            if (结果.成功) {
                提示.value = 结果.正文
                仓选中文件.clear()
                仓编辑模式.value = false
                载入仓()
            } else {
                提示.value = "批量删除失败：${结果.正文}"
            }
        }
    }
    fun 上传仓文件(
        字节: ByteArray, 
        文件名: String,
        目录前缀: String = "",
        模式: String = "keep",
        完整: Boolean = true
    ) {
        val 项 = 仓所属项目.value ?: return
        加载中.value = true
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) {
                仓库.仓上传文件(项.id, 字节, 文件名, 目录前缀, 模式, 完整)
            }
            加载中.value = false
            结果.onSuccess { msg ->
                提示.value = msg
                载入仓()
            }.onFailure { e ->
                提示.value = "上传失败：${e.message}"
            }
        }
    }
    /**
     * 顶栏返回箭头走这里：语义是「回上一页」，跟系统返回键一致，
     * 不是「回首页」。以前这里清空整个栈直接跳对话页，用户从
     * 代码仓进服务器登记再按箭头会一步跨回对话页，中间那层丢了。
     */
    fun 回对话() {
        if (!按返回()) {
            // 栈空了（直接落在某个二级页）才兜底回对话页
            当前屏.value = 屏幕.对话
            提示.value = ""
            打开的文件.value = null
            仓文件表.clear()
        }
    }
    /**
     * 跳到某一页，并把当前页压进返回栈。
     *
     * 同一页重复跳不压栈（避免连点两次要按两次返回），
     * 登录页不压栈（退登后不该能返回到登录态内部）。
     */
    fun 去(目标: 屏幕) {
        val 现在 = 当前屏.value
        if (现在 == 目标) return
        if (现在 != 屏幕.登录) {
            // 只防相邻重复，不摘除栈里已有的同名页：
            // 摘掉会把层级压平，A→B→A 之后返回键就跳不回中间那层了。
            // 栈的增长由下面的深度上限兜着，不会无限涨。
            if (返回栈.lastOrNull() != 现在) 返回栈.add(现在)
            // 极端情况下（用户反复来回跳）截掉最老的，只留最近 12 层
            while (返回栈.size > 12) 返回栈.removeAt(0)
        }
        当前屏.value = 目标
    }
    /**
     * 处理系统返回键。
     *
     * @return true 表示已经消费掉这次返回（回到了上一页），
     *         false 表示没有上一页，交回系统去退出应用
     */
    fun 按返回(): Boolean {
        // 登录页按返回直接退出应用，不做拦截
        if (当前屏.value == 屏幕.登录) return false
        val 上一页 = 返回栈.removeLastOrNull() ?: return false
        当前屏.value = 上一页
        // 离开二级页时清掉它的临时状态，回来时不会看到上次的残留
        提示.value = ""
        if (上一页 != 屏幕.代码仓) {
            打开的文件.value = null
            仓文件表.clear()
        }
        return true
    }
    // ================= 版本更新 =================
    /**
     * 启动时查一次版本。
     *
     * 强制更新的判断权在服务端（force 字段），这里只负责把弹窗立起来。
     * 每次冷启动都查、都弹：强制更新的意思就是不更新就不能用，
     * 记「本次已忽略」反而让强制失去意义。
     *
     * 查询失败什么都不做——网络不通时不该把用户堵在更新弹窗里。
     */
    fun 查版本(当前版本: String) {
        viewModelScope.launch {
            val 信息 = withContext(Dispatchers.IO) { 版本检查.查(当前版本) }
            if (信息.出错.isNotBlank()) return@launch
            版本.value = 信息
            if (信息.有新版 && 信息.强制) 显强制更新.value = true
        }
    }
    fun 清提示() { 提示.value = "" }
    override fun onCleared() {
        流.停()
        super.onCleared()
    }
    fun 取服务器列表() {
        viewModelScope.launch {
            val 列表 = withContext(Dispatchers.IO) { 仓库.取服务器列表() }
            服务器表.clear()
            服务器表.addAll(列表)
        }
    }
    fun 保存服务器(表单: SSHHostForm) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.保存服务器(表单) }
            结果.onSuccess { 取服务器列表() }
                 .onFailure { e -> 提示.value = e.message ?: "保存服务器失败" }
        }
    }
    fun 删服务器(id: String) {
        viewModelScope.launch {
            val 成功 = withContext(Dispatchers.IO) { 仓库.删服务器(id) }
            if (成功) 取服务器列表() else 提示.value = "删除失败"
        }
    }
    fun 测试服务器(id: String) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.测试服务器(id) }
            结果.onSuccess { 提示.value = "连通正常: $it" }
                 .onFailure { e -> 提示.value = e.message ?: "连通失败" }
        }
    }
    // ================= 用量 =================
    fun 开用量页() {
        去(屏幕.用量)
        载入用量概览()
        载入调用明细(1)
    }
    fun 载入用量概览() {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.取用量概览() }
            结果.onSuccess { 用量数据.value = it }
                 .onFailure { e -> 提示.value = e.message ?: "加载用量失败" }
        }
    }
    fun 载入调用明细(页码: Int) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.取调用明细(页码) }
            结果.onSuccess {
                明细页码.value = 页码
                调用明细表.clear(); 调用明细表.addAll(it)
            }.onFailure { e -> 提示.value = e.message ?: "加载明细失败" }
        }
    }
    // ================= 个人资料 =================
    fun 开资料页() {
        去(屏幕.个人资料)
        载入资料()
    }
    fun 载入资料() {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.取资料概览() }
            结果.onSuccess { 资料数据.value = it }
                 .onFailure { e -> 提示.value = e.message ?: "加载资料失败" }
        }
    }
    fun 改密码(旧: String, 新: String, 新2: String) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.改密码(旧, 新, 新2) }
            结果.onSuccess { 提示.value = "密码已修改成功" }
                 .onFailure { e -> 提示.value = e.message ?: "改密码失败" }
        }
    }
    fun 改邮箱(邮箱: String) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.改邮箱(邮箱) }
            结果.onSuccess { msg ->
                提示.value = msg
                资料数据.value = 资料数据.value?.copy(邮箱 = 邮箱)
            }.onFailure { e -> 提示.value = e.message ?: "改邮箱失败" }
        }
    }
    fun 新建密钥(名称: String) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.新建密钥(名称) }
            结果.onSuccess { token ->
                刚生成的密钥.value = token
                载入资料()
            }.onFailure { e -> 提示.value = e.message ?: "新建密钥失败" }
        }
    }
    fun 删密钥(密钥id: Int) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.删密钥(密钥id) }
            结果.onSuccess { 载入资料() }
                 .onFailure { e -> 提示.value = e.message ?: "删除密钥失败" }
        }
    }
    fun 重置密钥(密钥id: Int) {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.重置密钥(密钥id) }
            结果.onSuccess { token ->
                刚生成的密钥.value = token
                载入资料()
            }.onFailure { e -> 提示.value = e.message ?: "重置密钥失败" }
        }
    }
    fun 清刚生成的密钥() { 刚生成的密钥.value = null }
    // ================= 充值 =================
    fun 开充值页() {
        去(屏幕.充值)
        载入充值选项()
    }
    fun 载入充值选项() {
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.取充值选项() }
            结果.onSuccess { 选项 ->
                充值面额表.clear()
                充值面额表.addAll(选项.面额表)
                自定义金额开.value = 选项.自定义开
                自定义金额下限.value = 选项.自定义下限
                自定义金额上限.value = 选项.自定义上限
            }.onFailure { e -> 提示.value = e.message ?: "加载充值面额失败" }
        }
    }
    fun 充值下单(到账金额: String) {
        充值中.value = true
        二维码字节.value = null
        viewModelScope.launch {
            val 结果 = withContext(Dispatchers.IO) { 仓库.充值下单(到账金额, 扫码 = true) }
            充值中.value = false
            结果.onSuccess { 订单 ->
                当前订单.value = 订单
                if (订单.类型 == "qr") {
                    载入充值二维码(订单.订单号)
                }
                开始轮询充值单(订单.订单号)
            }.onFailure { e -> 提示.value = e.message ?: "下单失败" }
        }
    }
    private fun 载入充值二维码(订单号: String) {
        viewModelScope.launch {
            val 字节 = withContext(Dispatchers.IO) { 仓库.取充值二维码(订单号) }
            二维码字节.value = 字节
        }
    }
    /** 查一次订单状态，paid 时刷新余额、停轮询、清订单 */
    fun 查充值单(订单号: String, 手动: Boolean = false, 到账回调: () -> Unit) {
        viewModelScope.launch {
            // 手动点「立即查询」时给出进行中的反馈，否则网络稍慢就像按钮没反应
            if (手动) {
                查单中.value = true
                提示.value = "正在查询…"
            }
            val 结果 = withContext(Dispatchers.IO) { 仓库.查充值单(订单号) }
            if (手动) 查单中.value = false
            结果.onSuccess { (状态, 新余额) ->
                if (状态 == "paid") {
                    if (新余额 != null) 我的.value = 我的.value.copy(余额 = 新余额)
                    停轮询充值单()
                    当前订单.value = null
                    二维码字节.value = null
                    提示.value = "充值成功"
                    到账回调()
                } else if (手动) {
                    // 后台轮询保持安静，手动查询必须告诉用户结果
                    提示.value = "还没查到支付记录，付款后稍等几秒再试"
                }
            }.onFailure { e -> 提示.value = e.message ?: "查询订单失败" }
        }
    }
    /** 下单后自动轮询，每 3 秒查一次，付完款不用手动点查询 */
    private fun 开始轮询充值单(订单号: String) {
        轮询任务?.cancel()
        轮询任务 = viewModelScope.launch {
            while (true) {
                kotlinx.coroutines.delay(3000)
                查充值单(订单号) {}
            }
        }
    }
    fun 停轮询充值单() {
        轮询任务?.cancel()
        轮询任务 = null
    }
    /** 关闭充值页时清掉订单相关状态，别把上一单的码带进下一次 */
    /**
     * 离开充值页。先停轮询再走，不然离开后还在后台查单。
     * 去向交给返回栈决定：用户可能是从对话页进来的，也可能是从
     * 别的二级页进来的，一律跳回对话页会吞掉中间那层。
     */
    fun 关充值页() {
        停轮询充值单()
        当前订单.value = null
        二维码字节.value = null
        if (!按返回()) {
            当前屏.value = 屏幕.对话
            提示.value = ""
        }
    }
    
    /**
     * 保存工具开关到服务器
     */
    fun 保存工具开关(工具映射: Map<String, Int>) {
        viewModelScope.launch {
            提示.value = "正在保存..."
            val 结果 = withContext(Dispatchers.IO) {
                仓库.保存工具开关(工具映射)
            }
            结果.onSuccess {
                // 更新本地状态
                我的.value = 我的.value.copy(
                    tool_ssh_exec = 工具映射["ssh_exec"] ?: 1,
                    tool_sftp_read = 工具映射["sftp_read"] ?: 1,
                    tool_sftp_write = 工具映射["sftp_write"] ?: 1,
                    tool_sftp_list = 工具映射["sftp_list"] ?: 1,
                    tool_sftp_delete = 工具映射["sftp_delete"] ?: 1,
                    tool_sftp_patch = 工具映射["sftp_patch"] ?: 1,
                    tool_file_list = 工具映射["file_list"] ?: 1,
                    tool_file_read = 工具映射["file_read"] ?: 1,
                    tool_file_write = 工具映射["file_write"] ?: 1,
                    tool_file_delete = 工具映射["file_delete"] ?: 1,
                    tool_file_push = 工具映射["file_push"] ?: 1,
                    tool_file_patch = 工具映射["file_patch"] ?: 1,
                    tool_ws_list = 工具映射["ws_list"] ?: 1,
                    tool_ws_read = 工具映射["ws_read"] ?: 1,
                    tool_ws_write = 工具映射["ws_write"] ?: 1,
                    tool_ws_delete = 工具映射["ws_delete"] ?: 1,
                    tool_ws_patch = 工具映射["ws_patch"] ?: 1,
                    tool_ws_zip = 工具映射["ws_zip"] ?: 1,
                    tool_web_open = 工具映射["web_open"] ?: 1,
                    tool_web_search = 工具映射["web_search"] ?: 1,
                    tool_ppt_generate = 工具映射["ppt_generate"] ?: 1
                )
                提示.value = "保存成功"
            }.onFailure { e ->
                提示.value = e.message ?: "保存失败"
            }
        }
    }

    /**
     * 设置当前会话的上下文条数并落库（会话级设置）。
     * 0 = 跟随模型默认，2..60 = 自定义。
     */
    fun 设上下文条数(条数: Int) {
        val 会 = 当前会话.value ?: return
        if (条数 != 0 && 条数 !in 2..60) {
            提示.value = "上下文条数请填 2 到 60，或恢复默认"
            return
        }
        viewModelScope.launch {
            val 成 = withContext(Dispatchers.IO) { 仓库.设上下文条数(会.id, 条数) }
            if (成) {
                当前会话.value = 会.copy(上下文条数 = 条数)
                提示.value = if (条数 == 0) "上下文已恢复模型默认" else "上下文条数已设为 $条数"
            } else {
                提示.value = "上下文设置保存失败"
            }
        }
    }
}
