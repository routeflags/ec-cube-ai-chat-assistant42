# PHPMD 高複雑度 God Class — DashboardController / ChatApiController が閾値超過

## 優先度
🟡 中

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-4）
- 関連ファイル: `Controller/Admin/DashboardController.php:41 TooManyMethods 29 / ExcessiveClassComplexity 85`、`Controller/Api/ChatApiController.php:44 Complexity 74 / CBO 24 / __construct params 13`、`Controller/Admin/DesignController.php:192 NPath 243 / Cyclomatic 11`、`Entity/ChatLog.php:29 TooManyFields 23 / ExcessivePublicCount 45`

## 指摘事項
1. **DashboardController が God Class。** `methods 29 > 25`, `complexity 85 > 50`。`index()` が `else` 連発で肥大し、`handleSettingsPost()` など 543行に及ぶ。単体テスト `DashboardControllerTest.php` も 380行で `phpcs` 行長警告 7件。1クラスで「ダッシュボード表示」「設定保存」「同期」「ライセンス」まで担い、SRP 違反。
2. **ChatApiController が CBO 24 > 13。** `__construct` で 13 依存を注入。`Service/AiAgentInterface`, `Repository 5種`, `Logger`, `EventDispatcher` などが直接結合。`feedback()` の `NPath 720 / Cyclomatic 13` は `if/else` のネストが深い。テスト `ChatFlowServiceTest` も 395行で複雑度が高い。
3. **DesignController::validateWidgetSettings() が NPath 243 > 200。** 入力バリデーションが1メソッドに集中。`StaticAccess` で `DesignSettingsSyncService::` を直接呼んでおり DI 破壊。T-3 の `onlyWritten` と同根。
4. **Entity/ChatLog が TooManyFields 23 > 15。** だがこれは Doctrine カラム数由来で、分割するとマイグレーションが割れる。`ChatLog` を `ChatLog` + `ChatLogMeta` に分けると `dtb_chat_log` の JOIN が増え、EC-CUBE 本体のクエリに影響。
5. **phpmd.xml の閾値が一律。** `CyclomaticComplexity reportLevel 10` はテストの 307文字アサーションと同様、コントローラの 13 は即違反になるが、EC-CUBE 管理画面は 10 を超えるのが常態。`TooManyMethods 25` も `DashboardController` の 29 は 4 オーバーのみで、厳しすぎる。

## 改善案
**分割は Service 抽出で、Entity は除外。閾値は現実に合わせて緩和する。**

- **DashboardController 分解:**
  - `Service/DashboardSettingsService.php` に `handleSettingsPost()` / `saveLicense()` / `sync()` を抽出。Controller は `index()` で `if/else` を早期 return に置換。
  ```php
  // Before: if ($form->isSubmitted()) { ... } else { ... }
  // After:  if (!$form->isSubmitted()) { return $this->render(...); }
  //         return $this->settingsService->handle($form);
  ```
  - `TooManyMethods` は `phpmd.xml` で `maxMethods: 30` に緩和 or `DashboardController` のみ `suppressions.xml` で除外。

- **ChatApiController 分解:**
  - コンストラクタ 13 params → `ChatApiDeps` DTO または `ChatFacade` に集約:
  ```php
  class ChatApiFacade {
      public function __construct(
          private ChatFlowService $flow,
          private EmailReplyService $email,
          private FeedbackService $feedback,
      ) {}
  }
  // Controller は Facade 1本 + Request だけに
  ```
  - `feedback()` の `NPath 720` → `FeedbackValidator` / `FeedbackPersister` に分離。`Cyclomatic 13 → 5` を目標。

- **DesignController::validateWidgetSettings():**
  - `Service/Validator/WidgetSettingsValidator.php` に抽出。`StaticAccess` を `$this->syncService->validate()` に置換し、コンストラクタ DI にする。

- **Entity/ChatLog:**
  - `TooManyFields` / `ExcessivePublicCount` は `phpmd.xml` で除外:
  ```xml
  <rule ref="rulesets/codesize.xml/TooManyFields">
      <properties><property name="maxfields" value="25"/></properties>
  </rule>
  <exclude-pattern>*/Entity/ChatLog.php</exclude-pattern> <!-- or maxfields 緩和 -->
  ```

- BDD 受け入れ条件:
  - `vendor/bin/phpmd . text phpmd.xml | grep -c "ExcessiveClassComplexity"` が `2 → 0`（Dashboard/ChatApi が閾値以下）
  - `vendor/bin/phpunit` 緑 + `php bin/console debug:router | grep chat_api` でルーティング維持
  - `git diff --stat` で `Controller/` の行数が `Service/` に移動し、Controller 1ファイル 500行以下

## 備考
- 本タスクは工数大（見積 8h）のため T-1〜T-3 後に着手。MVP は閾値緩和のみで CI 緑化し、分割は次スプリントでも可。
- 編集範囲は `Controller/`, `Service/` 配下のみ。`Entity/` の分割はマイグレーションを伴うため別 ADR が必要。
- 検証: `vendor/bin/phpmetrics --report-html=artifacts/phpmetrics` で `kanDefect` と `complexity` の推移を可視化。
