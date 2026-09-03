# PHPStan Level5 エラー約15件が未解消 — return 型と Doctrine プロパティの誤検出

## 優先度
🟡 中

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-3）
- 関連ファイル: `phpstan.neon.dist`（level 5, paths 8, excludePaths 5, ignoreErrors 5）、`Controller/Admin/DashboardController.php:282 return.unusedType + isset.offset`、`Controller/Admin/DesignController.php:59 property.onlyWritten`、`Entity/AccessRule.php:36 property.unusedType` ほか Entity 7件、`Twig/LicenseHtmlExtension.php` 除外漏れ

## 指摘事項
1. **return.unusedType が実害。** `DashboardController::handleSettingsPost() never returns null so it can be removed from the return type.` は `?Response` を `Response` に直すべき正規の指摘。放置すると呼び出し側が `if ($response === null)` の不要分岐を残し、T-4 の複雑度を上げる。
2. **isset.offset が過検出。** `isset($data['providers'])` が `always exists` とあるが、これは PHPDoc `array{version: string, providers: array}` を `treatPhpDocTypesAsCertain: true` で確実視した結果。`phpstan.neon.dist` に `treatPhpDocTypesAsCertain: false` を入れずに `&&` を消すと、逆に `providers` 欠落時のバグを見逃す。
3. **Entity $id の unusedType 7件は Doctrine 由来の誤検出。** `private ?int $id = null;` に対し `never assigned int` と出るが、Doctrine がリフレクションで代入するため静的解析では見えない。`ignoreErrors` に入れずに `int` を削ると `getId(): ?int` の戻り型が `null` 固定になり、呼び出し側で `assertIsInt` が落ちる。
4. **DesignController::$configRepository onlyWritten。** `__construct` で代入されるが読まれない。実際は `save()` で `DesignSettingsSyncService::class` を静的呼び出ししており DI 未使用。T-4 の責務分離と合わせて直さないと、修正が中途半端になる。
5. **Twig 除外が漏れ。** `phpstan.neon.dist` の `excludePaths` に `Twig/*` が無いのに `ignoreErrors` で `twig_* not found` を握り潰している。`paths` に `Twig` を入れているため解析対象になり、無駄な `ignoreErrors` が増える。
6. **Level 5 止まり。** `level:5` は EC-CUBE プラグインとしては低め。`level:6` 以上で `missingType.iterableValue` などが検出できるが、現状の `ignoreErrors` が `level:5` 用にチューニングされており、上げると 30件超に跳ねる。

## 改善案
**誤検出は ignore に残し、実害ある型エラーだけを直す。Twig は paths から外すか ignore を整理する。**

- `DashboardController.php:282`:
  ```php
  // Before: public function handleSettingsPost(): ?Response
  // After:  public function handleSettingsPost(): Response
  // 呼び出し側の `if ($response === null)` 分岐を削除
  ```
- `DashboardController.php:543`:
  ```php
  // isset($data['providers']) は PHPDoc 由来の確実視なので、phpstan.neon.dist に
  // parameters:
  //     treatPhpDocTypesAsCertain: false
  // を追加。あるいは $data を array{providers?: array} に直す。
  ```
- `Entity/*` の `$id`:
  - 現状の `ignoreErrors` を維持せず、`phpstan.neon.dist` に以下を追加:
  ```yaml
  ignoreErrors:
      - { message: '#Property .*::\$id .* is never assigned int#', path: Entity/* }
  # または doctrineCover: entityDirs: [Entity] を入れて Doctrine 認識させる
  ```
  - `int` を削らない。`?int` を維持。

- `DesignController::$configRepository`:
  - 使っていないなら削除。使うなら `save()` の `DesignSettingsSyncService::` 静的呼び出しを `$this->configRepository` 経由に置換（T-4 と連携）。

- `Twig`:
  ```yaml
  paths: [Command, Controller, DoctrineMigrations, Entity, EventListener, Repository, Service, Nav.php] # Twig を外す
  # または excludePaths に Twig/* を追加し、ignoreErrors の Twig 2件を削除
  ```

- BDD 受け入れ条件:
  - `vendor/bin/phpstan analyse --error-format=table` が `0 errors`（ignore 込み）
  - `vendor/bin/phpstan analyse --level=6` で `<= 10 errors`（将来のレベル上げ余地を確認）
  - `vendor/bin/phpunit` 緑

## 備考
- 本タスクは T-1 の後に実施。前提の `phpVersion` 修正（T-6）が先でないと `level` の意味がブレる。
- 編集範囲は `Controller/`, `Entity/`, `phpstan.neon.dist` のみ。
- 検証: `vendor/bin/phpstan analyse --error-format=json | jq '.totals.fileErrors'` で推移確認。
