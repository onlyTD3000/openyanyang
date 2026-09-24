#!/bin/bash
# ==============================================================================
# maldet 恶意代码扫描（专抓 webshell）
#   用法：maldet_scan.sh [web|全路径]
#     web        扫 /www/wwwroot（默认）
#     /some/dir  扫指定目录
#   命中只报不隔离（conf.maldet 里 quarantine_hits=0），由人工确认后处理
# ==============================================================================
set -uo pipefail
ROOT='/www/wwwroot/code.77bot.cn'
CLI="php $ROOT/tools/intrusion_cli.php"
MALDET='/usr/local/sbin/maldet'
LOGDIR='/var/log/maldet_scan'
LOCK='/usr/local/maldetect/scan.lock'
ARG="${1:-web}"
mkdir -p "$LOGDIR"
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "已有 maldet 在运行，本次跳过"
    exit 0
fi
case "$ARG" in
    web) TARGET='/www/wwwroot' ;;
    /*)  TARGET="$ARG" ;;
    *)   echo "参数应为 web 或以 / 开头的绝对路径"; exit 2 ;;
esac
if [ ! -d "$TARGET" ]; then
    echo "目录不存在：$TARGET"
    exit 2
fi
TS="$(date +%Y%m%d_%H%M%S)"
LOG="$LOGDIR/scan_${TS}.log"
START=$(date +%s)
# ---------- 更新特征库 ----------
$MALDET -u >>"$LOG" 2>&1
SIGS=$(grep -oE '[0-9]+ signatures' "$LOG" 2>/dev/null | tail -1 | awk '{print $1}')
if [ -z "$SIGS" ]; then
    SIGS=$(grep -cE '.' /usr/local/maldetect/sigs/rfxn.hdb 2>/dev/null | head -1)
fi
[ -z "$SIGS" ] && SIGS='未知'
SIGVER=$(cat /usr/local/maldetect/sigs/rfxn.ver 2>/dev/null | head -1)
[ -z "$SIGVER" ] && SIGVER='未知'
# ---------- 执行扫描 ----------
$MALDET -a "$TARGET" >>"$LOG" 2>&1
RC=$?
COST=$(( $(date +%s) - START ))
# 取本次扫描的 SCANID，用来读取报告
SCANID=$(grep -oE 'SCANID[: ]+[0-9.-]+' "$LOG" 2>/dev/null | tail -1 | grep -oE '[0-9]+\.[0-9]+')
[ -z "$SCANID" ] && SCANID=$(ls -t /usr/local/maldetect/sess/session.* 2>/dev/null | head -1 | sed 's/.*session\.//')
TOTAL=0
HITS=0
HIT_LIST=''
if [ -n "$SCANID" ]; then
    REPORT="/usr/local/maldetect/sess/session.$SCANID"
    if [ -r "$REPORT" ]; then
        TOTAL=$(grep -oE 'TOTAL FILES[: ]+[0-9]+' "$REPORT" 2>/dev/null | grep -oE '[0-9]+$' | head -1)
        HITS=$(grep -oE 'TOTAL HITS[: ]+[0-9]+'  "$REPORT" 2>/dev/null | grep -oE '[0-9]+$' | head -1)
        # 命中明细在 FILE HIT LIST 之后
        HIT_LIST=$(sed -n '/FILE HIT LIST/,/^$/p' "$REPORT" 2>/dev/null | grep -E '^\{' | head -20)
    fi
fi
[ -z "$TOTAL" ] && TOTAL=0
[ -z "$HITS" ]  && HITS=0
INFO="maldet 特征库 ${SIGVER}（${SIGS} 条），扫描 ${TOTAL} 个文件，耗时 ${COST} 秒"
if [ "$HITS" -gt 0 ]; then
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        # 格式形如 {HEX}base64.inject.unclassed : /path/to/file
        SIG=$(echo "$line" | sed 's/ *:.*//')
        FILE=$(echo "$line" | sed 's/.*: *//')
        $CLI event --etype=malware --level=high --target="$FILE" --hits=1 \
            --detail="maldet 检出恶意代码：${SIG}。${INFO}" >/dev/null 2>&1
    done <<< "$HIT_LIST"
    $CLI event --etype=maldet --level=high --target="$TARGET" --hits="$HITS" \
        --detail="恶意代码扫描完成：检出 ${HITS} 个可疑文件（未隔离，需人工确认）。${INFO}。详见 ${LOG}" >/dev/null 2>&1
elif [ "$RC" -eq 0 ] || [ "$TOTAL" -gt 0 ]; then
    $CLI event --etype=maldet --level=low --target="$TARGET" --hits=0 \
        --detail="恶意代码扫描完成：未发现 webshell 或恶意代码。${INFO}。" >/dev/null 2>&1
else
    $CLI event --etype=maldet --level=mid --target="$TARGET" --hits=0 \
        --detail="恶意代码扫描异常，maldet 返回码 ${RC}。详见 ${LOG}" >/dev/null 2>&1
fi
find "$LOGDIR" -name '*.log' -mtime +30 -delete 2>/dev/null
exit 0
