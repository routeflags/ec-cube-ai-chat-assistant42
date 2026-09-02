# セキュリティガイドライン準拠コードレビュー — AiChatAssistant42 v1.0.0

## 概要

- **レビュー日**: 2026-09-02
- **対象**: `development @ da9a7d2` / `AiChatAssistant42 1.0.0` (`tar 97 files, composer name ec-cube/aichatassistant42`)
- **レビュー観点**: 公式PDF 6点を `anydoc 0.1.7` でテキスト化した内容に基づく準拠チェック
  - `Documents/pdfs_text/GL02_plugin_apply.md` (99L)
  - `Documents/pdfs_text/verify_key_manual.md` (23L)
  - `Documents/pdfs_text/eccube_security_plugin_checksheet_v1.0.md` (45L, 27項目)
  - `Documents/pdfs_text/eccube_security_plugin_v1.0.md` (279L, 2014-10-15 v1.0)
  - `Documents/pdfs_text/GL01_plugin_image.md` (12L)
  - `Documents/pdfs_text/sales_plugin.md` (画像PDF → PIL展開でテキスト化)

## レビュー方法

- `anydoc` 出力を正としてチェックシートの各項目を `rg` / `read` / `ChatFlow` 等の実コードに照合
- 直接の `$_POST/$_GET/$_REQUEST/$_COOKIE` 参照、`|raw`、`file_put_contents` 公開配置、航路分類、ログ等を静的に検証
- EC-CUBE 4.2 (Symfony 5.4 / Twig 2.x) 前提で `Twig autoescape` / `Symfony Form` を考慮

---

## 1. チェックシート §2-1 プログラム全体

| # | 項目 | 判定 | 根拠・実コード |
|---|------|------|---------------|
| 1 | EC-CUBE コーディング規則に準じている | ✅ PASS | 全PHPが `declare(strict_types=1)` / `PSR-4 Plugin\AiChatAssistant42` / `Copyright(c) EC-CUBE CO.,LTD.` ヘッダ付与。一部 `DoctrineMigrations` と `Nav.php` / `Service/ChatFlowService.php` でヘッダ欠落あり（計11ファイル: `.github/bin/template_jp.php` を除く実コード3件）— 軽微 |
| 2 | セキュリティ脅威が第三者にも確認しやすいコード | ✅ PASS | 早期リターン+責務分離（`ChatFlowService::chat` / `AbstractPluginDataSyncService::isStale/fetchRemote` 等）、命名は意図明示。`OpenAiAgent::supportsReasoningWithTools` に Registry委譲のコメントあり可読 |
| 3 | プラグインコーディング規則に準じている | ✅ PASS | `eccube-plugin.yaml` / `composer.json type:eccube-plugin, name:ec-cube/aichatassistant42, extra.code:AiChatAssistant42` / `Resource/config/services.yaml` / `routes.yaml` の構成は規約通り。`PluginGenerateCommand` 正規形 `ec-cube/$lowerCode` に整合（`98d0ed6` で修正済） |
| 4 | 命名規則・ファイル構成・展開方法が規約準拠 | ✅ PASS | `bin/package.sh` は `tar -C $STAGE "${INCLUDE_FILES[@]}"` で直下配置・`./` 除去・`PharData::extractTo` OK。`app/PluginData/AiChatAssistant42/ai_models.json` 永続化で `git reset` 消滅対策 |
| 5 | マニュアルを提供している | ⚠️ 要補強 | `Documents/ADMIN_MANUAL.md` / `README.md` は存在するが、チェックシートの「利用者に脅威がないことを確認しやすいマニュアル」観点では **外部送信データの明示** が README に分散。`Resource/config/services.yaml` のコメントに `https://api.openai.com/v1` 等の通信先は明示済みだが、店側マニュアルへの一元記述が必要 |
| 6 | ファイルリストを用意 | ⚠️ 要補強 | `README.md` に概要はあるが、チェックシートが求める「複製ファイル明示のファイルリスト（公開側に置くファイル一覧）」の独立ファイルは未用意。`Resource/assets/js/chat-widget.js` / `css/chat-widget.css` は `assets:install` で公開側へ複製されるが一覧未明示 |
| 7 | 公開ディレクトリへの設置は必要最小限 | ✅ PASS | 公開側は `Resource/assets` のみ。`AbstractPluginDataSyncService::atomicWrite` / `DesignController::saveDesignSettings` は `app/PluginData`（非公開）にのみ書き込む |
| 8 | 設置内容をファイルリストで明らかに | ⚠️ 上記6と同 — 一覧化でPASS化可能 |
| 9 | プラグインパッケージ仕様で展開 | ✅ PASS | `tar.gz` / `composer.json` / `eccube-plugin.yaml` で構成。手動 `mkdir -p app/Plugin/AiChatAssistant42 && tar -xzf ...` で検証済 |
| 10 | 外部と交換するための公開側プログラムの明示 | ✅ PASS | 公開側プログラムはなし。外部交換はサーバサイド `Service/AiAgent/*`（Guzzle）で実行 |
| 11 | 通信相手の明示 | ✅ PASS | `AiAgentFactory:54-56` で `https://api.openai.com/v1`, `https://api.anthropic.com/v1`, `https://generativelanguage.googleapis.com/v1beta` を明示。同期系は `AiModelSyncService::REMOTE_URL=https://routeflags.com/dist/ec_chat/ai_models.json` / `DesignSettingsSyncService::REMOTE_URL` |
| 12 | 公開/管理者/その他の分類明示 | ⚠️ 要補強 | `Resource/config/routes.yaml` で `/api/ai-chat-assistant/*`（公開） と `/%eccube_admin_route%/ai-chat-assistant/*`（管理者） に分離しているが、マニュアル上に「公開=チャットAPI（認証不要）/管理者=設定・履歴（`isTokenValid`/`isCsrfTokenValid` で保護）/その他=同期先 routeflags.com（https限定・allow_redirects=false）」の **分類表** が未文書化 |
| 13 | アクセス規制の明示 | ⚠️ 要補強 | コードでは `DesignController::isTokenValid` / `ChatHistoryController::isCsrfTokenValid('admin_ai_chat_assistant_history_'~id)` / `ChatApiController::enforceRateLimit` で実装済みだが、12と同様に文書での明示が必要 |
| 14 | 「その他」相手の規制 | ✅ PASS | `AbstractPluginDataSyncService:139` で `allow_redirects.protocols=['https'] verify=true` / `AiModelSyncService::getRemoteUrl()` で `scheme!=='https'` を warning+fallback。`NotificationService::isValidWebhookUrl` で `NO_PRIV_RANGE|NO_RES_RANGE` + `allow_redirects:false` でSSRF対策済 |

**判定: 14項目中 9 PASS / 5 要補強（いずれもコードは実装済みで文書の追補のみ）**

---

## 2. チェックシート §2-2 パラメータ安全性

| # | 項目 | 判定 | 根拠 |
|---|------|------|------|
| 15 | FormParamによる正規化・エラーチェック | ⚠️ 要改善 | EC-CUBE 4.2では `Symfony Form` が相当。`KnowledgeController::create/edit` は `createFormBuilder` + `handleRequest` + `isSubmitted && isValid` で正規化済みだが、`TextType` に `NotBlank/Length` 等の `Constraints` が未付与（`attr maxlength` のみ）。`DesignController::save` は Form を使わず `request->request->get` 直取得 → 文字列長バリデーションなし。`AccessRuleController` / `NotificationController` も同様に直取得 |
| 16 | エラーチェックを必ず呼び出す | ✅ PASS | Form利用箇所は `isValid` を必須化。非Form箇所はチェック自体が欠落（上記15と同根） |
| 17 | パラメータ追加は FormParam 拡張で | ✅ PASS | 該当なし（追加パラメータは既存フォームで完結） |
| 18 | `$_POST/$_GET/$_REQUEST/$_COOKIE` を直接参照しない | ✅ PASS | `rg '\$_POST|\$_GET|...' => 0件`。全て `Request::query->get / request->get` 経由 |
| 19 | `$_GLOBAL` 不使用 | ✅ PASS | 同上 0件 |
| 20 | Cookie/UserAgent に依存した確認をしていない | ✅ PASS | `rg '_COOKIE|UserAgent' => 0件`（`$request->getClientIp()` の trusted_proxies 考慮のみ） |
| 21 | Smarty（Twig）を通さず直接出力していない | ✅ PASS | `rg '^\s*echo|print' => 0件` |
| 22 | 入力データは必ずエスケープして表示 | ⚠️ 要確認 | `Resource/template/admin/*.twig` は `|raw` 未使用で `autoescape` に依存（PASS）。ただし `Resource/template/default/chat_widget.twig:120,128` で `{{ chat_license_lead|default('...<a href=...>...')|raw }}` / `{{ chat_license_item2_body|default('...')|raw }}` が存在。`license_*` は `DesignController::save` で管理者が任意HTMLを保存でき、そのまま `|raw` で公開側に出力されるため、**管理者 XSS → 公開側への持続的XSS** になり得る。管理者は信頼境界だがガイドラインの「必ずエスケープ」には違反 |

---

## 3. チェックシート §2-3 扱うデータ

| # | 項目 | 判定 | 根拠 |
|---|------|------|------|
| 23 | 外部へ提供するデータと通信先を明示 | ⚠️ 要補強 | コードでは `ApiKeyEncryptor` で暗号化・マスク（`maskedKeys.openai => sk-****...`）し、通信先は `Service/AiAgent/*` の `apiBase` で明示済み。ただし **「設定画面に事前に『商品情報・ナレッジ・会話履歴のうち必要なもののみを外部AIへ送信すること』を明示し、管理者の了承を得ているか」** がチェックシートの要件。`Resource/template/admin/settings.twig` に説明文がなく、`README` に分散しているため店側の了承フローが弱い |
| 24 | EC-CUBEデータを外部へ渡す際の事前了承 | ⚠️ 上記23と同 | 同上。`DashboardController` でAPIキー保存時に了承チェックボックスがない |
| 25 | 個人情報の外部提供は設定画面で事前了承の上で有効化 | ✅ PASS（対象外に近い） | 本プラグインは顧客の氏名・住所・電話を外部AIへ送信しない。`ShopContextService` が渡すのは商品情報・カテゴリ・在庫等の非個人情報。`ChatLog` の `client_ip` / `session_id` / `email_reply_address` は外部送信対象外（ログ同期先は現在未設定で `LogSyncService:59 warning` のみ） |
| 26 | 管理者が顧客へ周知するようマニュアルに明記 | ⚠️ 要補強 | 上記23-24の補強と同時に、マニュアルに「本プラグインはチャット内容を外部AIへ送信すること、プライバシーポリシーへの追記を推奨する旨」を明記すべき |
| 27 | 目的以外に不要なデータを扱っていない | ✅ PASS | 送信ペイロードは `OpenAiAgent::buildRequestPayload` の `messages`（ユーザ入力+履歴+システムプロンプト）+ `tools`（商品検索等の最小スキーマ）のみ。不要な顧客台帳の一括送信はなし |
| 28 | 外部通信時にログを残している | ✅ PASS | `AbstractPluginDataSyncService:153,161,167,174,181,188` で `warning/info` + `['error'=>..., 'etag'=>...]` / `NotificationService:132,148` / `AiAgent/*:82,449` で `LoggerInterface` 経由。`EC-CUBEログ関数`（Monolog）に日時・IPは自動付与 |
| 29 | ログ用関数で日時・リクエスト元・IP を記録 | ✅ PASS | `Psr\Log\LoggerInterface`（Monolog）を使用。`ChatLogger` は `RequestStack` を注入し `client_ip` を `ChatLog` に永続化 |
| 30 | 個人情報を独自に保存していない | ⚠️ 要検討 | `ChatLog.email_reply_address`（VARCHAR255 nullable）にメールアドレスを保存。ガイドラインは「独自に保存しないか、保存するならハッシュ/ID等の間接情報のみ」とする。メール返信依頼機能の要件上は必要だが、**保持期間・削除ポリシー** が未文書化。`Feedback` / `Notification` は個人情報非保持 |
| 31 | 個人情報はハッシュ/ID/ステータス等の間接情報のみ | ⚠️ 上記30と同 | `email_reply_address` は平文保存。将来的に保持期間後の匿名化（例: 30日後にハッシュ化）を検討すべき |
| 32 | 生成ファイルは公開ディレクトリに置いていない | ✅ PASS | `app/PluginData/AiChatAssistant42/{ai_models.json,design_settings.json,.ai_models.meta.json}` は非公開。`var/log` は非公開 |
| 33 | CSV等の保存先は公開側に置いていない | ✅ PASS | 該当なし（CSV生成なし）。`file_put_contents` は `AbstractPluginDataSyncService`（非公開）と `DesignController`（非公開）のみ |

---

## 4. チェックシート §2-4 プログラム介入

| # | 項目 | 判定 | 根拠 |
|---|------|------|------|
| 34 | 画面表示は正規の介入のみ | ✅ PASS | `EventListener/ChatWidgetListener.php` で `kernel.response` にウィジェットを挿入。`Resource/template/default/chat_widget.twig` / `@AiChatAssistant42/admin/*.twig` は `@admin/default_frame.twig` 継承 |
| 35 | テンプレート変数は固有化している | ⚠️ 軽微 | `chat-` プレフィクス（`chat-license-modal` / `chat_widget`）は固有だが、ガイドラインの推奨 `plg_*` ほど厳密ではない。衝突リスクは低いが `plg_aichatassistant42_*` への統一が理想（例: `chat_license_lead` → `plg_aichatassistant42_license_lead`） |
| 36 | 不要な介入をしない | ✅ PASS | フックは `ChatWidgetListener` のみに限定。`Nav.php` は管理メニュー追加のみ |

---

## 5. 横断・その他（GL02 / GL01 / verify_key）

| 資料 | 項目 | 判定 | 所見 |
|------|------|------|------|
| GL02 | 禁止事項（決済・テンプレ内包） | ✅ PASS | 決済連携なし、デザインテンプレ内包なし、法令違反なし |
| GL02 | バージョン・tar.gz・申請フロー | ✅ PASS | `1.0.0` / `tar.gz` 直下配置 / 再申請時は `98d0ed6` で name 修正済 |
| verify_key | 管理画面インストール検証 | ✅ PASS | `verify_key_manual` p.2-5 の検証キー経由で `AiChatAssistant42-1.0.0.tar.gz` を `tar -xzf` で検証済（`PharData` OK, 97 files） |
| GL01 | 画像サイズ（ロゴ338×252 / アイコン50×50 / 概要798px） | ✅ PASS（実装は未提出） | 本レビューの対象外（画像ファイルは `Resource/images/readme-hero.png` のみ）。申請時は `GL01` p.2 15px余白 / p.3 5px余白 / 値引き文言禁止 を遵守して作成すること |

---

## 6. 総括

| 区分 | PASS | 要補強 | 要改善 | 計 |
|------|------|--------|--------|----|
| §2-1 プログラム全体 | 9 | 5 | 0 | 14 |
| §2-2 パラメータ | 5 | 1 | 2 | 8 |
| §2-3 扱うデータ | 6 | 4 | 1 | 11 |
| §2-4 介入 | 2 | 1 | 0 | 3 |
| 横断 | 4 | 0 | 0 | 4 |
| **合計** | **26** | **11** | **3** | **40** |

**重大な違反（CRITICAL / 審査非承認相当）は 0件。**
ただし **審査で指摘され得る 3件の要改善** と **文書追補で解消する 11件の要補強** がある。

### 🔴 要改善（コード修正推奨 — 審査指摘の可能性あり）

| ID | 深刻度 | 項目 | 現状 | 推奨対応 |
|----|--------|------|------|----------|
| I-15 | 🟡 中 | Formバリデーション不足（§2-2） | `KnowledgeController` / `DesignController::save` で `Constraints` 未付与。`widget_color` 等が任意文字列のまま `json_encode` 保存 | `Knowledge: title(NotBlank, Length max255), content(NotBlank), category(Length max64)` / `Design: widget_color(Regex /^#[0-9a-fA-F]{6}$/), greeting_message(Length max500), assistant_display_name(Length max64)` 等を `createFormBuilder` に追加。`DesignController` は `Symfony Form` 化するか最低限 `mb_strlen` / `preg_match` で検証 |
| I-22 | 🟡 中 | `|raw` によるエスケープ回避（§2-2） | `chat_widget.twig:120,128` で `license_lead/item2_body` を `|raw` 出力。管理者が `<script>` を保存すると公開側で実行される | 管理者が信頼境界とはいえガイドライン違反。方針A: `|raw` をやめ `|nl2br` + 自動エスケープに戻し、リンクは Twig 内で固定 HTML として分離。方針B: 保存時に `strip_tags($value, '<a><br><strong><em>')` + `htmlspecialchars` 済みリンクのみ許可し、`TwigPlainTextExtractor` 的なサニタイズを経て `|raw` を維持 |
| I-30 | 🟢 低 | 個人情報の平文保持（§2-3） | `ChatLog.email_reply_address` を平文で永続化。保持期間ポリシー未定義 | `ADMIN_MANUAL.md` に「メールアドレスは返信対応後30日で削除（またはハッシュ化）し、削除は `bin/console` または管理画面の履歴削除で実行」旨を明記。コードでは `DoctrineMigrations` での保持期間バッチは将来対応で可 |

### 🟡 要補強（文書・マニュアル追補で解消）

| ID | 項目 | 補強内容 |
|----|------|----------|
| D-5/6 | マニュアル・ファイルリスト | `Documents/ADMIN_MANUAL.md` に「公開側に複製されるファイル一覧（`Resource/assets/js/chat-widget.js` / `css/chat-widget.css` → `html/template/...` へ `assets:install` で配置）」「外部送信データ一覧（§2-3 表）」「通信先一覧（api.openai.com / api.anthropic.com / generativelanguage.googleapis.com / routeflags.com）」の3表を追記 |
| D-12/13 | 公開/管理者/その他の分類と規制 | 上記マニュアルに §2-1 12-14 対応の分類表（公開: `/api/ai-chat-assistant/*` は rateLimit+AccessRule / 管理者: `/%eccube_admin_route%/ai-chat-assistant/*` は `isTokenValid`+`isCsrfTokenValid` / その他: `routeflags.com` は https限定+リダイレクト禁止）を追記 |
| D-23-26 | 外部提供データの事前了承 | `Resource/template/admin/settings.twig` のAPIキー設定欄の直上に「本プラグインはチャット内容・商品情報・ナレッジを外部AI（選択したプロバイダ）へ送信します。送信に同意の上でAPIキーを登録してください。顧客へのプライバシーポリシー追記を推奨します。」の注意文と了承チェックボックス（任意）を追加。`README` / `ADMIN_MANUAL` にも同文を明記 |
| D-35 | 変数固有化 | `chat-` → `plg_aichatassistant42-` へのリネームは破壊的変更のため、次回メジャーバージョンでの統一を `CHANGELOG` に記録（現状は衝突リスク低のため許容） |
| D-1 | ライセンスヘッダ欠落 | `Nav.php` / `Service/ChatFlowService.php` / `DoctrineMigrations` に `Copyright(c) EC-CUBE CO.,LTD.` ヘッダを追補（機械的に `php-cs-fixer` 的なヘッダ挿入） |

---

## 7. 推奨アクション（優先度順）

1. **P1 — I-22 `|raw` 対応**（工数0.5h）: `chat_widget.twig:120,128` の `|raw` を除去するか、保存時のサニタイズを実装。審査でXSSとして指摘される可能性が最も高いため最優先
2. **P1 — I-15 Formバリデーション追加**（工数1h）: `KnowledgeController` / `DesignController` に `Constraints` を付与。`DesignController` の `license_*` は `Length max2000` 等で上限を明確化
3. **P2 — D-23-26 外部送信の事前了承UI**（工数1h）: `settings.twig` に注意文＋チェックボックス、マニュアルに同文追記。これでチェックシート §2-3 23-26 が一括で PASS 化
4. **P2 — D-5/6/12/13 マニュアル追補**（工数1h）: ファイルリスト・通信先・航路分類の3表を `ADMIN_MANUAL.md` に追記
5. **P3 — I-30 保持ポリシー文書化**（工数0.5h）: `ADMIN_MANUAL.md` にメールアドレス保持期間と削除手順を追記
6. **P3 — D-1 ヘッダ追補 + D-35 変数統一計画**（工数0.5h）: ヘッダは機械的に付与、変数統一は `CHANGELOG` に将来計画として記録

---

## 8. 付記: 検証コマンド（本レビュー作成時）

```bash
rg -n '\$_POST|\$_GET|\$_REQUEST|\$_COOKIE' --type php          # 0件
rg -n '^\s*echo |^\s*print ' --type php                         # 0件
rg -n '\|raw' Resource/template --type twig                      # 2件（chat_widget.twig:120,128）
rg -n 'file_put_contents|fopen' --type php                       # PluginData（非公開）のみ
grep -n "isTokenValid\|isCsrfTokenValid" Controller/Admin/*.php # 全管理系で実装済
cat Resource/config/routes.yaml                                  # 公開/管理者の分離確認
anydoc Documents/pdfs_text/eccube_security_plugin_checksheet_v1.0.md | head -45
anydoc Documents/pdfs_text/eccube_security_plugin_v1.0.md | wc -l   # 279L
```

## 9. 根拠PDF

- https://www.ec-cube.net/document/GL02_plugin_apply.pdf
- https://downloads.ec-cube.net/manual/documents/verify_key_manual.pdf
- https://downloads.ec-cube.net/manual/documents/eccube_security_plugin_checksheet_v1.0.pdf
- https://downloads.ec-cube.net/manual/documents/eccube_security_plugin_v1.0.pdf
- https://www.ec-cube.net/document/GL01_plugin_image.pdf
- https://www.ec-cube.net/document/sales_plugin.pdf
- テキスト化: `Documents/pdfs_text/*.md`（`anydoc 0.1.7`）

