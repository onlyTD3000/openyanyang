#!/bin/bash
# 跑全部测试：JS 语法检查 + JS 单测 + PHP 单测。
#
# JS 用 Node 自带的 node:test，不装依赖。注意不能写 node --test tests/，
# Node 会把目录当模块解析然后报 MODULE_NOT_FOUND，必须逐个点到文件。
# PHP 测试要用 www 身份跑，root 跑会在数据目录里建出 root 属主的文件。
set -u
cd "$(dirname "$0")/.." || exit 1
FAIL=0
echo "=== JS 语法检查 ==="
for f in assets/js/*.js; do
  case "$f" in *.bak*) continue;; esac
  node --check "$f" || { echo "语法错误：$f"; FAIL=1; }
done
[ $FAIL -eq 0 ] && echo "全部通过"
echo
echo "=== PHP 语法检查 ==="
for f in api/*.php inc/*.php; do
  php -l "$f" >/dev/null || { echo "语法错误：$f"; FAIL=1; }
done
[ $FAIL -eq 0 ] && echo "全部通过"
echo
echo "=== JS 单元测试 ==="
node --test tests/*.test.js || FAIL=1
echo
echo "=== PHP 单元测试 ==="
for f in tests/*.test.php; do
  [ -f "$f" ] || continue
  if [ "$(id -u)" = "0" ]; then
    sudo -u www php "$f" || FAIL=1
  else
    php "$f" || FAIL=1
  fi
done
echo
[ $FAIL -eq 0 ] && echo "★ 全部通过" || echo "☠ 有失败项"
exit $FAIL
