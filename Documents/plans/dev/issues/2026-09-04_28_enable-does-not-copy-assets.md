# enable ではアセットがコピーされない — 原因と影響

## 優先度
🟡 中（通常インストールでは影響なし、開発/再有効化で影響あり）

## 対象
- 現象: `http://localhost:8080/` で `GET /html/plugin/AiChatAssistant42/assets/css/chat-widget.css 404` / `js 404` が連続し、チャットウィジェットが表示されない
- 計画書: `documents/plans/dev/2026-09-04_quality-tooling-refactor-plan.md` 外（検証環境起因）
- 関連ファイル: `/tmp/eccube-verify/src/Eccube/Service/PluginService.php`（`copyAssets` / `enable` / `installWithCode`）、`app/Plugin/AiChatAssistant42/Resource/assets/css/chat-widget.css`（22KB）、`Resource/assets/js/chat-widget.js`（31KB）、`html/plugin/AiChatAssistant42`（verify 環境で空）

## 事実（ログ・コード）

### 1. EC-CUBE のアセットコピーの仕様
`PluginService::copyAssets($pluginCode)` は固定で以下を行う：
```php
// src/Eccube/Service/PluginService.php:945-952
public function copyAssets($pluginCode) {
    $assetsDir = $this->calcPluginDir($pluginCode).'/Resource/assets';
    if (file_exists($assetsDir)) {
        $file->mirror($assetsDir, $this->eccubeConfig['plugin_html_realdir'].$pluginCode.'/assets');
    }
}
```
- コピー元: `[plugin_realdir]/[code]/Resource/assets`（本プラグインでは `Resource/assets/css,js` が存在）
- コピー先: `[plugin_html_realdir][code]/assets`（verify では `/var/www/html/html/plugin/AiChatAssistant42/assets`）

呼び出し箇所（`grep -n copyAssets PluginService.php`）：
- `192: $this->copyAssets($config['code']);` … `install($path, $source)`（tar.gz アップロード）
- `239: $this->copyAssets($config['code']);` … `installWithCode($code)`（`plugin:install --code`）
- `742,779: $this->copyAssets(...)` … `update()` / `updatePlugin()`（アップデート）
- `removeAssets` は `uninstall` 時のみ。`enable()` には `copyAssets` が無い。

### 2. verify 環境で再現した経緯
- `docker-compose.verify.yml` は `PLUGIN_DIR` を `app/Plugin/AiChatAssistant42:rw` にボリュームマウント。`html/plugin` はボリューム外で、ビルド直後は `html/plugin/.gitkeep` のみ。
- `2026-09-04 02:10` に `docker compose exec eccube php bin/console eccube:plugin:install --code=AiChatAssistant42` を実行 → `plugin already installed.` で `copyAssets` まで到達せず。
- 続けて `eccube:plugin:enable --code=AiChatAssistant42` は `[OK] Plugin Enabled.` だが、`enable()` の実装は `callPluginManagerMethod → setEnabled → regenerateProxy → flush` のみで `copyAssets` を呼ばない（679-710行）。
- 結果、`html/plugin/AiChatAssistant42/assets` は空のまま、`curl -I .../chat-widget.css` は `404 No route found for GET .../html/plugin/...`（`RouterListener.php:135`）、フロント `curl http://localhost:8080/` は `200` だがウィジェットは CSS/JS 404 で表示されない。

### 3. 手動コピーで復旧した証跡
```bash
docker compose exec eccube bash -c "
  mkdir -p html/plugin/AiChatAssistant42/assets/css html/plugin/AiChatAssistant42/assets/js
  cp -v app/Plugin/AiChatAssistant42/Resource/assets/css/* html/plugin/AiChatAssistant42/assets/css/
  cp -v app/Plugin/AiChatAssistant42/Resource/assets/js/* html/plugin/AiChatAssistant42/assets/js/
"
# -> chat-widget.css 22KB / chat-widget.js 31KB が 200 OK に
curl -I http://localhost:8080/html/plugin/AiChatAssistant42/assets/css/chat-widget.css  # 200 22297
curl -I .../js/chat-widget.js  # 200 31347
curl -s http://localhost:8080/ | grep -c ai-chat-assistant  # 5
SELECT is_enabled, provider FROM plg_ai_chat_assistant_config  # 1, gemini
```

## なぜ enable ではコピーされないのか（設計上の理由）

- EC-CUBE の想定フローは「アセットはインストール/アップデート時に一度コピーされ、enable/disable は DB フラグと Proxy 再生成だけ」であるため。`enable()` は軽量なトグルとして設計され、ファイル I/O を避けている。
- 逆に、開発時のように `git pull` で `Resource/assets` を更新したり、ボリュームマウントでホスト側だけを更新したりした場合は、DB の `is_enabled` は変わらないため `enable` を呼んでもアセットが追従しない。これが今回の乖離。

## 影響範囲

| 利用形態 | 影響 | 理由 |
|---|---|---|
| **通常ユーザー: 管理画面で tar.gz をアップロードしてインストール** | **影響なし** | `install($path)` が `unpack → copyAssets` を必ず呼ぶため、`html/plugin` に 22KB/31KB が配置される |
| **通常ユーザー: 有効→無効→有効のトグル** | **影響なし** | `disable/enable` でもアセットは削除されないため、初回インストール時のコピーが残る |
| **開発/verify: ボリュームマウント + `plugin:install --code` が already installed** | **影響あり（本件）** | `installWithCode` が `checkSamePlugin` で中断し、`enable` もコピーしないため手動 `cp` が必要 |
| **開発: `git pull` でアセットだけ更新** | **影響あり** | DB の有効状態は変わらないため、再 `enable` でも追従しない |

## 改善案（pre-commit は変更しない前提）

1. **verify 手順の明文化（推奨・最小工数）**  
   `Documents/LOCAL_VERIFICATION.md` の 2章に以下を追記：
   ```bash
   # verify 環境でアセットが 404 の場合
   docker compose -f docker-compose.verify.yml exec eccube bash -c "
     mkdir -p html/plugin/AiChatAssistant42/assets/css html/plugin/AiChatAssistant42/assets/js
     cp -v app/Plugin/AiChatAssistant42/Resource/assets/css/* html/plugin/AiChatAssistant42/assets/css/
     cp -v app/Plugin/AiChatAssistant42/Resource/assets/js/* html/plugin/AiChatAssistant42/assets/js/
   "
   ```
   または `eccube:plugin:uninstall → install` で再コピー。

2. **PluginManager での補完（恒久対策・要 Plan Architect 相談）**  
   `PluginManager::enable()` を本プラグインに追加し、`copyAssets` 相当の `Filesystem::mirror` を呼ぶ。EC-CUBE 本体の `enable()` が呼ばない分をプラグイン側で補う。影響範囲は `app/Plugin/AiChatAssistant42/PluginManager.php` のみに留まる。

3. **本対応は今回の品質リファクタ（95e5d34）のスコープ外**として、別 issue（本ファイル）で管理し、次スプリントで 2. を検討。`pre-commit` や `composer.json` には触れない。

## 備考
- 本件は 2026-09-04 17:08-17:13 の `docker compose logs`（`GET .../chat-widget.css 404` 連続）と `curl -I`（手動 cp 後に 200）で再現・復旧を確認済み。
- 通常ユーザーの初回インストールでは再現しないため、緊急度は中。ただし開発効率と verify の再現性のため、手順化は高 REI。
