<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * design_settings.json のリモート同期を担うサービス。
 *
 * - 取得元: https://routeflags.com/dist/ec_chat/design_settings.json (REMOTE_URL)
 * - 保存先: app/PluginData/AiChatAssistant42/design_settings.json (永続領域)
 * - 同期間隔: TTL 86400秒 (1日1回)、管理画面アクセス時にのみ trySyncIfStale() が呼ばれる
 * - 対象キー: license_* のみをリモート正本としてマージ、widget_* はローカル保持
 * - 排他: flock(LOCK_EX|LOCK_NB) で多重起動を防止
 * - 原子書き込み: tmp + rename + LOCK_EX、ETag/Last-Modified は meta.json に保存
 */
class DesignSettingsSyncService
{
    public const REMOTE_URL = 'https://routeflags.com/dist/ec_chat/design_settings.json';
    public const TTL = 86400;
    public const PLUGIN_DATA_PATH = '/app/PluginData/AiChatAssistant42/design_settings.json';
    public const META_PATH = '/app/PluginData/AiChatAssistant42/.design_settings.meta.json';
    public const LOCK_PATH = '/app/PluginData/AiChatAssistant42/.design_settings.sync.lock';

    /** リモートが正本となるキー (license_*) */
    public const REMOTE_MANAGED_KEYS = [
        'license_footer_label',
        'license_title',
        'license_lead',
        'license_item1_heading',
        'license_item1_body',
        'license_item2_heading',
        'license_item2_body',
        'license_item3_heading',
        'license_item3_body',
    ];

    /** 全デフォルト値 (初期配布値と同値) */
    public const DEFAULTS = [
        'widget_color' => '#2ec9bb',
        'widget_size' => 'medium',
        'position' => 'bottom-right',
        'greeting_message' => 'こんにちは！商品についてお気軽にご質問ください。',
        'assistant_display_name' => '商品アドバイザー',
        'license_footer_label' => 'ライセンスについて',
        'license_title' => 'ソフトウェアライセンスについて',
        'license_lead' => 'AiChatAssistant42（チャットソフトウェア）の著作権は <a href="https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/" target="_blank" rel="noopener">ROUTE FLAGS Co., Ltd.</a> に帰属し、GNU General Public License v2 (GPL-2.0-only) に基づき提供されています。',
        'license_item1_heading' => '著作権',
        'license_item1_body' => '© 2024-2026 ROUTE FLAGS Co., Ltd. All Rights Reserved.',
        'license_item2_heading' => 'ライセンス (GPL-2.0-only)',
        'license_item2_body' => '本ソフトウェアのソースコードは GPL-2.0-only で提供されています。複製・改変・再配布する際は GPL-2.0 の条件（著作権表示とライセンス条文の保持、改変時の変更明示、ソースコードの提供等）を遵守してください。詳細は同梱の COPYING ファイルまたは <a href="https://www.gnu.org/licenses/gpl-2.0.html" target="_blank" rel="noopener">https://www.gnu.org/licenses/gpl-2.0.html</a> をご覧ください。',
        'license_item3_heading' => 'オープンソースソフトウェアの利用',
        'license_item3_body' => '本ソフトウェアは以下のOSSを利用しています: EC-CUBE 4.2 (GPL-2.0-only)、Symfony 5.4 (MIT)、Doctrine ORM/DBAL (MIT)、Twig 2.x (BSD-3-Clause)、GuzzleHTTP (MIT)、Monolog (MIT)、KnpPaginatorBundle (MIT) ほか composer.json 記載のライブラリ。各OSSのライセンス詳細は各プロジェクトの配布物をご参照ください。',
    ];

    /** 1キーあたりの最大文字数 */
    private const MAX_STRING_LENGTH = 2000;
    /** レスポンス全体の最大バイト数 */
    private const MAX_PAYLOAD_BYTES = 64 * 1024;

    public function __construct(
        private ClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    /**
     * TTL 判定し、必要ならリモート同期を試みる。
     *
     * @return bool 同期が実行され成功した場合 true、TTL未到達または失敗時は false
     */
    public function trySyncIfStale(): bool
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }

        if (!$this->isStale()) {
            return false;
        }

        $lockHandle = $this->acquireLock();
        if ($lockHandle === null) {
            return false;
        }

        try {
            // 二重チェック: ロック取得後に再判定
            if (!$this->isStale()) {
                return false;
            }

            $remoteData = $this->fetchRemote();
            if ($remoteData === null) {
                return false;
            }

            $validated = $this->validate($remoteData);
            if ($validated === null) {
                return false;
            }

            $this->mergeAndSave($validated);

            return true;
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * TTL 超過かを判定する。
     */
    private function isStale(): bool
    {
        $meta = $this->loadMeta();
        if (isset($meta['last_synced_at'])) {
            $lastSyncedAt = (int) $meta['last_synced_at'];
            if (time() - $lastSyncedAt < self::TTL) {
                return false;
            }
            return true;
        }

        $dataPath = $this->getDataPath();
        if (!file_exists($dataPath)) {
            return true;
        }

        $mtime = (int) filemtime($dataPath);
        return (time() - $mtime) >= self::TTL;
    }

    /**
     * リモート JSON を取得する。304/エラー時は null を返し warning ログを出す。
     *
     * @return array<string,mixed>|null 200時のみ配列、304/失敗時は null
     */
    private function fetchRemote(): ?array
    {
        $meta = $this->loadMeta();
        $headers = [
            'User-Agent' => 'AiChatAssistant42/1.0 (+https://github.com/routeflags/ec-cube-ai-chat-assistant42)',
            'Accept' => 'application/json',
        ];
        if (!empty($meta['etag'])) {
            $headers['If-None-Match'] = $meta['etag'];
        }
        if (!empty($meta['last_modified'])) {
            $headers['If-Modified-Since'] = $meta['last_modified'];
        }

        try {
            $response = $this->httpClient->request('GET', self::REMOTE_URL, [
                'headers' => $headers,
                'timeout' => 5.0,
                'connect_timeout' => 2.0,
                'verify' => true,
                'allow_redirects' => [
                    'max' => 3,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['https'],
                ],
                'http_errors' => false,
            ]);
        } catch (TransferException $e) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => $e->getMessage()]);
            return null;
        }

        $status = $response->getStatusCode();
        if ($status === 304) {
            $this->updateMetaLastSyncedAt();
            $this->logger->info('Design settings sync: 304 Not Modified', ['etag' => $meta['etag'] ?? null]);
            return null;
        }

        if ($status !== 200) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'HTTP status ' . $status]);
            return null;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'application/json') === false) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'Invalid Content-Type: ' . $contentType]);
            return null;
        }

        $body = (string) $response->getBody();
        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'Payload too large: ' . strlen($body)]);
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            return null;
        }

        // ETag / Last-Modified を一時保存 (merge成功後に確定保存)
        $this->pendingRemoteMeta = [
            'etag' => $response->getHeaderLine('ETag'),
            'last_modified' => $response->getHeaderLine('Last-Modified'),
        ];

        return $decoded;
    }

    /** @var array<string,string> */
    private array $pendingRemoteMeta = [];

    /**
     * リモートデータをバリデーションし、未知キー除去・空文字補完を行う。
     *
     * @return array<string,string>|null 不正なら null
     */
    private function validate(array $remoteData): ?array
    {
        // 未知キーを除去し、許可キーのみ残す (DEFAULTS に存在するキーのみ)
        $allowedKeys = array_flip(array_keys(self::DEFAULTS));
        $filtered = array_intersect_key($remoteData, $allowedKeys);

        // REMOTE_MANAGED_KEYS のみを対象に検証
        $result = [];
        foreach (self::REMOTE_MANAGED_KEYS as $key) {
            if (!array_key_exists($key, $filtered)) {
                continue;
            }
            $value = $filtered[$key];
            if (!is_string($value)) {
                $this->logger->warning('Design settings sync failed, keeping local', ['error' => "Invalid type for {$key}"]);
                return null;
            }
            // 空文字は DEFAULTS で補完 (保存時ではなく表示時もだが、ここではスキップ)
            if ($value === '') {
                $value = self::DEFAULTS[$key];
            }
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                $this->logger->warning('Design settings sync failed, keeping local', ['error' => "Too long: {$key} (" . mb_strlen($value) . ')']);
                return null;
            }
            $result[$key] = $value;
        }

        if (empty($result)) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'No valid license keys in remote']);
            return null;
        }

        // 文面ドリフト検出: 旧文言が混入していないかはローカル側のマイグレーションで対応するが、
        // リモートにも旧文言があれば警告 (ただし採用はする)
        return $result;
    }

    /**
     * 既存 PluginData にリモートの license_* のみをマージして原子書き込みする。
     */
    private function mergeAndSave(array $validatedRemote): void
    {
        $dataPath = $this->getDataPath();
        $existing = $this->loadExistingData();

        // license_* のみをリモートで上書き、widget_* はローカル保持
        $merged = array_merge($existing, array_intersect_key($validatedRemote, array_flip(self::REMOTE_MANAGED_KEYS)));

        // 旧文言ドリフトのマイグレーション: 本サイト文言が残っていれば DEFAULTS で上書き
        $merged = $this->migrateDriftedPhrases($merged);

        // DEFAULTS で不足キーを補完
        $merged = array_merge(self::DEFAULTS, $merged);

        // 未知キーを除去 (DEFAULTS に無いキーは保存しない)
        $merged = array_intersect_key($merged, array_flip(array_keys(self::DEFAULTS)));

        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'json_encode failed: ' . json_last_error_msg()]);
            return;
        }

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning('Design settings sync failed, keeping local', ['error' => 'Merged payload too large']);
            return;
        }

        $this->atomicWrite($dataPath, $json);

        // meta 更新: 成功時のみ last_synced_at を現在時刻に
        $meta = $this->loadMeta();
        $meta['last_synced_at'] = time();
        if (!empty($this->pendingRemoteMeta['etag'])) {
            $meta['etag'] = $this->pendingRemoteMeta['etag'];
        }
        if (!empty($this->pendingRemoteMeta['last_modified'])) {
            $meta['last_modified'] = $this->pendingRemoteMeta['last_modified'];
        }
        $this->saveMeta($meta);

        $this->logger->info('Design settings synced from remote', [
            'etag' => $meta['etag'] ?? null,
            'keys' => array_keys($validatedRemote),
        ]);
    }

    /**
     * 旧文言ドリフトを検出し DEFAULTS で上書きする。
     *
     * @param array<string,string> $data
     * @return array<string,string>
     */
    private function migrateDriftedPhrases(array $data): array
    {
        if (isset($data['license_lead']) && str_contains($data['license_lead'], '本サイトおよび')) {
            $data['license_lead'] = self::DEFAULTS['license_lead'];
        }
        if (isset($data['license_item1_body']) && str_contains($data['license_item1_body'], '本サイトのコンテンツ')) {
            $data['license_item1_body'] = self::DEFAULTS['license_item1_body'];
        }
        return $data;
    }

    /**
     * 既存 PluginData を読み込む。無ければ DEFAULTS を返す。
     *
     * @return array<string,string>
     */
    private function loadExistingData(): array
    {
        $dataPath = $this->getDataPath();
        if (!file_exists($dataPath)) {
            return self::DEFAULTS;
        }
        $raw = @file_get_contents($dataPath);
        if ($raw === false) {
            return self::DEFAULTS;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULTS;
        }
        // 未知キー除去 + DEFAULTS 補完
        $filtered = array_intersect_key($decoded, array_flip(array_keys(self::DEFAULTS)));
        return array_merge(self::DEFAULTS, $filtered);
    }

    /**
     * ファイルを原子的に書き込む (tmp + rename + LOCK_EX)。
     */
    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmpPath, $content, LOCK_EX);
        rename($tmpPath, $path);
    }

    private function getDataPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }
        return $this->projectDir . self::PLUGIN_DATA_PATH;
    }

    private function getMetaPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }
        return $this->projectDir . self::META_PATH;
    }

    private function getLockPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }
        return $this->projectDir . self::LOCK_PATH;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadMeta(): array
    {
        $metaPath = $this->getMetaPath();
        if (!file_exists($metaPath)) {
            return [];
        }
        $raw = @file_get_contents($metaPath);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function saveMeta(array $meta): void
    {
        $metaPath = $this->getMetaPath();
        $dir = dirname($metaPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $this->atomicWrite($metaPath, $json);
        }
    }

    private function updateMetaLastSyncedAt(): void
    {
        $meta = $this->loadMeta();
        $meta['last_synced_at'] = time();
        $this->saveMeta($meta);
    }

    /**
     * 排他ロックを取得する。
     *
     * @return resource|null
     */
    private function acquireLock()
    {
        $lockPath = $this->getLockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * 管理画面表示用のメタ情報を取得する。
     *
     * @return array{last_synced_at:?int, etag:?string, last_modified:?string}
     */
    public function getSyncMeta(): array
    {
        $meta = $this->loadMeta();
        return [
            'last_synced_at' => isset($meta['last_synced_at']) ? (int) $meta['last_synced_at'] : null,
            'etag' => $meta['etag'] ?? null,
            'last_modified' => $meta['last_modified'] ?? null,
        ];
    }

    /**
     * 保存時のバリデーション (フォーム入力用)。
     *
     * @param array<string,mixed> $input
     * @return array{valid:bool, errors:string[], sanitized:array<string,string>}
     */
    public static function validateInput(array $input): array
    {
        $errors = [];
        $sanitized = [];
        $allowed = array_keys(self::DEFAULTS);

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (!is_string($value)) {
                $errors[] = "{$key} は文字列で指定してください。";
                continue;
            }
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                $errors[] = "{$key} は " . self::MAX_STRING_LENGTH . " 文字以内で入力してください。";
                continue;
            }
            $sanitized[$key] = $value;
        }

        // 未知キーは除去済み (sanitized のみ返す)
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized,
        ];
    }
}
