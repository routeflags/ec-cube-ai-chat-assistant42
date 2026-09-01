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

use Plugin\AiChatAssistant42\Service\AiAgent\OpenAiAgent;
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use PHPUnit\Framework\TestCase;

/**
 * OpenAiAgent の Capability Matrix 対応テスト。
 *
 * gpt-5.6 系では tools 併用時に reasoning_effort を除去し、
 * gpt-4o 系では維持されることを検証する。
 */
class OpenAiAgentTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $candidates = [
            dirname(__DIR__, 6) . '/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json',
            dirname(__DIR__, 4) . '/Resource/config/ai_models.json',
            dirname(__DIR__, 3) . '/Resource/config/ai_models.json',
        ];
        $found = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $found = $candidate;
                break;
            }
        }
        $this->configPath = $found ?? $candidates[1];
    }

    // ================================================================
    //  Helper: private buildRequestPayload を Reflection で呼び出す
    // ================================================================

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<int, mixed> $tools
     * @return array<string, mixed>
     */
    private function invokeBuildPayload(OpenAiAgent $agent, array $messages, array $tools): array
    {
        $ref = new \ReflectionMethod($agent, 'buildRequestPayload');
        $ref->setAccessible(true);
        return $ref->invoke($agent, $messages, $tools);
    }

    private function createDummyMessages(): array
    {
        return [
            ['role' => 'user', 'content' => 'hello'],
        ];
    }

    private function createDummyTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'search',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ];
    }

    // ================================================================
    //  gpt-5.6 + tools あり => reasoning 除去
    // ================================================================

    public function testGpt56WithToolsStripsReasoningEffort(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-5.6', 4096, '', 'https://api.openai.com/v1', 'medium', $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayNotHasKey('reasoning_effort', $payload, 'gpt-5.6 + tools では reasoning_effort が除去されるべき');
        self::assertArrayNotHasKey('reasoningEffort', $payload, 'gpt-5.6 + tools では reasoningEffort が除去されるべき');
        self::assertArrayHasKey('tools', $payload);
        self::assertArrayHasKey('max_completion_tokens', $payload);
        self::assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testGpt56TerraWithToolsStripsReasoningEffort(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-5.6-terra', 4096, '', 'https://api.openai.com/v1', 'high', $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayNotHasKey('reasoning_effort', $payload);
        self::assertArrayNotHasKey('reasoningEffort', $payload);
    }

    public function testGpt56LunaWithToolsStripsReasoningEffort(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-5.6-luna', 4096, '', 'https://api.openai.com/v1', 'low', $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayNotHasKey('reasoning_effort', $payload);
        self::assertArrayNotHasKey('reasoningEffort', $payload);
    }

    // ================================================================
    //  gpt-5.6 without tools => reasoning 維持
    // ================================================================

    public function testGpt56WithoutToolsKeepsReasoningEffort(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-5.6', 4096, '', 'https://api.openai.com/v1', 'medium', $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), []);

        self::assertArrayHasKey('reasoning_effort', $payload, 'gpt-5.6 tools なしでは reasoning は維持されるべき');
        self::assertSame('medium', $payload['reasoning_effort']);
        self::assertArrayNotHasKey('tools', $payload);
    }

    // ================================================================
    //  gpt-4o 系 + tools あり => reasoning 維持
    // ================================================================

    public function testGpt4oWithToolsKeepsReasoningEffort(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-4o', 4096, '', 'https://api.openai.com/v1', 'medium', $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayHasKey('reasoning_effort', $payload, 'gpt-4o は tools 併用でも reasoning を維持すべき');
        self::assertSame('medium', $payload['reasoning_effort']);
        self::assertArrayHasKey('max_tokens', $payload);
        self::assertArrayNotHasKey('max_completion_tokens', $payload);
    }

    public function testGpt4oMiniWithToolsKeepsReasoningEffort(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-4o-mini', 4096, '', 'https://api.openai.com/v1', 'low', $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayHasKey('reasoning_effort', $payload);
        self::assertSame('low', $payload['reasoning_effort']);
    }

    // ================================================================
    //  reasoning 未設定 => キー自体が出ない
    // ================================================================

    public function testNoReasoningEffortDoesNotAddKeysEvenForGpt4o(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-4o', 4096, '', 'https://api.openai.com/v1', null, $registry);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayNotHasKey('reasoning_effort', $payload);
        self::assertArrayNotHasKey('reasoningEffort', $payload);
    }

    // ================================================================
    //  フォールバック: Registry なしでも JSON 直接読み込みで同様に動作
    // ================================================================

    public function testFallbackWithoutRegistryStripsReasoningForGpt56WithTools(): void
    {
        // Registry を渡さずフォールバック経路をテスト
        $agent = new OpenAiAgent('test-key', 'gpt-5.6', 4096, '', 'https://api.openai.com/v1', 'medium', null);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayNotHasKey('reasoning_effort', $payload);
        self::assertArrayNotHasKey('reasoningEffort', $payload);
    }

    public function testFallbackWithoutRegistryKeepsReasoningForGpt4oWithTools(): void
    {
        $agent = new OpenAiAgent('test-key', 'gpt-4o', 4096, '', 'https://api.openai.com/v1', 'medium', null);

        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());

        self::assertArrayHasKey('reasoning_effort', $payload);
    }

    // ================================================================
    //  setter 経由での reasoning 変更
    // ================================================================

    public function testSetReasoningEffortDynamicallyAffectsPayload(): void
    {
        $registry = new AiModelRegistry($this->configPath);
        $agent = new OpenAiAgent('test-key', 'gpt-4o', 4096, '', 'https://api.openai.com/v1', null, $registry);

        // 初期はなし
        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());
        self::assertArrayNotHasKey('reasoning_effort', $payload);

        // setter で追加
        $agent->setReasoningEffort('high');
        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());
        self::assertSame('high', $payload['reasoning_effort']);

        // null に戻す
        $agent->setReasoningEffort(null);
        $payload = $this->invokeBuildPayload($agent, $this->createDummyMessages(), $this->createDummyTools());
        self::assertArrayNotHasKey('reasoning_effort', $payload);
    }

    // ================================================================
    //  max_completion_tokens 維持の検証
    // ================================================================

    public function testMaxTokensBranchStillWorks(): void
    {
        $registry = new AiModelRegistry($this->configPath);

        $agent56 = new OpenAiAgent('test-key', 'gpt-5.6', 2048, '', 'https://api.openai.com/v1', null, $registry);
        $payload56 = $this->invokeBuildPayload($agent56, $this->createDummyMessages(), []);
        self::assertSame(2048, $payload56['max_completion_tokens']);

        $agent4o = new OpenAiAgent('test-key', 'gpt-4o', 2048, '', 'https://api.openai.com/v1', null, $registry);
        $payload4o = $this->invokeBuildPayload($agent4o, $this->createDummyMessages(), []);
        self::assertSame(2048, $payload4o['max_tokens']);
    }

    // ================================================================
    //  JSON スキーマ簡易検証
    // ================================================================

    public function testAiModelsJsonIsValidAndContainsCapability(): void
    {
        $raw = file_get_contents($this->configPath);
        self::assertNotFalse($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('providers', $decoded);
        self::assertArrayHasKey('openai', $decoded['providers']);

        $models = $decoded['providers']['openai']['models'];
        $byId = [];
        foreach ($models as $model) {
            $byId[$model['id']] = $model;
        }

        // 必須モデルが存在し、capability が正しく設定されている
        self::assertArrayHasKey('gpt-5.6', $byId);
        self::assertFalse($byId['gpt-5.6']['supports_reasoning_with_tools'], 'gpt-5.6 は false であるべき');

        self::assertArrayHasKey('gpt-5.6-terra', $byId);
        self::assertFalse($byId['gpt-5.6-terra']['supports_reasoning_with_tools']);

        self::assertArrayHasKey('gpt-5.6-luna', $byId);
        self::assertFalse($byId['gpt-5.6-luna']['supports_reasoning_with_tools']);

        self::assertArrayHasKey('gpt-4o', $byId);
        self::assertTrue($byId['gpt-4o']['supports_reasoning_with_tools'], 'gpt-4o は true であるべき');

        self::assertArrayHasKey('gpt-4o-mini', $byId);
        self::assertTrue($byId['gpt-4o-mini']['supports_reasoning_with_tools']);

        // 全 openai モデルが supports_tools と supports_reasoning_with_tools を持つ
        foreach ($models as $model) {
            self::assertArrayHasKey('supports_tools', $model, "Model {$model['id']} は supports_tools を持つべき");
            self::assertArrayHasKey('supports_reasoning_with_tools', $model, "Model {$model['id']} は supports_reasoning_with_tools を持つべき");
            self::assertIsBool($model['supports_tools']);
            self::assertIsBool($model['supports_reasoning_with_tools']);
        }
    }

    // ================================================================
    //  AiModelRegistry の新メソッド検証
    // ================================================================

    public function testRegistrySupportsReasoningWithToolsMethod(): void
    {
        $registry = new AiModelRegistry($this->configPath);

        self::assertFalse($registry->supportsReasoningWithTools('openai', 'gpt-5.6'));
        self::assertFalse($registry->supportsReasoningWithTools('openai', 'gpt-5.6-terra'));
        self::assertFalse($registry->supportsReasoningWithTools('openai', 'gpt-5.6-luna'));
        self::assertTrue($registry->supportsReasoningWithTools('openai', 'gpt-4o'));
        self::assertTrue($registry->supportsReasoningWithTools('openai', 'gpt-4o-mini'));
        // 未知モデルは true（デグレ防止）
        self::assertTrue($registry->supportsReasoningWithTools('openai', 'unknown-model-xyz'));
        // 未知プロバイダも true
        self::assertTrue($registry->supportsReasoningWithTools('unknown_provider', 'gpt-4o'));
    }

    public function testRegistryGetModelAndSupportsTools(): void
    {
        $registry = new AiModelRegistry($this->configPath);

        $model = $registry->getModel('openai', 'gpt-4o');
        self::assertIsArray($model);
        self::assertSame('gpt-4o', $model['id']);

        self::assertNull($registry->getModel('openai', 'nonexistent'));
        self::assertNull($registry->getModel('unknown', 'gpt-4o'));

        self::assertTrue($registry->supportsTools('openai', 'gpt-4o'));
        self::assertFalse($registry->supportsTools('openai', 'nonexistent'));
        self::assertFalse($registry->supportsTools('unknown', 'gpt-4o'));
    }

    public function testRegistryBackwardCompatibilityWhenKeyMissing(): void
    {
        // 一時ファイルで supports_reasoning_with_tools が欠損したモデルをテスト
        $tmp = tempnam(sys_get_temp_dir(), 'ai_test_');
        file_put_contents($tmp, json_encode([
            'version' => '0.0.1',
            'providers' => [
                'openai' => [
                    'name' => 'OpenAI',
                    'api_base' => 'https://api.openai.com/v1',
                    'models' => [
                        [
                            'id' => 'gpt-test',
                            'name' => 'Test',
                            'description' => 'test',
                            'supports_tools' => true,
                            'cost_tier' => 'low',
                            'is_default' => true,
                            // supports_reasoning_with_tools を意図的に省略
                        ],
                    ],
                ],
            ],
        ]));

        try {
            $registry = new AiModelRegistry($tmp);
            // キー欠損は true（後方互換）であるべき
            self::assertTrue($registry->supportsReasoningWithTools('openai', 'gpt-test'));
        } finally {
            @unlink($tmp);
        }
    }
}
