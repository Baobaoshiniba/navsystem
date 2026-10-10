#!/usr/bin/env bash
#
# company-nav 本地启动脚本
#
# 关键点：用 PHP_CLI_SERVER_WORKERS 开启多 worker。
# PHP 内置服务器默认单进程，一个耗时请求（如后台批量抓取图标）会占住整个服务，
# 导致同浏览器其它后台请求排队等待；多 worker 可让请求并行。
#
# 用法：
#   ./start.sh                                  # 默认 127.0.0.1:8000，4 个 worker
#   PORT=8080 WORKERS=8 ./start.sh              # 自定义端口 / worker 数
#   HOST=0.0.0.0 ./start.sh                     # 局域网可访问
#
set -euo pipefail
cd "$(dirname "$0")"

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"
WORKERS="${WORKERS:-4}"

if ! command -v php >/dev/null 2>&1; then
    echo "未找到 php，请先安装 PHP 8.x（含 pdo_sqlite 扩展）并加入 PATH。" >&2
    exit 1
fi

export PHP_CLI_SERVER_WORKERS="$WORKERS"

echo "company-nav 启动中：http://${HOST}:${PORT}   前台 index.php / 后台 admin/"
echo "PHP_CLI_SERVER_WORKERS=${WORKERS}（Windows 下该环境变量不生效，会回退为单进程）"
echo "数据库：SQLite WAL + busy_timeout=60000ms，支持多 worker 并发读写。按 Ctrl+C 停止。"

exec php -S "${HOST}:${PORT}" -t .
