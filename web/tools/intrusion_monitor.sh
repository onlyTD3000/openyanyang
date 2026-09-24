#!/bin/bash
# ==============================================================================
# 入侵监控检测脚本（root，cron 每分钟）
#
# 设计要点：
#   1. 不连数据库。所有读写走 tools/intrusion_cli.php，凭据只留在 inc/config.php。
#   2. 日志增量读取。记录字节偏移，只处理新增内容，不重复扫全量。
#   3. 滑动窗口计数。状态存在 /var/lib/intrusion_monitor，重启不丢。
#   4. 自我保护。当前 SSH 连入的 IP 和本机地址绝不封禁，避免把自己关在门外。
#   5. 封禁走 firewalld ipset，不直接写 iptables，跟宝塔面板的规则和平共处。
#
# 版本 1.0.0
# ==============================================================================
SCRIPT_VER='1.0.0'
SITE_DIR='/www/wwwroot/code.77bot.cn'
CLI="$SITE_DIR/tools/intrusion_cli.php"
PHP='/usr/bin/php'
STATE='/var/lib/intrusion_monitor'
IPSET_NAME='intr_block'
LOCK="$STATE/lock"
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
mkdir -p "$STATE"
chmod 700 "$STATE"
# 汇总本次运行的动作，最后写进心跳，后台页面能看到「上次跑了什么」
SUMMARY=''
note() { SUMMARY="${SUMMARY}$1；"; }
# ------------------------------------------------------------------------------
# 单实例锁。上一次还没跑完就直接退出，避免扫描任务叠在一起把机器拖垮
# ------------------------------------------------------------------------------
exec 9>"$LOCK"
if ! flock -n 9; then
    exit 0
fi
# ------------------------------------------------------------------------------
# 读取后台配置
# ------------------------------------------------------------------------------
CONF_OUT="$($PHP "$CLI" conf 2>/dev/null)"
if [ -z "$CONF_OUT" ]; then
    exit 1
fi
eval "$CONF_OUT"
# 总开关关闭时，只写心跳不做任何检测，方便后台看出「脚本活着但被关了」
if [ "$INTR_ENABLED" != "1" ]; then
    $PHP "$CLI" heartbeat --state=off --msg='总开关关闭，未执行检测' \
        --ver="$SCRIPT_VER" >/dev/null 2>&1
    exit 0
fi
# ------------------------------------------------------------------------------
# 自我保护名单：本机所有 IP + 当前正连着 SSH 的对端 IP
# 这一步在封禁之前跑，宁可漏封也不能把管理员自己挡在外面
# ------------------------------------------------------------------------------
SELF_IPS="$(
    { ip -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1
      who 2>/dev/null | sed -n 's/.*(\([0-9.]\{7,\}\)).*/\1/p'
      ss -tn state established 2>/dev/null | awk 'NR>1{print $5}' | sed 's/:[0-9]*$//' | tr -d '[]'
    } | sort -u
)"
is_self() {
    printf '%s\n' "$SELF_IPS" | grep -qxF "$1"
}
# ------------------------------------------------------------------------------
# 防火墙：firewalld + ipset
# 用 ipset 而不是逐条 rich rule，是因为封禁数量上千时 ipset 匹配是常数开销
# ------------------------------------------------------------------------------
FW_MODE='none'
if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    FW_MODE='firewalld'
elif command -v iptables >/dev/null 2>&1; then
    FW_MODE='iptables'
fi
fw_init() {
    [ "$FW_MODE" = 'firewalld' ] || return 0
    # ipset 不存在则创建，并挂一条 drop 规则。只在首次执行，之后是空操作
    if ! firewall-cmd --permanent --info-ipset="$IPSET_NAME" >/dev/null 2>&1; then
        firewall-cmd --permanent --new-ipset="$IPSET_NAME" \
            --type=hash:ip --option=maxelem=65536 >/dev/null 2>&1
        firewall-cmd --permanent \
            --add-rich-rule="rule source ipset=$IPSET_NAME drop" >/dev/null 2>&1
        firewall-cmd --reload >/dev/null 2>&1
        note "已初始化 ipset $IPSET_NAME"
    fi
    # 规则可能被面板 reload 冲掉，每次补一次（重复添加会返回 ALREADY_ENABLED，无害）
    firewall-cmd --permanent \
        --add-rich-rule="rule source ipset=$IPSET_NAME drop" >/dev/null 2>&1
}
fw_block() {
    local ip="$1"
    case "$FW_MODE" in
        firewalld)
            firewall-cmd --ipset="$IPSET_NAME" --add-entry="$ip" >/dev/null 2>&1
            firewall-cmd --permanent --ipset="$IPSET_NAME" --add-entry="$ip" >/dev/null 2>&1
            ;;
        iptables)
            iptables -C INPUT -s "$ip" -j DROP 2>/dev/null \
                || iptables -I INPUT 1 -s "$ip" -j DROP 2>/dev/null
            ;;
        *) return 1 ;;
    esac
}
fw_unblock() {
    local ip="$1"
    case "$FW_MODE" in
        firewalld)
            firewall-cmd --ipset="$IPSET_NAME" --remove-entry="$ip" >/dev/null 2>&1
            firewall-cmd --permanent --ipset="$IPSET_NAME" --remove-entry="$ip" >/dev/null 2>&1
            ;;
        iptables)
            while iptables -C INPUT -s "$ip" -j DROP 2>/dev/null; do
                iptables -D INPUT -s "$ip" -j DROP 2>/dev/null || break
            done
            ;;
        *) return 1 ;;
    esac
}
[ "$INTR_BLOCK" = '1' ] && fw_init
# ------------------------------------------------------------------------------
# 上报事件。--want-block 只是「建议封」，最终由 PHP 侧按配置和白名单裁决
# ------------------------------------------------------------------------------
report() {
    local etype="$1" level="$2" ip="$3" target="$4" hits="$5" detail="$6" want="$7"
    if [ -n "$ip" ] && is_self "$ip"; then
        want=0   # 自己的地址只记录不封
    fi
    local out
    out="$($PHP "$CLI" event \
        --etype="$etype" --level="$level" --ip="$ip" \
        --target="$target" --hits="$hits" --detail="$detail" \
        --want-block="$want" 2>/dev/null)"
    # PHP 说要封，这里才真的动防火墙
    if printf '%s' "$out" | grep -q '^block=1'; then
        if fw_block "$ip"; then
            local bid
            bid="$($PHP "$CLI" queue 2>/dev/null | awk -F'|' -v i="$ip" '$2==i{print $4; exit}')"
            [ -n "$bid" ] && $PHP "$CLI" queue-done --id="$bid" --ok=1 --act=block >/dev/null 2>&1
            note "封禁 $ip（$etype）"
        else
            note "封禁 $ip 失败：防火墙不可用"
        fi
    fi
}
# ------------------------------------------------------------------------------
# 日志增量读取：把上次读到的字节位置存起来，只取新增部分
# 文件变小视为已轮转，从头开始读
# ------------------------------------------------------------------------------
read_new() {
    local file="$1" tag="$2"
    local offfile="$STATE/off_$tag"
    [ -r "$file" ] || return 1
    local size prev
    size="$(stat -c %s "$file" 2>/dev/null || echo 0)"
    prev=0
    [ -f "$offfile" ] && prev="$(cat "$offfile" 2>/dev/null || echo 0)"
    case "$prev" in ''|*[!0-9]*) prev=0 ;; esac
    # 偏移量校验：如果大于文件大小，说明状态损坏，重置为 0
    if [ "$prev" -gt "$size" ]; then
        prev=0
    fi
    if [ "$size" -lt "$prev" ]; then
        prev=0          # 轮转了
    fi
    echo "$size" > "$offfile"
    # 首次运行只记位置不读内容，否则会把历史日志里几万条旧记录当成新事件全报一遍
    if [ "$prev" -eq 0 ] && [ ! -s "$offfile.init" ]; then
        touch "$offfile.init"
        return 1
    fi
    [ "$size" -gt "$prev" ] || return 1
    tail -c +"$((prev + 1))" "$file" 2>/dev/null
}
# ------------------------------------------------------------------------------
# 滑动窗口计数：把 (时间戳 IP) 追加进状态文件，清掉过期行，再按 IP 汇总
# 返回超过阈值的 IP 和它的命中数
# ------------------------------------------------------------------------------
count_window() {
    local tag="$1" win="$2" max="$3" now
    local cfile="$STATE/cnt_$tag"
    now="$(date +%s)"
    # 输入是一行一个 IP
    awk -v t="$now" '{print t" "$0}' >> "$cfile"
    # 清理过期
    awk -v cut="$((now - win))" '$1 >= cut' "$cfile" > "$cfile.tmp" 2>/dev/null \
        && mv -f "$cfile.tmp" "$cfile"
    # 汇总，输出 "IP 次数"
    awk -v m="$max" '{c[$2]++} END{for(i in c) if(c[i] >= m) print i, c[i]}' "$cfile"
}
# 触发后清掉该 IP 的计数，避免同一波攻击每分钟重复上报
clear_ip_count() {
    local tag="$1" ip="$2"
    local cfile="$STATE/cnt_$tag"
    [ -f "$cfile" ] || return 0
    awk -v i="$ip" '$2 != i' "$cfile" > "$cfile.tmp" 2>/dev/null && mv -f "$cfile.tmp" "$cfile"
}
# 节流：某项检测多久跑一次（分钟）
should_run() {
    local tag="$1" mins="$2" now last
    local f="$STATE/ts_$tag"
    now="$(date +%s)"
    last=0
    [ -f "$f" ] && last="$(cat "$f" 2>/dev/null || echo 0)"
    case "$last" in ''|*[!0-9]*) last=0 ;; esac
    if [ "$((now - last))" -ge "$((mins * 60))" ]; then
        echo "$now" > "$f"
        return 0
    fi
    return 1
}
# ==============================================================================
# 检测一：SSH 暴力破解
# ==============================================================================
if [ "$INTR_SSH" = '1' ]; then
    NEW="$(read_new "$INTR_SSH_LOG" ssh)"
    if [ -n "$NEW" ]; then
        # 失败登录的几种日志措辞，一并覆盖
        FAILED="$(printf '%s\n' "$NEW" | grep -Ei 'Failed password|Invalid user|authentication failure|Failed publickey|maximum authentication attempts' \
            | grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | sort)"
        if [ -n "$FAILED" ]; then
            printf '%s\n' "$FAILED" | count_window ssh_fail "$INTR_SSH_WIN" "$INTR_SSH_MAX" \
            | while read -r bip bcnt; do
                [ -z "$bip" ] && continue
                report ssh_brute high "$bip" 'SSH' "$bcnt" \
                    "${INTR_SSH_WIN} 秒内 SSH 登录失败 ${bcnt} 次，超过阈值 ${INTR_SSH_MAX}" 1
                clear_ip_count ssh_fail "$bip"
            done
        fi
        # 登录成功的陌生 IP。不封禁——成功登录很可能就是管理员本人，
        # 封了反而添乱，只报警让人自己判断
        if [ "$INTR_SSH_NEWIP" = '1' ]; then
            KNOWN="$STATE/known_ssh_ip"
            touch "$KNOWN"
            printf '%s\n' "$NEW" | grep -E 'Accepted (password|publickey|keyboard-interactive)' \
                | grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | sort -u \
            | while read -r aip; do
                [ -z "$aip" ] && continue
                if ! grep -qxF "$aip" "$KNOWN"; then
                    echo "$aip" >> "$KNOWN"
                    report ssh_newip high "$aip" 'SSH 登录成功' 1 \
                        "首次出现的 IP 成功登录 SSH。若不是本人操作，请立即改密码并检查 authorized_keys" 0
                fi
            done
        fi
    fi
fi
# ==============================================================================
# 检测二：Web 日志（高频探测 + 攻击特征）
# ==============================================================================
if [ "$INTR_WEB" = '1' ]; then
    WNEW="$(read_new "$INTR_WEB_LOG" web)"
    if [ -n "$WNEW" ]; then
        # 只统计 4xx/5xx。正常用户不会一直撞错误码，扫描器会
        printf '%s\n' "$WNEW" \
            | awk '{ for(i=1;i<=NF;i++) if($i ~ /^"(GET|POST|HEAD|PUT|DELETE|OPTIONS)$/) { s=$(i+3); if(s+0>=400) print $1; break } }' \
            | grep -oE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' | sort \
            | count_window web_err "$INTR_WEB_WIN" "$INTR_WEB_MAX" \
        | while read -r wip wcnt; do
            [ -z "$wip" ] && continue
            report web_flood mid "$wip" 'Web 高频探测' "$wcnt" \
                "${INTR_WEB_WIN} 秒内产生 ${wcnt} 次 4xx/5xx 请求，疑似目录扫描或漏洞探测" 1
            clear_ip_count web_err "$wip"
        done
        # 攻击特征：命中即封，这类请求没有正常业务场景
        if [ "$INTR_WEB_RULE" = '1' ]; then
            printf '%s\n' "$WNEW" | grep -Ei \
                '(\.\./\.\./|/etc/passwd|union[+ ]+select|select.+from.+information_schema|<script>|onerror=|base64_decode|/bin/(ba)?sh|wget[+ ]+http|curl[+ ]+http|\.env|/\.git/config|phpunit.*eval|ThinkPHP.*invokefunction|xmlrpc\.php|/shell\?|eval\(|system\(|passthru\()' \
                2>/dev/null | head -50 \
            | while read -r line; do
                aip="$(printf '%s' "$line" | grep -oE '^([0-9]{1,3}\.){3}[0-9]{1,3}')"
                [ -z "$aip" ] && continue
                req="$(printf '%s' "$line" | grep -oE '"[A-Z]+ [^"]{0,180}' | head -1)"
                report web_attack high "$aip" "$req" 1 \
                    "Web 请求命中攻击特征：$req" 1
            done
        fi
    fi
fi
# ==============================================================================
# 检测三：可疑 PHP 文件（webshell 特征）
# 全站扫一遍很贵，所以按 intr_scan_min 节流，且只看最近修改过的文件
# ==============================================================================
if [ "$INTR_FILE" = '1' ] && should_run scan "$INTR_SCAN_MIN"; then
    if [ -d "$INTR_SCAN_DIR" ]; then
        # 排除目录关键词转成 grep 模式
        EXPAT="$(printf '%s' "$INTR_SCAN_EXCLUDE" | tr ',' '\n' | sed '/^$/d' | paste -sd'|' -)"
        [ -z "$EXPAT" ] && EXPAT='__nothing__'
        SEEN="$STATE/seen_php"
        touch "$SEEN"
        find "$INTR_SCAN_DIR" -type f -name '*.php' \
            -newermt "-$((INTR_SCAN_MIN + 2)) minutes" 2>/dev/null \
            | grep -Ev "$EXPAT" \
        | while read -r f; do
            [ -f "$f" ] || continue
            # 典型一句话木马特征：动态执行 + 编码解码 + 变量函数调用
            HIT="$(grep -oEi '(eval[[:space:]]*\(|assert[[:space:]]*\(|\$_(GET|POST|REQUEST|COOKIE)[[:space:]]*\[[^]]*\][[:space:]]*\(|base64_decode[[:space:]]*\([[:space:]]*\$_|gzinflate[[:space:]]*\(|str_rot13[[:space:]]*\(|create_function|preg_replace[[:space:]]*\(.*/e|call_user_func[[:space:]]*\([[:space:]]*\$_)' "$f" 2>/dev/null | sort -u | head -4 | paste -sd' ' -)"
            if [ -n "$HIT" ]; then
                SIG="$(md5sum "$f" 2>/dev/null | awk '{print $1}')"
                KEY="$SIG $f"
                # 同一文件内容只报一次，改动了才再报
                if ! grep -qxF "$KEY" "$SEEN"; then
                    echo "$KEY" >> "$SEEN"
                    report webshell high '' "${f#$INTR_SCAN_DIR/}" 1 \
                        "文件含可疑动态执行特征：$HIT
路径：$f
修改时间：$(stat -c %y "$f" 2>/dev/null)
属主：$(stat -c %U:%G "$f" 2>/dev/null)" 0
                fi
            fi
        done
    fi
fi
# ==============================================================================
# 检测四：新增 SUID 文件（提权后门的常见落脚点）
# ==============================================================================
if [ "$INTR_SUID" = '1' ] && should_run suid "$INTR_SUID_MIN"; then
    BASE="$STATE/base_suid"
    CUR="$STATE/cur_suid"
    find / -xdev \( -path /proc -o -path /sys -o -path /www/wwwroot -o -path /var/lib/docker \) -prune -o \
        -type f -perm -4000 -print 2>/dev/null | sort > "$CUR"
    if [ ! -f "$BASE" ]; then
        cp -f "$CUR" "$BASE"      # 首次建立基线，不报警
        note '已建立 SUID 基线'
    else
        NEWSUID="$(comm -13 "$BASE" "$CUR" | head -20)"
        if [ -n "$NEWSUID" ]; then
            report suid high '' 'SUID 文件' "$(printf '%s\n' "$NEWSUID" | wc -l)" \
                "检测到新增的 SUID 文件，这类文件能以属主权限运行，常被用来留提权后门：
$NEWSUID
确认无害后可在服务器执行下面这行把它并入基线：
cp -f $CUR $BASE" 0
            cp -f "$CUR" "$BASE"   # 已报过就并入基线，避免每小时重复报同一批
        fi
    fi
fi
# ==============================================================================
# 检测五：计划任务改动（后门持久化的第一选择）
# ==============================================================================
if [ "$INTR_CRON" = '1' ]; then
    CRONSNAP="$STATE/base_cron"
    CRONCUR="$(
        { crontab -l 2>/dev/null
          cat /etc/crontab 2>/dev/null
          find /etc/cron.d /etc/cron.hourly /etc/cron.daily -type f 2>/dev/null \
            | sort | xargs -r cat 2>/dev/null
        } | md5sum | awk '{print $1}'
    )"
    if [ ! -f "$CRONSNAP" ]; then
        echo "$CRONCUR" > "$CRONSNAP"
    else
        OLD="$(cat "$CRONSNAP" 2>/dev/null)"
        if [ "$OLD" != "$CRONCUR" ]; then
            echo "$CRONCUR" > "$CRONSNAP"
            report cron mid '' '计划任务' 1 \
                "root 的 crontab 或 /etc/cron.* 内容发生变化。
若不是你本人改的，请立即执行 crontab -l 和 ls -la /etc/cron.d 检查是否被写入了后门任务。
当前 root crontab：
$(crontab -l 2>/dev/null | head -30)" 0
        fi
    fi
fi
# ==============================================================================
# 检测六：可疑进程
# 判据是「路径可疑」而不是单看 CPU 高——正常业务也会吃满 CPU
# ==============================================================================
if [ "$INTR_PROC" = '1' ]; then
    SUSP="$(ps -eo pid,pcpu,user,comm,args --no-headers 2>/dev/null \
        | awk -v c="$INTR_PROC_CPU" '
            {
                cpu = $2 + 0
                line = $0
                bad = 0
                # 从 /tmp、/dev/shm、/var/tmp 里启动的程序，正常服务不会这么干
                if (line ~ /\/(tmp|dev\/shm|var\/tmp)\/[^ ]*/) bad = 1
                # 挖矿程序的典型特征
                if (line ~ /(xmrig|minerd|cryptonight|stratum\+tcp|nicehash|cpuminer|kdevtmpfsi|kinsing)/) bad = 1
                # 明文反弹 shell
                if (line ~ /(bash -i|nc .*-e|\/dev\/tcp\/)/) bad = 1
                if (bad && cpu >= 0) print
            }' | head -10)"
    if [ -n "$SUSP" ]; then
        PROCSNAP="$STATE/seen_proc"
        touch "$PROCSNAP"
        SIG="$(printf '%s' "$SUSP" | awk '{print $4}' | sort -u | md5sum | awk '{print $1}')"
        if ! grep -qxF "$SIG" "$PROCSNAP"; then
            echo "$SIG" >> "$PROCSNAP"
            report proc high '' '可疑进程' "$(printf '%s\n' "$SUSP" | wc -l)" \
                "发现路径或名称可疑的运行中进程：
$SUSP
请用 ls -la /proc/<PID>/exe 确认程序真实路径再决定是否 kill。" 0
        fi
    fi
fi
# ==============================================================================
# 检测七：系统关键文件改动
# ==============================================================================
if [ "$INTR_SYSFILE" = '1' ]; then
    for target in /etc/passwd /etc/shadow /etc/sudoers /root/.ssh/authorized_keys /etc/ssh/sshd_config; do
        [ -r "$target" ] || continue
        key="$(printf '%s' "$target" | tr '/.' '__')"
        snap="$STATE/base_file$key"
        cur="$(md5sum "$target" 2>/dev/null | awk '{print $1}')"
        if [ ! -f "$snap" ]; then
            echo "$cur" > "$snap"
            continue
        fi
        old="$(cat "$snap" 2>/dev/null)"
        if [ "$old" != "$cur" ]; then
            echo "$cur" > "$snap"
            extra=''
            case "$target" in
                /etc/passwd)
                    extra="当前 UID 0 的账号：$(awk -F: '$3==0{print $1}' /etc/passwd | paste -sd' ' -)" ;;
                /root/.ssh/authorized_keys)
                    extra="当前公钥数量：$(grep -c . "$target" 2>/dev/null)" ;;
            esac
            report sysfile high '' "$target" 1 \
                "系统关键文件内容发生变化：$target
$extra
若不是你本人改的，这通常意味着已经被拿到 root 权限，请立即断网排查。" 0
        fi
    done
fi
# ==============================================================================
# 消费后台下发的封禁/解封队列（管理员在页面上点的操作在这里落地）
# ==============================================================================
$PHP "$CLI" queue 2>/dev/null | while IFS='|' read -r act qip qexp qid; do
    [ -z "$qip" ] && continue
    case "$act" in
        block)
            if is_self "$qip"; then
                $PHP "$CLI" queue-done --id="$qid" --ok=0 --act=block \
                    --err='该地址是本机或当前 SSH 来源，已跳过' >/dev/null 2>&1
                continue
            fi
            if fw_block "$qip"; then
                $PHP "$CLI" queue-done --id="$qid" --ok=1 --act=block >/dev/null 2>&1
                note "队列封禁 $qip"
            else
                $PHP "$CLI" queue-done --id="$qid" --ok=0 --act=block \
                    --err="防火墙操作失败（模式：$FW_MODE）" >/dev/null 2>&1
            fi
            ;;
        unblock)
            if fw_unblock "$qip"; then
                $PHP "$CLI" queue-done --id="$qid" --ok=1 --act=unblock >/dev/null 2>&1
                note "队列解封 $qip"
            else
                $PHP "$CLI" queue-done --id="$qid" --ok=0 --act=unblock \
                    --err="防火墙操作失败（模式：$FW_MODE）" >/dev/null 2>&1
            fi
            ;;
    esac
done
# ==============================================================================
# 到期自动解封
# ==============================================================================
$PHP "$CLI" expired 2>/dev/null | while IFS='|' read -r eip eid; do
    [ -z "$eip" ] && continue
    fw_unblock "$eip"
    $PHP "$CLI" queue-done --id="$eid" --ok=1 --act=unblock >/dev/null 2>&1
    note "到期解封 $eip"
done
# ------------------------------------------------------------------------------
# 写心跳。后台就是靠这个时间判断脚本有没有在跑
# ------------------------------------------------------------------------------
[ -z "$SUMMARY" ] && SUMMARY='检测完成，无异常'
$PHP "$CLI" heartbeat --state=ok --msg="$SUMMARY" \
    --ver="$SCRIPT_VER" --fw="$FW_MODE" >/dev/null 2>&1
exit 0
