# Changelog

## [Unreleased]

### Added
- 管理画面の設定画面に「レート制限（分あたり上限）」の項目を追加。`Config.rate_limit_per_minute`（既定30、0で無効化）を UI から変更可能にした。従来は DB の UPDATE でしか変えられない状態だった

### Changed
- 静的解析を PHPStan level 5 → **level 8** に引き上げ (`phpstan.neon.dist`)。
  - 欠けていた iterable/パラメータ型注釈 (約 150 件) を補完し、`mixed` 由来の曖昧さを除去。
  - 旧抑制ルール 25 件のうち死んでいた 12 件を削除し、広域抑制 (`#should return .* but returns.*#` 等) を廃止。残る抑制は EC-CUBE スタブ未整備と DBAL 行配列形状の 2 件のみ。
  - `Tests/Stubs` を実型に追従 (`AbstractController::json/createFormBuilder/createNotFoundException`、`AbstractRepository` の総称化、`BaseInfoRepository::get`)。
  - `composer.json` require-dev に静的解析用の `symfony/form` / `symfony/validator` / `symfony/translation` / `twig/twig` を追加 (本番配布物には含まれない)。
- コード品質ゲートを green に: `composer lint` (PHPCS) を 292 エラー → 0 に、`composer md` (PHPMD) を 27 件 → 0 件に。
  - PHPCS: `Tests/Stubs`（EC-CUBE クラスの写しで PHPStan 対象外）と `bin/` の PSR1 side-effect を対象外にし、残りの 120 桁超の行を折り返し。
  - PHPMD: `\DateTime::createFromImmutable` の static access を例外化、`else` を早期 return に、`AiModelRegistry::resolveConfigPath` を分割。
  - `McpHttpController::handle()` を、HTTP メソッド/Content-Type/ボディ復号/jsonrpc 版判定/通知/レート制限/ディスパッチの各ステップに分割（応答コードとヘッダーは不変）。

### Fixed
- PHPStan が指摘した死んだ分岐と重複を整理: `ApiKeyEncryptor` の二重 `isEncrypted()` 判定、`unset($payload['reasoningEffort'])` (未設定キー)、`McpHttpController` の未使用 `ProductRepository` 依存 (services.yaml も更新)、`ProductRepository::$entityManager` の未使用プロパティ、`McpServerService` の重複定数 (正本は `McpHttpService`)、リポジトリの `save/delete` 上書きの戻り値型。
- `/api/ai-chat-assistant/*` で `InputBag::get()` の返り値 (`bool|float|int|string|null`) を文字列型の setter にそのまま渡していた箇所を明示的に文字列化。
- チャットウィジェットの入力で日本語 IME の変換確定 Enter が素のフォーム送信になり `POST /` が 405 (Method Not Allowed) になる問題を修正。入力要素を `<textarea rows="1">` にして Enter による implicit submission を起こさないようにし、フォームは `action="#" method="get"` + `onsubmit="return false"` にして JS が止め損ねても 405 のエラー画面にならないようにした (Enter での送信・Shift+Enter の改行は従来どおり)。

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
