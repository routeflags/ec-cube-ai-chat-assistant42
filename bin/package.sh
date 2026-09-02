#!/bin/bash
# AiChatAssistant42 パッケージングスクリプト
# EC-CUBE プラグイン規約に準拠した tar.gz を生成します。
# vendor/ は含めず、利用側で composer install または EC-CUBE 管理画面が解決します。
#
# Usage:
#   ./bin/package.sh              # AiChatAssistant42-1.0.0.tar.gz を生成
#   ./bin/package.sh --output /tmp/out.tar.gz
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

# バージョンは composer.json の version を正とする。なければ 1.0.0 にフォールバック
VERSION="$(php -r 'echo json_decode(file_get_contents("composer.json"), true)["version"] ?? "1.0.0";')"
ARCHIVE="AiChatAssistant42-${VERSION}.tar.gz"

# 出力先の上書きに対応
OUTPUT="${ARCHIVE}"
if [[ "${1:-}" == "--output" && -n "${2:-}" ]]; then
    OUTPUT="$2"
fi

# 既存アーカイブがあれば事前に除去（tar が自身を含まないように）
if [[ -f "$OUTPUT" ]]; then
    rm -f "$OUTPUT"
fi

# 除外リスト: 開発用ファイル・キャッシュ・CI 設定・配布不要なドキュメント
# H1 / M3 対応: Tests, phpunit.xml.dist は配布対象外（配布先の KERNEL_CLASS 不整合を避ける）
EXCLUDE_ARGS=(
    --exclude=vendor
    --exclude=.git
    --exclude=Tests
    --exclude=phpunit.xml.dist
    --exclude=.serena
    --exclude=.phpunit.result.cache
    --exclude=.env.verify
    --exclude=docker-compose.verify.yml
    --exclude=bin/package.sh
    --exclude=.github
    --exclude=.opencode
    --exclude=opencode.json
    --exclude=.mcp.json
    --exclude=artifacts
    --exclude=composer.lock
    --exclude=Documents/plans
    --exclude=Documents/LOCAL_VERIFICATION.md
    --exclude=.gitignore
    --exclude="${OUTPUT}"
    --exclude="${ARCHIVE}"
)

# 配布に含めるファイル・ディレクトリ（EC-CUBE プラグインとして必要なもののみ）
INCLUDE_FILES=(
    Command
    Controller
    DoctrineMigrations
    Entity
    EventListener
    Nav.php
    Repository
    Resource
    Service
    composer.json
    eccube-plugin.yaml
    README.md
    COPYING
)

# 含めるファイルの存在チェック（早期失敗で除外漏れ・欠落を検出）
missing=()
for file in "${INCLUDE_FILES[@]}"; do
    if [[ ! -e "$file" ]]; then
        missing+=("$file")
    fi
done
if [[ ${#missing[@]} -ne 0 ]]; then
    echo "[ERROR] 配布対象が見つかりません: ${missing[*]}" >&2
    exit 1
fi

echo "=== AiChatAssistant42 packaging ==="
echo "Version : ${VERSION}"
echo "Output  : ${OUTPUT}"
echo "Source  : ${PLUGIN_DIR}"
echo ""

echo "[1/2] Creating archive..."
# EC-CUBE オーナーズストア規約: アーカイブ直下に composer.json を置く（prefix なし）
# 手動設置時は mkdir -p app/Plugin/AiChatAssistant42 && tar -xzf -C app/Plugin/AiChatAssistant42 で展開する
# GNU/BSD tar の --transform 非互換を避けるため staging 方式を採用
STAGE="$(mktemp -d)"
# shellcheck disable=SC2064
trap "rm -rf \"$STAGE\"" EXIT
mkdir -p "${STAGE}"
for file in "${INCLUDE_FILES[@]}"; do
    cp -a "${file}" "${STAGE}/"
done
# OUTPUT が相対パスの場合は PLUGIN_DIR 基準に解決する
if [[ "${OUTPUT}" != /* ]]; then
    OUTPUT_ABS="${PLUGIN_DIR}/${OUTPUT}"
else
    OUTPUT_ABS="${OUTPUT}"
fi
# EC-CUBE の PharData 抽出は "./" エントリを嫌うため、ファイルリストを明示して prefix なしで固める
tar -czf "${OUTPUT_ABS}" -C "${STAGE}" "${INCLUDE_FILES[@]}"
# OUTPUT が相対だった場合は相対パスに戻す（後続の tar -tzf で利用するため）
if [[ "${OUTPUT}" != /* ]]; then
    OUTPUT="${OUTPUT_ABS##${PLUGIN_DIR}/}"
fi
# staging を即時削除し trap を解除
rm -rf "${STAGE}"
trap - EXIT
echo "  -> created: ${OUTPUT} ($(du -h "${OUTPUT}" | cut -f1))"

echo ""
echo "[2/2] Verifying archive (tar -tzf)..."
ARCHIVE_LIST="$(tar -tzf "${OUTPUT}")"
echo "${ARCHIVE_LIST}" | head -n 30
if [[ $(echo "${ARCHIVE_LIST}" | wc -l) -gt 30 ]]; then
    echo "  ... ($(echo "${ARCHIVE_LIST}" | wc -l) files total)"
fi

# 除外対象がアーカイブに混入していないことを検証
# 部分一致で誤検出しないようパス境界で判定
FORBIDDEN_PATTERNS=(
    "vendor"
    ".git"
    "Tests"
    "phpunit.xml.dist"
    ".serena"
    ".phpunit.result.cache"
    ".env.verify"
    "docker-compose.verify.yml"
    "bin/package.sh"
    ".github"
    ".opencode"
    "opencode.json"
    ".mcp.json"
    "artifacts"
    "composer.lock"
    "Documents/plans"
    "Documents/LOCAL_VERIFICATION.md"
    ".gitignore"
)

verification_failed=0
for pattern in "${FORBIDDEN_PATTERNS[@]}"; do
    if echo "${ARCHIVE_LIST}" | grep -q "${pattern}"; then
        echo "[ERROR] 禁止ファイルがアーカイブに含まれています: ${pattern}" >&2
        verification_failed=1
    fi
done

if [[ ${verification_failed} -ne 0 ]]; then
    echo "" >&2
    echo "Verification FAILED. Archive contains excluded files." >&2
    exit 1
fi

# 必須ファイルが含まれていることを検証（オーナーズストア規約: 直下配置）
REQUIRED_IN_ARCHIVE=(
    "composer.json"
    "eccube-plugin.yaml"
    "Resource/config/services.yaml"
    "README.md"
)
for required in "${REQUIRED_IN_ARCHIVE[@]}"; do
    if ! echo "${ARCHIVE_LIST}" | grep -q "${required}"; then
        echo "[ERROR] 必須ファイルがアーカイブに含まれていません: ${required}" >&2
        verification_failed=1
    fi
done

if [[ ${verification_failed} -ne 0 ]]; then
    echo "Verification FAILED. Required files missing." >&2
    exit 1
fi

echo "  -> OK (excluded files not present, required files present)"
echo ""
echo "=== Packaging succeeded: ${OUTPUT} ==="
echo "Install (manual): mkdir -p /path/to/ec-cube/app/Plugin/AiChatAssistant42 && tar -xzf ${OUTPUT} -C /path/to/ec-cube/app/Plugin/AiChatAssistant42"
echo "         bin/console eccube:plugin:install --code=AiChatAssistant42"
echo "Install (store): upload ${OUTPUT} via admin Store > Plugin install"
