#!/bin/bash
# ==============================================================================
# rkhunter 后门检测
#   用法：rkhunter_scan.sh [check|propupd]
#     check     执行检测（默认），结果写入后台入侵监控
#     propupd   重建系统文件基线。系统更新或装了新软件后要跑一次，
#               否则 rkhunter 会把正常的版本变更报成"文件被替换"
# ==============================================================================
set -uo pipefail
ROOT='/www/wwwroot/code.77bot.cn'
CLI="php $ROOT/tools/intrusion_cli.php"
LOGDIR='/var/log/rkhunter_scan'
LOCK='/var/lib/rkhunter/scan.lock'
MODE="${1:-check}"
mkdir -p "$LOGDIR"
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "已有 rkhunter 在运行，本次跳过"
    exit 0
fi
# ---------- 重建基线模式 ----------
if [ "$MODE" = 'propupd' ]; then
    rkhunter --propupd --nocolors >/dev/null 2>&1
    cp -a /etc/passwd /var/lib/rkhunter/db/passwd 2>/dev/null
    cp -a /etc/group  /var/lib/rkhunter/db/group  2>/dev/null
    chmod 600 /var/lib/rkhunter/db/passwd /var/lib/rkhunter/db/group 2>/dev/null
    $CLI event --etype=rkhunter --level=low --target='基线重建' --hits=0 \
        --detail='rkhunter 系统文件基线已重建，后续检测以当前系统状态为基准。' >/dev/null 2>&1
    echo "基线已重建"
    exit 0
fi
TS="$(date +%Y%m%d_%H%M%S)"
LOG="$LOGDIR/check_${TS}.log"
START=$(date +%s)
# ---------- 先更新检测数据库 ----------
rkhunter --update --nocolors >/dev/null 2>&1
# ---------- 执行检测 ----------
# --rwo 只输出警告，日志另外完整落盘
rkhunter --check --skip-keypress --nocolors --report-warnings-only \
    --logfile "$LOG" > "$LOG.warn" 2>&1
RC=$?
COST=$(( $(date +%s) - START ))
# 警告数：rkhunter 完整日志里的 Warning 行才算（.warn 是精简版，可能为空）
WARN_CNT=$(grep -c 'Warning:' "$LOG" 2>/dev/null | head -1)
WARN_CNT=${WARN_CNT:-0}
# 检测项统计：只数 Checking 行，用 head -1 防止 grep 输出多行
CHECKED=$(grep -cE '^\[[0-9:]+\][[:space:]]+Checking' "$LOG" 2>/dev/null | head -1)
CHECKED=${CHECKED:-0}
# rootkit 命中判定：只认 rkhunter 明确的威胁结论标记。
#   [ Not found ] / [ None found ] / [ OK ] 都是正常
#   [ Found ] 在存在性检查里表示"配置正常存在"，不是威胁，必须排除
#   真正的威胁标记是 [ Warning ] 和 INFECTED
INFECTED=$(grep -E '\[ (Warning|INFECTED) \]|INFECTED' "$LOG" 2>/dev/null | head -20)
INF_CNT=0
if [ -n "$INFECTED" ]; then
    INF_CNT=$(printf '%s\n' "$INFECTED" | grep -c . | head -1)
    INF_CNT=${INF_CNT:-0}
fi
DBVER=$(grep -oE 'Rootkit Hunter version [0-9.]+' "$LOG" 2>/dev/null | head -1 | awk '{print $NF}')
[ -z "$DBVER" ] && DBVER='1.4.6'
INFO="rkhunter ${DBVER}，检测 ${CHECKED} 项，耗时 ${COST} 秒"
if [ "$INF_CNT" -gt 0 ]; then
    # 发现 rootkit：逐条上报高危
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        $CLI event --etype=rootkit --level=high --target="${line:0:200}" --hits=1 \
            --detail="rkhunter 检出 rootkit 迹象：${line}。${INFO}。详见 ${LOG}" >/dev/null 2>&1
    done <<< "$INFECTED"
    $CLI event --etype=rkhunter --level=high --target='后门检测' --hits="$INF_CNT" \
        --detail="检出 ${INF_CNT} 处 rootkit 迹象，另有 ${WARN_CNT} 条警告。${INFO}。详见 ${LOG}" >/dev/null 2>&1
elif [ "$WARN_CNT" -gt 0 ]; then
    # 只有警告：中等级别，附上警告摘要
    SUMMARY=$(grep '^Warning:' "$LOG.warn" 2>/dev/null | head -8 | sed 's/^Warning: //' | tr '\n' ';' | cut -c1-600)
    $CLI event --etype=rkhunter --level=mid --target='后门检测' --hits="$WARN_CNT" \
        --detail="未发现 rootkit，但有 ${WARN_CNT} 条警告需关注：${SUMMARY}。${INFO}。详见 ${LOG}" >/dev/null 2>&1
else
    $CLI event --etype=rkhunter --level=low --target='后门检测' --hits=0 \
        --detail="未发现 rootkit，无警告，系统干净。${INFO}。" >/dev/null 2>&1
fi
find "$LOGDIR" -name '*.log*' -mtime +30 -delete 2>/dev/null
exit 0
