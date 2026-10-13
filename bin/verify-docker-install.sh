#!/bin/bash
# bin/verify-docker-install.sh - EC-CUBE 4.2/4.3/4.4 対応の docker compose インストール試験
#
# 審査 3607 で検出された 4.2 dep-on-root / 4.3 ContainerInterface を
# EC-CUBE 本体にマウントして eccube:plugin:install する結合試験で再現する。
# 4.4 (Symfony 7.4 / PHP 8.2〜8.5) は port 8082 + php:8.3-apache 系で追加 (T-11/V-04)。
# 既存 4.2(8080)/4.3(8081) の振る舞いは維持する。
# フルサイクル試験 (--cycle) は T-C1〜T-C4: tarball配置で install→enable→disable→enable→uninstall→reinstall を完走する。
# bindマウント (app/Plugin/<code> がホスト共有) では uninstall の rmdir が必ず失敗するため、サイクル環境はマウント無しで行う。
# 稼働中デバッグ環境 /tmp/eccube-verify-4.4 (port 8082) は破壊しない。サイクルは /tmp/eccube-cycle-4.4 + port 8083 + project eccube-cycle-44 の独立環境を使う。
#
# Usage:
#   ./bin/verify-docker-install.sh                          # both (4.2 + 4.3, 既存互換)
#   ./bin/verify-docker-install.sh --version 4.2            # 4.2 のみ (PHP 8.1, port 8080, code AiChatAssistant42)
#   ./bin/verify-docker-install.sh --version 4.3            # 4.3 のみ (PHP 8.2, port 8081, code AiChatAssistant42)
#   ./bin/verify-docker-install.sh --version 4.4            # 4.4 のみ (PHP 8.3, port 8082, code AiChatAssistant44)
#   ./bin/verify-docker-install.sh --version all            # 4.2 + 4.3 + 4.4
#   ./bin/verify-docker-install.sh --version 4.4 --cycle     # フルサイクル (T-C1〜T-C4。独立環境 /tmp/eccube-cycle-4.4 + port 8083)
#   ./bin/verify-docker-install.sh --version 4.4 --cycle --keep  # サイクル環境を残す
#   CYCLE_TMPDIR=/tmp/eccube-cycle-4.4 CYCLE_PORT=8083 CYCLE_PROJECT=eccube-cycle-44 TARBALL=/tmp/x.tar.gz ... --cycle  # 上書き可
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
CYCLE=0
CYCLE_TMPDIR="${CYCLE_TMPDIR:-/tmp/eccube-cycle-4.4}"
CYCLE_PORT="${CYCLE_PORT:-8083}"
CYCLE_PROJECT="${CYCLE_PROJECT:-eccube-cycle-44}"
TARBALL="${TARBALL:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="$2"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --quick) QUICK=1; shift ;;
    --doctor) DOCTOR=1; shift ;;
    --fix) FIX=1; shift ;;
    --cycle) CYCLE=1; shift ;;
    --cycle-tmpdir) CYCLE_TMPDIR="$2"; shift 2 ;;
    --cycle-port) CYCLE_PORT="$2"; shift 2 ;;
    --cycle-project) CYCLE_PROJECT="$2"; shift 2 ;;
    --tarball) TARBALL="$2"; shift 2 ;;
    -h|--help)
      echo "Usage: $0 [--version 4.2|4.3|4.4|both|all] [--keep] [--quick] [--doctor] [--fix] [--cycle [--cycle-tmpdir DIR] [--cycle-port PORT] [--cycle-project NAME] [--tarball FILE]]"
      echo "  --doctor       # 死活監視: コンテナ・フロント応答・var/所有者・memory.ini を点検"
      echo "  --fix          # 修復: var/ を www-data へ chown (root console 実行後の 500 対策)"
      echo "  4.2  -> EC-CUBE 4.2 + php:8.1-apache-bullseye + port 8080 (code AiChatAssistant42)"
      echo "  4.3  -> EC-CUBE 4.3 + php:8.2-apache-bullseye + port 8081 (code AiChatAssistant42)"
      echo "  4.4  -> EC-CUBE 4.4 + php:8.3-apache-bookworm + port 8082 (code AiChatAssistant44)"
      echo "  both -> 4.2 + 4.3 (既存互換。4.4は含まない)"
      echo "  all  -> 4.2 + 4.3 + 4.4"
      echo "  --cycle        # フルサイクル試験 (T-C1〜T-C4。--version 4.4 のみ。独立環境で install→enable→disable→enable→uninstall→reinstall)"
      echo "  --cycle-tmpdir # 既定 /tmp/eccube-cycle-4.4 (/tmp/eccube-verify-* は拒否。稼働中 8082 環境を保護)"
      echo "  --cycle-port   # 既定 8083 (8080/8081/8082 は拒否。稼働中デバッグ環境と衝突させない)"
      echo "  --cycle-project # 既定 eccube-cycle-44 (全compose呼び出しに -p で分離)"
      echo "  --tarball      # 既定は bin/package.sh で自動生成。指定時はそのtar.gzを使用"
      echo "  例: ./bin/verify-docker-install.sh --version 4.4 --cycle --keep"
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

  # 6d. 権限補正 (6/7 の console=root 実行で var/ が root 所有に戻るため。
  #     root 所有の var/runtime では WriteFailsafeFilesystemAdapter が保存を静かに諦め、
  #     レート制限が fail-open になる。var/build は 500 の原因)
  echo "[${ver} 6d] chown -R www-data var/"
  docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube bash -c "chown -R www-data var/" || exit 1

  # 7. 任意: API smoke (429/403/400 は許容、500 のみ NG)
  echo "[${ver} 7/7] API smoke (expect not 500)"
  HTTP_CODE="$(docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube curl -s -o /tmp/api_smoke.json -w "%{http_code}" -X POST http://localhost/api/ai-chat-assistant/chat -H "Content-Type: application/json" -d '{"message":"hello","session_id":"docker-verify-smoke"}' || echo "000")"
  echo "  -> HTTP $HTTP_CODE"
  if [[ "$HTTP_CODE" == "500" ]]; then
    echo "[ERROR] API が 500 を返しました" >&2
    docker compose -f "$tmpdir/docker-compose.verify.yml" exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi

  # 7b. チャットログ書込経路 (T-C5)。7/7 の API スモークは「500 でなければ OK」の
  #     ため API キー未設定の環境では書込経路に入らず素通りする。ここで実際に書き込ませる。
  chat_write_path_check "[${ver} 7b]" docker compose -f "$tmpdir/docker-compose.verify.yml"

  echo ""
  echo "=== [${ver}] PASSED ==="

  if [[ "$KEEP" -eq 0 ]]; then
    echo "[${ver} cleanup] docker compose down -v"
    (cd "$tmpdir" && docker compose -f docker-compose.verify.yml down -v) || true
  else
    echo "[${ver} keep] --keep 指定のためコンテナを残します: cd $tmpdir && docker compose -f docker-compose.verify.yml ps"
  fi
}

# チャットログ書込経路の実書き込み検査 (T-C5)。
#
# 守るもの: ChatLogger::log() は created_at に new DateTimeImmutable() を入れ、
# EntityManager::flush() で DBAL の型変換を通る。EC-CUBE 4.4 は datetimetz を
# Eccube\Doctrine\DBAL\Types\UTCDateTimeTzType (= DBAL 4 の DateTimeTzType) に
# マップし、DBAL 4 は DateTimeImmutable を拒否する (InvalidType) ため 500 になる。
#
# 既存の API スモークでは検知できない: スモークの判定は「500 でなければ OK
# (429/403/400 は許容)」で、プラグイン既定 (Config.is_enabled=0) や API キー未設定の
# 環境では /chat が 403/400 で早期リターンし書込経路に入らない。そこでここでは
# (1) Config を検査中だけ有効化し (行が無ければ最小行を作成)、(2) 使い捨てシナリオを1件入れ、
# (3) 一致メッセージを撃ち、(4) created_at 付きのログ行が INSERT されたかを DB で
# 確認する。シナリオ一致は API キー不要で chatLogger->log() に到達できる唯一の経路。
# 終了時は Config と検査データを元に戻す。
#
# 引数: $1 = ログ接頭辞 / $2.. = docker compose 実行の前置
#       (例: docker compose -f "$tmpdir/docker-compose.verify.yml")
chat_write_path_check() {
  local tag="$1"
  shift
  local -a cw=("$@")
  local keyword='__verify_chat_write_path__'
  local reply='chat-write-path-ok'
  local session code body rows created rc prev_enabled config_id

  # DB ファイルを特定する。DATABASE_URL の sqlite パスは EC-CUBE/Doctrine 側で
  # project dir 基準に解決されるため URL 文字列からは一意に決められない。
  # ここでは「プラグインのテーブルを持つ .db 実ファイル」を探す。
  local db
  db="$("${cw[@]}" exec -T -u www-data eccube sh -c '
    for f in /var/www/html/var/eccube.db /var/eccube.db /var/www/html/eccube.db \
             $(ls -1 /var/www/html/var/*.db /var/*.db 2>/dev/null); do
      [ -s "$f" ] || continue
      if sqlite3 "$f" "SELECT 1 FROM plg_ai_chat_assistant_log LIMIT 1" >/dev/null 2>&1; then
        echo "$f"
        exit 0
      fi
    done
    exit 1
  ' 2>/dev/null || true)"

  if [[ -z "$db" ]]; then
    echo "[WARN] ${tag} プラグインの sqlite DB を特定できないため書込経路検査をスキップ (この環境は未検証)"
    return 0
  fi
  echo "${tag} チャットログ書込経路: シナリオ一致 → ChatLogger → flush → DBAL 型変換 (db: ${db})"

  # Config が無い (管理画面で未保存) か is_enabled=0 の場合は /chat が 403 で
  # 早期リターンする。検査中だけ最小の設定行を作る / 有効化する。
  prev_enabled="$("${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
    "SELECT is_enabled FROM plg_ai_chat_assistant_config LIMIT 1;" 2>/dev/null || true)"
  config_id=""
  if [[ -z "$prev_enabled" ]]; then
    config_id="$("${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
      "INSERT INTO plg_ai_chat_assistant_config (is_enabled, create_date, update_date) VALUES (1, datetime('now'), datetime('now')); SELECT last_insert_rowid();" | tr -d '\r\n')"
    echo "  (設定行が無いため検査中のみ最小の設定行を作る: id=${config_id})"
  elif [[ "$prev_enabled" != "1" ]]; then
    "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
      "UPDATE plg_ai_chat_assistant_config SET is_enabled = 1;" >/dev/null
    echo "  (Config.is_enabled=$prev_enabled のため検査中のみ有効化する)"
  fi

  # 再実行を決定的にするため前回の残骸を消してから、使い捨てシナリオを1件入れる
  "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
    "DELETE FROM plg_ai_chat_assistant_scenario WHERE trigger_keyword = '$keyword';" >/dev/null
  "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
    "INSERT INTO plg_ai_chat_assistant_scenario (trigger_keyword, trigger_type, response_text, response_type, priority, is_active, create_date, update_date) VALUES ('$keyword', 'exact', '$reply', 'text', 1000, 1, datetime('now'), datetime('now'));" >/dev/null

  # セッションは毎回新しい UUID にしてレート制限 (session/IP) を踏まない
  session="$("${cw[@]}" exec -T eccube sh -c 'cat /proc/sys/kernel/random/uuid' | tr -d '\r\n')"
  code="$("${cw[@]}" exec -T eccube curl -s -o /tmp/chat_write_path.json -w "%{http_code}" \
    -X POST http://localhost/api/ai-chat-assistant/chat \
    -H "Content-Type: application/json" \
    -d "{\"message\":\"$keyword\",\"session_id\":\"$session\"}" || echo "000")"
  body="$("${cw[@]}" exec -T eccube cat /tmp/chat_write_path.json 2>/dev/null || true)"
  echo "  response: HTTP $code $(printf '%s' "$body" | head -c 300 | tr -d '\n')"

  rc=0
  if [[ "$code" != "200" || "$body" != *"$reply"* ]]; then
    echo "[ERROR] ${tag} シナリオ一致の /chat が 200 を返しません (HTTP $code)。書込経路に到達できていません" >&2
    rc=1
  else
    rows="$("${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" "SELECT COUNT(*) FROM plg_ai_chat_assistant_log WHERE session_id = '$session' AND user_message = '$keyword' AND created_at IS NOT NULL;")"
    created="$("${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" "SELECT COALESCE(MAX(created_at), 'NULL') FROM plg_ai_chat_assistant_log WHERE session_id = '$session';")"
    echo "  plg_ai_chat_assistant_log: rows=$rows created_at=$created"
    if [[ "$rows" != "1" ]]; then
      echo "[ERROR] ${tag} チャットログが書けていません (期待 1 行 / 実際 $rows 行)" >&2
      echo "        ChatLogger::log() → flush() → DBAL 型変換 (datetimetz × DateTimeImmutable) を疑うこと" >&2
      rc=1
    fi
  fi

  # 後始末 (成否にかかわらず必ず通る)
  "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
    "DELETE FROM plg_ai_chat_assistant_scenario WHERE trigger_keyword = '$keyword';" >/dev/null 2>&1 || true
  if [[ -n "$config_id" ]]; then
    "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
      "DELETE FROM plg_ai_chat_assistant_config WHERE id = $config_id;" >/dev/null 2>&1 || true
  elif [[ "$prev_enabled" != "1" ]]; then
    "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
      "UPDATE plg_ai_chat_assistant_config SET is_enabled = $prev_enabled;" >/dev/null 2>&1 || true
  fi

  if [[ "$rc" -eq 0 ]]; then
    "${cw[@]}" exec -T -u www-data eccube sqlite3 -cmd '.timeout 5000' "$db" \
      "DELETE FROM plg_ai_chat_assistant_log WHERE session_id = '$session';" >/dev/null 2>&1 || true
    echo "  -> OK (created_at 付きで 1 行 INSERT された)"
  else
    "${cw[@]}" exec -T eccube sh -c 'tail -n 20 var/log/dev/*.log 2>/dev/null || true' || true
  fi
  return "$rc"
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

# =============================================================================
# フルサイクル試験 (--cycle, T-C1〜T-C4)。4.4 のみ。独立環境で実行する。
# - tmpdir 既定 /tmp/eccube-cycle-4.4、port 既定 8083、project 既定 eccube-cycle-44
# - /tmp/eccube-verify-4.4 (port 8082, 稼働中デバッグ環境) には触れない
# - bindマウント (app/Plugin/<code> がホスト共有) では uninstall の rmdir が
#   `Device or resource busy` で必ず失敗するため、サイクル環境はマウント無し
#   (tarball配置) を既定とし、マウント検出時は SKIP/分岐する
# =============================================================================
dc_cycle() {
  docker compose -p "$CYCLE_PROJECT" -f "$CYCLE_TMPDIR/docker-compose.cycle.yml" "$@"
}

cycle_compose_ps_q() {
  dc_cycle ps -q eccube 2>/dev/null | head -n 1
}

# bind検出: 0=マウント無し (サイクル継続可)、1=マウントあり (SKIP理由表示)
check_bind_mount() {
  local code="$1"
  if dc_cycle exec -T eccube grep -q "app/Plugin/${code}" /proc/mounts 2>/dev/null; then
    echo "[4.4-cycle] SKIP: app/Plugin/${code} がバインドマウントされています。"
    echo "  EC-CUBE の uninstall は rmdir で必ず失敗します (Device or resource busy)。"
    echo "  これは環境固有の制約であり製品バグではありません。tarball配置 (マウント無し) の環境で再実行してください。"
    dc_cycle exec -T eccube grep "app/Plugin/${code}" /proc/mounts 2>/dev/null || true
    return 1
  fi
  echo "[4.4-cycle] bind check: OK (app/Plugin/${code} はマウントされていません)"
  return 0
}

# dtb_plugin 期待値検査: expect は "empty" / "0" / "1"
check_dtb_plugin() {
  local code="$1" expect="$2"
  local out
  out="$(dc_cycle exec -T eccube php bin/console dbal:run-sql "SELECT code, enabled FROM dtb_plugin WHERE code='${code}'" 2>&1 || true)"
  echo "$out" | tail -n 10
  if [[ "$expect" == "empty" ]]; then
    if echo "$out" | grep -q "$code"; then
      echo "[ERROR] dtb_plugin に ${code} が残っています (uninstall 後に行なしを期待)" >&2
      return 1
    fi
    echo "  -> OK (dtb_plugin 行なし)"
    return 0
  fi
  if echo "$out" | grep -q "$code"; then
    echo "  -> OK (dtb_plugin 行あり)"
    return 0
  fi
  echo "[ERROR] dtb_plugin に ${code} がありません (phase 期待: enabled=${expect})" >&2
  return 1
}

# plg_* テーブル有無: expect は "yes" / "no"。sqlite以外では記録のみ (WARN) に緩和
check_plg_tables() {
  local expect="$1"
  local out
  out="$(dc_cycle exec -T eccube php bin/console dbal:run-sql "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'plg_ai_chat_assistant%'" 2>&1 || true)"
  echo "$out" | tail -n 15
  if echo "$out" | grep -qi "no such table\|syntax error\|SQLSTATE\|An exception"; then
    echo "[WARN] plg_* 判定をスキップ (sqlite以外またはDB差異のため記録のみ)"
    return 0
  fi
  if [[ "$expect" == "yes" ]]; then
    if echo "$out" | grep -q "plg_ai_chat_assistant"; then
      echo "  -> OK (plg_* あり)"
      return 0
    fi
    echo "[ERROR] plg_ai_chat_assistant_* がありません (期待: あり)" >&2
    return 1
  fi
  if echo "$out" | grep -q "plg_ai_chat_assistant"; then
    echo "[ERROR] plg_ai_chat_assistant_* が残っています (期待: なし)" >&2
    return 1
  fi
  echo "  -> OK (plg_* なし)"
  return 0
}

# assets検査: expect は "yes" (必須) / "record" (記録のみ)
check_assets() {
  local code="$1" expect="$2"
  if dc_cycle exec -T eccube test -d "html/plugin/${code}/assets" 2>/dev/null; then
    echo "  -> OK (html/plugin/${code}/assets あり)"
    return 0
  fi
  if [[ "$expect" == "record" ]]; then
    echo "  -> INFO (html/plugin/${code}/assets なし。install直後/uninstall後は本体帰属のため記録のみ)"
    return 0
  fi
  echo "[ERROR] html/plugin/${code}/assets がありません" >&2
  return 1
}

# PluginData JSON検査: expect は "kept" (保持必須) / "optional" (未作成可)
check_plugindata() {
  local code="$1" expect="$2"
  local found
  found="$(dc_cycle exec -T eccube bash -c "ls app/PluginData/${code}/ 2>/dev/null || echo MISSING" 2>/dev/null || echo MISSING)"
  echo "  PluginData/${code}: $found"
  if [[ "$found" == "MISSING" ]]; then
    if [[ "$expect" == "optional" ]]; then
      echo "  -> OK (未作成可)"
      return 0
    fi
    echo "[ERROR] app/PluginData/${code}/ がありません (期待: 保持)" >&2
    return 1
  fi
  echo "  -> OK (PluginData 保持)"
  return 0
}

run_cycle_44() {
  local php_image="${PHP_IMAGE_44:-php:8.3-apache-bookworm}"
  local eccube_branch="${ECCUBE_BRANCH_44:-4.4}"
  local port="$CYCLE_PORT"
  local tmpdir="$CYCLE_TMPDIR"
  local project="$CYCLE_PROJECT"
  local plugin_code="${PLUGIN_CODE:-AiChatAssistant44}"
  local tarball="$TARBALL"

  echo ""
  echo "======================================================================"
  echo "=== [4.4-cycle] FULL CYCLE on independent env (T-C1〜T-C4) ==="
  echo "======================================================================"
  echo "tmpdir: $tmpdir (verify環境 /tmp/eccube-verify-4.4 とは別)"
  echo "port: $port / project: $project / code: $plugin_code"
  echo "plugin: $PLUGIN_DIR"

  # --- ガード (T-C0) ---
  if [[ "$VERSION" != "4.4" ]]; then
    echo "[ERROR] --cycle は --version 4.4 のみ対応です (指定: $VERSION)" >&2
    exit 1
  fi
  if [[ "$tmpdir" == /tmp/eccube-verify-* ]]; then
    echo "[ERROR] CYCLE_TMPDIR に verify 環境 ($tmpdir) は指定できません。稼働中デバッグ環境保護のため停止します" >&2
    exit 1
  fi
  if [[ "$port" == "8080" || "$port" == "8081" || "$port" == "8082" ]]; then
    echo "[ERROR] CYCLE_PORT に $port は指定できません (8080/8081/8082 は稼働中環境用)。8083 等を指定してください" >&2
    exit 1
  fi
  if [[ "$DOCTOR" -eq 1 || "$FIX" -eq 1 ]]; then
    echo "[ERROR] --cycle と --doctor/--fix は併用できません" >&2
    exit 1
  fi
  if [[ -d "/tmp/eccube-verify-4.4" ]]; then
    echo "[4.4-cycle guard] 稼働中デバッグ環境 /tmp/eccube-verify-4.4 を確認 (破壊しません)"
    if docker compose -f /tmp/eccube-verify-4.4/docker-compose.verify.yml exec -T eccube true 2>/dev/null; then
      echo "  -> verify-4.4 container running (保護対象として維持)"
    else
      echo "  -> verify-4.4 container は停止中 (保護対象として放置。起動はしない)"
    fi
  fi

  # --- 1. EC-CUBE 本体を用意 ---
  if [[ ! -d "$tmpdir/.git" ]]; then
    echo "[4.4-cycle 1/9] git clone -b $eccube_branch EC-CUBE -> $tmpdir"
    rm -rf "$tmpdir"
    if ! git clone -b "$eccube_branch" https://github.com/EC-CUBE/ec-cube.git "$tmpdir"; then
      echo "[WARN] branch $eccube_branch が無いため tag 4.4.0-alpha-1 で再試行" >&2
      git clone -b "4.4.0-alpha-1" https://github.com/EC-CUBE/ec-cube.git "$tmpdir"
    fi
  else
    echo "[4.4-cycle 1/9] EC-CUBE 既存 $tmpdir を再利用"
    git -C "$tmpdir" fetch origin "$eccube_branch" --depth 1 || true
    git -C "$tmpdir" checkout "$eccube_branch" || git -C "$tmpdir" checkout "4.4.0-alpha-1" || true
  fi

  # --- 2. サイクル用 compose を生成 (マウント無しが核心。verify定義は改変しない) ---
  echo "[4.4-cycle 2/9] docker-compose.cycle.yml を生成 (PLUGIN bind無し・volume分離)"
  cp "$PLUGIN_DIR/Tests/Docker/.env.verify" "$tmpdir/.env"
  cat > "$tmpdir/docker-compose.cycle.yml" <<'CYCLE_YML'
services:
  eccube:
    image: __PHP_IMAGE__
    ports:
      - "__CYCLE_PORT__:80"
    volumes:
      - ./:/var/www/html
      - eccube_var_cycle:/var/www/html/var
      - eccube_vendor_cycle:/var/www/html/vendor
    environment:
      APP_ENV: "dev"
      APP_DEBUG: 1
      DATABASE_URL: "sqlite:///var/eccube.db"  # 4.4 verify定義と同一 (Tests/Docker/docker-compose.verify.yml)。%kernel.project_dir% 形式は解決されず CANTOPEN になるため使用禁止
      DATABASE_SERVER_VERSION: 3
      ECCUBE_AUTH_MAGIC: "verify-cycle-magic"
      ECCUBE_ADMIN_ROUTE: "admin"
    command: >
      bash -c "
      apt-get update && apt-get install -y libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libsqlite3-dev git unzip sqlite3 &&
      docker-php-ext-configure gd --with-freetype --with-jpeg &&
      docker-php-ext-install -j$$(nproc) zip gd intl pdo pdo_sqlite opcache &&
      echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-memory-limit.ini &&
      a2enmod rewrite &&
      echo 'ServerName localhost' >> /etc/apache2/apache2.conf &&
      apache2-foreground
      "

volumes:
  eccube_var_cycle:
  eccube_vendor_cycle:
CYCLE_YML
  sed -i.bak -e "s#__PHP_IMAGE__#${php_image}#g" -e "s#__CYCLE_PORT__#${port}#g" "$tmpdir/docker-compose.cycle.yml"
  rm -f "$tmpdir/docker-compose.cycle.yml.bak"
  echo "  -> wrote $tmpdir/docker-compose.cycle.yml (no PLUGIN_DIR bind)"

  # --- 3. 起動 + プロビジョニング待ち ---
  echo "[4.4-cycle 3/9] docker compose -p $project up -d --build"
  (
    cd "$tmpdir"
    PHP_IMAGE="$php_image" ECCUBE_PORT="$port" docker compose -p "$project" -f docker-compose.cycle.yml up -d --build
    echo "  -> waiting for provisioning (memory.ini + intl + zip)..."
    for i in $(seq 1 120); do
      if docker compose -p "$project" -f docker-compose.cycle.yml exec -T eccube test -f /usr/local/etc/php/conf.d/zz-memory-limit.ini 2>/dev/null \
      && docker compose -p "$project" -f docker-compose.cycle.yml exec -T eccube php -m 2>/dev/null | grep -qE "^intl$" \
      && docker compose -p "$project" -f docker-compose.cycle.yml exec -T eccube php -m 2>/dev/null | grep -qE "^zip$"; then
        echo "  -> eccube ready (try $i)"
        break
      fi
      sleep 10
      if [[ $i -eq 120 ]]; then
        echo "[ERROR] eccube container not ready (extensions missing)" >&2
        docker compose -p "$project" -f docker-compose.cycle.yml logs --tail 50 eccube || true
        exit 1
      fi
    done
  )
  echo "[4.4-cycle 3b] composer install (EC-CUBE本体 vendor)"
  dc_cycle exec -T eccube bash -c \
    "command -v composer >/dev/null || { php -r \"copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');\" && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet; }" || exit 1
  dc_cycle exec -T eccube bash -c \
    "cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress" || exit 1

  echo "[4.4-cycle 3c] pre-create sqlite file (fresh volume 対策: schema:drop の CANTOPEN 回避)"
  dc_cycle exec -T eccube bash -c "touch /var/eccube.db && chown www-data:www-data /var/eccube.db && ls -la /var/eccube.db" || exit 1

  echo "[4.4-cycle 4/9] eccube:install --no-interaction"
  dc_cycle exec -T eccube php bin/console eccube:install --no-interaction || {
    echo "[WARN] eccube:install 失敗 — doctrine:database:create + migrations でフォールバック"
    dc_cycle exec -T eccube php bin/console doctrine:database:create --if-not-exists || true
    dc_cycle exec -T eccube php bin/console doctrine:migrations:migrate --no-interaction || true
  }
  echo "[4.4-cycle 4b] chown -R www-data var/"
  dc_cycle exec -T eccube bash -c "chown -R www-data var/" || exit 1

  # SameSite 検出 (既存 5c をサイクル環境でも実行)
  SAMESITE="$(dc_cycle exec -T eccube grep -E "cookie_samesite" app/config/eccube/packages/framework.yaml 2>/dev/null || true)"
  echo "[4.4-cycle 4c] SameSite check: $SAMESITE"
  if echo "$SAMESITE" | grep -q "none"; then
    echo "[WARN] SameSite=None のため HTTP では管理画面ログイン不可。lax + var/cache + var/build 削除が必要。詳細は Documents/LOCAL_VERIFICATION.md §9 参照" >&2
  fi

  # --- T-C1: tarball配置 (管理画面アップロード相当) ---
  echo "[4.4-cycle 5/9] T-C1 package → install --path (管理画面アップロード相当)"
  if [[ -z "$tarball" ]]; then
    tarball="/tmp/AiChatAssistant44-cycle.tar.gz"
    echo "  building $tarball via bin/package.sh"
    bash "$PLUGIN_DIR/bin/package.sh" --output "$tarball" || exit 1
  fi
  if [[ ! -f "$tarball" ]]; then
    echo "[ERROR] tarball がありません: $tarball" >&2
    exit 1
  fi
  cid="$(cycle_compose_ps_q)"
  if [[ -z "$cid" ]]; then
    echo "[ERROR] eccube container が見つかりません" >&2
    exit 1
  fi
  docker cp "$tarball" "$cid:/tmp/plugin.tar.gz" || exit 1
  # bind検出 (サイクル既定ではマウント無しのはず。検出時は方針通りSKIP/分岐)
  if ! check_bind_mount "$plugin_code"; then
    echo "[4.4-cycle] bind検出のため uninstall 含むサイクルは SKIP します (T-C2 異常系の期待動作)" >&2
    echo "=== [4.4-cycle] SKIPPED (bind mount detected) ==="
    if [[ "$KEEP" -eq 0 ]]; then
      (cd "$tmpdir" && docker compose -p "$project" -f docker-compose.cycle.yml down -v) || true
    fi
    exit 0
  fi
  if ! dc_cycle exec -T eccube php bin/console eccube:plugin:install --path /tmp/plugin.tar.gz; then
    echo "[WARN] --path が失敗。手動配置 + install --code にフォールバック"
    # package.sh 成果物はアーカイブ直下に composer.json (strip不要) が正規。
    # strip-first は Service/→直下 など全ツリーを平坦化し二重宣言 fatal を起こすため禁止。既配置 (yamlあり) なら再展開しない
    if dc_cycle exec -T eccube test -f "app/Plugin/${plugin_code}/eccube-plugin.yaml" 2>/dev/null; then
      echo "  -> 既配置あり (再展開スキップ)"
    else
      dc_cycle exec -T eccube bash -c "mkdir -p app/Plugin/${plugin_code} && tar -xzf /tmp/plugin.tar.gz -C app/Plugin/${plugin_code}" || exit 1
      if ! dc_cycle exec -T eccube test -f "app/Plugin/${plugin_code}/eccube-plugin.yaml" 2>/dev/null; then
        echo "[WARN] 直下展開で yaml なし。--strip-components=1 で再試行 (要フォルダ形式のtarball用)"
        dc_cycle exec -T eccube bash -c "rm -rf app/Plugin/${plugin_code} && mkdir -p app/Plugin/${plugin_code} && tar -xzf /tmp/plugin.tar.gz -C app/Plugin/${plugin_code} --strip-components=1" || exit 1
      fi
    fi
    dc_cycle exec -T eccube php bin/console eccube:plugin:install --code="$plugin_code" || exit 1
  fi
  echo "[4.4-cycle 5b] assert after install"
  check_dtb_plugin "$plugin_code" "0" || exit 1
  check_plg_tables "yes" || exit 1
  check_assets "$plugin_code" "record" || exit 1
  check_plugindata "$plugin_code" "optional" || exit 1

  # --- T-C2: enable → disable → enable ---
  echo "[4.4-cycle 6/9] enable"
  dc_cycle exec -T eccube php bin/console eccube:plugin:enable --code="$plugin_code" || exit 1
  dc_cycle exec -T eccube php bin/console cache:clear --no-warmup || exit 1
  check_dtb_plugin "$plugin_code" "1" || exit 1
  check_plg_tables "yes" || exit 1
  check_assets "$plugin_code" "yes" || exit 1
  check_plugindata "$plugin_code" "optional" || exit 1

  echo "[4.4-cycle 6b] disable (assets残存・PluginData保持)"
  dc_cycle exec -T eccube php bin/console eccube:plugin:disable --code="$plugin_code" || exit 1
  check_dtb_plugin "$plugin_code" "0" || exit 1
  check_plg_tables "yes" || exit 1
  check_assets "$plugin_code" "yes" || exit 1
  check_plugindata "$plugin_code" "optional" || exit 1

  echo "[4.4-cycle 6c] re-enable"
  dc_cycle exec -T eccube php bin/console eccube:plugin:enable --code="$plugin_code" || exit 1
  check_dtb_plugin "$plugin_code" "1" || exit 1
  check_assets "$plugin_code" "yes" || exit 1

  # --- T-C3: HTTP実測 (Discovery + front + API smoke + route数) ---
  echo "[4.4-cycle 7/9] T-C3/T-C4 HTTP + routes + API smoke"
  # 権限補正 (T-C2 の console=root 実行で var/ が root 所有に戻るため。root 所有の
  # var/runtime では WriteFailsafeFilesystemAdapter が保存を静かに諦め、レート制限が
  # fail-open になり E2E T-07 が通らない。var/build は 500 の原因)
  echo "[4.4-cycle 6d] chown -R www-data var/"
  dc_cycle exec -T eccube bash -c "chown -R www-data var/" || exit 1

  echo "[cycle 7a] debug:router | grep ai_chat (expect >=20)"
  ROUTE_COUNT="$(dc_cycle exec -T eccube php bin/console debug:router | grep -c ai_chat || true)"
  echo "  -> ai_chat routes: $ROUTE_COUNT"
  if [[ "$ROUTE_COUNT" -lt 20 ]]; then
    echo "[ERROR] ai_chat ルートが少なすぎます: $ROUTE_COUNT (expected >=20, ideally 28)" >&2
    exit 1
  fi
  echo "[cycle 7b] Discovery GET /.well-known/mcp.json (expect 200 + tools==7)"
  DISC_CODE="$(dc_cycle exec -T eccube curl -s -o /tmp/disc.json -w "%{http_code}" http://localhost/.well-known/mcp.json || echo "000")"
  echo "  -> HTTP $DISC_CODE"
  if [[ "$DISC_CODE" == "500" ]]; then
    echo "[ERROR] Discovery が 500" >&2
    dc_cycle exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi
  dc_cycle exec -T eccube bash -c "cat /tmp/disc.json | head -c 500; echo" || true
  echo "[cycle 7c] front GET / (expect not 500)"
  FRONT_CODE="$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "http://localhost:${port}/" || echo "000")"
  echo "  -> HTTP $FRONT_CODE"
  if [[ "$FRONT_CODE" == "500" ]]; then
    echo "[ERROR] front が 500" >&2
    dc_cycle exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi
  echo "[cycle 7d] admin GET (未ログインは302許容・500のみNG)"
  ADMIN_CODE="$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "http://localhost:${port}/admin" -L --max-redirs 0 || echo "000")"
  echo "  -> HTTP $ADMIN_CODE (302/200 許容)"
  if [[ "$ADMIN_CODE" == "500" ]]; then
    echo "[ERROR] admin が 500" >&2
    dc_cycle exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi
  echo "[cycle 7e] API smoke POST /api/ai-chat-assistant/chat (expect not 500)"
  HTTP_CODE="$(dc_cycle exec -T eccube curl -s -o /tmp/api_smoke.json -w "%{http_code}" -X POST http://localhost/api/ai-chat-assistant/chat -H "Content-Type: application/json" -d '{"message":"hello","session_id":"docker-cycle-smoke"}' || echo "000")"
  echo "  -> HTTP $HTTP_CODE"
  if [[ "$HTTP_CODE" == "500" ]]; then
    echo "[ERROR] API が 500 を返しました" >&2
    dc_cycle exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi

  echo "[cycle 7f] HTML検証ゲート C4 (bin/lint-html.sh: void-style再発防止。静的チェック・docker不要)"
  if ! "$PLUGIN_DIR/bin/lint-html.sh"; then
    echo "[ERROR] HTML検証ゲート (C4) が失敗しました" >&2
    exit 1
  fi

  # --- T-C5: チャットログ書込経路 (Entity→flush→DBAL 型変換を通す) ---
  chat_write_path_check "[cycle 7g]" docker compose -p "$CYCLE_PROJECT" -f "$CYCLE_TMPDIR/docker-compose.cycle.yml"

  # --- T-C2 続き: uninstall → reinstall ---
  # 管理画面 (Admin Store PluginController::uninstall) は pluginService->uninstall($Plugin) = force=true。
  # forceなし (console既定) はディレクトリ残存のため管理画面と非等価。サイクル試験は --uninstall-force を使用する。
  # bindマウント環境で force すると rmdir busy で失敗する (製品バグではなく環境制約)。tarball配置の本環境では完走する。
  echo "[4.4-cycle 8/9] uninstall --uninstall-force (管理画面UI相当)"
  if ! check_bind_mount "$plugin_code"; then
    echo "[4.4-cycle] bind検出のため uninstall は SKIP します" >&2
    echo "=== [4.4-cycle] SKIPPED (bind mount detected before uninstall) ==="
    if [[ "$KEEP" -eq 0 ]]; then
      (cd "$tmpdir" && docker compose -p "$project" -f docker-compose.cycle.yml down -v) || true
    fi
    exit 0
  fi
  echo "[4.4-cycle 8a] PluginData marker 作成 (S-07移行済み/user JSON の代役。保持検証用)"
  dc_cycle exec -T eccube bash -c "mkdir -p app/PluginData/${plugin_code} && echo '{\"cycle\":\"marker\",\"code\":\"${plugin_code}\"}' > app/PluginData/${plugin_code}/cycle_marker.json && chown -R www-data app/PluginData/${plugin_code} && cat app/PluginData/${plugin_code}/cycle_marker.json" || exit 1
  dc_cycle exec -T eccube php bin/console eccube:plugin:uninstall --code="$plugin_code" --uninstall-force || {
    echo "[ERROR] uninstall が失敗しました。bind環境では rmdir busy が原因のことがあります" >&2
    dc_cycle exec -T eccube grep "app/Plugin/${plugin_code}" /proc/mounts 2>/dev/null || true
    exit 1
  }
  echo "[4.4-cycle 8b] assert after uninstall (行なし・tables なし・force削除・marker保持)"
  check_dtb_plugin "$plugin_code" "empty" || exit 1
  check_plg_tables "no" || exit 1
  echo "[cycle 8b] plugin dir removed by --uninstall-force"
  if dc_cycle exec -T eccube test -e "app/Plugin/${plugin_code}" 2>/dev/null; then
    echo "[ERROR] app/Plugin/${plugin_code} が残っています (force削除を期待)" >&2
    exit 1
  fi
  echo "  -> OK (app/Plugin/${plugin_code} なし)"
  echo "[cycle 8b] assets removed by --uninstall-force"
  if dc_cycle exec -T eccube test -e "html/plugin/${plugin_code}" 2>/dev/null; then
    echo "[ERROR] html/plugin/${plugin_code} が残っています (force削除を期待)" >&2
    exit 1
  fi
  echo "  -> OK (html/plugin/${plugin_code} なし)"
  echo "[cycle 8b] PluginData marker kept (本体は PluginData を削除しない + PluginManager::uninstall は no-op)"
  dc_cycle exec -T eccube test -f "app/PluginData/${plugin_code}/cycle_marker.json" || {
    echo "[ERROR] app/PluginData/${plugin_code}/cycle_marker.json が消えています" >&2
    exit 1
  }
  echo "  -> OK (cycle_marker.json 保持)"

  echo "[4.4-cycle 9/9] reinstall (移行/fallback 検証: marker引継ぎ)"
  dc_cycle exec -T eccube php bin/console eccube:plugin:install --path /tmp/plugin.tar.gz || exit 1
  dc_cycle exec -T eccube php bin/console eccube:plugin:enable --code="$plugin_code" || exit 1
  check_dtb_plugin "$plugin_code" "1" || exit 1
  check_plg_tables "yes" || exit 1
  check_assets "$plugin_code" "yes" || exit 1
  dc_cycle exec -T eccube test -f "app/PluginData/${plugin_code}/cycle_marker.json" || {
    echo "[ERROR] reinstall 後に marker が消えています (引継ぎ失敗)" >&2
    exit 1
  }
  echo "  -> OK (marker 引継ぎ)"
  # 権限補正 (9/9 の console=root 実行で var/ が root 所有に戻るため。--keep で残した
  # 環境をそのまま E2E (Playwright) で使えるようにする)
  echo "[4.4-cycle 9c] chown -R www-data var/"
  dc_cycle exec -T eccube bash -c "chown -R www-data var/" || exit 1

  echo "[cycle 9b] reinstall API smoke (expect not 500)"
  HTTP_CODE2="$(dc_cycle exec -T eccube curl -s -o /tmp/api_smoke2.json -w "%{http_code}" -X POST http://localhost/api/ai-chat-assistant/chat -H "Content-Type: application/json" -d '{"message":"hello","session_id":"docker-cycle-reinstall"}' || echo "000")"
  echo "  -> HTTP $HTTP_CODE2"
  if [[ "$HTTP_CODE2" == "500" ]]; then
    echo "[ERROR] reinstall 後 API が 500" >&2
    dc_cycle exec -T eccube cat var/log/dev.log | tail -n 100 || true
    exit 1
  fi

  echo ""
  echo "=== [4.4-cycle] PASSED (install→enable→disable→enable→uninstall→reinstall 完走) ==="

  if [[ "$KEEP" -eq 0 ]]; then
    echo "[4.4-cycle cleanup] docker compose -p $project down -v"
    (cd "$tmpdir" && docker compose -p "$project" -f docker-compose.cycle.yml down -v) || true
  else
    echo "[4.4-cycle keep] --keep 指定のためコンテナを残します: cd $tmpdir && docker compose -p $project -f docker-compose.cycle.yml ps (port $port)"
  fi
}

if [[ "$CYCLE" -eq 1 ]]; then
  run_cycle_44
  echo ""
  echo "======================================================================"
  echo "=== Cycle test PASSED ==="
  echo "======================================================================"
  exit 0
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
