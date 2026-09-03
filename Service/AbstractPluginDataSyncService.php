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
use LogicException;

/**
 * プラグインデータ同期の共通基盤。
 *
 * - TTL/ETag/Last-Modified による条件付き GET
 * - flock(LOCK_EX|LOCK_NB) による排他
 * - tmp + rename + LOCK_EX による原子書き込み
 * - meta.json による last_synced_at/etag 管理
 *
 * 差分は validate()/persist() のみに閉じる。
 */
abstract class AbstractPluginDataSyncService
{
    public const TTL = 86400;

    protected const MAX_PAYLOAD_BYTES = 64 * 1024;

    protected const MAX_STRING_LENGTH = 2000;

    /** @var array<string,string> */
    protected array $pendingRemoteMeta = [];

    public function __construct(
        protected ClientInterface $httpClient,
        protected LoggerInterface $logger,
        protected string $projectDir,
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
            throw new LogicException(sprintf('%s: projectDir is not configured.', static::class));
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

            $this->persist($validated);

            return true;
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * TTL 超過かを判定する。
     */
    protected function isStale(): bool
    {
        $meta = $this->loadMeta();
        if (isset($meta['last_synced_at'])) {
            $lastSyncedAt = (int) $meta['last_synced_at'];
            if (time() - $lastSyncedAt < static::TTL) {
                return false;
            }

            return true;
        }

        $dataPath = $this->getDataPath();
        if (!file_exists($dataPath)) {
            return true;
        }

        $mtime = (int) filemtime($dataPath);

        return (time() - $mtime) >= static::TTL;
    }

    /**
     * リモート JSON を取得する。304/エラー時は null を返し warning ログを出す。
     *
     * @return array<string,mixed>|null 200時のみ配列、304/失敗時は null
     */
    protected function fetchRemote(): ?array
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
            $response = $this->httpClient->request('GET', $this->getRemoteUrl(), [
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
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => $e->getMessage()]);

            return null;
        }

        $status = $response->getStatusCode();
        if ($status === 304) {
            $this->updateMetaLastSyncedAt();
            $this->logger->info($this->getNotModifiedLogMessage(), ['etag' => $meta['etag'] ?? null]);

            return null;
        }

        if ($status !== 200) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'HTTP status ' . $status]);

            return null;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && stripos($contentType, 'application/json') === false) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Invalid Content-Type: ' . $contentType]);

            return null;
        }

        $body = (string) $response->getBody();
        if (strlen($body) > static::MAX_PAYLOAD_BYTES) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Payload too large: ' . strlen($body)]);

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Invalid JSON: ' . json_last_error_msg()]);

            return null;
        }

        // ETag / Last-Modified を一時保存 (persist 成功後に確定保存)
        $this->pendingRemoteMeta = [
            'etag' => $response->getHeaderLine('ETag'),
            'last_modified' => $response->getHeaderLine('Last-Modified'),
        ];

        return $decoded;
    }

    /**
     * ファイルを原子的に書き込む (tmp + rename + LOCK_EX)。
     */
    protected function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // random_bytes(8) + pid で衝突耐性を高める（MN02）
        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(8)) . '.' . getmypid();
        file_put_contents($tmpPath, $content, LOCK_EX);
        rename($tmpPath, $path);
    }

    /**
     * @return array<string,mixed>
     */
    protected function loadMeta(): array
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
    protected function saveMeta(array $meta): void
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

    protected function updateMetaLastSyncedAt(): void
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
    protected function acquireLock()
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
    protected function releaseLock($handle): void
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

    abstract protected function getRemoteUrl(): string;

    abstract protected function getDataPath(): string;

    abstract protected function getMetaPath(): string;

    abstract protected function getLockPath(): string;

    /**
     * リモートデータを検証する。
     *
     * @param array<string,mixed> $remoteData
     * @return array<string,mixed>|null 検証失敗で null + warning
     */
    abstract protected function validate(array $remoteData): ?array;

    /**
     * 検証済みデータを永続化する。
     *
     * @param array<string,mixed> $validated
     */
    abstract protected function persist(array $validated): void;

    abstract protected function getSyncFailureLogMessage(): string;

    abstract protected function getNotModifiedLogMessage(): string;
}
