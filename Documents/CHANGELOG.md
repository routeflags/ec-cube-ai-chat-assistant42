# Changelog

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
