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

namespace Plugin\AiChatAssistant42\Tests\Functional\Controller\Api;

use Eccube\Tests\Web\AbstractWebTestCase;

/**
 * ModelApiController の機能テスト。
 *
 * モデル一覧 API（GET /models）のレスポンス構造を検証する。
 */
class ModelApiControllerTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ================================================================
    //  GET /api/ai-chat-assistant/models
    // ================================================================

    public function testModelsEndpointReturnsSuccessWithProviders(): void
    {
        $this->client->request('GET', $this->generateUrl('ai_chat_assistant_models_list'));

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('providers', $data);
        $this->assertArrayHasKey('version', $data);
    }

    public function testModelsEndpointReturnsProviderDetails(): void
    {
        $this->client->request('GET', $this->generateUrl('ai_chat_assistant_models_list'));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertNotEmpty($data['providers']);

        // 各プロバイダに必須フィールドがあることを確認
        foreach ($data['providers'] as $provider) {
            $this->assertArrayHasKey('key', $provider);
            $this->assertArrayHasKey('name', $provider);
            $this->assertArrayHasKey('models', $provider);
            $this->assertIsArray($provider['models']);
            $this->assertNotEmpty($provider['models']);
        }
    }

    public function testModelsEndpointIncludesAllThreeProviders(): void
    {
        $this->client->request('GET', $this->generateUrl('ai_chat_assistant_models_list'));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        $providerKeys = array_column($data['providers'], 'key');
        $this->assertContains('openai', $providerKeys);
        $this->assertContains('anthropic', $providerKeys);
        $this->assertContains('gemini', $providerKeys);
    }

    public function testModelsEndpointReturnsNonEmptyVersion(): void
    {
        $this->client->request('GET', $this->generateUrl('ai_chat_assistant_models_list'));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertNotEmpty($data['version']);
    }

    public function testModelsEndpointReturnsModelFields(): void
    {
        $this->client->request('GET', $this->generateUrl('ai_chat_assistant_models_list'));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        // OpenAI のモデル至少1つのフィールドを検証
        $openai = null;
        foreach ($data['providers'] as $provider) {
            if ($provider['key'] === 'openai') {
                $openai = $provider;
                break;
            }
        }

        $this->assertNotNull($openai, 'OpenAI provider should be present');

        $firstModel = $openai['models'][0];
        $this->assertArrayHasKey('id', $firstModel);
        $this->assertArrayHasKey('name', $firstModel);
        $this->assertArrayHasKey('description', $firstModel);
        $this->assertArrayHasKey('max_tokens', $firstModel);
        $this->assertArrayHasKey('supports_tools', $firstModel);
        $this->assertArrayHasKey('cost_tier', $firstModel);
    }

    public function testModelsEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', $this->generateUrl('ai_chat_assistant_models_list'));

        $response = $this->client->getResponse();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }
}
