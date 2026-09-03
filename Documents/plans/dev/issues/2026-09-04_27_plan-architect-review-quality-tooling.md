# Plan Architect レビュー — 品質ツール導入リファクタ計画は条件付き承認（CI 除く）

## 優先度
🟡 中

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-6〜T-7, 全8 issue）
- 関連ファイル: `phpcs.xml.dist` / `phpstan.neon.dist` / `phpmd.xml` / `composer.json` / `.githooks/pre-commit` / `.gitignore` / `opencode.json` / `Service/` / `Controller/Admin/` / `.github/workflows`（不在確認）

## 指摘事項
1. **🔴 phpVersion ミスマッチ** — `composer.json: php >=8.0` に対し `phpstan.neon.dist: phpVersion 80200`。8.0 で死ぬコードを検出できない。`null`（実行環境追従）にすると CI が 8.2 固定の場合に再現しない。
2. **🔴 pre-commit と phpcs.xml.dist の二重管理** — 現行 `.githooks/pre-commit` は `PSR12` 直指定で `parallel/cache/exclude` を無視。`phpstan` も `xargs` で staged 差分のみを解析し `phpstan.neon.dist` の `paths` 設定と衝突。CI を除外する本指示のもとでは pre-commit の軽量化がより重要。
3. **🔴 composer.lock 方針未確定** — 534KB が `??` 未追跡。EC-CUBE プラグインは慣例で `lock` 非コミットだが `.gitignore` に未登録のため `git status` が常に汚れる。
4. **🟡 ChatApiFacade 過剰** — `__construct 13 params` の解消に Facade DTO を挟むと seam が増え Issue14 の `AbstractPluginDataSyncService` と同様の抽象肥大を再現。`ChatFlowService` + `FeedbackValidator` への責務移譲で十分。
5. **🟡 God Class は閾値緩和で MVP 緑化を先行** — `DashboardController 29 methods / complexity 85` は `maxMethods 30 / classReportLevel 60` 緩和で CI 相当のローカル緑化が可能。分割は次スプリントで可。計画書の MVP 方針を支持。
6. **🟡 pre-commit 重量化** — 現行 hook が `phpcs+phpstan+phpmd` 3連で 10秒超。`opencode.json permission "*": allow` と併用で `phpmetrics`（30秒）がローカル暴走するリスク。
7. **🟡 テスト計画の粒度不足** — T-4 の 8h 分解で seam 定義なし。`DashboardSettingsService` は Unit モック、`debug:router` 回帰は Functional 1件で担保すべき。`vendor/bin/phpunit --testsuite=unit` は `Tests/` 構成と不一致。
8. **🟢 phpmetrics コスパ** — `vendor` 除外なしで 60秒超。CI 除外のため本計画では CI 専用化せず `composer metrics` の手動実行に留める。1ヶ月で `kanDefect <0.3` なら削除判断の ADR を残す。
9. **🟢 編集範囲表記** — 計画書の `app/配下のみ` は本リポジトリでは `Plugin/AiChatAssistant42/` 直下。`src/Eccube` 禁止は遵守。
10. **🟢 命名衝突** — `DashboardSettingsService` と既存 `DesignSettingsSyncService` が混同されやすい。`DashboardSettingsHandler` 等へのリネーム検討。

## 改善案
- **T-6 に pre-commit 是正を統合:** `phpcs --standard=phpcs.xml.dist --cache=.phpcs-cache --parallel=8` に統一。`phpstan`/`phpmd` は pre-commit から外し `composer stan` / `composer md` の手動実行に分離（CI 除外のため）。
- **phpVersion は 80000 に下げる:** `phpstan.neon.dist` を `80000` に。代替 `null` は非推奨。コメントで `// 最小サポート 8.0、8.2 機能は手動確認` を追記。
- **ChatApiFacade は見送り:** Facade ではなく `ChatFlowService` への移譲と `FeedbackValidator` 抽出に留める。T-4 着手前に再確認。
- **God Class は閾値緩和で先行緑化:** `phpmd.xml` で `maxMethods 30 / classReportLevel 60` に緩和し、分割は Issue23 の次スプリントへ。
- **テスト seam 明記:** `DashboardSettingsService` は `PHPUnit\Framework\TestCase` + モック、`debug:router` は `Functional` 1件。`--testsuite=unit` 表記を `vendor/bin/phpunit` に修正。
- **CI 除外に伴う代替:** `docker-compose.verify.yml` / `.github/workflows` 追加は本指示により削除。`composer quality` はローカル手動で担保。

## 備考
- 総評: **条件付き承認 — 上記 T-6 の設定乖離を先に是正すれば Implementer 着手可。** CI を除外してもローカル `composer quality` で品質ゲートは担保可能。
- 再利用判定: Issue14 の `AbstractPluginDataSyncService` と本計画の `DashboardSettingsService` / `WidgetSettingsValidator` は重複なし。むしろ T-4 で同基底を再利用しないことが重複防止になる。
- 次のアクション: T-6（設定是正）→ T-1（phpcbf）→ T-3（phpstan）の順で Implementer 着手。T-7 の CI 部分はスキップ。
