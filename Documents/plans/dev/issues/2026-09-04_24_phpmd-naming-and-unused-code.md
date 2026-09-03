# PHPMD 命名・未使用コード 40件超 — MissingImport / ShortVariable / UnusedFormalParameter

## 優先度
🟢 低

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-5）
- 関連ファイル: `phpmd.xml`（cleancode/naming/unusedcode/controversial 全有効）、`Command/CleanupEmailCommand.php:42 ShortVariable $io`、`Controller/Admin/*Controller.php:82 MissingImport` ほか 15件、`DoctrineMigrations/Version20260815*.php:31 ShortMethodName up/down` ほか 14件、`Entity/AccessRule.php:29 CamelCasePropertyName $rule_type` ほか 30件

## 指摘事項
1. **MissingImport 15件は FQCN 使用。** `Controller/Admin/AccessRuleController.php:82` で `\Eccube\Entity\Xxx` を `use` せず直接書いている。PSR12 でも `use` 推奨だが、EC-CUBE プラグインでは `Eccube\` と `Plugin\` を混在させると `use` が 20行になり、むしろ可読性が落ちるケースもある。
2. **ShortVariable $id, $io, $m が 12件。** `public function edit(Request $request, $id)` の `$id` は Symfony ルーティングの慣例。`$io = new SymfonyStyle` の `$io` も広く使われる。`phpmd.xml` の `minimum=3` が厳しすぎ、T-4 の複雑度より優先度低。
3. **UnusedFormalParameter $request, $schema が 10件。** `Migrations::up(Schema $schema)` の `$schema` は Doctrine インターフェースで必須だが未使用。`AccessRuleController::delete(Request $request, $id)` の `$request` も CSRF チェックで使わないと未使用扱い。`@SuppressWarnings(PHPMD.UnusedFormalParameter)` を付けるか、インターフェース由来は除外すべき。
4. **ShortMethodName up/down が 8件。** `DoctrineMigrations/Version20260815000000.php::up()` は Doctrine 規約で変更不可。`phpmd.xml` で `ShortMethodName` を `Migrations/*` から除外していない。
5. **CamelCasePropertyName $rule_type など 30件は Doctrine カラム名。** `Entity/ChatLog.php` の `$session_id` は `dtb_chat_log.session_id` にマッピング。`camelCase` に直すと `#[ORM\Column(name: "session_id")]` が必要になり、マイグレーション差分が出る。T-6 で除外すべきだったが漏れている。
6. **LongVariable $notificationRepository 1件。** `NotificationController.php:44` の 24文字は `LongVariable` 閾値 20 超。だが `notificationRepository` は意味が明確で、短縮すると可読性が落ちる。

## 改善案
**閾値緩和と除外でノイズを消し、真の未使用コードだけを残す。手動リネームは最小限に。**

- `phpmd.xml` 修正案:
```xml
<!-- Migrations は規約なので除外 -->
<exclude-pattern>*/DoctrineMigrations/*</exclude-pattern>

<!-- Entity の snake_case は Doctrine 由来なので除外 -->
<rule ref="rulesets/naming.xml/CamelCasePropertyName">
    <properties>
        <property name="allowUnderscore" value="true"/> <!-- or -->
    </properties>
</rule>
<!-- 代替: -->
<exclude-pattern>*/Entity/*</exclude-pattern>

<!-- ShortVariable は $id を許容 -->
<rule ref="rulesets/cleancode.xml/ShortVariable">
    <properties>
        <property name="minimum" value="2"/>
        <property name="exceptions" value="id,io,m"/>
    </properties>
</rule>

<!-- UnusedFormalParameter はインターフェース由来を除外 -->
<rule ref="rulesets/unusedcode.xml/UnusedFormalParameter">
    <properties>
        <property name="allowUnusedInAbstract" value="true"/>
    </properties>
</rule>
```

- 手動修正が必要なもののみ対応:
  - `MissingImport`: `Controller/Api/ChatApiController.php:259` など `use` 追加で解消できるものは `use` 追加。`Eccube\` が 10件超ならまとめて `use Eccube\Entity\...` に。
  - `LongVariable`: 閾値を `25` に緩和。`notificationRepository` はそのまま。

- BDD 受け入れ条件:
  - `vendor/bin/phpmd . text phpmd.xml | grep -c "MissingImport"` が `15 → <5`（残りは意図的 FQCN）
  - `grep -c "ShortVariable"` が `12 → 0`（閾値緩和後）
  - `vendor/bin/phpmd . text phpmd.xml | wc -l` が `100 → <30`（ノイズ除去後、残りは実害あるもののみ）

## 備考
- 本タスクは優先度低。T-1〜T-4 の後に「ノイズ除去」として実施。
- 編集は `phpmd.xml` 中心で、コード修正は `use` 追加のみ。リネームはしない。
- 検証: `vendor/bin/phpmd . text phpmd.xml 2>&1 | head -n 20` で推移確認。`phpcs` と異なり自動修正は無いため、手動で 5件ずつ潰す。
