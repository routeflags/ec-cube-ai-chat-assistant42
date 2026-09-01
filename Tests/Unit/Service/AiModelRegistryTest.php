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

use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use PHPUnit\Framework\TestCase;

/**
 * AiModelRegistry の単体テスト。
 *
 * ai_models.json の読み込み・プロバイダ参照・モデル検証を検証する。
 */
class AiModelRegistryTest extends TestCase
{
    private AiModelRegistry $registry;

    protected function setUp(): void
    {
        // EC-CUBE ルート (app/Plugin/...) とプラグイン単体リポジトリ (Resource/...) の両方に対応
        $candidates = [
            dirname(__DIR__, 6) . '/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json',
            dirname(__DIR__, 4) . '/Resource/config/ai_models.json',
            dirname(__DIR__, 3) . '/Resource/config/ai_models.json',
        ];
        $configPath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $configPath = $candidate;
                break;
            }
        }
        if ($configPath === null) {
            $configPath = $candidates[0];
        }
        $this->registry = new AiModelRegistry($configPath);
    }

    // ================================================================
    //  getProviders
    // ================================================================

    public function testGetProvidersReturnsAllRegisteredProviders(): void
    {
        $providers = $this->registry->getProviders();

        $this->assertIsArray($providers);
        $this->assertCount(3, $providers);

        $keys = array_column($providers, 'key');
        $this->assertContains('openai', $keys);
        $this->assertContains('anthropic', $keys);
        $this->assertContains('gemini', $keys);
    }

    public function testGetProvidersIncludesModelCount(): void
    {
        $providers = $this->registry->getProviders();

        foreach ($providers as $provider) {
            $this->assertArrayHasKey('model_count', $provider);
            $this->assertGreaterThanOrEqual(1, $provider['model_count']);
        }
    }

    public function testGetProvidersIncludesNameAndApiBase(): void
    {
        $providers = $this->registry->getProviders();

        foreach ($providers as $provider) {
            $this->assertArrayHasKey('name', $provider);
            $this->assertArrayHasKey('api_base', $provider);
            $this->assertNotEmpty($provider['name']);
        }
    }

    // ================================================================
    //  getModels
    // ================================================================

    public function testGetModelsReturnsModelsForOpenAi(): void
    {
        $models = $this->registry->getModels('openai');

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        // 5 models: gpt-5.6, terra, luna, gpt-4o, gpt-4o-mini — gpt-4o must exist but not necessarily first
        $ids = array_column($models, 'id');
        $this->assertContains('gpt-4o', $ids);
        $this->assertCount(5, $models);
    }

    public function testGetModelsReturnsModelsForAnthropic(): void
    {
        $models = $this->registry->getModels('anthropic');

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        $this->assertStringContainsString('claude', $models[0]['id']);
    }

    public function testGetModelsReturnsModelsForGemini(): void
    {
        $models = $this->registry->getModels('gemini');

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        $this->assertStringContainsString('gemini', $models[0]['id']);
    }

    public function testGetModelsReturnsEmptyArrayForUnknownProvider(): void
    {
        $models = $this->registry->getModels('nonexistent_provider');

        $this->assertIsArray($models);
        $this->assertEmpty($models);
    }

    public function testGetModelsReturnsRequiredFields(): void
    {
        $models = $this->registry->getModels('openai');

        foreach ($models as $model) {
            $this->assertArrayHasKey('id', $model);
            $this->assertArrayHasKey('name', $model);
            $this->assertArrayHasKey('description', $model);
            // max_tokens は DB 管理へ移行のため JSON から削除（任意）
            // $this->assertArrayHasKey('max_tokens', $model);
            $this->assertArrayHasKey('supports_tools', $model);
            $this->assertArrayHasKey('cost_tier', $model);
            $this->assertArrayHasKey('is_default', $model);
        }
    }

    // ================================================================
    //  getDefaultModel
    // ================================================================

    public function testGetDefaultModelReturnsDefaultForOpenAi(): void
    {
        $model = $this->registry->getDefaultModel('openai');

        $this->assertEquals('gpt-4o', $model);
    }

    public function testGetDefaultModelReturnsDefaultForAnthropic(): void
    {
        $model = $this->registry->getDefaultModel('anthropic');

        $this->assertStringContainsString('claude-sonnet', $model);
    }

    public function testGetDefaultModelReturnsDefaultForGemini(): void
    {
        $model = $this->registry->getDefaultModel('gemini');

        $this->assertStringContainsString('gemini', $model);
    }

    public function testGetDefaultModelReturnsNullForUnknownProvider(): void
    {
        $model = $this->registry->getDefaultModel('nonexistent_provider');

        $this->assertNull($model);
    }

    // ================================================================
    //  isValidModel
    // ================================================================

    public function testIsValidModelReturnsTrueForKnownModel(): void
    {
        $this->assertTrue($this->registry->isValidModel('openai', 'gpt-4o'));
        $this->assertTrue($this->registry->isValidModel('openai', 'gpt-4o-mini'));
    }

    public function testIsValidModelReturnsFalseForUnknownModel(): void
    {
        $this->assertFalse($this->registry->isValidModel('openai', 'gpt-99'));
        $this->assertFalse($this->registry->isValidModel('openai', 'nonexistent'));
    }

    public function testIsValidModelReturnsFalseForUnknownProvider(): void
    {
        $this->assertFalse($this->registry->isValidModel('nonexistent_provider', 'gpt-4o'));
    }

    // ================================================================
    //  getVersion
    // ================================================================

    public function testGetVersionReturnsNonEmptyString(): void
    {
        $version = $this->registry->getVersion();

        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    // ================================================================
    //  getAll
    // ================================================================

    public function testGetAllReturnsCompleteConfig(): void
    {
        $all = $this->registry->getAll();

        $this->assertArrayHasKey('version', $all);
        $this->assertArrayHasKey('providers', $all);
        $this->assertCount(3, $all['providers']);
    }

    // ================================================================
    //  getApiBase
    // ================================================================

    public function testGetApiBaseReturnsUrlForKnownProvider(): void
    {
        $apiBase = $this->registry->getApiBase('openai');

        $this->assertIsString($apiBase);
        $this->assertStringStartsWith('https://', $apiBase);
    }

    public function testGetApiBaseReturnsNullForUnknownProvider(): void
    {
        $apiBase = $this->registry->getApiBase('nonexistent_provider');

        $this->assertNull($apiBase);
    }

    // ================================================================
    //  getProviderName
    // ================================================================

    public function testGetProviderNameReturnsNameForKnownProvider(): void
    {
        $name = $this->registry->getProviderName('openai');

        $this->assertEquals('OpenAI', $name);
    }

    public function testGetProviderNameReturnsNullForUnknownProvider(): void
    {
        $name = $this->registry->getProviderName('nonexistent_provider');

        $this->assertNull($name);
    }

    // ================================================================
    //  getAllModels
    // ================================================================

    public function testGetAllModelsReturnsFlattenedModelsFromAllProviders(): void
    {
        $allModels = $this->registry->getAllModels();

        $this->assertIsArray($allModels);
        // openai 5 + anthropic 3 + gemini 3 = 11
        $this->assertCount(11, $allModels);
    }

    public function testGetAllModelsIncludesProviderKeyForEachModel(): void
    {
        $allModels = $this->registry->getAllModels();

        foreach ($allModels as $model) {
            $this->assertArrayHasKey('provider', $model);
            $this->assertContains($model['provider'], ['openai', 'anthropic', 'gemini']);
        }
    }

    // ================================================================
    //  Constructor error cases
    // ================================================================

    public function testConstructorThrowsRuntimeExceptionForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);

        new AiModelRegistry('/nonexistent/path/ai_models.json');
    }

    public function testConstructorThrowsInvalidArgumentExceptionForMalformedJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Create a temporary file with invalid JSON
        $tempFile = tempnam(sys_get_temp_dir(), 'ai_test_');
        file_put_contents($tempFile, '{ invalid json }}');

        try {
            new AiModelRegistry($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testConstructorThrowsInvalidArgumentExceptionForMissingProvidersKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tempFile = tempnam(sys_get_temp_dir(), 'ai_test_');
        file_put_contents($tempFile, json_encode(['version' => '1.0.0']));

        try {
            new AiModelRegistry($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testConstructorThrowsInvalidArgumentExceptionForNonArrayProviders(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tempFile = tempnam(sys_get_temp_dir(), 'ai_test_');
        file_put_contents($tempFile, json_encode(['version' => '1.0.0', 'providers' => 'not-an-array']));

        try {
            new AiModelRegistry($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }
}
