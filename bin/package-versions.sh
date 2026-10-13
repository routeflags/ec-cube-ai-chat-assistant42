#!/bin/bash
# 4.4 / 4.3・4.2 向けパッケージの分離ビルドスキル
#
# code 分離運用（4.4=AiChatAssistant44、4.3/4.2=AiChatAssistant42）のため、
# 現在の checkout を切り替えずに両ブランチの配布用 tar.gz を別々に生成する。
# 各ブランチ自身の bin/package.sh を使うため、命名・除外・検証規約は各世代に追従する。
#
# Usage:
#   ./bin/package-versions.sh                          # 4.4 + 4.2系の両方を ./dist へ
#   ./bin/package-versions.sh --only 4.4                # 4.4 のみ
#   ./bin/package-versions.sh --only 4.2                # 4.2系のみ
#   ./bin/package-versions.sh --out /tmp/pkg            # 出力先を指定
#   ./bin/package-versions.sh --branch-44 4.4 --branch-42 4.2
#   BRANCH_44=4.4 BRANCH_42=4.2 OUT_DIR=./dist ./bin/package-versions.sh
#
set -euo pipefail

BRANCH_44="${BRANCH_44:-4.4}"
BRANCH_42="${BRANCH_42:-4.2}"
ONLY="${ONLY:-all}"
OUT_DIR="${OUT_DIR:-dist}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --only) ONLY="$2"; shift 2 ;;
    --out) OUT_DIR="$2"; shift 2 ;;
    --branch-44) BRANCH_44="$2"; shift 2 ;;
    --branch-42) BRANCH_42="$2"; shift 2 ;;
    -h|--help)
      sed -n '2,14p' "$0"
      exit 0
      ;;
    *) echo "[ERROR] Unknown arg: $1" >&2; exit 1 ;;
  esac
done

case "$ONLY" in
  all|4.4|4.2) ;;
  *) echo "[ERROR] --only は all|4.4|4.2 のみ" >&2; exit 1 ;;
esac

REPO_ROOT="$(git rev-parse --show-toplevel)"
mkdir -p "$OUT_DIR"
OUT_ABS="$(cd "$OUT_DIR" && pwd)"

for b in "$BRANCH_44" "$BRANCH_42"; do
  if ! git -C "$REPO_ROOT" rev-parse --verify --quiet "refs/heads/$b" > /dev/null; then
    echo "[ERROR] ブランチが存在しません: $b" >&2
    exit 1
  fi
done
command -v php > /dev/null || { echo "[ERROR] php が必要です" >&2; exit 1; }

WT_BASE="$(mktemp -d)"
cleanup() {
  for wt in "$WT_BASE"/*; do
    [[ -d "$wt" ]] || continue
    git -C "$REPO_ROOT" worktree remove --force "$wt" 2>/dev/null || rm -rf "$wt"
  done
  rmdir "$WT_BASE" 2>/dev/null || true
}
trap cleanup EXIT

build_one() {
  local label="$1" branch="$2" expect_code="$3"
  local wt="$WT_BASE/$label"
  echo "=== [$label] branch=$branch expect_code=$expect_code ==="
  git -C "$REPO_ROOT" worktree add --detach "$wt" "$branch" --quiet
  local version
  # worktree 上でバージョン取得（package.sh と同じ順序: composer.json の version）
  version="$(cd "$wt" && php -r '$c=json_decode(file_get_contents("composer.json"),true); echo $c["version"] ?? "";')"
  local output="$OUT_ABS/${expect_code}-${version}.tar.gz"
  rm -f "$output"
  (cd "$wt" && ./bin/package.sh --output "$output" > /dev/null)
  local code_in_archive
  code_in_archive="$(tar -xzOf "$output" composer.json | php -r '$c=json_decode(stream_get_contents(STDIN),true); echo $c["extra"]["code"] ?? "";')"
  if [[ "$code_in_archive" != "$expect_code" ]]; then
    echo "[ERROR] [$label] code 不一致: expected=$expect_code got=$code_in_archive" >&2
    exit 1
  fi
  echo "  -> OK: $output (code=$code_in_archive version=$version)"
}

if [[ "$ONLY" == "all" || "$ONLY" == "4.4" ]]; then
  build_one "eccube44" "$BRANCH_44" "AiChatAssistant44"
fi
if [[ "$ONLY" == "all" || "$ONLY" == "4.2" ]]; then
  build_one "eccube42" "$BRANCH_42" "AiChatAssistant42"
fi

echo ""
echo "=== Version-separated packaging succeeded: $OUT_ABS ==="
ls -lh "$OUT_ABS"/*.tar.gz