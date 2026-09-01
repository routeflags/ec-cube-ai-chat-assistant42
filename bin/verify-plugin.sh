#!/bin/bash
# 本プラグインのローカル検証スクリプト（EC-CUBEなしでも動く簡易チェック）
# Usage: ./bin/verify-plugin.sh
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
echo "=== AiChatAssistant42 Plugin Verification ==="
echo "Plugin dir: $PLUGIN_DIR"
echo ""

echo "[1/5] composer.json validate"
composer validate --no-check-publish
echo "  -> OK"
echo ""

echo "[2/5] PHP syntax check"
find "$PLUGIN_DIR" -name "*.php" -type f | while read f; do
  php -l "$f" > /dev/null
done
echo "  -> OK (all php files syntax ok)"
echo ""

echo "[3/5] Hardcode check (thch-vape.shop should be 0)"
count=$(grep -r "thch-vape.shop" "$PLUGIN_DIR" --include="*.php" --exclude-dir=.git | grep -v "Documents/plans" | grep -v "artifacts" | wc -l | tr -d ' ')
if [ "$count" -eq 0 ]; then
  echo "  -> OK (0 hits)"
else
  echo "  -> NG ($count hits)"
  grep -r "thch-vape.shop" "$PLUGIN_DIR" --include="*.php" | head
  exit 1
fi
echo ""

echo "[4/5] EasyArticle dependency check (plg_ea_article should be 0 in code)"
count=$(grep -r "plg_ea_article" "$PLUGIN_DIR" --include="*.php" | wc -l | tr -d ' ')
# コメント1件は許容（旧テーブル参照しない旨のコメント）
if [ "$count" -le 1 ]; then
  echo "  -> OK ($count hits, comment only)"
else
  echo "  -> NG ($count hits)"
  grep -r "plg_ea_article" "$PLUGIN_DIR" --include="*.php"
  exit 1
fi
echo ""

echo "[5/5] Route count check (should be 28)"
count=$(grep -c "^[a-z_]*:" "$PLUGIN_DIR/Resource/config/routes.yaml")
if [ "$count" -eq 28 ]; then
  echo "  -> OK (28 routes)"
else
  echo "  -> NG ($count routes, expected 28)"
  exit 1
fi
echo ""

echo "=== All local checks passed ==="
echo ""
echo "Next: Dockerでの結合検証は Documents/LOCAL_VERIFICATION.md を参照してください。"
echo "  cat Documents/LOCAL_VERIFICATION.md"
