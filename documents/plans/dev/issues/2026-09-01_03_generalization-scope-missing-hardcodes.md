# 汎用化タスクの対象漏れ（admin-dev 固定とドメイン固定の残存）

## 対象
- 計画書: `documents/plans/dev/2026-09-01_ai-chat-assistant42-generalization-plan.md`
- 関連ファイル: `Service/EmailReplyService.php`
- 関連ファイル: `Service/DesignSettingsSyncService.php`
- 関連ファイル: `Service/ChatFlowService.php`
- 関連ファイル: `ADMIN_MANUAL.md`

## 指摘事項
計画タスク 2 は「ドメイン/ブランド外部化」を扱っていますが、具体的な対象列挙が不足しています。
現状は `thch-vape.shop` 固定だけでなく、`admin-dev` 固定 URL や User-Agent 固定値、ヘルプ URL の固定案内が点在しています。
このままでは、実装後も別ショップ導入時に固有文字列が露出する可能性があります。

## 改善案
1. 計画に「ハードコード棚卸しタスク」を追加する（コード + ドキュメント横断）。
2. URL 生成は既存の Symfony ルータ / `UrlGeneratorInterface` 再利用を原則にする。
   - 管理 URL はルート名から生成し、`admin-dev` 直書きを禁止する。
3. ショップ URL / ショップ名は `BaseInfo` と設定値の優先順位を仕様化する。
4. 受け入れに「別ドメインの開発環境で固定文言が1件も残らない」を追加する。

## 備考
`ADMIN_MANUAL.md` の URL 記載も `/admin-dev/...` 固定のため、文書側も同時に修正対象です。
