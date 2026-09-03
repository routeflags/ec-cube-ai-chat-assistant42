# PHPCS 行長警告 175件が放置 — Generic.Files.LineLength 120文字超

## 優先度
🟡 中

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-2）
- 関連ファイル: `phpcs.xml.dist:15 ref="PSR12"`（PSR12 は soft limit 120）、`Tests/Unit/Service/ChatFlowServiceTest.php:149(307文字)`、`Tests/Unit/Service/GeminiAgentTest.php:67(160文字), 269(139文字)`、`Service/ChatLoggerTest.php` ほか `WARNINGS 175`

## 指摘事項
1. **175 warnings がノイズ化。** `phpcs --report=summary` で `WARNINGS 175` が常に出るため、真の違反（T-1 の fixable error）と埋もれて CI が形骸化。`--warning-severity=0` で消す運用も可能だが、根本解決にならない。
2. **テストの可読性低下。** `ChatFlowServiceTest.php:149` の 307文字は `assertSame('...very long expected JSON...', $actual)` の1行アサーション。レビューで横スクロールが必須になり、差分レビュー効率が落ちる。
3. **一括修正のリスク。** 全 175件を1 PR で直すと `git diff` が 500行超になり、T-1 の自動修正と衝突。かといって放置すると `phpcs` の `cache` が 1.5MB まで肥大し、差分検出が遅くなる。
4. **除外ルール未設計。** URL やアノテーション (`@Route`, `@var`) は 120 超が許容されるべきだが、`phpcs.xml.dist` に `<exclude-pattern>` も `<rule ref="Generic.Files.LineLength"><properties><property name="ignoreUrls" value="true"/></properties></rule>` も無い。

## 改善案
**T-1 後に 10件ずつの小バッチで手動折返し。URL/アノテーションは除外ルールで許容する。**

- `phpcs.xml.dist` 修正案:
```xml
<rule ref="Generic.Files.LineLength">
    <properties>
        <property name="lineLimit" value="120"/>
        <property name="absoluteLineLimit" value="150"/>
        <property name="ignoreUrls" value="true"/>
    </properties>
</rule>
<!-- Tests のアサーションは 150 まで許容 -->
<rule ref="Generic.Files.LineLength.TooLong">
    <exclude-pattern>Tests/*</exclude-pattern>
</rule>
```
  または `Tests/` は `absoluteLineLimit 200` に緩和（テストの expected JSON は可読性より正確性優先）。

- 手動修正パターン:
  - 引数縦並び: `new Response(200, ['ETag'=>..., 'Content-Type'=>...], json_encode($valid))` を複数行に。
  - 変数抽出: `$expectedJson = json_encode([...], JSON_UNESCAPED_SLASHES); $this->assertSame($expectedJson, $actual);`
  - Heredoc: 長文プロンプトは `<<<JSON` で外出し。

- BDD 受け入れ条件:
  - `vendor/bin/phpcs --report=summary --warning-severity=5` で `WARNINGS < 50`（URL 除外後、残りは実害あるもののみ）
  - `vendor/bin/phpunit` が緑のまま（折返しで構文エラー無し）
  - `git diff --stat` が 1 PR あたり 10ファイル以内

## 備考
- 本タスクは緊急ではないが、Warnings を放置すると「Warnings は無視してよい」文化が定着し、将来の Critical が埋もれる。隔週で 10件ずつ潰すのが REI 高い。
- 編集は `Tests/` 中心で `Service/` のロジックには触れないためリスク低。
- 検証: `vendor/bin/phpcs --report=json | jq '.totals.warnings'` で推移を追う。`phpmetrics` の `kanDefect` と併せて可視化すると効果的。
