# テスト計画の具体性不足（シーム別の検証設計が未定義）

## 対象
- 計画書: `documents/plans/dev/2026-09-01_ai-chat-assistant42-generalization-plan.md`
- 関連ファイル: `Controller/Api/ChatApiController.php`
- 関連ファイル: `Service/ChatFlowService.php`
- 関連ファイル: `Service/EmailReplyService.php`

## 指摘事項
計画には受け入れシナリオがありますが、Unit / Integration / Web のどこで何を担保するかが未定義です。
現リポジトリには既存テスト資産が薄く、テスト種別と対象シームを先に定義しないと、実装時に網羅漏れが発生しやすいです。

## 改善案
1. 計画にシーム別テストマトリクスを追加する。
   - Unit: `ChatFlowService` のルール判定・文脈組み立て。
   - Integration: Repository の SQL 実行と依存テーブル有無での分岐。
   - Web: `ChatApiController` の 200/400/403/409/429 契約。
2. BDD の各タスクに「正常/異常/境界」対応テストケース ID を紐付ける。
3. 既存ユーティリティ再利用方針を明記する。
   - `AbstractWebTestCase`、`CommandTester`、Doctrine トランザクション分離パターンを採用する。

## 備考
公開前スモークだけでは回帰検知が弱いため、最低限 API 契約テストの自動化を優先してください。
