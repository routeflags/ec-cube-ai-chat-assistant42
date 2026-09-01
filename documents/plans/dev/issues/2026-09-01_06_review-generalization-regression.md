# レビュー指摘: 汎用化対応の残存不整合とレート制限のセキュリティ懸念

## 対象
- 計画書: `documents/plans/dev/2026-09-01_ai-chat-assistant42-generalization-plan.md`
- 対象ブランチ差分: `ShopContextService` 新設, `ChatApiController#enforceRateLimit` 分離, `Entity/ChatLog#client_ip`, `DoctrineMigrations/Version20260901000000`, `ProductRepository`, `ChatFlowService`, `EmailReplyService`, `DesignSettingsSyncService`, `README/ADMIN_MANUAL/USE_CASES`

## 優先度
- 🔴 高: `$_SERVER['REMOTE_ADDR']` 直参照（Trusted Proxies未対応）
- 🟡 中: READMEルート数不一致(29表記 vs 実体28)、マイグレーション非冪等、CLI時の絶対URLが相対フォールバック
- 🟢 低: USE_CASESの特定商材名（THCH/リキッド）例示の汎用化余地、ProductRepository fallback匿名クラスの責務

## 指摘事項

### 1. [高] `ChatApiController::enforceRateLimit` が `$_SERVER['REMOTE_ADDR']` を直参照 — リバースプロキシでIP制限が無力化/誤爆
`$clientIp = $_SERVER['REMOTE_ADDR'] ?? ''` は `Request::getClientIp()`（`trusted_proxies`考慮）を経由していない。EC-CUBE本番は多くの場合リバースプロキシ配下で `REMOTE_ADDR=127.0.0.1` に集約され、IP制限が「単一IPに集約→全員で共有制限に達する」か「外部IPが取得できずスキップ」になる。セッション制限は分離済みで後方互換のtry-catchはあるが、IP制限の実効性が環境依存で失われる。

### 2. [中] READMEのルート数表記が実体と不一致
READMEは `29ルート` と記載するが `Resource/config/routes.yaml` の `ai-chat` を含む定義は28件。`grep -c "ai-chat" routes.yaml` で28。計画の「29ルート修正」は未達。ドキュメント整合の受け入れ（実数と一致）を満たさない。どちらかを正に合わせる必要がある。

### 3. [中] `Version20260901000000` が非冪等（`IF NOT EXISTS`なし）
`ALTER TABLE ... ADD client_ip` / `CREATE INDEX` にガードがない。Doctrineはバージョン管理で再実行しない前提だが、適用失敗後の手動リトライや `doctrine:migrations:migrate --dry-run` 検証で重複エラーになる。`ADD COLUMN IF NOT EXISTS` 相当のガード or `SchemaManager` での存在チェックを検討。`down()`の順序（index→column）は正しい。

### 4. [中] CLI/非リクエスト文脈で絶対URLが相対にフォールバック
`ShopContextService::getBaseUrl()` は `Request` なし かつ `UrlGenerator::getContext()->getHost()==''` のとき `''` を返す。`ChatFlowService::buildSystemPrompt` は `''` 時に `当ショップ` ラベルにし、`EmailReplyService::getShopUrl()` は `'/'` にフォールバック。メール本文やAI回答のリンクが相対パス（`/products/detail/{id}`）で出力され、メールクライアントや外部AI引用でリンク切れになる。`BaseInfo`や `eccube_base_url` パラメータからのフォールバックが望ましい。

### 5. [低] `ProductRepository::createFallbackShopContextService` の匿名クラスが責務逸脱
テスト用フォールバックとして `BaseInfoRepository` を無引数で継承し `get()` を偽装。DIで注入されない古いキャッシュや単体テスト外で本番パスがフォールバックに落ちた場合、ショップ名が常に空で `このショップ` になる。`services.yaml` で `ShopContextService` を明示定義しnullableではなく必須注入にする方が破壊検出が容易。

### 6. [低] `USE_CASES.md` の特定商材例示の汎用化余地
`CBD リキッド` / `THCH ペーパー` は thch-vape.shop 固有カテゴリの例示。allowlist（維持対象）はライセンス/OSS参照URLのみと計画で定義されており、ユースケースの例示も「汎用的な商品例（例: Tシャツ、ワイン）」に置換するか、冒頭で「例示であり任意商材に置換可能」と注記すべき。現状は汎用プラグインの文書として商材バイアスが残る。

## 改善案
1. `ChatApiController::enforceRateLimit(Request $request)` に `Request` を渡し `$request->getClientIp() ?? ''` に置換。`ChatLogger::log` も同様に `RequestStack` から取得するか、Controllerで `client_ip` を明示渡しにする。`$_SERVER` 直参照を全削除し、`framework.trusted_proxies` 設定を併記。
2. `routes.yaml` の実数を `grep -c "^ai_chat"` 等で再計測し README の `29` を `28` に修正、または欠落ルートがあれば追加。CIで `routes:debug | wc -l` とREADMEの数値を突合する簡易スクリプトを追加。
3. マイグレーションに存在チェックを追加（例: `$schema->hasTable` / `$table->hasColumn('client_ip')` でガード）または `ADD COLUMN IF NOT EXISTS` 互換の分岐。少なくともコメントで非冪等の旨を明記。
4. `ShopContextService::getBaseUrl()` の最終フォールバックで `eccube_base_url` パラメータ or `BaseInfo` のショップURL設定を参照。`EmailReplyService::getShopUrl()` も同様。
5. `ProductRepository` の ShopContext 注入を必須化し、テスト時のみモックを渡す設計に変更。匿名 fallback は削除するか `@internal` 明記。
6. `USE_CASES.md` の例示を汎用化（例: 「ワイヤレスイヤホン」「オーガニックコットンTシャツ」）に置換、または注記「以下は例示。任意の商品カテゴリに読み替えてください」を追記。

## 備考
- EasyArticle参照除去は完全（`plg_ea_article` 参照0件、tool定義10件、ChatFlowServiceは `dtb_news` のみ、try-catchで空文字フォールバックあり）。回帰なし。
- thch-vape.shop / admin-dev のハードコードはコード0件、READMEの `/<admin_route>/` 表記は適正。User-Agentは `github` に是正済み。
- レート制限の分離（session:閾値 / ip:閾値*2）とtry-catch後方互換は維持。仕様の `ipLimit = rateLimit*2` は計画に明記がないため README/コメントに追記推奨。
