# AI チャットアシスタント for EC-CUBE 4.2

EC-CUBE の商品情報に AI チャットで質問できるプラグインです。

## 機能

### フロントエンド AI チャット

- 商品名・価格・在庫・カテゴリを自然言語で質問可能
- OpenAI / Anthropic / Google Gemini の 3 プロバイダに対応
- モデルは外部 JSON ファイルで定義（プラグイン更新なしでモデル追加可能）
- レスポンシブデザイン（PC / スマホ対応）
- メール返信依頼機能（チャットで解決しない場合）

### MCP サーバー

- STDIO で商品データを提供

### 管理画面（9セクション / 24ルート）


| ページ     | URL パス                                          | 機能                               |
| ------- | ----------------------------------------------- | -------------------------------- |
| ダッシュボード | `/<admin_route>/ai-chat-assistant/dashboard`    | KPI（総会話数・解決率・エラー率）、プロバイダ別統計      |
| プラグイン設定 | `/<admin_route>/ai-chat-assistant/settings`     | 有効/無効、プロバイダ、モデル、API キー、システムプロンプト |
| チャット履歴  | `/<admin_route>/ai-chat-assistant/history`      | 会話ログ一覧・詳細表示                      |
| 統計・レポート | `/<admin_route>/ai-chat-assistant/report`       | プロバイダ別・モデル別・時間帯別分布、CSV エクスポート    |
| ナレッジ管理  | `/<admin_route>/ai-chat-assistant/knowledge`    | FAQ / 商品情報の登録・編集・削除              |
| シナリオ管理  | `/<admin_route>/ai-chat-assistant/scenario`     | キーワードトリガーの自動応答登録                 |
| アクセスルール | `/<admin_route>/ai-chat-assistant/access`       | IP / 時間帯 / ブロックワード               |
| デザイン設定  | `/<admin_route>/ai-chat-assistant/design`       | ウィジェット色 / サイズ / 位置 / 表示名         |
| 通知ルール   | `/<admin_route>/ai-chat-assistant/notification` | メール / Webhook / LINE 通知設定        |


> **注**: `/<admin_route>/` は EC-CUBE の管理画面ルートプレフィックスです（デフォルト: `/admin-dev/`）。

## 必要要件

- EC-CUBE 4.2
- PHP 8.0+
- Guzzle（EC-CUBE に同梱済み）

## インストール

```bash
composer require routeflags/ec-cube-ai-chat-assistant42
php bin/console eccube:plugin:install --code=AiChatAssistant42
php bin/console eccube:plugin:enable --code=AiChatAssistant42
```

## セットアップ

1. 管理画面 → 設定 → AI チャットアシスタント → プラグイン設定
2. API キーを入力（OpenAI / Anthropic / Google Gemini のいずれか）
3. チャット有効にする
4. フロントでチャットウィジェットを確認

## ディレクトリ構成

```
app/Plugin/AiChatAssistant42/
├── Controller/
│   ├── Admin/                     # 管理画面コントローラ（8ファイル）
│   │   ├── DashboardController.php    # ダッシュボード + 設定
│   │   ├── ChatHistoryController.php  # チャット履歴
│   │   ├── ReportController.php       # 統計・レポート
│   │   ├── KnowledgeController.php    # ナレッジ管理
│   │   ├── ScenarioController.php     # シナリオ管理
│   │   ├── AccessRuleController.php   # アクセスルール
│   │   ├── DesignController.php       # デザイン設定
│   │   └── NotificationController.php # 通知ルール
│   └── Api/                       # REST API コントローラ（2ファイル）
│       ├── ChatApiController.php      # /api/ai-chat-assistant/chat
│       └── ModelApiController.php     # /api/ai-chat-assistant/models
├── Entity/                        # Doctrine エンティティ（6ファイル）
│   ├── Config.php                     # プラグイン設定
│   ├── ChatLog.php                    # チャットログ
│   ├── Knowledge.php                  # ナレッジ
│   ├── Scenario.php                   # シナリオ
│   ├── AccessRule.php                 # アクセスルール
│   └── Notification.php               # 通知ルール
├── Repository/                    # リポジトリ（6ファイル）
├── Service/                       # サービスクラス
│   ├── AiAgentInterface.php           # AI エージェントインターフェース
│   ├── AiAgent/                       # 各プロバイダ実装
│   │   ├── OpenAiAgent.php
│   │   ├── AnthropicAgent.php
│   │   └── GeminiAgent.php
│   ├── AiAgentFactory.php             # ファクトリ
│   ├── AiModelRegistry.php            # モデルレジストリ
│   ├── ChatLogger.php                 # チャットログ記録
│   ├── LogSyncService.php             # ログ同期
│   ├── McpServerService.php           # MCP サーバー
│   ├── NotificationService.php        # 通知送信
│   └── AccessRuleService.php          # アクセス制御
├── EventListener/
│   └── ChatWidgetListener.php         # フロントにチャットウィジェット注入
├── Command/                       # コンソールコマンド（2ファイル）
├── DoctrineMigrations/            # DB マイグレーション（6ファイル）
├── Resource/
│   ├── config/
│   │   ├── routes.yaml                # 全ルート定義（28ルート）
│   │   ├── services.yaml              # DI 定義
│   │   ├── ai_models.json             # AI モデル定義
│   │   └── design_settings.json       # デザイン設定デフォルト値
│   ├── template/admin/                # 管理画面テンプレート（12ファイル）
│   └── template/default/
│       └── chat_widget.twig           # フロントチャットウィジェット
├── Resource/assets/
│   ├── css/chat-widget.css            # ウィジェット CSS
│   └── js/chat-widget.js              # ウィジェット JavaScript
├── Nav.php                        # 管理画面ナビゲーション
├── composer.json
├── eccube-plugin.yaml
├── CHANGELOG.md
└── README.md
```

## ルート定義

ルーティングはすべて `Resource/config/routes.yaml` で定義されています。
PHP 8 の `#[Route]` アトリビュートは使用していません（EC-CUBE 4.2 の Symfony 5.4 パターンに準拠）。


| カテゴリ    | ルート名プレフィックス                              | メソッド       |
| ------- | ---------------------------------------- | ---------- |
| API     | `ai_chat_assistant_api_*`                | GET / POST |
| ダッシュボード | `admin_ai_chat_assistant_dashboard`      | GET        |
| 設定      | `admin_ai_chat_assistant_settings`       | GET, POST  |
| ナレッジ    | `admin_ai_chat_assistant_knowledge_*`    | GET, POST  |
| シナリオ    | `admin_ai_chat_assistant_scenario_*`     | GET, POST  |
| アクセスルール | `admin_ai_chat_assistant_access_*`       | GET, POST  |
| デザイン    | `admin_ai_chat_assistant_design_*`       | GET, POST  |
| 通知      | `admin_ai_chat_assistant_notification_*` | GET, POST  |
| 履歴      | `admin_ai_chat_assistant_history*`       | GET        |
| レポート    | `admin_ai_chat_assistant_report`         | GET        |


## DB テーブル


| テーブル名                                | 用途                         |
| ------------------------------------ | -------------------------- |
| `plg_ai_chat_assistant_config`       | プラグイン設定（プロバイダ、モデル、API キー等） |
| `plg_ai_chat_assistant_log`          | チャットログ（メッセージ、応答、タイムスタンプ）   |
| `plg_ai_chat_assistant_knowledge`    | ナレッジベース（FAQ、商品情報）          |
| `plg_ai_chat_assistant_scenario`     | 自動応答シナリオ                   |
| `plg_ai_chat_assistant_access_rule`  | アクセス制御ルール                  |
| `plg_ai_chat_assistant_notification` | 通知設定                       |


## MCP サーバーの利用

```bash
# MCP サーバー起動
php bin/console app:ai-chat-assistant

# .mcp.json に追加
{
  "mcpServers": {
    "ec-product": {
      "command": "php",
      "args": ["bin/console", "app:ai-chat-assistant"],
      "cwd": "/path/to/ec-cube"
    }
  }
}
```

## AI モデルの追加

`app/Plugin/AiChatAssistant42/Resource/config/ai_models.json` を編集するか、
管理画面からリモート JSON URL を設定して自動更新してください。

## 開発メモ

### Twig 2.x 互換性

EC-CUBE 4.2 は Twig 2.15.4 を使用しています:

- `|reduce()` アロー関数: 非対応 → `for` ループに変換
- `|default([])`: 対応
- `form_token()`: 非対応 → `csrf_token('admin')` を使用

### DQL 制約

Doctrine DQL では以下がサポートされていません:

- `ELSE NULL` in CASE: `ELSE 0` に変更
- `HOUR()` 関数: ネイティブ SQL（`Connection::fetchAllAssociative`）に変換

## アンインストール

```bash
php bin/console eccube:plugin:uninstall --code=AiChatAssistant42
```

## ライセンス

Proprietary License