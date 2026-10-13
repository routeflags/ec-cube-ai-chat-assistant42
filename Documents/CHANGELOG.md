# Changelog

## [Unreleased] - EC-CUBE 4.4 対応 (AiChatAssistant44)

### Added
- EC-CUBE 4.4 (Symfony 7.4 / PHP 8.2〜8.5) 対応を追加。同一リポジトリのまま code 分離 `AiChatAssistant44` (D-01)。別リポジトリは作らない。
- Entity 8件を Doctrine Attribute 化 (`Types::` + typed property)。テーブル名 `plg_ai_chat_assistant_*` は変更なし (データ消失禁止)。
- `PluginManager::enable/update/disable/uninstall(...): void` + `#[Override]`、`Nav::getNav(): array` + `#[Override]`、`EventSubscriber::getSubscribedEvents(): array` + `#[Override]`。`isMasterRequest` フォールバックを廃止し `isMainRequest` のみ (Symfony 7.4)。
- データ移行: `PluginData/AiChatAssistant42/*.json` (design_settings/ai_models) を新code側へ自動移行 + フォールバック参照 (S-07)。テーブルは据え置きのためDB移行不要。
- Docker 4.4 検証: `bin/verify-docker-install.sh --version 4.4|all` (php:8.3-apache-bullseye + port 8082 + code AiChatAssistant44)。`both` は既存互換 (4.2+4.3のみ)。`Tests/Docker/docker-compose.verify.yml` は `PLUGIN_MOUNT` 切替 + 新旧 vendor hide。`playwright.config.ts` は 8082 対応。`Makefile dbs-fetch DBS_BRANCH=4.4` + `dbs-status` に 8082 追加。
- SameSite 対応: HTTP 検証時は `framework.yaml cookie_samesite:none -> lax` + `var/cache` + `var/build` 削除が必要な旨を `Documents/LOCAL_VERIFICATION.md §9` に追記。スクリプト側で `none` 検出時は WARN 表示。HTTPS (`symfony server:start`) では不要。
- `composer.json` require-dev を DBAL `^4.0` / ORM `^3.0` / Symfony `^7.4` に固定 (4.4本番相当。`composer.lock` は plugin 非コミット方針のため範囲固定 + `make matrix-guard` で二重化)、`eccube-plugin.yaml` require を `php >=8.2` + `ec-cube ^4.4` に更新。
- `Tests/Stubs` を 4.4 実型に追従 (`AbstractPluginManager: void` 5件、`TemplateEvent::setParameter/addSnippet`、`AbstractRepository::createQueryBuilder` 等)。
- README 日英 + バッジを 4.4 (Symfony 7.4 / PHP >=8.2) に更新。
- E2E 管理画面スモーク (T-09): `e2e/mcp.spec.ts` に admin ログイン→管理9画面 (`dashboard|history|report|settings|knowledge|scenario|access|design|notification`) の 200 確認を追加 (計42テスト)。`E2E_ADMIN_ID`/`E2E_ADMIN_PASSWORD` で上書き可 (既定 `admin`/`password` は docker verify env限定)。T-01〜T-08 非破壊・graceful skip維持。
- Matrix 本番一致: `make matrix-guard` (installed.json の DBAL4/ORM3 検証) を `test-matrix`/`test-matrix-chaos` の前提化 (DBAL3 green の誤保証防止)。
- リリース必須ゲート: `Documents/LOCAL_VERIFICATION.md §11` に `--cycle` (T-C1〜T-C4) + E2E (T-01〜T-09) + Matrix (DBAL4/ORM3) の green を Store提出前の必須関門として明記。4.2系への反映は見送り (理由を §11 に記録)。

### Changed
- [破壊的変更] MCPツール `search_products` を `aichat_search_products` に改名 (公式MCPの同名ツールとの衝突回避)。旧名での `tools/call` はエラー (`isError: true`) になります。AIチャットの function calling 名も同一定義のため連動して変更されます。

### Fixed
- [4.4] `UTCDateTimeTzType` (DBAL 4) が `DateTimeImmutable` を受け付けない問題を修正: エンティティの日付setter 15件で可変 `DateTime` に正規化、`ChatLogRepository` の日付引数 15件も同様。チャット履歴保存の `EntityManagerClosed` 500を解消 (MySQL/PG/SQLiteのMatrix 45件で検証)。
- チャット履歴保存の失敗が元のエラーを隠さないよう `logChatSuccess` / `logChatError` を堅牢化 (DB例外時は monolog のみ)。

### Security
- 変更なし (CSRF/キーマスク/ログ匿名化/MCP サニタイズを維持)。


## [1.1.3] - 2026-09-09

### Fixed
- 価格の税込・税抜の誤表示を修正: EC-CUBE の `price02` は税抜保存が仕様のため、ページコンテキストの価格帯を「円（税込）」→「円（税抜）」に変更し、ツール定義（`search_products` / `get_product_detail` / `get_stock`）に税抜価格である旨を明記
- Discovery 文書（`/.well-known/mcp.json`）の表示名を DB のショップ名から参照するように変更（未設定・DB 不可時は従来の `EC-CUBE MCP` にフォールバック、`serverInfo.name` は不変）

## [1.1.2] - 2026-09-08

### Fixed
- オーナーズストア審査指摘に対応: `composer.json` の `description` をプラグイン名称「AIチャットアシスタント」に変更（[プラグイン仕様](https://doc4.ec-cube.net/plugin_spec) に準拠）
- `composer.json` の `require` に `ec-cube/plugin-installer: ^2.0` を追加（4.2系以降の仕様に準拠）
- 商品名・文書内の表記を「for EC-CUBE 4.2/4.3」に更新（4.3対応を明示）
- PostgreSQL/SQLite で商品検索が500エラーになる問題を修正（LIKEエスケープ文字を `!` に統一しDB差異を解消）
- PostgreSQL で商品の表示状態（真偽値）比較がエラーになる問題を修正（真偽値をバインドで渡すよう変更）

## [1.1.1] - 2026-09-07

### Fixed
- チャットウィジェットでIME変換中のEnter確定が誤送信されないよう修正（`compositionstart/end` + `e.isComposing || keyCode 229` ガード）
- 商品キーワード検索の `LIKE ... ESCAPE '\'` を `ESCAPE '\\'` に修正（MariaDB/MySQL ERROR 1064 で検索全滅する問題を解消）

## [1.1.0] - 2026-09-05

### Added
- Web MCP 対応（Streamable HTTP） — `POST /mcp`（initialize / tools/list / tools/call x7）+ `GET /.well-known/mcp.json`（Discovery, transport: streamable-http）
- MCP HTTP コントローラ `McpHttpController` / サービス `McpHttpService` / `RateLimitService`（`cache.app` で IP別 120/min, `get_stock` 60/min, PSR-6準拠キー）
- CORS（`ACAO: *` + `Vary: Origin` + `OPTIONS 204`）、`415` + `jsonrpc:"2.0"` 検証、サニタイズ（SQL漏洩防止）
- Playwright e2e `e2e/mcp.spec.ts` 33 tests（T-01〜T-08）+ `playwright.config.ts` + Docker 試験（`eccube-e2e-test-42:8085` で 33 passed）

### Fixed
- `LIKE` ワイルドカード `\%\_` エスケープ + `ESCAPE '\'`、在庫 `stock_unlimited=true` 時 `stock:null` 曖昧化
- RateLimit flaky（YmdHi 分跨ぎ）を上限 240 + 分跨ぎ警告で harden
- `verify-plugin.sh` の `routes >=28` 緩和、hardcode 除外

### Security
- `sanitizeErrorMessage` で `SQLSTATE/Doctrine/plg_/dtb_/INSERT/DELETE/TABLE/.php` を `Internal error` に置換

## [1.0.1] - 2026-09-04

### Fixed
- `ServiceWiring` 統合テスト追加、DI 不整合修正（DesignController）、イベントサブスクライバ改善

## [1.0.0] - 2026-08-15

### Added
- AI チャットウィジェット（フロントエンド）
- OpenAI / Anthropic / Google Gemini 3プロバイダ対応
- 外部 JSON モデル定義（`ai_models.json`）
- MCP サーバー（STDIO transport）
- 管理画面ダッシュボード（KPI / 統計 / エラーアラート）
- チャット履歴管理（検索 / フィルタ / 詳細表示）
- 統計・レポート（プロバイダ別 / モデル別 / 時間別 / CSV出力）
- ナレッジ管理（FAQ / 商品情報 CRUD）
- 自動応答シナリオ（キーワードトリガー / exact/contains/regex）
- デザイン設定（ウィジェット色 / サイズ / 位置）
- 通知設定（メール / Webhook / LINE）
- アクセス制限（IP / 時間帯 / ブロックワード）
- メール返信依頼機能（ユーザー側）
- PDCA ログ（ローカルDB + リモート送信）
- レート制限（セッション単位）
- CSRF 保護
- レスポンシブデザイン（PC / スマホ）

### Security
- API キーは DB に保存（マスク表示対応）
- CSRF トークン検証（管理画面削除操作）
- ログ匿名化（個人情報は記録しない）
- regex インジェクション対策（シナリオトリガー検証）
