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

/**
 * design_settings.json のリモート同期を担うサービス。
 *
 * - 取得元: https://routeflags.com/dist/ec_chat/design_settings.json (REMOTE_URL)
 * - 保存先: app/PluginData/AiChatAssistant42/design_settings.json (永続領域)
 * - 同期間隔: TTL 86400秒 (1日1回)、管理画面アクセス時にのみ trySyncIfStale() が呼ばれる
 * - 対象キー: license_* のみをリモート正本としてマージ、widget_* はローカル保持
 * - 排他: flock(LOCK_EX|LOCK_NB) で多重起動を防止
 * - 原子書き込み: tmp + rename + LOCK_EX、ETag/Last-Modified は meta.json に保存
 *
 * 共通インフラは AbstractPluginDataSyncService に集約し、
 * 本クラスは validate()/persist() の差分のみを担う。
 */
class DesignSettingsSyncService extends AbstractPluginDataSyncService
{
    public const REMOTE_URL = 'https://routeflags.com/dist/ec_chat/design_settings.json';
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
        'license_lead' => 'AiChatAssistant42（チャットソフトウェア）の著作権は ROUTE FLAGS Co., Ltd. に帰属し、GNU General Public License v2 (GPL-2.0-only) に基づき提供されています。',
        'license_item1_heading' => '著作権',
        'license_item1_body' => '© 2024-2026 ROUTE FLAGS Co., Ltd. All Rights Reserved.',
        'license_item2_heading' => 'ライセンス (GPL-2.0-only)',
        'license_item2_body' => '本ソフトウェアのソースコードは GPL-2.0-only で提供されています。複製・改変・再配布する際は GPL-2.0 の条件（著作権表示とライセンス条文の保持、改変時の変更明示、ソースコードの提供等）を遵守してください。',
        'license_item3_heading' => 'オープンソースソフトウェアの利用',
        'license_item3_body' => '本ソフトウェアは以下のOSSを利用しています: EC-CUBE 4.2 (GPL-2.0-only)、Symfony 5.4 (MIT)、Doctrine ORM/DBAL (MIT)、Twig 2.x (BSD-3-Clause)、GuzzleHTTP (MIT)、Monolog (MIT)、KnpPaginatorBundle (MIT) ほか composer.json 記載のライブラリ。各OSSのライセンス詳細は各プロジェクトの配布物をご参照ください。',
    ];

    protected function getRemoteUrl(): string
    {
        return self::REMOTE_URL;
    }

    protected function getDataPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::PLUGIN_DATA_PATH;
    }

    protected function getMetaPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::META_PATH;
    }

    protected function getLockPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('DesignSettingsSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::LOCK_PATH;
    }

    protected function getSyncFailureLogMessage(): string
    {
        return 'Design settings sync failed, keeping local';
    }

    protected function getNotModifiedLogMessage(): string
    {
        return 'Design settings sync: 304 Not Modified';
    }

    /**
     * リモートデータをバリデーションし、未知キー除去・空文字補完を行う。
     *
     * @return array<string,string>|null 不正なら null
     */
    protected function validate(array $remoteData): ?array
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
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid type for {$key}"]);

                return null;
            }
            // 空文字は DEFAULTS で補完
            if ($value === '') {
                $value = self::DEFAULTS[$key];
            }
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Too long: {$key} (" . mb_strlen($value) . ')']);

                return null;
            }
            $result[$key] = $value;
        }

        if (empty($result)) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'No valid license keys in remote']);

            return null;
        }

        return $result;
    }

    /**
     * 既存 PluginData にリモートの license_* のみをマージして原子書き込みする。
     *
     * @param array<string,string> $validated
     */
    protected function persist(array $validated): void
    {
        $dataPath = $this->getDataPath();
        $existing = $this->loadExistingData();

        // license_* のみをリモートで上書き、widget_* はローカル保持
        $merged = array_merge($existing, array_intersect_key($validated, array_flip(self::REMOTE_MANAGED_KEYS)));

        // 旧文言ドリフトのマイグレーション: 本サイト文言が残っていれば DEFAULTS で上書き
        $merged = $this->migrateDriftedPhrases($merged);

        // DEFAULTS で不足キーを補完
        $merged = array_merge(self::DEFAULTS, $merged);

        // 未知キーを除去 (DEFAULTS に無いキーは保存しない)
        $merged = array_intersect_key($merged, array_flip(array_keys(self::DEFAULTS)));

        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'json_encode failed: ' . json_last_error_msg()]);

            return;
        }

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Merged payload too large']);

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
            'keys' => array_keys($validated),
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

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized,
        ];
    }
}
