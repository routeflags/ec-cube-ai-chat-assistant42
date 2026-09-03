# 品質ツール導入リファクタ計画 — composer + phpcs/phpstan/phpmd/phpmetrics

## 概要
2026-09-04 に `composer.json` へ `squizlabs/php_codesniffer ^4.0` / `phpstan ^2.2` / `phpmd ^2.15` / `phpmetrics ^2.11` を追加し、`phpcs.xml.dist` / `phpstan.neon.dist` / `phpmd.xml` / `.gitignore` を整備した。
`vendor/bin/phpcs` で 47 Errors + 175 Warnings、`phpstan` で Level5 約15件、`phpmd` で 100件超の違反が検出されたため、BDD で振る舞い単位に分割して段階的に解消する。

- 作成日: 2026-09-04
- 対象ブランチ: `feat/quality-tooling` 想定（現行差分: `composer.json` / `.gitignore` / `composer.lock` 未追跡 / `.phpcs-cache`）
- 検証ツール: `serena`/`read` + `vendor/bin/phpcs --report=json` / `phpstan analyse` / `phpmd` 実行確認済み
- 関連 Issues: `Documents/plans/dev/issues/2026-09-04_19`〜`26`

## ゴール
- `composer quality`（lint+stan+md）が 15秒以内で 0 errors
- `git commit` 時に `phpcs` が 3秒でブロック（pre-commit）
- `phpstan level:5` が 0 errors（将来 level:8 への道筋を残す）
- `phpmd` ノイズを除外し、真の複雑度違反のみを残す

## BDD タスク一覧

### T-6: 設定ファイル是正 — 先行 [優先: 🔴 高 / REI 高]
- **振る舞い**: `phpstan.neon.dist: phpVersion 80200 → 80000` に修正、`composer.json` に `scripts: { lint, lint:fix, stan, md, metrics, quality }` を追加、`phpmd.xml` で `Entity/*` / `DoctrineMigrations/*` を除外、`.phpcs-cache` 運用を `README` に追記、`/composer.lock` 方針を ADR 化
- **正常系**: `composer validate && vendor/bin/phpstan --version && grep phpVersion phpstan.neon.dist` が期待通り
- **異常系**: `phpVersion` を 80000 に下げても 8.2 機能を使っているコードがあれば `phpstan` で検出されること
- **データ例**: `composer.json` に `"lint": "phpcs --standard=phpcs.xml.dist"` 追加 → `composer lint` で `FOUND 0 ERRORS` まで持っていける
- **受入**: `composer validate` パス + `cat composer.json | jq .scripts` で確認 + `vendor/bin/phpcs --report=summary` が 47→30 errors に減る
- **Issue**: `2026-09-04_19`

### T-1: PHPCS 自動修正 — phpcbf [優先: 🔴 高 / 工数 0.5h]
- **振る舞い**: `vendor/bin/phpcbf` を実行 → `Squiz.WhiteSpace.ControlStructureSpacing` / `PSR12.ControlStructures.ControlStructureSpacing` の 17 fixable が 0 になる
- **正常系**: `vendor/bin/phpcs --report=summary` → `0 ERRORS, 175 WARNINGS`
- **異常系**: 差分が 200行超なら `Tests/` と `Service/` で PR 分割
- **データ例**: `AiModelSyncServiceTest.php:66,77,183,194,430,435` の空行なしを自動付与
- **受入**: `rm -f .phpcs-cache && vendor/bin/phpcs` で fixable 0 + `vendor/bin/phpunit --testsuite=unit` 緑
- **Issue**: `2026-09-04_20`

### T-3: PHPStan Level5 実害解消 [優先: 🟡 中]
- **振る舞い**: `DashboardController::handleSettingsPost(): ?Response → Response` 型修正、`treatPhpDocTypesAsCertain: false` 追加、`Entity $id` は `ignoreErrors` に残す、`DesignController::$configRepository` 静的呼び出しを DI 化、`Twig` を `paths` から除外
- **正常系**: `vendor/bin/phpstan analyse --error-format=table` → `0 errors`
- **異常系**: `isset($data['providers'])` の `always exists` を消す際に `providers` 欠落バグを見逃さないこと
- **データ例**: `Entity/AccessRule.php:36` の `unusedType` は Doctrine リフレクション由来なので削らない
- **受入**: `level:5` で 0 errors、 `level:6` で ≤10 errors（将来の上げ幅確認）
- **Issue**: `2026-09-04_22`

### T-2: PHPCS 行長警告 175件の段階解消 [優先: 🟡 中]
- **振る舞い**: `Generic.Files.LineLength` を `lineLimit 120 / absolute 150 / ignoreUrls true` に設定し、残りを 10件バッチで手動折返し
- **正常系**: `ChatFlowServiceTest.php:149(307文字)` を変数抽出 + 縦並びで 120 以内に
- **異常系**: URL/アノテーションは除外、テストの expected JSON は 150 まで許容
- **データ例**: `GeminiAgentTest.php:67(160文字)` → 引数を複数行に
- **受入**: `WARNINGS < 50` まで減 + `phpunit` 緑 + 1 PR あたり 10ファイル以内
- **Issue**: `2026-09-04_21`

### T-4: God Class 分解 [優先: 🟡 中 / 工数 8h]
- **振る舞い**: `DashboardController(29 methods, complexity85)` → `DashboardSettingsService` 抽出、`ChatApiController(CBO24, params13)` → `ChatApiFacade` で集約、`DesignController::validateWidgetSettings() NPath243` → `WidgetSettingsValidator` に分離
- **正常系**: `phpmd | grep ExcessiveClassComplexity` が `2 → 0`
- **異常系**: `php bin/console debug:router | grep chat_api` でルーティング維持
- **データ例**: `ChatApiController::__construct(13)` → `ChatApiFacade(flow, email, feedback)` の 3本に
- **受入**: Controller 1ファイル 500行以下 + `phpmetrics` の `kanDefect` 低下を可視化
- **Issue**: `2026-09-04_23`

### T-5: 命名・未使用コード ノイズ除去 [優先: 🟢 低]
- **振る舞い**: `phpmd.xml` で `ShortVariable minimum 2 + exceptions id,io,m` / `Entity/*` 除外 / `DoctrineMigrations/*` 除外、`MissingImport` は `use` 追加で解消
- **正常系**: `phpmd | grep MissingImport` が `15 → <5`
- **異常系**: `$id` は Symfony 慣例なのでリネームしない
- **データ例**: `CleanupEmailCommand.php:42 $io` は `exceptions` に追加して許容
- **受入**: `phpmd | wc -l` が `100 → <30`
- **Issue**: `2026-09-04_24`

### T-7: CI / Githooks / Metrics 連携 [優先: 🟡 中]
- **振る舞い**: `.githooks/pre-commit` に `phpcs --cache --parallel=8` (3秒) を追加、`docker-compose.verify.yml` / `.github/workflows/quality.yml` に `composer quality` / `composer metrics` を追加、`README.md` に `composer quality` 一発実行を追記、`.phpcs-cache` を `actions/cache` で保持
- **正常系**: `composer quality` 15秒以内で 0 errors、`git commit` で違反時にブロック
- **異常系**: `phpmetrics` は 30秒かかるため pre-commit から除外、CI のみで `upload-artifact`
- **データ例**: `time composer lint` 3秒、`time composer quality` 15秒
- **受入**: `artifacts/phpmetrics/report.json` 生成 + `kanDefect` 可視化
- **Issue**: `2026-09-04_25` / `26`（phpmetrics 活用設計は 26 で補足）

## 実行順序
```
T-6 (設定是正) → T-1 (自動修正) → T-3 (phpstan) → T-2 (行長 10件バッチ) → T-5 (命名ノイズ除去) → T-4 (分割) → T-7 (CI)
```
REI 高い T-6/T-1 を先に。T-4 は工数大のため後半、MVP は閾値緩和のみでも CI 緑化可。

## 検証計画
- `composer validate && composer quality` が 0 errors
- `vendor/bin/phpunit --testsuite=unit` 緑（T-1/T-3 後に回帰）
- `git diff --stat` で 1 PR あたり 10ファイル以内（T-2/T-5）
- `vendor/bin/phpmetrics --report-json=artifacts/phpmetrics/report.json` で `kanDefect` 推移

## 非スコープ
- `src/Eccube` 直接編集禁止（`app/` 配下のみ）
- `Entity/ChatLog` の物理分割（マイグレーションを伴うため別 ADR）
- `phpstan level:8` への即時上げ（本計画では level:5 を 0 にし、level:6 で ≤10 を確認するまで）

## 制約
- 触ったファイルはボーイスカウトルールで周辺も改善するが、公開 I/F の破壊的変更は Plan Architect 相談
- `main` への直接 push 禁止、必ず `feat/quality-tooling` ブランチ経由
