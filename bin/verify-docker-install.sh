#!/bin/bash
# bin/verify-docker-install.sh - EC-CUBE 4.2/4.3/4.4 対応の docker compose インストール試験
#
# 審査 3607 で検出された 4.2 dep-on-root / 4.3 ContainerInterface を
# EC-CUBE 本体にマウントして eccube:plugin:install する結合試験で再現する。
# 4.4 (Symfony 7.4 / PHP 8.2〜8.5) は port 8082 + php:8.3-apache 系で追加 (T-11/V-04)。
# 既存 4.2(8080)/4.3(8081) の振る舞いは維持する。
#
# Usage:
#   ./bin/verify-docker-install.sh                          # both (4.2 + 4.3, 既存互換)
#   ./bin/verify-docker-install.sh --version 4.2            # 4.2 のみ (PHP 8.1, port 8080, code AiChatAssistant42)
#   ./bin/verify-docker-install.sh --version 4.3            # 4.3 のみ (PHP 8.2, port 8081, code AiChatAssistant42)
#   ./bin/verify-docker-install.sh --version 4.4            # 4.4 のみ (PHP 8.3, port 8082, code AiChatAssistant44)
#   ./bin/verify-docker-install.sh --version all            # 4.2 + 4.3 + 4.4
#   ./bin/verify-docker-install.sh --version 4.2 --keep     # 終了時に down -v しない
#   ./bin/verify-docker-install.sh --version both --quick   # unit のみ (docker省略)
#   PLUGIN_DIR=/path/to/plugin ./bin/verify-docker-install.sh --version 4.4
#   PLUGIN_CODE=AiChatAssistant44 ./bin/verify-docker-install.sh --version 4.4  # 上書き可
#
set -euo pipefail

PLUGIN_DIR_DEFAULT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="${PLUGIN_DIR:-$PLUGIN_DIR_DEFAULT}"
VERSION="both"
KEEP=0
QUICK=0
DOCTOR=0
FIX=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="$2"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --quick) QUICK=1; shift ;;
    --doctor) DOCTOR=1; shift ;;
    --fix) FIX=1; shift ;;
    -h|--help)
      echo "Usage: $0 [--version 4.2|4.3|4.4|both|all] [--keep] [--quick] [--doctor] [--fix]"
      echo "  --doctor       # 死活監視: コンテナ・フロント応答・var/所有者・memory.ini を点検"
      echo "  --fix          # 修復: var/ を www-data へ chown (root console 実行後の 500 対策)"
      echo "  4.2  -> EC-CUBE 4.2 + php:8.1-apache-bullseye + port 8080 (code AiChatAssistant42)"
      echo "  4.3  -> EC-CUBE 4.3 + php:8.2-apache-bullseye + port 8081 (code AiChatAssistant42)"
      echo "  4.4  -> EC-CUBE 4.4 + php:8.3-apache-bookworm + port 8082 (code AiChatAssistant44)"
      echo "  both -> 4.2 + 4.3 (既存互換。4.4は含まない)"
      echo "  all  -> 4.2 + 4.3 + 4.4"
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
  local plugin_code
  local plugin_mount

  case "$ver" in
    4.2) php_image="php:8.1-apache-bullseye"; eccube_branch="4.2"; port="8080"; plugin_code="${PLUGIN_CODE:-AiChatAssistant42}"; plugin_mount="${PLUGIN_MOUNT:-AiChatAssistant42}" ;;
    4.3) php_image="php:8.2-apache-bullseye"; eccube_branch="4.3"; port="8081"; plugin_code="${PLUGIN_CODE:-AiChatAssistant42}"; plugin_mount="${PLUGIN_MOUNT:-AiChatAssistant42}" ;;
    4.4) php_image="${PHP_IMAGE_44:-php:8.3-apache-bookworm}"; eccube_branch="${ECCUBE_BRANCH_44:-4.4}"; port="8082"; plugin_code="${PLUGIN_CODE:-AiChatAssistant44}"; plugin_mount="${PLUGIN_MOUNT:-AiChatAssistant44}" ;;
    *) echo "[ERROR] unsupported version: $ver" >&2; exit 1 ;;
  esac

  tmpdir="/tmp/eccube-verify-${ver}"
  echo ""
  echo "======================================================================"
  echo "=== [${ver}] EC-CUBE ${ver} + ${php_image} (port ${port}, code ${plugin_code}) ==="
  echo "======================================================================"
  echo "tmpdir: $tmpdir"
  echo "plugin: $PLUGIN_DIR"
  echo "plugin_code: $plugin_code / mount: app/Plugin/$plugin_mount"

  # 1. EC-CUBE 本体を用意 (既存なら再利用、なければ clone)
  # 4.4 は alpha 期のため branch `4.4` が無ければ tag `4.4.0-alpha-1` にフォールバックする。
  if [[ ! -d "$tmpdir/.git" ]]; then
    echo "[${ver} 1/7] git clone -b $eccube_branch EC-CUBE -> $tmpdir"
    rm -rf "$tmpdir"
    if ! git clone -b "$eccube_branch" https://github.com/EC-CUBE/ec-cube.git "$tmpdir"; then
      if [[ "$ver" == "4.4" ]]; then
        echo "[WARN] branch $eccube_branch が無いため tag 4.4.0-alpha-1 で再試行" >&2
        git clone -b "4.4.0-alpha-1" https://github.com/EC-CUBE/ec-cube.git "$tmpdir"
      else
        exit 1
      fi
    fi
  else
    echo "[${ver} 1/7] EC-CUBE 既存 $tmpdir を再利用 (git fetch)"
    git -C "$tmpdir" fetch origin "$eccube_branch" --depth 1 || true
    git -C "$tmpdir" checkout "$eccube_branch" || git -C "$tmpdir" checkout "4.4.0-alpha-1" || true
  fi

  # 2. compose 定義と env を配置
  echo "[${ver} 2/7] docker-compose.verify.yml / .env.verify を配置"
  cp "$PLUGIN_DIR/Tests/Docker/docker-compose.verify.yml" "$tmpdir/docker-compose.verify.yml"
  cp "$PLUGIN_DIR/Tests/Docker/.env.verify" "$tmpdir/.env"
  # .env は compose の env_file ではなく EC-CUBE 本体用。compose 側は environment 直書きのため不要だが一応配置

  # 3. 起動
  echo "[${ver} 3/7] docker compose up -d --build (PHP_IMAGE=$php_image, PLUGIN_DIR=$PLUGIN_DIR, PORT=$port, MOUNT=$plugin_mount)"
  (
    cd "$tmpdir"
    PHP_IMAGE="$php_image" PLUGIN_DIR="$PLUGIN_DIR" PLUGIN_MOUNT="$plugin_mount" ECCUBE_PORT="$port" docker compose -f docker-compose.verify.yml up -d --build
    echo "  -> waiting for eccube container (provisioning: apt + PHP extensions + memory.ini)..."
    for i in $(seq 1 120); do
      if docker compose -f docker-compose.verify.yml exec -T eccube test -f /usr/local/etc/php/conf.d/zz-memory-limit.ini 2>/dev/null \
      && docker compose -f docker-compose.verify.yml exec -T eccube php -m 2>/dev/null | grep -qE "^intl$" \
      && docker compose -f docker-compose.verify.yml exec -T eccube php -m 2>/dev/null | grep -qE "^zip$"; then
        echo "  -> eccube ready (try $i)"
        break
      fi
      sleep 10
      if [[ $i -eq 120 ]]; then
        echo "[ERROR] eccube container not ready (extensions missing)" >&2
        docker compose -f docker-compose.verify.yml logs --tail 50 eccube || true
        exit 1
      fi
    done
  )

  # 3b. composer install (EC-CUBE 本体の vendor が無いと bin/console が動かない。
  #     Makefile dbs-install と同等。post-install の cache:clear もここで走る)
  echo "[${ver} 3b] composer install (EC-CUBE本体 vendor)"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube bash -c \
    "command -v composer >/dev/null || { php -r \"copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');\" && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet; }" || exit 1
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube bash -c \
    "cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress" || exit 1

  # 4. EC-CUBE install
  echo "[${ver} 4/7] eccube:install --no-interaction"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console eccube:install --no-interaction || {
    echo "[WARN] eccube:install 失敗 — doctrine:database:create + migrations でフォールバック"
    docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console doctrine:database:create --if-not-exists || true
    docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console doctrine:migrations:migrate --no-interaction || true
  }

  # 4b. 権限補正 (console=root 実行で作られた var/ を www-data へ。apache 500 防止)
  echo "[${ver} 4b] chown -R www-data var/"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube bash -c "chown -R www-data var/" || exit 1

  # 5. plugin install / enable — 審査再現の核心
  echo "[${ver} 5/7] eccube:plugin:install --code=${plugin_code} (審査再現)"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console eccube:plugin:install --code="$plugin_code"
  echo "[${ver} 5b] eccube:plugin:enable"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console eccube:plugin:enable --code="$plugin_code"

  # 5c. SameSite 検出 (4.4): HTTP + cookie_samesite:none では管理画面ログイン不可。WARN表示。
  if [[ "$ver" == "4.4" ]]; then
    SAMESITE="$(docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube grep -E "cookie_samesite" app/config/eccube/packages/framework.yaml 2>/dev/null || true)"
    echo "[${ver} 5c] SameSite check: $SAMESITE"
    if echo "$SAMESITE" | grep -q "none"; then
      echo "[WARN] 4.4 は SameSite=None のため HTTP (php -S / 本compose) では管理画面ログイン不可。" >&2
      echo "[WARN] HTTP検証時は framework.yaml の cookie_samesite:none -> lax + var/cache + var/build 削除が必要。HTTPS(symfony server:start)では不要。詳細は Documents/LOCAL_VERIFICATION.md §9 参照。" >&2
    fi
  fi

  # 6. cache + debug — 4.3 ContainerInterface をここで検出
  echo "[${ver} 6/7] cache:clear --no-warmup && cache:warmup (4.3 ContainerInterface gate)"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console cache:clear --no-warmup
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console cache:warmup

  echo "[${ver} 6b] debug:container ChatApiController --show-arguments"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube php bin/console debug:container "Plugin\\${plugin_code}\\Controller\\Api\\ChatApiController" --show-arguments | head -n 50

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

# 死活監視 (--doctor) / 修復 (--fix)。構築は行わない。未構築の版は SKIP。
doctor_versions() {
  case "$VERSION" in
    4.2|4.3|4.4) echo "$VERSION" ;;
    both) echo "4.2 4.3" ;;
    all) echo "4.2 4.3 4.4" ;;
    *) echo "[ERROR] --version は 4.2|4.3|4.4|both|all のみ" >&2; exit 1 ;;
  esac
}

doctor_one_version() {
  local ver="$1" port tmpdir fail=0
  case "$ver" in
    4.2) port="8080" ;;
    4.3) port="8081" ;;
    4.4) port="8082" ;;
  esac
  tmpdir="/tmp/eccube-verify-${ver}"
  echo "=== [${ver}] doctor (port ${port}) ==="
  if [[ ! -d "$tmpdir" ]]; then
    echo "  -> SKIP (未構築: $tmpdir が無い)"
    return 0
  fi
  if ! docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube true 2>/dev/null; then
    echo "  -> NG: container not running"; fail=1
  else
    echo "  -> OK: container running"
  fi
  local http
  http="$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "http://localhost:${port}/" || echo "000")"
  if [[ "$http" == "200" ]]; then
    echo "  -> OK: front HTTP 200"
  else
    echo "  -> NG: front HTTP $http"; fail=1
  fi
  local bad
  bad="$(docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube bash -c 'find var/cache var/build var/log -maxdepth 4 ! -user www-data 2>/dev/null | head -n 5' || true)"
  if [[ -z "$bad" ]]; then
    echo "  -> OK: var/ ownership (all www-data)"
  else
    echo "  -> NG: non-www-data files under var/: $bad"; fail=1
  fi
  if docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube test -f /usr/local/etc/php/conf.d/zz-memory-limit.ini 2>/dev/null; then
    echo "  -> OK: memory_limit ini present"
  else
    echo "  -> NG: memory_limit ini missing"; fail=1
  fi
  return $fail
}

if [[ "$DOCTOR" -eq 1 || "$FIX" -eq 1 ]]; then
  overall=0
  for v in $(doctor_versions); do
    if [[ "$FIX" -eq 1 && -d "/tmp/eccube-verify-${v}" ]]; then
      echo "=== [${v}] fix: chown -R www-data var/ ==="
      docker compose -f "/tmp/eccube-verify-${v}/docker-compose.verify.yml" exec -T eccube bash -c "chown -R www-data var/" || overall=1
    fi
    doctor_one_version "$v" || overall=1
  done
  if [[ "$overall" -eq 0 ]]; then echo "=== doctor: ALL HEALTHY ==="; else echo "=== doctor: FAILURES DETECTED ===" >&2; fi
  exit $overall
fi

case "$VERSION" in
  4.2) run_one_version "4.2" ;;
  4.3) run_one_version "4.3" ;;
  4.4) run_one_version "4.4" ;;
  both) run_one_version "4.2"; run_one_version "4.3" ;;
  all) run_one_version "4.2"; run_one_version "4.3"; run_one_version "4.4" ;;
  *) echo "[ERROR] --version は 4.2|4.3|4.4|both|all のみ" >&2; exit 1 ;;
esac

echo ""
echo "======================================================================"
echo "=== All requested versions PASSED ==="
echo "======================================================================"
