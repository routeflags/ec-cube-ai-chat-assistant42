# 設定ファイルの不整合と composer.lock 方針未定 — phpVersion ミスマッチとキャッシュ肥大

## 優先度
🔴 高

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-6）
- 関連ファイル: `composer.json`（php >=8.0 vs phpstan 80200）、`phpstan.neon.dist:4`、`phpmd.xml:15-21`、`phpcs.xml.dist:9`、`.gitignore:11`、`composer.lock`（534KB 未追跡）、`.phpcs-cache`（1.5MB）

## 指摘事項
1. **phpVersion ミスマッチ。** `composer.json` は `php >=8.0` を要求するが `phpstan.neon.dist` は `phpVersion: 80200` (8.2) を固定。下位互換（8.0 で動くが 8.2 解析で通る）なコードが 8.0 環境で死んでも検出できない。`level:5` のまま 8.0 固有の `readonly`/`enum`/`mixed` 境界が見えない。
2. **composer.lock 方針未定。** EC-CUBE プラグインは慣例として `composer.lock` を `.gitignore` に入れるが、現状は未追跡 (`?? composer.lock`) のまま。CI 再現性と配布 tarball の整合性が曖昧。`composer validate` も `lock` 有無で挙動が変わる。
3. **.phpcs-cache 肥大。** 1.5MB の JSON キャッシュがリポジトリ直下に残留。`.gitignore` に `/.phpcs-cache` を追加済みだが、既存ファイルは `git status` から消えただけでディスクには残る。新規 clone 時の再生成手順がドキュメントに無い。
4. **phpmd.xml の Entity 誤検出。** `CamelCasePropertyName` が `Entity/ChatLog.php: $session_id` など Doctrine `snake_case` カラムを全件違反扱い。T-5 のノイズになり、真の違反が埋もれる。
5. **scripts 未定義。** `composer.json` に `scripts: { lint/stan/md }` が無いため `composer lint` 一発実行ができず、属人化する。`opencode.json` の `permission: "*": allow` と併用で CI とローカルの実行差が出る。

## 改善案
- **phpVersion 修正:** `phpstan.neon.dist` を `phpVersion: 80000` に下げるか、コメントで `// 8.0 最小サポート、8.2 機能は CI で 8.2 ジョブを追加` と明記。代替として `parameters: phpVersion: null`（実行環境に追従）も可だが、CI で 8.0 コンテナを回すのが最も安全。
- **lock 方針を ADR 化:** `Documents/plans/dev/issues` に ADR を残す。推奨は **プラグイン配布物は lock をコミットしない**（EC-CUBE 本体が依存解決するため）→ `.gitignore` に `/composer.lock` を追加。逆に再現性重視ならコミットし `composer install --frozen` を CI に追加。いずれも `composer validate --no-check-publish` を CI に入れる。
- **キャッシュ運用:** `README.md` に `vendor/bin/phpcs --cache=.phpcs-cache` が初回生成される旨を追記。`bin/clean.sh` があれば `rm -f .phpcs-cache` を追加。
- **phpmd 除外:** `phpmd.xml` に `<exclude-pattern>*/Entity/*</exclude-pattern>` または `<rule ref="rulesets/naming.xml/CamelCasePropertyName"><exclude-pattern>Entity</exclude-pattern></rule>` 的な分割を追加。`braces` 系は PSR12 に任せて `controversial.xml` から除外も検討。
- **scripts 追加:**
```json
"scripts": {
    "lint": "phpcs --standard=phpcs.xml.dist",
    "lint:fix": "phpcbf --standard=phpcs.xml.dist",
    "stan": "phpstan analyse -c phpstan.neon.dist",
    "md": "phpmd . text phpmd.xml",
    "metrics": "phpmetrics --report-html=artifacts/phpmetrics ."
}
```

## 備考
- 本指摘は T-1〜T-5 の前提。設定がブレたまま自動修正を走らせると差分が再発する。
- 編集範囲は設定ファイルのみで `src/Eccube` に触れないため規約遵守。
- 検証: `composer validate && vendor/bin/phpstan --version && cat phpstan.neon.dist | grep phpVersion`
