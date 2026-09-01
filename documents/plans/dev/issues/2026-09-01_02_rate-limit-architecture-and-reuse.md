# レート制限計画の粒度不足と既存ライブラリ再利用不足

## 対象
- 計画書: `documents/plans/dev/2026-09-01_ai-chat-assistant42-generalization-plan.md`
- 関連ファイル: `Controller/Api/ChatApiController.php`
- 関連ファイル: `Entity/ChatLog.php`

## 指摘事項
計画タスク 3 は方向性は正しいですが、実装方針が不足しています。
現実装の IP 制限は `session_id` ではなく全体件数を数えるため、実質「グローバル制限」です。
また、SQL `COUNT(*)` ベースの毎リクエスト判定を継続すると、アクセス増加時に DB 負荷が増える懸念があります。

## 改善案
1. 計画に「制限キー」と「窓」を明記する。
   - 例: `session:{session_id}` と `ip:{client_ip}` を分離し、別閾値を持つ。
2. 車輪の再発明を避け、Symfony RateLimiter（EC-CUBE 同梱コンポーネント）利用案を優先検討する。
   - 独自 SQL 集計よりテストしやすく、実装の責務分離がしやすい。
3. SQL 継続案の場合はインデックス設計を計画に含める。
   - `created_at` 単体だけでなく、`(session_id, created_at)` 複合 index を検討する。
4. BDD に「同一 IP 多セッション」「同一 session_id 別IP（プロキシ配下）」を追加する。

## 備考
429 の返し分け（session 起因 / ip 起因）を JSON メッセージで識別可能にしておくと、運用監視が容易です。
