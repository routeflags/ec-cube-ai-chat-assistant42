# ローカル検証手順（EC-CUBE 4.2/4.3/4.4 + Docker）

本プラグイン `AiChatAssistant44` をローカルの EC-CUBE 環境でインストール・有効化・動作確認する手順です。
本ブランチは EC-CUBE 4.4 (Symfony 7.4 / PHP 8.2〜8.5) 対応版です。4.2/4.3 用の `AiChatAssistant42` とは code 分離しています (D-01)。
`cosme_eccube4` は PHP 7.4 のため要件（PHP >=8.2）を満たしません。PHP 8.1 (4.2) / 8.2 (4.3) / 8.3 (4.4) の EC-CUBE 環境を新規に立ち上げることを推奨します。

> **ワンコマンド**: `./bin/verify-docker-install.sh --version 4.4` で 4.4 (8082) を自動検証できます。`--version all` で 4.2 (8080) + 4.3 (8081) + 4.4 (8082) の一気通し。`--version both` は既存互換 (4.2+4.3のみ)。審査 3607 の `dep-on-root` / `ContainerInterface` も事前にローカルゲートで検出します。詳細は `bin/verify-docker-install.sh --help` を参照。

---

## 0. 前提

- Docker 27.x / Docker Compose v2.31+
- 本リポジトリ `ec-cube-ai-chat-assistant42` がローカルにクローン済みであること（例: `../ec-cube-ai-chat-assistant42`）。本ブランチ `feature/eccube-44-support` を使用すること。
- ポート 8080 (4.2) / 8081 (4.3) / 8082 (4.4) / 4430 が空いていること
- `PHP_IMAGE` / `ECCUBE_PORT` / `PLUGIN_MOUNT` 環境変数で EC-CUBE 4.2/4.3/4.4 を切り替え可能 (`docker-compose.verify.yml` は `${PHP_IMAGE:-php:8.1-apache-bullseye}` / `${ECCUBE_PORT:-8080}` / `${PLUGIN_MOUNT:-AiChatAssistant44}`)。4.4 は `php:8.3-apache-bullseye` + `PLUGIN_MOUNT=AiChatAssistant44` + port 8082。

EC-CUBE 本体は検証用に `/tmp/eccube-verify` にクローンします（既存の `cosme_eccube4` は利用しません）。

---

## 1. 検証環境のセットアップ（初回のみ）

```bash
# EC-CUBE 4.2 をクローン（PHP 8.1 対応）
git clone -b 4.2 https://github.com/EC-CUBE/ec-cube.git /tmp/eccube-verify
cd /tmp/eccube-verify

# 検証用 docker-compose を本プラグインからコピー
# PLUGIN_DIR は任意のパスに置き換えてください
PLUGIN_DIR=/path/to/ec-cube-ai-chat-assistant42
cp $PLUGIN_DIR/Tests/Docker/docker-compose.verify.yml ./docker-compose.verify.yml
cp $PLUGIN_DIR/Tests/Docker/.env.verify ./.env
# またはプラグインが sibling の場合:
# cp ../ec-cube-ai-chat-assistant42/Tests/Docker/docker-compose.verify.yml ./docker-compose.verify.yml
# cp ../ec-cube-ai-chat-assistant42/Tests/Docker/.env.verify ./.env

# 起動（sqlite, php:8.1-apache）
docker compose -f docker-compose.verify.yml up -d --build
docker compose -f docker-compose.verify.yml ps

# 初回インストール（DB作成・マイグレーション）
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:install --no-interaction
# もしくは手動セットアップ
docker compose -f docker-compose.verify.yml exec eccube php bin/console doctrine:database:create --if-not-exists
docker compose -f docker-compose.verify.yml exec eccube php bin/console doctrine:migrations:migrate --no-interaction
```

`docker-compose.verify.yml` は本プラグインを `app/Plugin/AiChatAssistant44` にマウントします。ホスト側で編集した内容が即座にコンテナへ反映されます。

---

## 2. プラグインのインストールと有効化

```bash
cd /tmp/eccube-verify

# プラグインがマウントされていることを確認
docker compose -f docker-compose.verify.yml exec eccube ls -la app/Plugin/AiChatAssistant44/eccube-plugin.yaml

# インストール
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:plugin:install --code=AiChatAssistant44

# 有効化
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:plugin:enable --code=AiChatAssistant44

# キャッシュクリア
docker compose -f docker-compose.verify.yml exec eccube php bin/console cache:clear --no-warmup
docker compose -f docker-compose.verify.yml exec eccube php bin/console cache:warmup
```

期待結果:
- `install` が `Plugin installed: AiChatAssistant44` で成功
- `enable` が `Plugin enabled` で成功
- `app/PluginData/` 配下に `design_settings.json` が生成される

失敗時の確認:
```bash
docker compose -f docker-compose.verify.yml exec eccube cat var/log/dev.log | tail -n 100
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:plugin:list
```

---

## 3. 管理画面の確認

1. ブラウザで `http://localhost:8080/admin` を開く（初期ID `admin` / `password`）
2. 左メニュー `設定 > AIチャットアシスタント` に以下が表示されること
   - ダッシュボード
   - プラグイン設定
   - チャット履歴
   - 統計・レポート
   - ナレッジ管理
   - シナリオ管理
   - アクセスルール
   - デザイン設定
   - 通知ルール
3. `プラグイン設定` でプロバイダを `OpenAI`、ダミーのAPIキー `sk-test-dummy` を入力し保存。`is_enabled` を ON にする。

ルートの確認:
```bash
docker compose -f docker-compose.verify.yml exec eccube php bin/console debug:router | grep ai_chat
# 28ルートが表示されること（admin 24 + api 4）
```

---

## 4. フロントとAPIの動作確認

### 4.1 チャットウィジェット

- `http://localhost:8080/` を開き、右下にチャットボタンが表示されること
- ボタンをクリックして `こんにちは！商品についてお気軽にご質問ください。` が表示されること
- ブラウザコンソールでエラーがないこと

### 4.2 API 直接呼び出し（レート制限・バリデーション）

```bash
# 正常系: message 必須
curl -s http://localhost:8080/api/ai-chat-assistant/chat \
  -H "Content-Type: application/json" \
  -d '{"message":"在庫はありますか？","session_id":"verify-test-001"}' | jq .

# 異常系: message欠落 → 400
curl -s http://localhost:8080/api/ai-chat-assistant/chat \
  -H "Content-Type: application/json" \
  -d '{}' | jq .

# 異常系: プラグイン無効時 → 403（設定で無効にしてから再実行）
# レート制限: 同一sessionで連続10回 → 11回目で 429 (session) が返ること
for i in $(seq 1 11); do
  curl -s http://localhost:8080/api/ai-chat-assistant/chat \
    -H "Content-Type: application/json" \
    -d "{\"message\":\"test $i\",\"session_id\":\"rate-test\"}" | jq .error
done

# IP制限: 同一IPで別sessionを大量投下 → 429 (ip) が返ること（閾値 x2）
```

### 4.3 フィードバックとメール返信

```bash
# feedback: positive
curl -s http://localhost:8080/api/ai-chat-assistant/feedback \
  -H "Content-Type: application/json" \
  -d '{"session_id":"verify-test-001","feedback":"positive"}' | jq .

# feedback 重複 → 409
curl -s http://localhost:8080/api/ai-chat-assistant/feedback \
  -H "Content-Type: application/json" \
  -d '{"session_id":"verify-test-001","feedback":"positive"}' | jq .

# email-reply-request: 事前にチャットログが必要
curl -s http://localhost:8080/api/ai-chat-assistant/email-reply-request \
  -H "Content-Type: application/json" \
  -d '{"session_id":"verify-test-001","email":"user@example.com"}' | jq .
```

---

## 5. DBとマイグレーションの確認

```bash
# マイグレーション一覧
docker compose -f docker-compose.verify.yml exec eccube php bin/console doctrine:migrations:status

# client_ip カラムが追加されていること
docker compose -f docker-compose.verify.yml exec eccube php bin/console doctrine:schema:validate
docker compose -f docker-compose.verify.yml exec eccube sqlite3 var/eccube.db "PRAGMA table_info(plg_ai_chat_assistant_log);" | grep client_ip
```

---

## 9. EC-CUBE 4.4 の SameSite/HTTPS 注意 (必須)

EC-CUBE 4.4.0-alpha-1 ではセッション Cookie に `SameSite=None` を付与するため、HTTP (`php -S` / 本 `docker-compose.verify.yml`) で動かすとインストール後に管理画面へログインできません (公式リリースノートより)。

- HTTP (`php -S` / 本compose) で検証する場合: `app/config/eccube/packages/framework.yaml` の `cookie_samesite: none` を `lax` に変更し、変更後は `var/cache` と `var/build` を削除してください。
- HTTPS (`symfony server:start` 等) で動かせる環境ではこの変更は不要です。
- `bin/verify-docker-install.sh --version 4.4` は `framework.yaml` の `cookie_samesite` を検出し、`none` のままの場合は WARN を表示します。WARN が出たら上記切替を行って再検証してください。
- Playwright 4.4 検証: `E2E_BASE_URL=http://localhost:8082 npx playwright test` (HTTP+lax 前提。V-04/V-05)。

---

## 6. クリーンアップ

```bash
cd /tmp/eccube-verify
docker compose -f docker-compose.verify.yml down -v
# 必要ならクローンを削除
rm -rf /tmp/eccube-verify
```

---

## 7. トラブルシューティング

| 症状 | 確認コマンド |
|------|-------------|
| `eccube:plugin:install` が `code not found` | `ls app/Plugin/AiChatAssistant44/eccube-plugin.yaml` がコンテナ内にあるか |
| 管理画面にメニューが出ない | `php bin/console cache:clear` → ブラウザ再読み込み |
| APIが 500 | `var/log/dev.log` と `docker compose logs eccube` を確認 |
| チャットが表示されない | `Resource/config/services.yaml` の `ChatWidgetListener` が `kernel.event_subscriber` として登録されているか |
| マイグレーション失敗 | `doctrine:migrations:migrate --dry-run` でSQLを確認 |
| MCP が 429 誤爆 / 全員 127.0.0.1 扱い | `config/packages/framework.yaml` の `trusted_proxies: ['127.0.0.1','REMOTE_ADDR']` と `trusted_headers: ['x-forwarded-for','x-forwarded-proto']` を設定 — Cloudflare 環境で未設定だと `getClientIp()` が proxy IP を返し全ユーザが同一バケットを共有する |
| MCP が http を返す / mixed content | 同上 `trusted_proxies` 未設定だと `getSchemeAndHttpHost()` が http を返し `/.well-known/mcp.json` の `transport.url` が http になる — 上記設定で https を正しく取得する |

---

## 8. 本番同等の検証（オプション）

- 本番では `thch-vape.shop` 固定が残っていないことを確認:
  ```bash
  grep -r "thch-vape.shop" app/Plugin/AiChatAssistant44 --include="*.php" | wc -l
  # 0 であること
  ```
- `EasyArticle` 未導入でもチャットが 200 を返すこと:
  ```bash
  sqlite3 var/eccube.db "SELECT name FROM sqlite_master WHERE type='table' AND name='plg_ea_article';"
  # 空であること + APIが200
  ```

---

## 10. フルサイクル試験 `--cycle` (T-C1〜T-C4: install→enable→disable→enable→uninstall→reinstall)

稼働中デバッグ環境 `/tmp/eccube-verify-4.4` (port 8082) を破壊せず、独立環境でアンインストール試験まで完走する手順です。
T-01受入の `uninstall` が従来の install/enable 試験では未実施だったため追加しました (bind制約の記録を含む)。

```bash
# 既定: /tmp/eccube-cycle-4.4 + port 8083 + project eccube-cycle-44
./bin/verify-docker-install.sh --version 4.4 --cycle
# 環境を残す場合
./bin/verify-docker-install.sh --version 4.4 --cycle --keep
# 上書き例
CYCLE_TMPDIR=/tmp/eccube-cycle-4.4 CYCLE_PORT=8083 CYCLE_PROJECT=eccube-cycle-44 ./bin/verify-docker-install.sh --version 4.4 --cycle
```

- T-C1: `bin/package.sh` の tar.gz を `install --path` (管理画面アップロード相当) で導入。失敗時は手動配置 + `install --code` にフォールバック
- T-C2: 各遷移で `dtb_plugin` 行 / `plg_ai_chat_assistant_*` / `html/plugin` assets / `PluginData/*.json` (S-07) を検証。uninstall は `--uninstall-force` あり (管理画面 `PluginController::uninstall` は force=true が既定。forceなしはディレクトリ残存のため非等価)。保持検証は marker JSON (`cycle_marker.json`) で行う`plg_*` は本体がdrop、JSONは保持 (現 `PluginManager::uninstall` は no-op) のため reinstall は移行/fallback検証になる
- T-C3: `/.well-known/mcp.json` (200+tools==7)・`GET /`・`GET /admin` (302許容)・API smoke (非500) を実測
- T-C4: `ai_chat` ルート >=20 (理想28)・API非500の既存ゲートを維持。`--version 4.4 --keep/--quick/--doctor` は従来通り green

### bindマウント制約 (必読): `rmdir ... Device or resource busy` について

- `app/Plugin/<code>` がホスト共有 (bindマウント) の環境で EC-CUBE の uninstall を実行すると、`rmdir` が `Device or resource busy` で必ず失敗し 500 になります
- これはマウント環境固有の制約であり、製品バグではありません。管理画面から再現した場合は試験環境の構成を見直してください
- `--cycle` は tarball配置 (マウント無し) の独立環境を既定とし、実行前に `/proc/mounts` で `app/Plugin/AiChatAssistant44` を検出します。検出時は uninstall を SKIP し、理由を表示して `SKIPPED (bind mount detected)` で終了します
- 稼働中デバッグ環境 (`/tmp/eccube-verify-4.4`, port 8082) は `--cycle` の保護対象です。`CYCLE_TMPDIR=/tmp/eccube-verify-*` と `CYCLE_PORT=8080/8081/8082` は拒否されます

## 11. リリース必須ゲート (必読)

Store提出用 tarball (`make ship` / `bin/package.sh`) を作成する前に、以下3点の green を必須関門とします。
1つでも red/skip (環境不備による skip を含む) のままリリースしないこと。

| # | 関門 | コマンド | 証跡 |
|---|------|---------|------|
| G-1 | フルサイクル `--cycle` (T-C1〜T-C4) green | `./bin/verify-docker-install.sh --version 4.4 --cycle` (§10) | ログの `T-C1`〜`T-C4` 完走 + `admin 500なし` |
| G-2 | E2E (T-01〜T-09) green | `E2E_BASE_URL=http://localhost:8083 npm run test:e2e` (T-09は `E2E_ADMIN_ID`/`E2E_ADMIN_PASSWORD` 上書き可、既定 `admin`/`password` は docker verify env限定) | `42 passed` (T-09: 管理9画面 200) |
| G-3 | Matrix 本番一致 (DBAL 4 / ORM 3) green | `make test-matrix` (内蔵 `matrix-guard` が DBAL4/ORM3 を検証。`composer.json` require-dev は `^4.0`/`^3.0`/`^7.4` に固定) | `matrix-guard: OK (DBAL4/ORM3)` + `Tests/Matrix` green |

- `make ship` (`verify → test-unit → test-matrix → package`) は G-3 を含みますが、G-1 (`--cycle`) と G-2 (E2E) は docker 実機が必要なため別途実行し、本節の表をチェックリストとして使ってください。
- `composer.lock` は plugin ではコミットしない方針 (`.gitignore`) のため、本番一致は `composer.json` の範囲固定 + `make matrix-guard` の二重化で担保します。DBAL 3 系での green を DBAL 4 の保証とみなさないこと (`getName()` 500 の再発防止)。
- 4.2系への反映は見送り。理由: (1) 4.2本番相当は DBAL3/ORM2/Symfony6.4 (`AiChatAssistant42` の `composer.json` が `^3.6`/`^2.15`/`^6.4` に固定) のため DBAL4固定化は移植対象外、(2) 4.2 の `verify-docker-install.sh` に `--cycle` 基盤が無いため T-C1〜T-C4 移植は別途infra移植が必要、(3) 4.2 の e2e は T-01〜T-08 で green 維持中であり T-09 移植は別issueで判断する。

