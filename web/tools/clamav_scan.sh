#!/bin/bash
# ==============================================================================
# ClamAV 病毒扫描
#   用法：clamav_scan.sh [full|web|quick]
#     full   全系统扫描，耗时较长，建议每周一次
#     web    只扫站点目录，建议每天一次
#     quick  只扫常见木马落地目录，几分钟完成
#   扫描结果、病毒库版本与特征数会写入后台入侵监控
# ==============================================================================
set -uo pipefail
ROOT='/www/wwwroot/code.77bot.cn'
CLI="php $ROOT/tools/intrusion_cli.php"
LOGDIR='/var/log/clamav'
STATE='/var/lib/clamav_scan'
LOCK="$STATE/scan.lock"
MODE="${1:-full}"
mkdir -p "$LOGDIR" "$STATE"
# 防重复运行：扫描很吃 IO，不能并行
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "已有扫描在运行，本次跳过"
    exit 0
fi
TS="$(date +%Y%m%d_%H%M%S)"
LOG="$LOGDIR/${MODE}_${TS}.log"
# ---------- 扫描范围 ----------
EXCLUDES=()
case "$MODE" in
    full)
        TARGETS=(/)
        EXCLUDES=(
            --exclude-dir='^/proc' --exclude-dir='^/sys'
            --exclude-dir='^/dev'  --exclude-dir='^/run'
            --exclude-dir='^/var/lib/clamav'
            --exclude-dir='^/var/log/clamav'
            --exclude-dir='^/www/server/data'
            --exclude-dir='^/www/server/panel/logs'
            # Wine 的 Windows 模拟目录：1582 个 PE 文件，ClamAV 逐个深度解析
            # 会拖慢整轮扫描几十分钟，且属软件自带文件，非威胁来源
            --exclude-dir='^/root/\.wine'
            --exclude-dir='/\.cache/'
            --exclude-dir='^/usr/local/maldetect'
            --exclude-dir='scantemp\.'
        )
        MAXOPT=(--max-filesize=100M --max-scansize=400M)
        ;;
    web)
        TARGETS=(/www/wwwroot)
        MAXOPT=(--max-filesize=50M --max-scansize=200M)
        ;;
    quick)
        # 只扫木马常见落地点，目标是几分钟内跑完。
        # 不含 /www/wwwroot：那是 web 模式的活，放进来会退化成全量扫描。
        TARGETS=(/tmp /var/tmp /dev/shm /root /home)
        EXCLUDES=(
            # Wine 的 Windows 模拟目录，全是 PE 文件，ClamAV 深度解析极慢
            # 且属于软件自带文件，不是威胁来源
            --exclude-dir='^/root/\.wine'
            --exclude-dir='^/root/\.cache'
            --exclude-dir='^/root/\.npm'
            --exclude-dir='^/root/\.local/share/Trash'
            --exclude-dir='^/tmp/systemd-private'
            # maldet 的特征样本库和扫描临时目录：里面存的就是恶意代码特征，
            # 不排除会自己扫自己，必然误报
            --exclude-dir='^/usr/local/maldetect'
            --exclude-dir='scantemp\.'
        )
        MAXOPT=(--max-filesize=20M --max-scansize=80M)
        ;;
    *)
        echo "未知模式：$MODE（可选 full / web / quick）"
        exit 2
        ;;
esac
# ---------- 病毒库信息 ----------
DB_VER='未知'
DB_TIME='未知'
if [ -r /var/lib/clamav/daily.cvd ]; then
    DB_VER="$(sigtool --info /var/lib/clamav/daily.cvd 2>/dev/null | awk -F': ' '/^Version/{print $2; exit}')"
    DB_TIME="$(sigtool --info /var/lib/clamav/daily.cvd 2>/dev/null | awk -F': ' '/^Build time/{print $2; exit}')"
fi
# 特征总数：不能用 clamscan --version 的尾部数字，那是年份（如 2026）。
# 引擎实际加载数在扫描日志的 Known viruses 行，扫完后再取。
DB_SIGS=0
START_TS="$(date +%s)"
echo "[$(date '+%F %T')] 开始 $MODE 扫描，病毒库 $DB_VER（$DB_TIME）" >> "$LOG"
# ---------- 执行扫描 ----------
# nice/ionice 降优先级，避免扫描把业务卡住
nice -n 19 ionice -c3 clamscan -r -i \
    "${MAXOPT[@]}" ${EXCLUDES[@]+"${EXCLUDES[@]}"} \
    --log="$LOG" "${TARGETS[@]}" >/dev/null 2>&1
RC=$?
COST=$(( $(date +%s) - START_TS ))
# ---------- 解析结果 ----------
SCANNED="$(awk -F': ' '/^Scanned files/{print $2}' "$LOG" | tail -1 | tr -d ' ')"
# 从日志取引擎实际加载的特征数
DB_SIGS="$(awk -F': ' '/^Known viruses/{print $2}' "$LOG" | tail -1 | tr -d ' ')"
[ -z "$DB_SIGS" ] && DB_SIGS=0
DATA="$(awk -F': ' '/^Data scanned/{print $2}' "$LOG" | tail -1)"
[ -z "$SCANNED" ] && SCANNED=0
[ -z "$DATA" ] && DATA='0 MB'
FOUND_LIST="$(grep ' FOUND$' "$LOG" 2>/dev/null | head -20)"
FOUND_CNT="$(grep -c ' FOUND$' "$LOG" 2>/dev/null)"
[ -z "$FOUND_CNT" ] && FOUND_CNT=0
echo "[$(date '+%F %T')] 扫描结束：文件 $SCANNED，感染 $FOUND_CNT，耗时 ${COST}s" >> "$LOG"
# ---------- 写入后台监控 ----------
MODE_CN='全系统'
[ "$MODE" = 'web' ]   && MODE_CN='站点目录'
[ "$MODE" = 'quick' ] && MODE_CN='快速'
DBINFO="病毒库 ${DB_VER}（${DB_TIME}），特征 ${DB_SIGS} 条"
if [ "$FOUND_CNT" -gt 0 ]; then
    # 发现病毒：逐个文件报高危事件
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        VFILE="${line%%: *}"
        VNAME="${line##*: }"
        VNAME="${VNAME% FOUND}"
        $CLI event --etype=virus --level=high \
            --target="$VFILE" --hits=1 \
            --detail="ClamAV 检出病毒：${VNAME}。${DBINFO}" >/dev/null 2>&1
    done <<< "$FOUND_LIST"
    $CLI event --etype=virus_scan --level=high \
        --target="$MODE_CN" --hits="$FOUND_CNT" \
        --detail="${MODE_CN}扫描完成：检出 ${FOUND_CNT} 个感染文件，扫描 ${SCANNED} 个文件，耗时 ${COST} 秒。${DBINFO}。详见 ${LOG}" >/dev/null 2>&1
elif [ "$RC" -eq 0 ]; then
    $CLI event --etype=virus_scan --level=low \
        --target="$MODE_CN" --hits=0 \
        --detail="${MODE_CN}扫描完成：未发现病毒，扫描 ${SCANNED} 个文件（${DATA}），耗时 ${COST} 秒。${DBINFO}。" >/dev/null 2>&1
else
    $CLI event --etype=virus_scan --level=mid \
        --target="$MODE_CN" --hits=0 \
        --detail="${MODE_CN}扫描异常退出，clamscan 返回码 ${RC}，已扫描 ${SCANNED} 个文件。详见 ${LOG}" >/dev/null 2>&1
fi
# 日志只留 30 天
find "$LOGDIR" -name '*.log' -mtime +30 -delete 2>/dev/null
exit 0
