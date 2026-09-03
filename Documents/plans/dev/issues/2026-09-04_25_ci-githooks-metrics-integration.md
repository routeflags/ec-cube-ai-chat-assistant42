# CI / Githooks / Metrics 連携未整備 — ローカルと CI で品質ゲートが乖離

## 優先度
🟡 中

## 対象
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md`（T-7）
- 関連ファイル: `.githooks/pre-commit`（存在するが品質ツール未組み込み）、`opencode.json:9 permission "*": allow`、`docker-compose.verify.yml`（verify 用だが lint/stan 未定義）、`bin/`（verify スクリプト群）、`artifacts/`（gitignore 済みだが phpmetrics 出力先未定）、`vendor/phpmetrics`（2.11 追加済みだが実行未設定）、`.phpcs-cache`（cache 運用未文書化）

## 指摘事項
1. **Githooks が品質ツールを叩いていない。** `.githooks/pre-commit` は存在するが `phpcs --cache` を含まず、T-1 の自動修正がコミット前に検出できない。`opencode.json` が `permission "*": allow` のため、ローカルで `phpmetrics` が重い処理を走らせても止まらない。
2. **CI とローカルの乖離。** `docker-compose.verify.yml` は `verify-10models.sh` 的な検証はあるが `phpcs/phpstan/phpmd` のステージが無い。GitHub Actions の `workflow`（`9009992 chore: update workflow`）が何をしているか不明で、品質ゲートが CI で担保されていない。
3. **phpmetrics 未活用。** `phpmetrics/phpmetrics ^2.11` を `require-dev` に追加したのに `phpmetrics.json` も `artifacts/phpmetrics` 出力も無い。`--report-html` が 30秒かかるため pre-commit に入れると開発体験が落ちるが、CI でも回さないなら追加した意味が無い。
4. **artifacts 出力先未定。** `phpmetrics` の HTML は `artifacts/phpmetrics/` に出す想定だが `.gitignore` に `/artifacts` が入っているため CI で成果物を残せない。`Documents/plans` も gitignore 済みで、メトリクス推移の可視化が残らない。
5. **verify スクリプトとの重複。** `bin/verify-10models.sh` 的なモデル数検証と品質ツール検証が別系統。`composer.json` の `scripts` が無いため `composer lint && composer stan` の一発実行ができず、新規参画者が何を叩けばよいか README に書かれていない。

## 改善案
**pre-commit は軽量 (phpcs のみ 3秒)、CI は全量 (phpcs+phpstan+phpmd+metrics) に分離。composer scripts で一発化する。**

- `composer.json` scripts 追加（T-6 と連携）:
```json
"scripts": {
    "lint": "phpcs --standard=phpcs.xml.dist --warning-severity=5",
    "lint:fix": "phpcbf --standard=phpcs.xml.dist",
    "stan": "phpstan analyse -c phpstan.neon.dist --error-format=table",
    "md": "phpmd . text phpmd.xml",
    "metrics": "phpmetrics --report-html=artifacts/phpmetrics --report-json=artifacts/phpmetrics/report.json .",
    "quality": ["@lint", "@stan", "@md"],
    "quality:ci": ["@quality", "@metrics"]
}
```

- `.githooks/pre-commit` 修正案:
```bash
#!/bin/sh
# 軽量のみ 3秒以内
vendor/bin/phpcs --standard=phpcs.xml.dist --cache=.phpcs-cache --parallel=8
if [ $? -ne 0 ]; then
  echo "phpcs failed. Run: composer lint:fix"
  exit 1
fi
# phpstan/phpmd は重いので pre-push または CI に回す
```

- `docker-compose.verify.yml` / `.github/workflows/*.yml` 追加:
```yaml
# jobs:
#   quality:
#     runs-on: ubuntu-latest
#     steps:
#       - uses: actions/checkout@v4
#       - uses: shivammathur/setup-php@v2
#         with: { php-version: '8.0', tools: composer }
#       - run: composer install --no-interaction
#       - run: composer quality
#       - run: composer metrics  # artifacts を upload-artifact
```

- `README.md` 追記:
```markdown
## 品質ツール
composer lint      # phpcs 3秒
composer lint:fix  # 自動修正
composer stan      # phpstan level5
composer md        # phpmd
composer quality   # 全品質ゲート
```

- `.phpcs-cache` 運用: `README.md` に `初回は自動生成、差分検出がおかしい時は rm -f .phpcs-cache` を追記。CI では `actions/cache` で `.phpcs-cache` をキャッシュ。

- BDD 受け入れ条件:
  - `composer quality` が 10秒以内で `0 errors`（T-1〜T-3 後）
  - `git commit` 時に `phpcs` が 3秒以内で走り、違反があればブロック
  - `docker compose -f docker-compose.verify.yml run --rm php composer quality:ci` が CI と同結果
  - `artifacts/phpmetrics/report.json` が生成され、`kanDefect` が可視化できる

## 備考
- 本タスクは T-1〜T-6 の後に実施。品質ゲートが無いとリファクタの回帰を検出できないため、CI 化は必須だが、pre-commit を重くすると開発体験が落ちるので軽量/重量を分離する。
- 編集範囲は `.githooks/`, `composer.json`, `docker-compose.verify.yml`, `.github/workflows/`, `README.md` のみ。
- 検証: `time composer lint` が 3秒以内、`time composer quality` が 15秒以内であること。
