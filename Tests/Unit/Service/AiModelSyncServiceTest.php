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

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Service\AiModelSyncService;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

/**
 * AiModelSyncService の単体テスト。
 *
 * TASK 6a: sys_get_temp_dir() に隔離した projectDir と Guzzle mock で
 * 同期の正常・異常・TTL・エッジケースを検証する。
 */
class AiModelSyncServiceTest extends TestCase
{
    private string $tmpDir = '';

    private string $originalEnvUrl = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/.ai_model_sync_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
        // 環境変数の退避
        $this->originalEnvUrl = $_ENV['AI_MODELS_SYNC_URL'] ?? '';
        if (isset($_ENV['AI_MODELS_SYNC_URL'])) {
            unset($_ENV['AI_MODELS_SYNC_URL']);
        }
    }

    protected function tearDown(): void
    {
        // 環境変数の復元
        if ($this->originalEnvUrl !== '') {
            $_ENV['AI_MODELS_SYNC_URL'] = $this->originalEnvUrl;
        } else {
            unset($_ENV['AI_MODELS_SYNC_URL']);
        }

        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function validPayload(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/Resource/config/ai_models.json');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function createService(ClientInterface $httpClient, ?TestLogger $logger = null, ?string $projectDir = null): AiModelSyncService
    {
        $logger = $logger ?? new TestLogger();
        $dir = $projectDir ?? $this->tmpDir;

        return new AiModelSyncService($httpClient, $logger, $dir);
    }

    private function dataPath(): string
    {
        return $this->tmpDir . AiModelSyncService::PLUGIN_DATA_PATH;
    }

    private function metaPath(): string
    {
        return $this->tmpDir . AiModelSyncService::META_PATH;
    }

    // ================================================================
    //  200正常→true+PluginData生成+meta更新
    // ================================================================

    public function testSyncSucceedsOn200AndCreatesPluginDataAndMeta(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => '"abc123"',
            'Last-Modified' => 'Wed, 01 Jan 2025 00:00:00 GMT',
        ], $body);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertTrue($result, '200 should return true');
        $this->assertFileExists($this->dataPath(), 'PluginData should be created');
        $this->assertFileExists($this->metaPath(), 'meta should be created');

        $saved = json_decode(file_get_contents($this->dataPath()), true);
        $this->assertIsArray($saved);
        $this->assertArrayHasKey('providers', $saved);

        $meta = json_decode(file_get_contents($this->metaPath()), true);
        $this->assertArrayHasKey('last_synced_at', $meta);
        $this->assertEquals('"abc123"', $meta['etag']);
        $this->assertEquals('Wed, 01 Jan 2025 00:00:00 GMT', $meta['last_modified']);

        $this->assertTrue($logger->hasInfoThatContains('AI model synced from remote'));
    }

    // ================================================================
    //  version不正は許容（MJ01）
    // ================================================================

    public function testSyncSucceedsWhenVersionIsNotString(): void
    {
        $payload = $this->validPayload();
        $payload['version'] = 12345;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertTrue($result, 'version int should be tolerated and still sync');
        $this->assertFileExists($this->dataPath());
        $saved = json_decode(file_get_contents($this->dataPath()), true);
        // version が除去されているか、または文字列化されていても providers は保持される
        $this->assertArrayHasKey('providers', $saved);
    }

    // ================================================================
    //  304→false+last_synced_at更新
    // ================================================================

    public function testSyncReturnsFalseOn304AndUpdatesLastSyncedAt(): void
    {
        // 事前に meta を古い時刻で作成し、ETag を持たせる
        $oldTime = time() - 90000;
        $dir = dirname($this->metaPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->metaPath(), json_encode([
            'last_synced_at' => $oldTime,
            'etag' => '"old-etag"',
            'last_modified' => 'Wed, 01 Jan 2025 00:00:00 GMT',
        ], JSON_UNESCAPED_UNICODE));

        // stale にするため data ファイルも古くする（必要に応じて）
        $dataDir = dirname($this->dataPath());
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
        }
        file_put_contents($this->dataPath(), json_encode($this->validPayload(), JSON_UNESCAPED_UNICODE));
        touch($this->dataPath(), $oldTime);

        $response = new Response(304, [], '');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result, '304 should return false');

        $meta = json_decode(file_get_contents($this->metaPath()), true);
        $this->assertGreaterThan($oldTime, $meta['last_synced_at'], 'last_synced_at should be updated on 304');
        $this->assertTrue($logger->hasInfoThatContains('304 Not Modified'));
    }

    // ================================================================
    //  500→false+warning
    // ================================================================

    public function testSyncReturnsFalseOn500AndLogsWarning(): void
    {
        $response = new Response(500, ['Content-Type' => 'application/json'], 'error');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result);
        $this->assertTrue($logger->hasWarningThatContains('AI model sync failed'));
    }

    // ================================================================
    //  Content-Type不正→false
    // ================================================================

    public function testSyncReturnsFalseOnInvalidContentType(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result);
        $this->assertTrue($logger->hasWarningThatContains('AI model sync failed'));
        $this->assertFalse(is_file($this->dataPath()) && filesize($this->dataPath()) > 0 && json_decode(file_get_contents($this->dataPath()), true) !== null && isset(json_decode(file_get_contents($this->dataPath()), true)['providers']));
    }

    // ================================================================
    //  JSON不正→false
    // ================================================================

    public function testSyncReturnsFalseOnInvalidJson(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '{ invalid json ');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result);
        $this->assertTrue($logger->hasWarningThatContains('AI model sync failed'));
    }

    // ================================================================
    //  providers欠落→false
    // ================================================================

    public function testSyncReturnsFalseWhenProvidersMissing(): void
    {
        $body = json_encode(['version' => '2.0.0'], JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result);
        $this->assertTrue($logger->hasWarningThatContains('AI model sync failed'));
    }

    // ================================================================
    //  cost_tier不正→false
    // ================================================================

    public function testSyncReturnsFalseOnInvalidCostTier(): void
    {
        $payload = $this->validPayload();
        $payload['providers']['openai']['models'][0]['cost_tier'] = 'ultra';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result);
        $this->assertTrue($logger->hasWarningThatContains('AI model sync failed'));
    }

    // ================================================================
    //  重複id→false
    // ================================================================

    public function testSyncReturnsFalseOnDuplicateId(): void
    {
        $payload = $this->validPayload();
        // openai の先頭2モデルを同一 id にする
        $payload['providers']['openai']['models'][1]['id'] = $payload['providers']['openai']['models'][0]['id'];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result);
        $this->assertTrue($logger->hasWarningThatContains('AI model sync failed'));
    }

    // ================================================================
    //  projectDir==''→LogicException
    // ================================================================

    public function testProjectDirEmptyThrowsLogicException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger, '');

        $this->expectException(\LogicException::class);
        $service->trySyncIfStale();
    }

    // ================================================================
    //  AI_MODELS_SYNC_URL=http の際は https フォールバック（CR01）
    // ================================================================

    public function testHttpEnvUrlFallsBackToHttpsDefault(): void
    {
        $_ENV['AI_MODELS_SYNC_URL'] = 'http://evil.example.com/ai_models.json';

        $payload = $this->validPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $capturedUrl = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($response, &$capturedUrl) {
                $capturedUrl = $url;

                return $response;
            });

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertTrue($result, 'http fallback should still sync with default https URL');
        $this->assertEquals(AiModelSyncService::REMOTE_URL, $capturedUrl, 'http env should fallback to https default URL');
        $this->assertTrue($logger->hasWarningThatContains('AI_MODELS_SYNC_URL must be https'));
    }

    public function testHttpsEnvUrlIsUsed(): void
    {
        $customUrl = 'https://custom.example.com/ai_models.json';
        $_ENV['AI_MODELS_SYNC_URL'] = $customUrl;

        $payload = $this->validPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $capturedUrl = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($response, &$capturedUrl) {
                $capturedUrl = $url;

                return $response;
            });

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertTrue($result);
        $this->assertEquals($customUrl, $capturedUrl);
    }

    // ================================================================
    //  TTL未到達→false（リクエストなし）
    // ================================================================

    public function testSyncSkippedWhenNotStale(): void
    {
        // 直前に同期済みの meta を作成
        $dir = dirname($this->metaPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->metaPath(), json_encode(['last_synced_at' => time()], JSON_UNESCAPED_UNICODE));
        $dataDir = dirname($this->dataPath());
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
        }
        file_put_contents($this->dataPath(), json_encode($this->validPayload(), JSON_UNESCAPED_UNICODE));

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $logger = new TestLogger();
        $service = $this->createService($httpClient, $logger);

        $result = $service->trySyncIfStale();

        $this->assertFalse($result, 'TTL not expired should skip sync');
    }
}
