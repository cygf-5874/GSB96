#!/usr/bin/env bash
# 固定验收入口的薄封装：先切到仓库根，再把参数原样转给 check/check.php。
#
#   bash scripts/check.sh                 跑全部 12 个场景
#   bash scripts/check.sh -list           列出全部场景
#   bash scripts/check.sh --only merge    只跑一组
set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

exec php check/check.php "$@"
