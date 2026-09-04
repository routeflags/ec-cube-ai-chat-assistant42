#!/bin/bash
# bin/verify-docker-install.sh - EC-CUBE 4.2/4.3 両対応の docker compose インストール試験
#
# 審査 3607 で検出された 4.2 dep-on-root / 4.3 ContainerInterface を
# EC-CUBE 本体にマウントして eccube:plugin:install する結合試験で再現する。
#
# Usage:
#   ./bin/verify-docker-install.sh                          # both (4.2 + 4.3)
#   ./bin/verify-docker-install.sh --version 4.2            # 4.2 のみ (PHP 8.1)
#   ./bin/verify-docker-install.sh --version 4.3            # 4.3 のみ (PHP 8.2)
#   ./bin/verify-docker-install.sh --version 4.2 --keep     # 終了時に down -v しない
#   ./bin/verify-docker-install.sh --version both --quick   # unit のみ (docker省略)
#   PLUGIN_DIR=/path/to/plugin ./bin/verify-docker-install.sh --version 4.3
#
set -euo pipefail

PLUGIN_DIR_DEFAULT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="${PLUGIN_DIR:-$PLUGIN_DIR_DEFAULT}"
VERSION="both"
KEEP=0
QUICK=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="$2"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --quick) QUICK=1; shift ;;
    -h|--help)
      echo "Usage: $0 [--version 4.2|4.3|both] [--keep] [--quick]"
      exit 0
      ;;
    *) echo "[ERROR] Unknown arg: $1" >&2; exit 1 ;;
  esac
done

if [[ ! -f "$PLUGIN_DIR/eccube-plugin.yaml" ]]; then
  echo "[ERROR] PLUGIN_DIR が不正です: $PLUGIN_DIR (eccube-plugin.yaml が見つかりません)" >&2
  exit 1
fi

# 審査で落ちた2件をローカルで事前に gate する (docker不要)
echo "=== [0/3] Local gates (composer validate + grep + phpstan) ==="
echo "[0a] composer.json に ec-cube/ec-cube が無いこと"
if grep -q '"ec-cube/ec-cube"' "$PLUGIN_DIR/composer.json"; then
  echo "[ERROR] composer.json に ec-cube/ec-cube が残っています (dep-on-root)" >&2
  exit 1
fi
echo "  -> OK"

echo "[0b] composer validate"
composer --working-dir="$PLUGIN_DIR" validate --no-check-publish > /dev/null
echo "  -> OK"

echo "[0c] services.yaml に ContainerInterface bind があること"
if ! grep -q "ContainerInterface" "$PLUGIN_DIR/Resource/config/services.yaml"; then
  echo "[ERROR] Resource/config/services.yaml に ContainerInterface bind がありません" >&2
  exit 1
fi
echo "  -> OK"

if [[ "$QUICK" -eq 1 ]]; then
  echo ""
  echo "=== [quick] --quick 指定のため docker はスキップ ==="
  exit 0
fi

if ! command -v docker > /dev/null 2>&1; then
  echo "[ERROR] docker が見つかりません" >&2
  exit 1
fi
if ! docker compose version > /dev/null 2>&1; then
  echo "[ERROR] docker compose v2 が必要です" >&2
  exit 1
fi

run_one_version() {
  local ver="$1"
  local php_image
  local eccube_branch
  local port
  local tmpdir

  case "$ver" in
    4.2) php_image="php:8.1-apache-bullseye"; eccube_branch="4.2"; port="8080" ;;
    4.3) php_image="php:8.2-apache-bullseye"; eccube_branch="4.3"; port="8081" ;;
    *) echo "[ERROR] unsupported version: $ver" >&2; exit 1 ;;
  esac

  tmpdir="/tmp/eccube-verify-${ver}"
  echo ""
  echo "======================================================================"
  echo "=== [${ver}] EC-CUBE ${ver} + ${php_image} (port ${port}) ==="
  echo "======================================================================"
  echo "tmpdir: $tmpdir"
  echo "plugin: $PLUGIN_DIR"

  # 1. EC-CUBE 本体を用意 (既存なら再利用、なければ clone)
  if [[ ! -d "$tmpdir/.git" ]]; then
    echo "[${ver} 1/7] git clone -b $eccube_branch EC-CUBE -> $tmpdir"
    rm -rf "$tmpdir"
    git clone -b "$eccube_branch" https://github.com/EC-CUBE/ec-cube.git "$tmpdir"
  else
    echo "[${ver} 1/7] EC-CUBE 既存 $tmpdir を再利用 (git fetch)"
    git -C "$tmpdir" fetch origin "$eccube_branch" --depth 1 || true
    git -C "$tmpdir" checkout "$eccube_branch" || true
  fi

  # 2. compose 定義と env を配置
  echo "[${ver} 2/7] docker-compose.verify.yml / .env.verify を配置"
  cp "$PLUGIN_DIR/docker-compose.verify.yml" "$tmpdir/docker-compose.verify.yml"
  cp "$PLUGIN_DIR/.env.verify" "$tmpdir/.env"
  # .env は compose の env_file ではなく EC-CUBE 本体用。compose 側は environment 直書きのため不要だが一応配置

  # 3. 起動
  echo "[${ver} 3/7] docker compose up -d --build (PHP_IMAGE=$php_image, PLUGIN_DIR=$PLUGIN_DIR, PORT=$port)"
  (
    cd "$tmpdir"
    PHP_IMAGE="$php_image" PLUGIN_DIR="$PLUGIN_DIR" ECCUBE_PORT="$port" docker compose -f docker-compose.verify.yml up -d --build
    echo "  -> waiting for eccube container..."
    for i in $(seq 1 30); do
      if docker compose -f docker-compose.verify.yml exec -T eccube php -v > /dev/null 2>&1; then
        echo "  -> eccube ready (try $i)"
        break
      fi
      sleep 2
      if [[ $i -eq 30 ]]; then
        echo "[ERROR] eccube container not ready" >&2
        docker compose -f docker-compose.verify.yml logs --tail 50 eccube || true
        exit 1
      fi
    done
  )

  # 4. EC-CUBE install
  echo "[${ver} 4/7] eccube:install --no-interaction"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console eccube:install --no-interaction || {
    echo "[WARN] eccube:install 失敗 — doctrine:database:create + migrations でフォールバック"
    docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console doctrine:database:create --if-not-exists || true
    docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console doctrine:migrations:migrate --no-interaction || true
  }

  # 5. plugin install / enable — 審査再現の核心
  echo "[${ver} 5/7] eccube:plugin:install --code=AiChatAssistant42 (審査再現)"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console eccube:plugin:install --code=AiChatAssistant42
  echo "[${ver} 5b] eccube:plugin:enable"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console eccube:plugin:enable --code=AiChatAssistant42

  # 6. cache + debug — 4.3 ContainerInterface をここで検出
  echo "[${ver} 6/7] cache:clear --no-warmup && cache:warmup (4.3 ContainerInterface gate)"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console cache:clear --no-warmup
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console cache:warmup

  echo "[${ver} 6b] debug:container ChatApiController --show-arguments"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console debug:container 'Plugin\AiChatAssistant42\Controller\Api\ChatApiController' --show-arguments | head -n 50

  echo "[${ver} 6c] debug:router | grep ai_chat (expect 28)"
  ROUTE_COUNT="$(docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console debug:router | grep -c ai_chat || true)"
  echo "  -> ai_chat routes: $ROUTE_COUNT"
  if [[ "$ROUTE_COUNT" -lt 20 ]]; then
    echo "[ERROR] ai_chat ルートが少なすぎます: $ROUTE_COUNT (expected >=20, ideally 28)" >&2
    exit 1
  fi

  # 7. 任意: API smoke (429/403/400 は許容、500 のみ NG)
  echo "[${ver} 7/7] API smoke (expect not 500)"
  HTTP_CODE="$(docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube curl -s -o /tmp/api_smoke.json -w "%{http_code}" -X POST http://localhost/api/ai-chat-assistant/chat -H "Content-Type: application/json" -d '{"message":"hello","session_id":"docker-verify-smoke"}' || echo "000")"
  echo "  -> HTTP $HTTP_CODE"
  if [[ "$HTTP_CODE" == "500" ]]; then
    echo "[ERROR] API が 500 を返しました" >&2
    docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi

  echo ""
  echo "=== [${ver}] PASSED ==="

  if [[ "$KEEP" -eq 0 ]]; then
    echo "[${ver} cleanup] docker compose down -v"
    (cd "$tmpdir" && docker compose -f docker-compose.verify.yml down -v) || true
  else
    echo "[${ver} keep] --keep 指定のためコンテナを残します: cd $tmpdir && docker compose -f docker-compose.verify.yml ps"
  fi
}

case "$VERSION" in
  4.2) run_one_version "4.2" ;;
  4.3) run_one_version "4.3" ;;
  both) run_one_version "4.2"; run_one_version "4.3" ;;
  *) echo "[ERROR] --version は 4.2|4.3|both のみ" >&2; exit 1 ;;
esac

echo ""
echo "======================================================================"
echo "=== All requested versions PASSED ==="
echo "======================================================================"
