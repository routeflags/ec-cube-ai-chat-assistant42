# PhpMetrics 導入のみで活用設計なし — 追加理由とレポート運用が未定義

## 優先度
🟢 低

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-6/T-7 補足）
- 関連ファイル: `composer.json: require-dev phpmetrics/phpmetrics ^2.11`（新規追加）、`phpmd.xml` / `phpstan.neon.dist`（既存）、`artifacts/`（gitignore）、`Documents/plans/dev/issues/2026-09-04_19_*.md` ほか

## 指摘事項
1. **導入目的が不明。** `phpmetrics` は `coupling / kanDefect / complexity` を可視化するが、現状 `phpmd` と `phpstan` で十分に複雑度は検出できている。`phpmetrics` を入れた理由（例: 将来の `Maintainability Index` 追跡、PR ごとの `artifacts/phpmetrics/report.json` 差分）が `README.md` や ADR に書かれていない。`composer.json` に 4ツール同時追加した中で、最も重く（30秒）最も使われないリスクがある。
2. **設定ファイル無し。** `phpmetrics.json` や `phpmetrics.yml` が無く、デフォルトの `violations` 閾値で動く。`DashboardController` の `kanDefect 0.85` が違反なのに気づかない。
3. **出力先と gitignore の衝突。** `artifacts/` は `.gitignore` で除外。`phpmetrics --report-html=artifacts/phpmetrics` を CI で回しても成果物が残らず、推移が見えない。`Documents/plans` も除外のため、レポートを残す場所が無い。
4. **他ツールとの重複。** `phpmd` の `CyclomaticComplexity 10` と `phpmetrics` の `cyclomaticComplexity` が二重検出。どちらを正とするか未定義で、開発者が `phpmd` と `phpmetrics` で異なる指摘を受け混乱する。
5. **実行コスト。** `phpmetrics` は `vendor/` を含めると 2万ファイル解析で 60秒超。`phpcs.xml.dist` のように `exclude-pattern` が無いと、毎回 `vendor/` を舐めて遅くなる。

## 改善案
**PhpMetrics は CI 専用にし、ローカルでは `composer metrics` の手動実行に留める。設定と出力先を明文化する。**

- 導入目的を `README.md` に1行で明記:
```markdown
- phpmetrics: 結合度/欠陥予測（kanDefect）の推移を CI で可視化。閾値は phpmd と役割分担（phpmd=即時違反、metrics=推移観測）。
```

- `composer.json` に `exclude` 相当を `scripts` で担保:
```json
"metrics": "phpmetrics --exclude=\"vendor,Tests,artifacts,Documents\" --report-html=artifacts/phpmetrics --report-json=artifacts/phpmetrics/report.json ."
```

- 出力先: `artifacts/phpmetrics/` は gitignore だが CI の `upload-artifact` で 30日保持。推移を見たい場合は `Documents/reports/phpmetrics_YYYYMMDD.json` に月次スナップショットを残す（`Documents` は gitignore だが `reports` のみ除外解除するか、別 `artifacts/metrics-history/` を用意）。

- 閾値設計: `phpmd` は `Cyclomatic 10` で即時ブロック、`phpmetrics` は `kanDefect > 0.5` を警告（CI は `continue-on-error: true` でブロックしない）。二重検出を避けるため `phpmetrics` の `cyclomaticComplexity` 違反は `info` に留める。

- 削除も選択肢: 1ヶ月回して `kanDefect` が `DashboardController` 以外で 0.3 未満なら、コストに見合わないとして `composer remove phpmetrics/phpmetrics` も検討。ADR に「導入→観測→削除判断」を残す。

- BDD 受け入れ条件:
  - `composer metrics` が 20秒以内で `artifacts/phpmetrics/index.html` を生成
  - `artifacts/phpmetrics/report.json` の `kanDefect` が `DashboardController` で 0.5 超を検出（T-4 の分割効果を可視化）
  - `README.md` に実行方法と目的が 3行以内で記載

## 備考
- 本指摘は低優先度。T-1〜T-5 が終わった後に「可視化」として実施。
- 編集範囲は `composer.json`, `README.md`, `.github/workflows/` のみ。`phpmetrics` 自体の設定ファイルは任意。
- 検証: `time composer metrics 2>&1 | tail -n 5` で実行時間確認。`cat artifacts/phpmetrics/report.json | jq '.violations'` で違反数確認。
