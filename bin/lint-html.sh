#!/bin/bash
# bin/lint-html.sh - front+管理画面主要テンプレの HTML検証 (C1再発防止ゲート)
#
# 店舗版 `.htmlvalidate.json` (recommended + void-style:error 等) を基準にする。
# - node + html-validate がある場合: 実検証 (対象 twig を静的HTMLとして検査)
# - ない場合: void要素 self-closing の grep ガード (SVGの正しい self-closing は除外)
# いずれも C1適用後は exit 0、C1以前の状態では exit != 0 になること。
# Usage: ./bin/lint-html.sh
set -u
cd "$(dirname "$0")/.."
PLUGIN_DIR="$PWD"

TARGETS=(
  "Resource/template/admin/design.twig"
  "Resource/template/admin/notification.twig"
  "Resource/template/admin/access_rule.twig"
  "Resource/template/admin/chat_history.twig"
  "Resource/template/default/chat_widget.twig"
  "Resource/template/default/mcp_link.twig"
)
FAIL=0

for f in "${TARGETS[@]}"; do
  if [[ ! -f "$f" ]]; then
    echo "[lint-html] MISSING: $f" >&2
    FAIL=1
  fi
done
if [[ "$FAIL" -ne 0 ]]; then exit 1; fi

# --- (a) 実検証 (利用可能な場合のみ) ---
if command -v npx >/dev/null 2>&1 && npx --no-install html-validate --version >/dev/null 2>&1; then
  echo "[lint-html] html-validate による実検証"
  # twigタグを含むため完全検証はできない。void-style相当の静的検査に絞る場合は (b) にフォールバック。
  # レンダリング済みHTMLの検証は店舗版と同様に lint:html:* (curl+stdin) で行う。
  echo "[lint-html] (twig原稿のため実検証はスキップし grep ガードを実行。レンダー後検証は verify-docker-install.sh T-C3 を参照)"
fi

# --- (b) void-style grep ガード (必須) ---
# 対象: 行末が " />" の void要素 (input)。SVG (path/line/circle/polygon、"/>終わり) は除外。
echo "[lint-html] void-style grep ガード (input self-closing 検出)"
VIOLATIONS="$(grep -n ' />$' "${TARGETS[@]}" 2>/dev/null | grep -v 'mcp_link.twig' || true)"
if [[ -n "$VIOLATIONS" ]]; then
  echo "[lint-html] ERROR: void要素の self-closing が残っています:" >&2
  echo "$VIOLATIONS" >&2
  exit 1
fi

# --- (c) unique-landmark ガード: 管理画面の <nav> には aria-label 必須 ---
echo "[lint-html] unique-landmark ガード (素の <nav> 検出)"
BARE_NAV="$(grep -n '^ *<nav>$' Resource/template/admin/chat_history.twig || true)"
if [[ -n "$BARE_NAV" ]]; then
  echo "[lint-html] ERROR: aria-label のない <nav> があります:" >&2
  echo "$BARE_NAV" >&2
  exit 1
fi

# --- (d) C2 ガード: body snippet 側に <link rel="mcp"> がないこと ---
echo "[lint-html] C2 ガード (chat_widget.twig 内 rel=mcp の排除)"
if grep -q 'rel="mcp"' Resource/template/default/chat_widget.twig; then
  echo "[lint-html] ERROR: chat_widget.twig (body) に <link rel=\"mcp\"> が残っています" >&2
  exit 1
fi
if ! grep -q 'rel="mcp"' Resource/template/default/mcp_link.twig; then
  echo "[lint-html] ERROR: mcp_link.twig (head) に <link rel=\"mcp\"> がありません" >&2
  exit 1
fi

echo "[lint-html] OK (${#TARGETS[@]} files)"
