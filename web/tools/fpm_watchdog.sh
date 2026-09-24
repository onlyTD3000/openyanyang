#!/bin/bash
# ==============================================================================
# php-fpm 探活自愈（root，cron 每分钟）
#
# 为什么需要它：
#   php-fpm 用 unix socket 时，如果短时间内被连续 reload（例如通过宝塔改
#   php.ini，面板会在写完配置后自动 reload，而 php.ini 与 php-cli.ini 是分两
#   次写入、各触发一轮），新实例会继承旧 socket fd 并报
#   「Another FPM instance seems to already listen on ...」后初始化失败。
#   结果是「假活」：socket 还在监听、systemd 仍报 active，但没有可用 worker，
#   所有请求被 refuse，nginx 一律返回 502。
#
#   这就是 2026-08-10 02:26~02:27 那次 502 的成因。因此本脚本刻意不看
#   systemctl is-active —— 那次它全程都是 active。只认真实请求的结果。
#
# 设计要点：
#   1. 真实探活。用 curl 打本机 nginx，看是否还能拿到 PHP 产出的响应。
#   2. 连续失败才动手。避免偶发超时导致误重启。
#   3. 冷却期。两次自愈至少间隔 COOLDOWN 秒，防止起不来时反复重启拖垮机器。
#   4. 修复用 stop→清残留 sock→start，不用 reload（reload 正是病根）。
#   5. 单实例锁 + 全程留痕。
#
# 版本 1.0.0
# ==============================================================================
SCRIPT_VER='1.0.0'
PHP_VER='85'
SERVICE="php-fpm-${PHP_VER}"
SOCK="/tmp/php-cgi-${PHP_VER}.sock"
# 探活地址：本机 nginx 上的一个必然由 PHP 处理的入口。
# 200/301/302/4xx 都算健康——只要不是 502/503/504 或连不上，就说明 PHP 在干活。
PROBE_URL='http://127.0.0.1:8800/chat.php'
PROBE_TIMEOUT=10
FAIL_THRESHOLD=2      # 连续失败几次才自愈
COOLDOWN=300          # 两次自愈的最小间隔（秒）

STATE='/var/lib/fpm_watchdog'
LOCK="$STATE/lock"
FAILFILE="$STATE/fail_count"
LASTFIX="$STATE/last_fix"
LOG="$STATE/watchdog.log"

export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

mkdir -p "$STATE"
chmod 700 "$STATE"

say() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG"
}

# 单实例锁：上一次还没跑完（例如正在重启服务）就直接退出
exec 9>"$LOCK"
if ! flock -n 9; then
    exit 0
fi

# ------------------------------------------------------------------------------
# 探活
# ------------------------------------------------------------------------------
CODE="$(curl -s -o /dev/null -w '%{http_code}' -m "$PROBE_TIMEOUT" "$PROBE_URL" 2>/dev/null)"

alive=1
case "$CODE" in
    502|503|504|000|'') alive=0 ;;
esac

if [ "$alive" = "1" ]; then
    # 恢复了就清零计数。之前若有失败记录，补一条恢复日志便于回溯。
    PREV="$(cat "$FAILFILE" 2>/dev/null || echo 0)"
    if [ "$PREV" -gt 0 ] 2>/dev/null; then
        say "探活恢复正常（HTTP $CODE），清零失败计数（原 $PREV）"
    fi
    echo 0 > "$FAILFILE"
    exit 0
fi

# ------------------------------------------------------------------------------
# 失败累计
# ------------------------------------------------------------------------------
FAILS="$(cat "$FAILFILE" 2>/dev/null || echo 0)"
[ -z "$FAILS" ] && FAILS=0
FAILS=$((FAILS + 1))
echo "$FAILS" > "$FAILFILE"
say "探活失败（HTTP ${CODE:-无响应}），连续第 $FAILS 次，阈值 $FAIL_THRESHOLD"

if [ "$FAILS" -lt "$FAIL_THRESHOLD" ]; then
    exit 0
fi

# ------------------------------------------------------------------------------
# 冷却期检查：防止 fpm 起不来时每分钟重启一次
# ------------------------------------------------------------------------------
NOW="$(date +%s)"
LAST="$(cat "$LASTFIX" 2>/dev/null || echo 0)"
[ -z "$LAST" ] && LAST=0
DIFF=$((NOW - LAST))
if [ "$DIFF" -lt "$COOLDOWN" ]; then
    say "仍不健康，但距上次自愈仅 ${DIFF}s（冷却 ${COOLDOWN}s），本次跳过。可能需要人工介入。"
    exit 0
fi

# ------------------------------------------------------------------------------
# 自愈：stop → 清残留 sock → start
# 不用 reload / restart：reload 是病根；restart 在个别情况下也会走继承 socket
# 的路径，stop 后确认 sock 消失再 start 最干净。
# ------------------------------------------------------------------------------
say "开始自愈 $SERVICE（连续失败 $FAILS 次）"
echo "$NOW" > "$LASTFIX"

/etc/rc.d/init.d/php-fpm-${PHP_VER} stop >/dev/null 2>&1
sleep 3

# 停止后 socket 文件仍残留则手动清掉，否则新实例又会撞「已被监听」
if [ -S "$SOCK" ]; then
    if ss -xl 2>/dev/null | grep -q "$SOCK"; then
        say "警告：stop 后 $SOCK 仍在被监听，可能有残留进程，尝试继续"
    else
        rm -f "$SOCK"
        say "已清理残留 socket 文件 $SOCK"
    fi
fi

/etc/rc.d/init.d/php-fpm-${PHP_VER} start >/dev/null 2>&1
sleep 4

# 复检
CODE2="$(curl -s -o /dev/null -w '%{http_code}' -m "$PROBE_TIMEOUT" "$PROBE_URL" 2>/dev/null)"
case "$CODE2" in
    502|503|504|000|'')
        say "自愈后仍不健康（HTTP ${CODE2:-无响应}），需人工排查：journalctl -u $SERVICE 与 /www/server/php/$PHP_VER/var/log/php-fpm.log"
        ;;
    *)
        say "自愈成功，探活恢复（HTTP $CODE2）"
        echo 0 > "$FAILFILE"
        ;;
esac

# 日志超过 2MB 截断保留后半，避免无限增长
if [ -f "$LOG" ] && [ "$(stat -c%s "$LOG" 2>/dev/null || echo 0)" -gt 2097152 ]; then
    tail -c 1048576 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

exit 0
