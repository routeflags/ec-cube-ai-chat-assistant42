# AiModelSyncService 新設計画

## 目的
`Resource/config/ai_models.json` を静的同梱からリモート同期型に昇格し、`DesignSettingsSyncService` と同様の TTL/ETag/flock 機構で `app/PluginData/AiChatAssistant42/ai_models.json` を正本として運用する。プラグイン再配布なしでモデル追加・廃止（例: gpt-5 系の reasoning 制約変更、Gemini 3.x 入替）を即時反映する。

## 現状分析（ツール根拠）

- `serena_find_symbol: AiModelRegistry` / `DesignSettingsSyncService` で既存規約を確認
  - `AiModelRegistry` は `__construct(string $configPath)` で `file_get_contents + json_decode` のみ。リモート同期なし。`services.yaml:28-29` で `%kernel.project_dir%/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json` を固定注入。
  - `DashboardController::resolveAiModelsPath()` は `is_file` で 2候補を探索するが `PluginData` は見ない。
  - `OpenAiAgent::resolveSupportsReasoningFallback()` も同様に 6候補のローカル探索のみ。
  - `DesignSettingsSyncService` は `REMOTE_URL=https://routeflags.com/dist/ec_chat/design_settings.json` `TTL=86400` `ETag/Last-Modified` `flock + tmp+rename` `app/PluginData/AiChatAssistant42/design_settings.json` で完全実装済み。再利用可能なパターン。

## 設計方針

- **基底抽出（Issue #14 対応）**: `isStale/fetchRemote/atomicWrite/loadMeta/saveMeta/acquireLock/releaseLock/getSyncMeta` 約350行を `Service/AbstractPluginDataSyncService` に抽出。`DesignSettingsSyncService` と `AiModelSyncService` は `validate()` / `persist()` のみを実装する差分クラスとする。TASK 0 で先に基底抽出を完了させ、振る舞い不変をテストで担保する。
- **全文置換**: `ai_models.json` は `providers` 全体が正本のため `license_* のみマージ` ではなく**全文置換**とする。`persist()` 前に `copy($path, $path . '.bak')` で1世代バックアップを取得する（Issue #16）。
- **フォールバック集約（Issue #15 対応）**: `AiModelRegistry` を Single Source of Truth に昇格。`__construct(string $configPath, string $projectDir = '', ?LoggerInterface $logger = null)` に拡張し、`resolveConfigPath()` で `PluginData → Resource/config` の優先探索を集約。`DashboardController::resolveAiModelsPath()` / `OpenAiAgent::resolveCapabilityFromJson()` の分散探索は Registry 委譲に寄せ、重複を解消する。`services.yaml` は `AiModelRegistry` に `$projectDir` を注入し第一引数を `PluginData/ai_models.json` に切替。
- **実行契機**: `DashboardController::index()` 先頭で `AiModelSyncService::trySyncIfStale()` を呼出（`DesignController` と同パターン）。`catch (\Throwable)` では必ず `logger->warning` を残す（Issue #16）。
- **バリデーション厳格化（Issue #16 対応）**: `providers` 存在のみでなく `ALLOWED_PROVIDERS/ALLOWED_COST_TIERS/MAX_MODELS_PER_PROVIDER(20)/MAX_STRING_LENGTH(2000)/MAX_ID_LENGTH(128)/重複id` を検証。`Content-Type` は `stripos($ct,'application/json')===false` で `charset` 付きを許容。`REMOTE_URL` は `$_ENV['AI_MODELS_SYNC_URL']` で上書き可能にする。

## BDDタスク一覧

### 0. 基底抽出 — AbstractPluginDataSyncService 新設（Issue #14）
- 入力: 既存 `DesignSettingsSyncService` 単体で `trySyncIfStale()` が `200/304/失敗` で正しく振る舞う
- 振る舞い: `isStale/fetchRemote/atomicWrite/loadMeta/saveMeta/updateMetaLastSyncedAt/acquireLock/releaseLock/getSyncMeta/getDataPath/getMetaPath/getLockPath` を `AbstractPluginDataSyncService` に抽出。`DesignSettingsSyncService` は `validate()`（license_* 検証）と `persist()`（license_* マージ）のみに縮退し振る舞い不変を維持。`AiModelSyncService` は基底を継承し 80行程度で完結
- 受け入れ: `php -l Service/AbstractPluginDataSyncService.php && php -l Service/DesignSettingsSyncService.php` OK / `vendor/bin/phpunit --testsuite unit` 既存 156件 緑維持

### 1. AiModelSyncService 新設 — 正常系
- 入力: `PluginData/ai_models.json` が存在しない or `last_synced_at` が 86400秒超過、かつ `GET https://routeflags.com/dist/ec_chat/ai_models.json` が `200 + Content-Type: application/json(; charset=utf-8 可) + {version:"2.0.0", providers:{...}}` を返す
- 振る舞い: `validate()` で `ALLOWED_PROVIDERS/ALLOWED_COST_TIERS/重複id/文字数上限` を含む厳格検証を通過後、`copy($path,$path.'.bak')` でバックアップ → `atomicWrite(tmp+rename+LOCK_EX)` で保存 → `meta.json(.ai_models.meta.json)` に `last_synced_at/etag/last_modified` を記録し `true` / `logger->info('AI model synced from remote')` を返す。`getRemoteUrl()` は `$_ENV['AI_MODELS_SYNC_URL'] ?? self::REMOTE_URL` で上書き可能
- データ例: 現行 `ai_models.json v2.0.0`（openai 5 / anthropic 2 / gemini 3 = 10モデル）。`MAX_PAYLOAD 64KB` 以内

### 2. 同 — 異常系・304系
- 入力: `304 Not Modified` / `status !=200` / `Content-Type` に `application/json` を含まない / `JSON不正` / `providersキー欠落` / `models[].id欠落` / `cost_tier不正` / `重複id` / `description超過` / `ペイロード >64KB`
- 振る舞い: いずれも `logger->warning('AI model sync failed, keeping local', ['error'=>...])` を残しローカルを保持、`false` を返す。`304` 時のみ `last_synced_at` を現在時刻に更新し `info('AI model sync: 304 Not Modified')` を出す
- エッジ: `version` 不正は許容（既存 Registry は `version` を必須としない）が `providers` 不正は拒否

### 3. 同 — 排他・エッジケース
- 入力: TTL未到達 / `flock(LOCK_EX|LOCK_NB)` 取得失敗（二重起動） / ロック取得後の二重チェックで TTL解消 / `projectDir === ''`
- 振る舞い: 同期スキップ（`false`）。`projectDir` 未設定時は `LogicException('AiModelSyncService: projectDir is not configured.')`
- 検証: `getSyncMeta(): array{last_synced_at:?int, etag:?string, last_modified:?string}` で管理画面表示用メタを返す

### 4. AiModelRegistry フォールバック改修（Issue #15）
- 入力: `AiModelRegistry` が `'%kernel.project_dir%/app/PluginData/AiChatAssistant42/ai_models.json'` を第一引数に受け取るが、当該ファイルが存在しない
- 振る舞い: `__construct(string $configPath, string $projectDir = '', ?LoggerInterface $logger = null)` に拡張し、`resolveConfigPath()` で `[ $configPath(PluginData), $projectDir/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json, dirname(__DIR__,2)/Resource/config/ai_models.json ]` を順に探索。`DashboardController::resolveAiModelsPath()` は Registry 委譲が優先のためフォールバック経路のみ PluginData 追加に留める。`OpenAiAgent::resolveCapabilityFromJson()` は探索リスト先頭に PluginData を追加するが、将来的に Registry 必須化しファイル探索自体を削除する方針をコメントに明記
- エッジ: 両方欠落時は従来通り `RuntimeException('AI model config not found: ...')`。`getAll()/getVersion()` は同期後の内容を即時反映
- DI: `services.yaml` で `AiModelRegistry` に `$projectDir: '%kernel.project_dir%'` を追加注入、第一引数を `PluginData/ai_models.json` に切替

### 5. 管理画面連携・DI設定（Issue #15, #16）
- 入力: 管理者が `GET /admin/ai-chat-assistant/dashboard` にアクセス
- 振る舞い: `DashboardController` に `?AiModelSyncService $syncService` を autowire 追加、`index()` 先頭で `try { $this->syncService?->trySyncIfStale(); } catch (\Throwable $e) { $this->logger?->warning('AI model sync failed, keeping local', ['error'=>$e->getMessage()]); }` ※必ず warning を残す。`services.yaml` に `AiModelSyncService` 定義（`$httpClient '@GuzzleHttp\ClientInterface' $logger '@logger' $projectDir '%kernel.project_dir%'`）と `DashboardController` の明示的 `$syncService: '@Plugin\AiChatAssistant42\Service\AiModelSyncService'` 注入を追記
- 補足: `REMOTE_URL` は `getRemoteUrl()` で `$_ENV['AI_MODELS_SYNC_URL'] ?? self::REMOTE_URL` を返す

### 6a. テスト — AiModelSyncService 単体（Unit, Issue #17）
- 入力: `createMock(ClientInterface::class)` + `GuzzleHttp\Psr7\Response` 実体（`200+ETag/Last-Modified/Content-Type: application/json; charset=utf-8`）を用い、`sys_get_temp_dir()/.ai_model_sync_test_uniqid/` を `projectDir` にして隔離
- 振る舞い: `200正常→true+PluginData生成+meta更新` / `304→false+last_synced_at更新` / `500→false+warning` / `Content-Type不正→false` / `JSON不正→false` / `providers欠落→false` / `cost_tier不正→false` / `重複id→false` / `payload>64KB→false` / `TTL未到達→false` / `flock失敗→false`（事前に `fopen+flock(LOCK_EX)`） / `projectDir==''→LogicException` を `PHPUnit\Framework\TestCase` で検証。`tearDown()` で `rm -rf`。

### 6b. テスト — AiModelRegistry フォールバック（Unit, Issue #17）
- 入力: 一時ディレクトリに `PluginData/ai_models.json`（11モデル）と `Resource/config/ai_models.json`（10モデル）を用意
- 振る舞い: `new AiModelRegistry($pluginDataPath, $tmpProjectDir)` で前者が優先、削除後は後者にフォールバック、両方無ければ `RuntimeException`。既存 `AiModelRegistryTest::setUp()` の候補探索を明示的パス注入に書き換えまたはパラメタライズド追加

### 6c. テスト — DashboardController 連携 + DI検証（Unit/Integration, Issue #17）
- 入力: `DashboardControllerTest` に `AiModelSyncService` モックを注入し `index()` 呼出
- 振る舞い: `trySyncIfStale()` が1回呼ばれること、例外時は画面が 200 で返ること（握りつぶしが正しく働くこと）を `expects($this->once())->method('trySyncIfStale')` で検証。DI は `php bin/console debug:container Plugin\\AiChatAssistant42\\Service\\AiModelSyncService --show-arguments` と `php bin/console lint:container` で検証（`grep -n "ai_models"` ではなく debug:container を使う）
- 受け入れ: `php -l` 全対象 OK / `vendor/bin/phpunit --testsuite unit` 156件 + 新規分 OK / 同期前は `ai_models 10/10` 維持、同期後はモックしたリモートのモデル数に一致することを確認（10固定の期待を削除）

## 実装制約

- `src/Eccube` は編集しない。変更は `app/Plugin/AiChatAssistant42` 配下のみ
- `services.yaml` の既存公開インターフェース（APIパス、JSONキー）を破壊しない
- `DesignSettingsSyncService` の `MAX_PAYLOAD 64KB / MAX_STRING_LENGTH 2000 / flock / atomicWrite` パターンを踏襲
- リモートURLは `https://routeflags.com/dist/ec_chat/ai_models.json` を想定（仮URLで実装し、後で差替可能にする）

## 検証計画

- `php bin/console debug:container Plugin\\AiChatAssistant42\\Service\\AiModelSyncService --show-arguments` と `php bin/console lint:container` で DI 検証（Issue #17）
- `php -l Service/AbstractPluginDataSyncService.php && php -l Service/AiModelSyncService.php && php -l Service/AiModelRegistry.php && php -l Controller/Admin/DashboardController.php`
- `vendor/bin/phpunit --testsuite unit`（156件 + 新規 6a/6b/6c）
- 管理画面 `DashboardController::index()` で `getSyncMeta()` の `last_synced_at` が更新されることを `DashboardControllerTest` で自動検証（目視は補助）
- `bin/verify-10models.sh` は同期**前**に 10/10 を確認、同期**後**はモックしたリモートのモデル数に一致することを確認（10固定の期待を削除、Issue #17）

## 影響範囲

- `Service/AbstractPluginDataSyncService.php` 新設（Issue #14: 共通インフラ抽出）
- `Service/DesignSettingsSyncService.php` 改修（基底継承化、振る舞い不変）
- `Service/AiModelSyncService.php` 新設（基底継承、validate/persist のみ、~80行）
- `Service/AiModelRegistry.php` 改修（`$projectDir/$logger` 追加、`resolveConfigPath()` 集約、フォールバック）
- `Controller/Admin/DashboardController.php` 改修（`AiModelSyncService` 注入、trySyncIfStale 呼出 with warning、loadAiModels の PluginData 対応）
- `Service/AiAgent/OpenAiAgent.php` 改修（フォールバック探索に PluginData 追加、Registry必須化への deprecation コメント）
- `Resource/config/services.yaml` 改修（Abstract は定義不要、AiModelSyncService 定義、AiModelRegistry 引数変更、DashboardController 明示注入）
- `Tests/Unit/Service/AiModelSyncServiceTest.php` 新設（6a）、`AiModelRegistryTest` 追記（6b）、`DashboardControllerTest` 追記（6c）

## 根拠ツール

- `serena_find_symbol: DesignSettingsSyncService` で同期パターンを確認
- `codebase-memory-mcp: search_graph name_pattern AiModelRegistry` で参照箇所を特定
- `read: Service/AiModelRegistry.php, Resource/config/ai_models.json, services.yaml, DashboardController.php` で現行パス解決を確認
