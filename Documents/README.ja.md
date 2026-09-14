# AIチャットアシスタント for EC-CUBE 4.2 / 4.3

![AIチャットアシスタント for EC-CUBE 4.2/4.3](Resource/images/readme-hero.png)

![EC-CUBE](https://img.shields.io/badge/EC--CUBE-4.2%20%7C%204.3-orange)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4)
![License](https://img.shields.io/badge/license-GPL--2.0--only-green)
![MCP](https://img.shields.io/badge/MCP-Streamable%20HTTP-2ec9bb)
![WebMCP](https://img.shields.io/badge/WebMCP-Supported-2ec9bb)
![E2E](https://img.shields.io/badge/E2E-Playwright-brightgreen)

**EC-CUBEの商品情報とショップ独自のナレッジを利用して、購入者からの質問にAIが回答するオープンソースのAIコマースアシスタントです。**

OpenAI、Anthropic Claude、Google Geminiに対応し、商品検索・商品比較・在庫確認・複数ターンの会話など、ECサイトの商品選びをAIで支援します。

さらに **MCP（Model Context Protocol）とWebMCPの両方に対応**。

従来のチャットUIだけでなく、外部のMCPクライアントやAIエージェント、ブラウザ上のAIからEC-CUBEの機能を利用するための基盤としても利用できます。

[English README](README.md)

---

## 概要

ECサイトでは、購入前のユーザーから次のような質問が繰り返し発生します。

> 「この商品はいつ入荷しますか？」

> 「初心者向けの商品はありますか？」

> 「この2つの商品は何が違いますか？」

> 「予算内でおすすめの商品を教えてください」

AIチャットアシスタントは、EC-CUBEの商品情報やショップが登録したナレッジを利用して、こうした質問へ自然言語で回答します。

同じセッション内では会話履歴を維持するため、

```text
「初心者向けの商品を教えて」
            ↓
「その中で一番安いものは？」
            ↓
「それの在庫は？」
```

といった連続した質問にも対応できます。

AIだけでは解決できない問い合わせについては、人によるサポートへ引き継ぐこともできます。

**商品案内の自動化だけではなく、問い合わせ履歴の分析、FAQ改善、有人対応へのエスカレーションまでEC-CUBEの管理画面から運用できます。**

---

# フィロソフィー

私たちは、ソフトウェアだけでなく、長期間にわたり見直されず社会に積み重なった仕組みにも「技術的負債」と似た構造が存在すると考えています。

OSSの開発、公開、改善、議論を通じて、技術を社会へ還元していくことを大切にしています。

この考え方については以下のコラムで紹介しています。

[社会にも、技術的負債がある。｜リキッド通販ショップ](https://www.thch-vape.shop/guide/column/git-log--oneline--all--society)

---

# 主な特徴

* AIによる商品検索・商品案内
* 商品比較・在庫確認
* 複数ターンの会話
* OpenAI対応
* Anthropic Claude対応
* Google Gemini対応
* ショップ独自ナレッジ
* AI + 定型回答のハイブリッド
* チャット履歴
* 利用統計・レポート
* メール回答依頼
* 通知
* アクセス制御
* MCP Server
* MCP Streamable HTTP
* WebMCP
* MCP Discovery
* レート制限
* セキュリティ対策
* PC / スマートフォン対応

---

# AIによる商品案内

EC-CUBEの商品データを利用して、購入者の商品選びを支援します。

主に以下の情報を扱えます。

* 商品名
* 価格
* 在庫
* カテゴリ
* 商品検索
* 商品比較
* 複数ターンの会話

一般的なAIチャットとは異なり、EC-CUBEに登録されているショップの商品情報を利用して回答できます。

---

# 3つのAIプロバイダに対応

以下のAIプロバイダを利用できます。

### OpenAI

OpenAIの各種対応モデルを利用できます。

### Anthropic

Anthropic Claudeの対応モデルを利用できます。

### Google Gemini

Google Geminiの対応モデルを利用できます。

利用するプロバイダ・モデルはEC-CUBE管理画面から変更できます。

モデル一覧は外部JSONから更新できるため、新しいAIモデルが登場した場合でも、プラグイン本体を更新せずモデル定義を追加できます。

---

# MCP / WebMCP

AIチャットアシスタントは、通常のチャットUIに加えて **MCPとWebMCP** に対応しています。

```text
                    ┌─────────────────┐
                    │     EC-CUBE     │
                    │ Commerce Data   │
                    └────────┬────────┘
                             │
               ┌─────────────┼─────────────┐
               │             │             │
               ▼             ▼             ▼
         Chat Widget     MCP Server      WebMCP
               │             │             │
               ▼             ▼             ▼
           購入者        MCP Client    Browser / AI
                             │
                             ▼
                         AI Agent
```

これにより、EC-CUBEの商品データやEC機能を複数のAIインターフェースから利用できます。

---

## MCP Server

外部のMCP互換クライアントやAIエージェントからEC-CUBEへ接続するためのMCP Serverを提供します。

### Transport

```text
Streamable HTTP
```

### Endpoints

```text
POST /mcp
GET /.well-known/mcp.json
```

### MCP操作

以下のMCP操作に対応します。

```text
initialize
tools/list
tools/call
```

クライアントはまずMCP Serverへ接続し、利用可能なツールを取得したうえでEC-CUBEの機能を呼び出せます。

```text
AI Agent
    │
    ▼
initialize
    │
    ▼
tools/list
    │
    ▼
利用可能なToolを取得
    │
    ▼
tools/call
    │
    ▼
EC-CUBE
```

---

## MCP Discovery

MCP ServerのDiscovery情報を公開します。

```text
GET /.well-known/mcp.json
```

MCPクライアントやAIエージェントが、MCP ServerのエンドポイントやTransport情報を発見するために利用できます。

---

## WebMCP

WebMCPにも対応しています。

MCP Serverがサーバー側からEC-CUBEの機能をAIエージェントへ提供するのに対して、WebMCPは**EC-CUBEのストアフロントとブラウザ上のAIを接続するためのインターフェース**として利用できます。

```text
EC-CUBE Storefront
        │
        ▼
      WebMCP
        │
        ▼
Browser / AI Agent
```

これにより、従来の

```text
人間
 ↓
Chat Widget
 ↓
AI
 ↓
EC-CUBE
```

だけではなく、

```text
Browser AI / AI Agent
        ↓
      WebMCP
        ↓
     EC-CUBE
```

という経路からECサイトの機能をAIへ公開できます。

---

## MCPとWebMCPの違い

|             | MCP Server         | WebMCP          |
| ----------- | ------------------ | --------------- |
| 主な実行場所      | サーバー               | ブラウザ / Webページ   |
| 対象          | MCPクライアント・AIエージェント | ブラウザ上のAI        |
| EC-CUBEとの接続 | HTTP MCP endpoint  | Storefront      |
| 用途          | 外部AIからEC機能を利用      | WebページからAIへ機能公開 |
| 本プラグイン      | 対応                 | 対応              |

両方を利用することで、EC-CUBEを単なる「AIチャット付きECサイト」ではなく、**AIエージェントから利用可能なコマース基盤**として拡張できます。

---

# AI + 定型回答のハイブリッド

すべての問い合わせをAIへ送信する必要はありません。

「返品」「送料」「営業時間」など、回答が決まっている質問にはシナリオ機能を利用できます。

```text
購入者
   │
   ▼
問い合わせ
   │
   ▼
シナリオに一致？
   │
   ├── YES ──→ 定型回答
   │
   └── NO
        │
        ▼
       AI
        │
        ├── 商品情報
        └── ナレッジ
             │
             ▼
            回答
```

定型質問ではAI APIを呼び出さないため、

* AI APIコスト削減
* レスポンス高速化
* 回答内容の統一

にも利用できます。

---

# 最短セットアップ

基本的なAIチャットを開始するために、すべての機能を設定する必要はありません。

## 1. プラグインをインストール

GitHub Releaseなどからプラグインパッケージを取得してEC-CUBEへインストールします。

CLIの場合：

```bash
php bin/console eccube:plugin:install --code=AiChatAssistant42
php bin/console eccube:plugin:enable --code=AiChatAssistant42
```

EC-CUBE管理画面から有効化することもできます。

---

## 2. APIキーを設定

EC-CUBE管理画面からプラグイン設定を開きます。

```text
設定
└── AIチャットアシスタント
      └── プラグイン設定
```

以下のいずれかのAPIキーを設定します。

* OpenAI
* Anthropic
* Google Gemini

---

## 3. チャットを有効化

「チャットを有効にする」をONにします。

フロントエンドへAIチャットウィジェットが表示されます。

**基本的なセットアップはこれだけです。**

ナレッジ、シナリオ、通知、アクセス制御などは必要に応じて設定できます。

---

# 購入者側の機能

## レスポンシブチャット

PC / スマートフォンに対応したチャットウィジェットを表示します。

ショップデザインに合わせて以下を変更できます。

* ウィジェットカラー
* サイズ
* 表示位置
* AIアシスタント表示名
* 初回メッセージ

---

## 複数ターンの会話

同じセッション内では過去の会話を参照し、文脈を維持できます。

```text
「初心者向けの商品を教えて」
          ↓
「その中で一番安いものは？」
          ↓
「それの在庫は？」
```

このような質問を一連の会話として処理できます。

---

## メール回答依頼

AIチャットだけでは解決できなかった場合、購入者はショップへ回答を依頼できます。

```text
AIチャット
    │
    ▼
解決できない
    │
    ▼
メール回答を依頼
    │
    ▼
店舗スタッフ
    │
    ▼
有人対応
```

AIですべての問い合わせを完結させるのではなく、人によるサポートへ引き継げる設計です。

---

# 管理画面

AIチャットの設定から運用状況の分析まで、EC-CUBE管理画面から行えます。

| ページ     | 主な機能                        |
| ------- | --------------------------- |
| ダッシュボード | 総会話数・解決率・エラー率・平均応答時間        |
| プラグイン設定 | AIプロバイダ・モデル・APIキー・システムプロンプト |
| チャット履歴  | 購入者とAIの会話履歴                 |
| 統計・レポート | プロバイダ別・モデル別・時間帯別分析          |
| ナレッジ管理  | FAQ・ショップ独自情報                |
| シナリオ管理  | キーワードによる定型回答                |
| アクセスルール | IP・時間帯・ブロックワード              |
| デザイン設定  | 色・サイズ・位置・表示名                |
| 通知ルール   | メール・Webhook・LINE通知          |

---

# ダッシュボード

AIチャットの運用状況を確認できます。

主なKPI：

* 総会話数
* 解決率
* エラー率
* 平均応答時間
* プロバイダ別利用状況
* モデル別利用状況
* 時間帯別リクエスト
* 未対応メール返信

単にAIチャットを設置するだけではなく、

```text
利用
 ↓
計測
 ↓
分析
 ↓
頻出質問を発見
 ↓
ナレッジ / シナリオ改善
 ↓
回答品質向上
```

という改善サイクルを回せます。

---

# ナレッジ管理

EC-CUBEの商品情報だけでは回答できないショップ独自情報を登録できます。

例えば、

```text
タイトル:
返品・交換について

カテゴリ:
返品・交換

本文:
商品到着後7日以内、
未開封の商品に限り返品できます。
```

と登録すると、AIが回答時のナレッジとして利用します。

利用例：

* 返品・交換
* 配送
* 送料
* 支払方法
* 商品の使い方
* 店舗情報
* ショップ独自FAQ

---

# シナリオ管理

特定の質問に対して、AIを利用せず定型回答できます。

```text
キーワード:
返品

マッチ:
部分一致

回答:
返品は商品到着後7日以内、
未開封の商品に限り受け付けています。
```

対応するマッチ方式：

| タイプ  | 動作            |
| ---- | ------------- |
| 完全一致 | 入力内容が完全に一致    |
| 部分一致 | 入力内容にキーワードを含む |
| 正規表現 | 正規表現による判定     |

複数のシナリオが一致した場合は優先度によって回答を決定します。

---

# チャット履歴・分析

購入者とAIの会話を管理画面から確認できます。

記録される主な情報：

* ユーザー入力
* AI回答
* セッション
* AIプロバイダ
* AIモデル
* 応答時間
* トークン使用量
* エラー
* 使用したツール
* メール回答依頼

チャット履歴から頻出質問を発見し、ナレッジやシナリオへ追加できます。

```text
購入者の質問
      │
      ▼
チャット履歴
      │
      ▼
頻出質問を発見
      │
      ▼
ナレッジ / シナリオへ追加
      │
      ▼
回答品質を改善
```

---

# 統計・レポート

チャット利用状況を分析できます。

主な分析対象：

* AIプロバイダ別
* AIモデル別
* 時間帯別
* エラー状況
* 応答時間
* 利用状況

CSV出力にも対応しています。

---

# アクセス制御

AI APIの不正利用や不要なリクエストを抑えるため、アクセスルールを設定できます。

対応ルール：

* IPアドレス
* 時間帯
* ブロックワード

通常のチャットに加えて、MCP HTTPエンドポイントにもレート制限を実装しています。

---

# 通知

問い合わせ状況に応じて通知できます。

対応：

* メール
* Webhook
* LINE

AIだけで処理せず、人による対応が必要な問い合わせを店舗運営へつなげる用途に利用できます。

---

# セキュリティ

ECサイトでAIや外部エージェントを利用することを前提として、複数のセキュリティ対策を実装しています。

主な対策：

* CSRF保護
* APIキーのマスク表示
* ログ匿名化
* シナリオ入力検証
* 正規表現インジェクション対策
* セッション単位のレート制限
* MCP IP単位レート制限
* MCPツール単位レート制限
* JSON-RPC 2.0検証
* Content-Type検証
* SQLワイルドカードのエスケープ
* 内部エラー情報のサニタイズ

MCPレスポンスでは、SQLSTATE、Doctrine、内部DBテーブル名、PHPファイル名などの内部情報が外部へ漏洩しないようエラーメッセージをサニタイズします。

---

# テスト・品質管理

MCP HTTPインターフェースについてPlaywrightによるE2Eテストを用意しています。

テスト対象には以下を含みます。

* MCP Discovery
* initialize
* tools/list
* tools/call
* HTTPリクエスト検証
* エラー処理
* レート制限
* セキュリティ関連レスポンス

PHPコードでは以下の品質管理ツールを利用しています。

* PHPUnit
* PHP_CodeSniffer
* PHPStan
* PHPMD
* PHPMetrics

コード品質チェック：

```bash
composer quality
```

CI向け：

```bash
composer quality:ci
```

---

# 対応環境

## EC-CUBE

* EC-CUBE 4.2
* EC-CUBE 4.3

## PHP

```text
PHP >= 8.1
```

## データベース

* MySQL
* PostgreSQL
* SQLite（主に開発・テスト用途）

MySQL / PostgreSQL間のLIKE検索や真偽値処理など、DBMS間の差異についても互換性を考慮しています。

---

# アーキテクチャ

AIチャットアシスタントは、大きく3つのAIアクセス経路を提供します。

```text
                       EC-CUBE
                          │
               ┌──────────┴──────────┐
               │                     │
        Product Catalog         Store Knowledge
               │                     │
               └──────────┬──────────┘
                          │
                          ▼
                 AI Chat Assistant
                          │
        ┌─────────────────┼─────────────────┐
        │                 │                 │
        ▼                 ▼                 ▼
     OpenAI           Anthropic          Gemini


        ┌─────────────────────────────────┐
        │        AI Access Layer          │
        └─────────────────────────────────┘

              │             │
      ┌───────┴───────┐     └──────────────┐
      ▼               ▼                    ▼

 Chat Widget      MCP Server             WebMCP
      │               │                    │
      ▼               ▼                    ▼
   購入者       External AI Agent     Browser AI
```

つまり、

```text
Human → Chat → AI → EC-CUBE

AI Agent → MCP → EC-CUBE

Browser AI → WebMCP → EC-CUBE
```

という複数のアクセスモデルを1つのEC-CUBEプラグインで扱います。

---

# 開発

リポジトリを取得します。

```bash
git clone https://github.com/routeflags/ec-cube-ai-chat-assistant42.git
cd ec-cube-ai-chat-assistant42
```

依存関係をインストールします。

```bash
composer install
```

品質チェック：

```bash
composer quality
```

CI向け：

```bash
composer quality:ci
```

---

# プロジェクト構成

```text
AiChatAssistant42/
├── Controller/
├── Entity/
├── Event/
├── Form/
├── Repository/
├── Resource/
├── Service/
├── Tests/
├── Documents/
├── composer.json
└── README.md
```

---

# 変更履歴

リリースごとの追加機能、修正、セキュリティ変更については、

[CHANGELOG](Documents/CHANGELOG.md)

を参照してください。

---

# バグ報告・機能提案

バグ報告や機能提案はGitHub Issuesで受け付けています。

https://github.com/routeflags/ec-cube-ai-chat-assistant42/issues

報告時には可能であれば以下を記載してください。

* EC-CUBEバージョン
* PHPバージョン
* データベース
* プラグインバージョン
* 再現手順
* 期待する動作
* 実際の動作
* エラーログ（機密情報を除く）

---

# コントリビューション

Issue、バグ報告、ドキュメント改善、Pull Requestを歓迎します。

大きな変更を行う場合は、実装前にIssueで提案してください。

Pull Requestでは可能な範囲で、

* 既存コードスタイルとの整合
* テストの追加
* 静的解析
* 既存機能への影響確認

をお願いします。


# ライセンス

このプロジェクトは **GPL-2.0-only** ライセンスで公開されています。

詳細についてはリポジトリのライセンスファイルを参照してください。

---

# 開発元

**ROUTE FLAGS Co., Ltd.**

GitHub:

https://github.com/routeflags

Web:

https://blog.routeflags.com/

---

# AI × Commerce × MCP × WebMCP

AIチャットアシスタントは、ECサイトへチャットボットを追加するだけのプラグインではありません。

EC-CUBEの商品データ、ショップ独自ナレッジ、AIモデル、MCP、WebMCPを接続することで、

```text
EC-CUBE
   +
Commerce Data
   +
AI
   +
MCP
   +
WebMCP
```

を一つのオープンソースプラグインとして提供します。

目指しているのは、**人間がAIへ質問するECサイトだけではなく、AIエージェント自身が商品を発見し、ECサイトの機能を利用できるコマース基盤**です。

EC-CUBEを、AIエージェント時代のオープンなコマースプラットフォームへ。
