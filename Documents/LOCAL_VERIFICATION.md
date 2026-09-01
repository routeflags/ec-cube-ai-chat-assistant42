# ローカル検証手順（EC-CUBE 4.2 + Docker）

本プラグイン `AiChatAssistant42` をローカルの EC-CUBE 環境でインストール・有効化・動作確認する手順です。
`cosme_eccube4` は PHP 7.4 のため要件（PHP >=8.0）を満たしません。PHP 8.1 の EC-CUBE 4.2 環境を新規に立ち上げることを推奨します。

---

## 0. 前提

- Docker 27.x / Docker Compose v2.31+
- 本リポジトリ `ec-cube-ai-chat-assistant42` がローカルにクローン済みであること（例: `../ec-cube-ai-chat-assistant42`）
- ポート 8080 / 4430 が空いていること

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
cp $PLUGIN_DIR/docker-compose.verify.yml ./docker-compose.verify.yml
cp $PLUGIN_DIR/.env.verify ./.env
# またはプラグインが sibling の場合:
# cp ../ec-cube-ai-chat-assistant42/docker-compose.verify.yml ./docker-compose.verify.yml
# cp ../ec-cube-ai-chat-assistant42/.env.verify ./.env

# 起動（sqlite, php:8.1-apache）
docker compose -f docker-compose.verify.yml up -d --build
docker compose -f docker-compose.verify.yml ps

# 初回インストール（DB作成・マイグレーション）
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:install --no-interaction
# もしくは手動セットアップ
docker compose -f docker-compose.verify.yml exec eccube php bin/console doctrine:database:create --if-not-exists
docker compose -f docker-compose.verify.yml exec eccube php bin/console doctrine:migrations:migrate --no-interaction
```

`docker-compose.verify.yml` は本プラグインを `app/Plugin/AiChatAssistant42` にマウントします。ホスト側で編集した内容が即座にコンテナへ反映されます。

---

## 2. プラグインのインストールと有効化

```bash
cd /tmp/eccube-verify

# プラグインがマウントされていることを確認
docker compose -f docker-compose.verify.yml exec eccube ls -la app/Plugin/AiChatAssistant42/eccube-plugin.yaml

# インストール
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:plugin:install --code=AiChatAssistant42

# 有効化
docker compose -f docker-compose.verify.yml exec eccube php bin/console eccube:plugin:enable --code=AiChatAssistant42

# キャッシュクリア
docker compose -f docker-compose.verify.yml exec eccube php bin/console cache:clear --no-warmup
docker compose -f docker-compose.verify.yml exec eccube php bin/console cache:warmup
```

期待結果:
- `install` が `Plugin installed: AiChatAssistant42` で成功
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
| `eccube:plugin:install` が `code not found` | `ls app/Plugin/AiChatAssistant42/eccube-plugin.yaml` がコンテナ内にあるか |
| 管理画面にメニューが出ない | `php bin/console cache:clear` → ブラウザ再読み込み |
| APIが 500 | `var/log/dev.log` と `docker compose logs eccube` を確認 |
| チャットが表示されない | `Resource/config/services.yaml` の `ChatWidgetListener` が `kernel.event_subscriber` として登録されているか |
| マイグレーション失敗 | `doctrine:migrations:migrate --dry-run` でSQLを確認 |

---

## 8. 本番同等の検証（オプション）

- 本番では `thch-vape.shop` 固定が残っていないことを確認:
  ```bash
  grep -r "thch-vape.shop" app/Plugin/AiChatAssistant42 --include="*.php" | wc -l
  # 0 であること
  ```
- `EasyArticle` 未導入でもチャットが 200 を返すこと:
  ```bash
  sqlite3 var/eccube.db "SELECT name FROM sqlite_master WHERE type='table' AND name='plg_ea_article';"
  # 空であること + APIが200
  ```

