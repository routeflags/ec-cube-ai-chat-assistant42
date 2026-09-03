# PHPCS 自動修正可能エラー 47件が未解消 — phpcbf 未実行で CI が常に赤

## 優先度
🔴 高

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-1）
- 関連ファイル: `phpcs.xml.dist`（PSR12, parallel 8, cache .phpcs-cache）、`Tests/Unit/Service/AiModelSyncServiceTest.php:66,77,183,194,430,435`、`Tests/Unit/Service/AiModelRegistryTest.php:44,47,406,417`、`Service/GeminiAgentTest.php:324,379,519,558` ほか `ReportSummary: ERRORS 47, fixable 17`

## 指摘事項
1. **Fixable が 17件残留。** `vendor/bin/phpcs --report=summary` で `ERRORS 47 / fixable 17`。内訳は `Squiz.WhiteSpace.ControlStructureSpacing.NoLineAfterClose`（制御構造後の空行なし）と `PSR12.ControlStructures.ControlStructureSpacing`（複数行 `if` の括弧改行）。`phpcbf` 一発で消えるのに手動修正扱いになっている。
2. **なぜ残ったかが不明。** `phpcs.xml.dist` は `parallel` と `cache` を有効化済みだが、`composer.json` に `lint:fix` スクリプトが無いため誰も `phpcbf` を叩いていない。T-2 の手動行長修正と混同され、レビューで「自動or手動」の切り分けができない。
3. **差分肥大リスク。** 一括 `phpcbf` は `Tests/Unit/Controller/Admin/DashboardControllerTest.php:81-82` のように括弧位置を自動整形し、差分が 100 行超になる。1 PR で T-1+T-2 を混ぜると `git diff` が読めなくなる。
4. **キャッシュ不整合。** `.phpcs-cache` が 1.5MB のまま `phpcbf` を走らせると `hash` が古いまま残り、次回 `phpcs` が差分を検出しないケースがある（`--cache` の既知挙動）。

## 改善案
**T-1 を独立した自動修正 PR として先に片付ける。手動修正 (T-2) とは分離する。**

- 手順:
  1. `vendor/bin/phpcbf --standard=phpcs.xml.dist --report=summary` を実行（dry-run 的に `git diff --stat` で確認）。
  2. `rm -f .phpcs-cache && vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary` で `fixable 0` を確認。
  3. 差分が 200行超なら `Tests/` と `Service/` で PR を2分割（REI 高だがレビュー負荷を抑える）。
  4. `composer.json` に `"lint:fix": "phpcbf --standard=phpcs.xml.dist"` を追加し、README に記載。

- BDD 受け入れ条件:
  - `vendor/bin/phpcs --report=summary` → `FOUND 0 ERRORS AND 175 WARNINGS`（Warnings は T-2 で扱うため残存可）
  - `git diff --name-only | xargs php -l` が全て `No syntax errors`
  - `vendor/bin/phpunit --testsuite=unit` が緑（整形でロジックが壊れていないこと）

- 除外しない: `fixable` は自動修正に任せ、手動で空行を足さない。手動修正は T-2 で扱う。

## 備考
- 本タスクは工数 0.5h で効果 36% 減（47→30 errors 相当）のため REI 最優先。
- 編集範囲は `Service/`, `Tests/` 配下のみで `app/` 配下の EC-CUBE コアには触れない。
- 検証: `vendor/bin/phpcs --report=json | jq '.totals.errors'` が 30 以下になること（残りは non-fixable の行長など）。
