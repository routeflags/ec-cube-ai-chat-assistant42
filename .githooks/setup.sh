#!/bin/sh
#
# Git hooks をセットアップするスクリプト
#
# 使い方: bash .githooks/setup.sh
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$REPO_ROOT"

echo "🔧 Git hooks をセットアップ中..."

# git config でフックパスを設定
git config core.hooksPath .githooks

echo "✅ core.hooksPath = $(git config core.hooksPath)"
echo ""
echo "以下のフックが有効になりました:"
ls -1 .githooks/*.sh .githooks/pre-commit .githooks/commit-msg 2>/dev/null | while read f; do
    echo "  - $(basename $f)"
done
echo ""
echo "フックをスキップしたい場合: git commit --no-verify"
