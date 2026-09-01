# EasyArticle 依存の未定義による汎用プラグイン化リスク

## 対象
- 計画書: `documents/plans/dev/2026-09-01_ai-chat-assistant42-generalization-plan.md`
- 関連ファイル: `Repository/ProductRepository.php`
- 関連ファイル: `Service/ChatFlowService.php`
- 関連ファイル: `composer.json`

## 指摘事項
計画書に、`plg_ea_article` / `plg_ea_article_category` など EasyArticle 系テーブル依存の扱いが含まれていません。
現実装では記事検索やシステムプロンプト構築時にこれらテーブルへ直接アクセスしているため、依存プラグイン未導入環境では SQL 例外や機能劣化が発生します。

## 改善案
1. 計画に「依存プラグイン戦略」を追加する。
   - A案: `eccube-plugin.yaml` / `composer.json` で依存を明示する。
   - B案: 依存を optional とし、テーブル存在チェックで機能を自動 degrade する。
2. 実装では既存の `EntityManagerInterface` / DBAL を再利用し、`SchemaManager` でテーブル有無を判定する共通ヘルパーを導入する。
3. 受け入れシナリオに「EasyArticle 未導入時でも chat API が 500 にならない」を追加する。

## 備考
公開プラグイン化の最重要リスクです。
ドメイン汎用化より先に解消しないと、インストール直後の可用性を担保できません。
