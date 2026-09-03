# Git Hooks

このディレクトリには、コミット時の品質チェックを行う git hooks が格納されています。

## セットアップ

リポジトリを clone または pull 後、以下のコマンドでフックを有効にしてください:

```bash
git config core.hooksPath .githooks
```

または `make setup` を実行してください（Makefile に定義済みの場合）。

## フック一覧

### pre-commit
コミット前に以下を自動チェックします:

| チェック | 内容 | 失敗時のアクション |
|---------|------|-------------------|
| PHP 構文チェック | ステージされた PHP ファイルの `php -l` 実行 | コミット中止 |
| 大容量ファイル | 1MB 超のファイルを検出 | コミット中止 |
| デバッグコード | `dump()`, `dd()`, `var_dump()` 等の残存を検出 | コミット中止 |
| テスト実行 | SalesReport42 関連変更時は `SeoDataApiControllerTest` / `OrderGscQueryServiceTest` / `SeoAttributionServiceTest` を実行 | コミット中止 |

### pre-push
push 前に以下を自動チェックします:

| チェック | 内容 | 失敗時のアクション |
|---------|------|-------------------|
| PHP 構文チェック | 差分 PHP ファイルの `php -l` | push 中止 |
| SalesReport42 テスト | `SeoDataApiControllerTest` / `OrderGscQueryServiceTest` / `SeoAttributionServiceTest` を実行 | push 中止 |

SalesReport42 関連の変更がある場合は重点的に上記3ファイルをテストします。

### commit-msg
コミットメッセージが Conventional Commits 形式かを検証します:

```
<type>(<scope>): <subject>

type: feat, fix, docs, style, refactor, test, chore, perf, ci, build
```

例:
```
feat(chat): チャットウィジェットにモーションを追加
fix(api): レート制限のIP検証を修正
refactor: リファクタリング
```

## スキップ

フックをスキップしたい場合:

```bash
git commit --no-verify -m "chore: テスト用コミット"
```

## トラブルシューティング

### フックが実行されない
```bash
# フックパスの設定を確認
git config core.hooksPath

# 実行権限を確認
ls -la .githooks/
```

### PHP 構文チェックが遅い
PHP のバージョンに依存します。PHP 8.0+ を使用してください。
