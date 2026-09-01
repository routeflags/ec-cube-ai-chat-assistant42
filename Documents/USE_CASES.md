# AiChatAssistant42 — ユースケース資料

## 1. プラグイン概要

| 項目 | 内容 |
|------|------|
| プラグイン名 | AI チャットアシスタント (AiChatAssistant42) |
| 対象 | EC-CUBE 4.2 |
| プロバイダ | OpenAI / Anthropic / Google Gemini |
| フロントエンド | レスポンシブチャットウィジェット（PC / スマホ） |
| 管理画面 | 11 ページ（ダッシュボード、設定、履歴、レポート、ナレッジ、シナリオ、アクセスルール、デザイン、通知） |

---

## 2. ユースケース一覧

### UC-01: 商品に関する質問チャット（基本）

#### 概要
購入見込み客がフロントページで商品について質問し、AI が商品情報を基に回答する。

#### 前提条件
- プラグインが有効化されている
- AI プロバイダの API キーが設定されている
- 商品が DB に登録されている

#### フロー

```
[購入者] → [チャットウィジェット] → [POST /api/ai-chat-assistant/chat]
                                        ↓
                                 [ChatApiController::chat()]
                                        ↓
                           ┌─ アクセス制限チェック
                           ├─ シナリオマッチング（即答）
                           ├─ セッション履歴取得
                           ├─ システムプロンプト構築（設定値 + ナレッジ）
                           └─ AI エージェント呼び出し（ツール呼び出し付き）
                                        ↓
                            [AI] → [商品検索ツール] → [回答生成]
                                        ↓
                                 [応答 + ログ記録]
```

#### 期待する振る舞い

| 入力例 | 期待応答 |
|--------|---------|
| 「CBD リキッドでおすすめはありますか？」 | 商品リストからおすすめを提示 |
| 「THCH ペーパーの価格はいくらですか？」 | 価格と在庫状況を回答 |
| 「この商品は在庫ありますか？」 | ツールで在庫確認し回答 |
| 「返品したいのですが」 | シナリオマッチで返品手順を即答 |

#### エッジケース

| ケース | 期待振る舞い |
|--------|------------|
| チャット無効時 | HTTP 403 + 「AI チャットアシスタントが無効です」 |
| API キー未設定時 | HTTP 400 + 「○○ の API キーが設定されていません」 |
| レート制限超過 | HTTP 429 + 「リクエストが多すぎます」 |
| AI プロバイダ障害 | HTTP 500 + 「サーバーで問題が発生しました」 |
| ネットワーク切断 | フロントで「ネットワークに接続できません」 |

---

### UC-02: 複数ターンの会話（文脈維持）

#### 概要
同じセッション内で複数回の質問を行い、AI が過去の会話文脈を理解して回答する。

#### フロー

```
[購入者] 「初心者向けの商品を教えて」
    ↓ (session_id: abc123)
[AI] 「初心者には ○○ がおすすめです」
    ↓ (DB にログ保存)
[購入者] 「その中で一番安いものは？」  ← 同じ session_id
    ↓
[ChatLogger::fetchSessionHistory("abc123")]
    ↓ (過去のやり取りを取得)
[AI] 「一番安いのは △△ で ¥500 です」  ← 文脈を理解
```

#### 期待する振る舞い

| # | ユーザー入力 | 期待振る舞い |
|---|------------|------------|
| 1 | 「初心者向けの商品を教えて」 | 商品リストを提示 |
| 2 | 「その中で一番安いものは？」 | 1件目で挙げた商品の中から最安を特定 |
| 3 | 「それの在庫はある？」 | 2件目で挙げた商品の在庫を確認 |
| 4 | 「ありがとう」 | 丁寧な挨拶で応答 |

#### 技術詳細

- `ChatLogger::fetchSessionHistory()`: 直近 20 件のセッション履歴を DB から取得
- 履歴は `[{role: 'user', content: '...'}, {role: 'assistant', content: '...'}]` 形式
- 各エージェントの `buildInitialMessages()` / `buildInitialContents()` で履歴を prepend
- 別セッションの会話が混入しない（`session_id` で分離）

---

### UC-03: ナレッジベース活用

#### 概要
管理者が設定した FAQ / 商品情報を AI が参照して回答に活用する。

#### 管理画面操作

```
[管理者] → [ナレッジ管理] → [新規登録]
  - タイトル: 「返品・交換ポリシー」
  - 本文: 「商品到着後7日以内、未開封品に限り返品可能」
  - カテゴリ: 「返品・交換」
  - 状態: 有効
```

#### チャットでの活用

```
[購入者] 「返品したいのですが、条件は？」
    ↓
[ChatFlowService::buildSystemPrompt()]
    ↓ (システムプロンプトにナレッジを追加)
    ↓ "## ナレッジベース（FAQ・商品情報）"
    ↓ "- 【返品・交換】返品・交換ポリシー: 商品到着後7日以内..."
    ↓
[AI] 「返品は商品到着後7日以内、未開封品に限り可能です」
```

#### 技術詳細

- `ChatFlowService::buildKnowledgeContext()`: 有効なナレッジを DB から取得（最大 50 件）
- システムプロンプトの末尾に `## ナレッジベース` として追加
- カテゴリ別に整理表示

---

### UC-04: シナリオ自動応答

#### 概要
特定のキーワードに対する定型応答を事前設定し、AI 呼び出しなしで即答する。

#### 管理画面操作

```
[管理者] → [シナリオ管理] → [新規登録]
  - トリガーキーワード: 「返品」
  - マッチタイプ: 完全一致 / 部分一致 / 正規表現
  - 応答テキスト: 「返品は商品到着後7日以内にお願いします」
  - 優先度: 10
  - 状態: 有効
```

#### マッチングルール

| マッチタイプ | 説明 | 例 |
|-------------|------|-----|
| exact | 完全一致 | 「返品」→「返品」のみマッチ |
| contains | 部分一致 | 「返品」→「返品の方法を教えて」にもマッチ |
| regex | 正規表現 | `/^(返品|キャンセル)/` → 「返品したい」「キャンセルしたい」にマッチ |

#### フロー

```
[購入者] 「返品」
    ↓
[ChatFlowService::matchScenario("返品")]
    ↓ (priority DESC でソートされたシナリオを順にチェック)
    ↓ → exact マッチ: レスポンス返却
    ↓
[AI 呼び出しなし] → [即座に応答]
    ↓
[ログ記録] (provider/model は設定値、response_time_ms: 0)
```

#### 優先度

- 複数シナリオがマッチした場合、`priority` が高いものが採用される
- priority が同じ場合は DB 登録順（id ASC）

---

### UC-05: アクセス制限

#### 概要
IP アドレス、時間帯、ブロックワードによるアクセス制限を設定する。

#### 管理画面操作

```
[管理者] → [アクセスルール] → [新規登録]
  - ルール種別: ip / time / block_keyword
  - ルール値: 192.168.1.* / 22:00-06:00 / 禁止キーワード1,禁止キーワード2
  - 状態: 有効
```

#### 制限種別

| 種別 | 説明 | ルール値の例 |
|------|------|------------|
| ip | IP アドレスでブロック | `192.168.1.*`, `10.0.0.1` |
| time | 時間帯で制限 | `22:00-06:00` |
| block_keyword | キーワードでブロック | `禁止語1,禁止語2` |

#### フロー

```
[購入者] → [POST /api/ai-chat-assistant/chat]
    ↓
[ChatFlowService::checkAccessRules()]
    ↓ (DB から有効なルールを取得)
    ↓ IP チェック: ワイルドカードパターンマッチ
    ↓
    ├─ マッチ → HTTP 403 + 「IP アドレスがブロックされています」
    └─ マッチなし → 処理続行
```

---

### UC-06: デザインカスタマイズ

#### 概要
管理画面でウィジェットの色・サイズ・位置・挨拶メッセージを変更し、フロントに反映する。

#### 管理画面操作

```
[管理者] → [デザイン設定]
  - ウィジェットカラー: #ff6b6b
  - サイズ: large
  - 位置: bottom-left
  - 挨拶メッセージ: 「当ショップへようこそ！」
  → [保存]
```

#### フロー

```
[管理者] 保存
    ↓
[DesignController::save()] → design_settings.json に保存
    ↓
[購入者] フロントページを表示
    ↓
[ChatWidgetListener::onDefaultFrame()]
    ↓ (design_settings.json を読み込み)
    ↓ (Twig 変数として注入)
    ↓
[chat_widget.twig]
    ↓ style="--chat-widget-color: #ff6b6b;"
    ↓ class="chat-toggle--large"
    ↓ class="ai-chat-assistant--bottom-left"
    ↓挨拶メッセージ: 「当ショップへようこそ！」
    ↓
[chat-widget.css]
    ↓ --chat-brand が上書きされ、ボタン色が変化
```

#### 期待する振る舞い

| 設定項目 | 変更前 | 変更後 |
|---------|--------|--------|
| ウィジェット色 | `#2ec9bb`（ブランド色） | `#ff6b6b`（赤） |
| サイズ | medium (56px) | large (68px) |
| 位置 | bottom-right | bottom-left |
| 挨拶 | 「こんにちは！商品についてお気軽にご質問ください。」 | 「当ショップへようこそ！」 |

---

### UC-07: システムプロンプト設定

#### 概要
管理者が AI の振る舞いを定義するシステムプロンプトを設定し、全プロバイダで利用する。

#### 管理画面操作

```
[管理者] → [プラグイン設定]
  - システムプロンプト: 「あなたは当ショップの商品アドバイザーです。
    商品の成分・効果・使い方について専門的な知識で回答してください。
    回答は簡潔かつ丁寧に。」
  → [保存]
```

#### フロー

```
[購入者] 「CRDP の効果を教えて」
    ↓
[ChatFlowService::buildSystemPrompt()]
    ↓ (config.getSystemPrompt() を取得)
    ↓ (ナレッジコンテキストを追加)
    ↓
[OpenAiAgent::getSystemPrompt()]
    ↓ (customSystemPrompt があればそちらを使用)
    ↓
[API リクエスト] system: 「あなたは当ショップの商品アドバイザーです...」
```

---

### UC-08: メール返信依頼

#### 概要
チャットで解決できない場合、ユーザーがメールアドレスを入力して管理者への返信を依頼する。

#### フロー

```
[購入者] → [「解決できません」ボタンをクリック]
    ↓
[メールモーダル表示]
    ↓ [メールアドレス入力] → [送信]
    ↓
[POST /api/ai-chat-assistant/email-reply-request]
    ↓
[ChatApiController::emailReplyRequest()]
    ↓ (executeStatement で email_reply_address を更新)
    ↓ (影響行数を確認: 0件なら 404)
    ↓
[NotificationService::checkAndSend()]
    ↓ (管理者へメール/Webhook/LINE 通知)
    ↓
[購入者] 「メールアドレスを記録しました。後ほどご連絡いたします。」
```

#### 期待する振る舞い

| ケース | 期待振る舞い |
|--------|------------|
| 正常保存 | HTTP 200 + 「メールアドレスを記録しました」 |
| 対象ログなし | HTTP 404 + 「対象のチャットログが見つかりませんでした」 |
| 不正メールアドレス | HTTP 400 + 「リクエストが不正です」 |
| セッション ID 未指定 | HTTP 400 + 「リクエストが不正です」 |

---

### UC-09: 管理画面ダッシュボード

#### 概要
AI チャットの運用状況を KPI として一覧表示する。

#### 表示内容

| KPI | 説明 | 計算方法 |
|-----|------|---------|
| 総会話数 | 過去30日のチャット総数 | `COUNT(log.id)` |
| 解決率 | 解決済みの割合 | `resolved / total * 100` |
| エラー率 | エラー発生の割合 | `errors / total * 100` |
| 平均応答時間 | AI 応答の平均（ms） | `AVG(response_time_ms)` |
| プロバイダ別使用量 | OpenAI / Anthropic / Gemini の内訳 | `GROUP BY provider` |
| 時間帯別分布 | 24時間のリクエスト分布 | `HOUR(created_at)` |
| エラー内訳 | エラータイプ別集計 | `GROUP BY error_type` |
| 未対応メール返信 | メール返信依頼のうち未対応 | `email_replied_at IS NULL` |

---

### UC-10: チャット履歴確認

#### 概要
管理者が過去のチャットログを検索・確認し、顧客対応の質を管理する。

#### 管理画面操作

```
[管理者] → [チャット履歴]
  - セッション ID / プロバイダ / 日付範囲でフィルタ
  - [詳細] → 会話の全容を確認
```

#### 表示内容

| 項目 | 説明 |
|------|------|
| セッション ID | ユーザーごとのチャットセッション識別子 |
| プロバイダ | 使用した AI プロバイダ |
| モデル | 使用したモデル名 |
| ユーザー入力 | ユーザーの質問内容 |
| AI 応答 | AI の回答内容 |
| ツール使用 | 使用したツール名（商品検索等） |
| 応答時間 | AI 応答にかかった時間 |
| トークン使用量 | 入力 / 出力トークン数 |
| エラー | エラーメッセージ（ある場合） |
| メール返信依頼 | メールアドレス（依頼がある場合） |

---

### UC-11: 統計・レポート

#### 概要
チャット運用の詳細な統計を分析し、CSV エクスポートする。

#### 表示セクション

| セクション | 内容 |
|-----------|------|
| プロバイダ別使用量 | 件数、平均応答時間、エラー件数、構成比 |
| モデル別パフォーマンス | 件数、平均応答時間、平均入力/出力トークン、エラー件数 |
| 時間帯別リクエスト分布 | 0〜23時のバーチャート |
| エラー内訳 | エラータイプ、件数、最新メッセージ |

#### CSV エクスポート

- プロバイダ別データを CSV でダウンロード可能
- ヘッダー: プロバイダ、件数、平均応答時間(ms)、エラー件数、構成比(%)

---

### UC-12: MCP サーバー連携

#### 概要
外部の AI クライアント（Claude Desktop / Cursor / VS Code）から商品データにアクセスする。

#### 使用方法

```bash
# MCP サーバー起動
php bin/console app:ai-chat-assistant

# .mcp.json 設定例
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

#### 提供ツール

| ツール名 | 説明 | 入力 | 出力 |
|---------|------|------|------|
| search_products | 商品検索 | keyword, category, min_price, max_price | 商品リスト |
| get_product_detail | 商品詳細 | product_id | 商品情報 |
| check_stock | 在庫確認 | product_id | 在庫数・状態 |

---

## 3. API 仕様

### POST /api/ai-chat-assistant/chat

#### リクエスト

```json
{
  "message": "CBD リキッドのおすすめを教えて",
  "session_id": "abc123-def456"
}
```

| フィールド | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| message | string | はい | ユーザー入力メッセージ |
| session_id | string | いいえ | セッション ID（省略時は自動生成） |

#### 正常応答 (200)

```json
{
  "success": true,
  "reply": "おすすめの CBD リキッドは以下の3商品です...",
  "tools_used": ["search_products"]
}
```

#### エラー応答

| ステータス | 原因 | エラーメッセージ例 |
|-----------|------|-------------------|
| 400 | メッセージ未指定 | 「message フィールドは必須です」 |
| 400 | API キー未設定 | 「○○ の API キーが設定されていません」 |
| 403 | チャット無効 | 「AI チャットアシスタントが無効です」 |
| 403 | アクセス制限 | 「IP アドレスがブロックされています」 |
| 429 | レート制限 | 「リクエストが多すぎます」 |
| 500 | AI エラー | 「サーバーで問題が発生しました」 |

---

### POST /api/ai-chat-assistant/email-reply-request

#### リクエスト

```json
{
  "session_id": "abc123-def456",
  "email": "user@example.com"
}
```

#### 正常応答 (200)

```json
{
  "success": true,
  "message": "メールアドレスを記録しました。後ほどご連絡いたします。"
}
```

---

## 4. 管理画面ナビゲーション

```
設定
└── AI チャットアシスタント
    ├── ダッシュボード       (/ai-chat-assistant/dashboard)
    ├── プラグイン設定       (/ai-chat-assistant/settings)
    ├── チャット履歴         (/ai-chat-assistant/history)
    ├── 統計・レポート       (/ai-chat-assistant/report)
    ├── ナレッジ管理         (/ai-chat-assistant/knowledge)
    ├── シナリオ管理         (/ai-chat-assistant/scenario)
    ├── アクセスルール       (/ai-chat-assistant/access)
    ├── デザイン設定         (/ai-chat-assistant/design)
    └── 通知ルール           (/ai-chat-assistant/notification)
```

---

## 5. DB テーブル一覧

| テーブル名 | 主要カラム | 用途 |
|-----------|-----------|------|
| `plg_ai_chat_assistant_config` | provider, model, api_key_*, system_prompt, is_enabled | プラグイン設定 |
| `plg_ai_chat_assistant_log` | session_id, user_message, assistant_reply, tools_used, response_time_ms, email_reply_address | チャットログ |
| `plg_ai_chat_assistant_knowledge` | title, content, category, is_active | ナレッジベース |
| `plg_ai_chat_assistant_scenario` | trigger_keyword, trigger_type, response_text, priority | 自動応答シナリオ |
| `plg_ai_chat_assistant_access_rule` | rule_type, rule_value, is_active | アクセス制限 |
| `plg_ai_chat_assistant_notification` | notification_type, config_json | 通知設定 |

---

## 6. 技術的な制約・注意事項

| 項目 | 内容 |
|------|------|
| Twig | 2.15.4（アロー関数非対応、`|reduce` 非対応） |
| DQL | `ELSE NULL` 非対応 → `ELSE 0`、`HOUR()` 非対応 → ネイティブ SQL |
| ルーティング | `routes.yaml` ベース（PHP 8 `#[Route]` 属性非使用） |
| ナビゲーション | `Nav.php`（`EccubeNav` インターフェース）+ `eccube_nav.yaml` |
| jQuery | 不使用（Vanilla JS のみ） |
| コントローラ | `AbstractController` 継承、`protected $entityManager` を利用 |
| Entity ID | `private ?int $id = null;`（初期化必須） |
