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

namespace Plugin\AiChatAssistant42\Tests\Unit\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Controller\Admin\DashboardController;
use Plugin\AiChatAssistant42\Entity\Config;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * DashboardController のユニットテスト。
 *
 * settings() の正常系・異常系・エッジケースを検証する。
 * EntityManager はモックし、DB 実行せずにコントローラロジックのみを検証する。
 */
class DashboardControllerTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var EntityRepository&MockObject */
    private EntityRepository $configRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configRepository = $this->createMock(EntityRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->with(Config::class)
            ->willReturn($this->configRepository);
    }

    private function createController(array $mockMethods = []): DashboardController
    {
        $needsGetParameter = !in_array('getParameter', $mockMethods, true);
        $methods = array_unique(array_merge(
            ['isTokenValid', 'addSuccess', 'addError', 'redirectToRoute', 'render', 'getParameter'],
            $mockMethods
        ));

        /** @var DashboardController&MockObject $controller */
        $controller = $this->getMockBuilder(DashboardController::class)
            ->onlyMethods($methods)
            ->getMock();

        $controller->setEntityManager($this->entityManager);

        // loadAiModels() が getParameter('kernel.project_dir') を呼ぶためスタブする
        if ($needsGetParameter) {
            $controller->method('getParameter')
                ->willReturnCallback(function (string $name) {
                    if ($name === 'kernel.project_dir') {
                        // EC-CUBE ルート (tests/Plugin/.../Admin → 7) とプラグイン単体 (Tests/Unit/Controller/Admin → 4) の両対応
                        $candidates = [
                            dirname(__DIR__, 7),
                            dirname(__DIR__, 4),
                            dirname(__DIR__, 5),
                        ];
                        foreach ($candidates as $candidate) {
                            if (is_file($candidate . '/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json')
                                || is_file($candidate . '/Resource/config/ai_models.json')) {
                                return $candidate;
                            }
                        }
                        return $candidates[0];
                    }

                    throw new \InvalidArgumentException(sprintf('Unknown parameter "%s"', $name));
                });
        }

        return $controller;
    }

    // ================================================================
    //  GET: 設定が存在しない場合のデフォルト生成
    // ================================================================

    public function testSettingsGetCreatesDefaultConfigWhenNotFound(): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->with([], ['id' => 'ASC'])
            ->willReturn(null);

        $persistedConfig = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (Config $config) use (&$persistedConfig) {
                $persistedConfig = $config;
            });
        $this->entityManager->expects($this->once())
            ->method('flush');

        $controller = $this->createController();
        $controller->method('render')
            ->willReturnCallback(function (string $template, array $params) use (&$persistedConfig) {
                $this->assertEquals('@AiChatAssistant42/admin/settings.twig', $template);
                $this->assertArrayHasKey('config', $params);
                $this->assertInstanceOf(Config::class, $params['config']);
                $this->assertEquals('openai', $params['config']->getProvider());
                $this->assertEquals('gpt-4o', $params['config']->getModel());
                $this->assertEquals(4096, $params['config']->getMaxTokens());
                $this->assertEquals(0, $params['config']->getIsEnabled());
                // ai_models.json の 6モデルが select に渡されることを検証
                $this->assertArrayHasKey('modelsByProvider', $params);
                $this->assertArrayHasKey('allModelIds', $params);
                $this->assertIsArray($params['modelsByProvider']);
                $this->assertIsArray($params['allModelIds']);
                $this->assertGreaterThanOrEqual(6, count($params['allModelIds']));
                $this->assertArrayHasKey('openai', $params['modelsByProvider']);
                $this->assertArrayHasKey('anthropic', $params['modelsByProvider']);
                $this->assertArrayHasKey('gemini', $params['modelsByProvider']);

                return new Response('rendered');
            });

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'GET');

        $response = $controller->settings($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($persistedConfig);
    }

    // ================================================================
    //  GET: 既存設定がある場合はそのまま render
    // ================================================================

    public function testSettingsGetRendersWithExistingConfig(): void
    {
        $existingConfig = (new Config())
            ->setProvider('anthropic')
            ->setModel('claude-3-5-sonnet-20241022')
            ->setMaxTokens(8192)
            ->setIsEnabled(1);

        $this->configRepository
            ->method('findOneBy')
            ->willReturn($existingConfig);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $controller = $this->createController();
        $controller->method('render')
            ->willReturnCallback(function (string $template, array $params) use ($existingConfig) {
                $this->assertEquals('@AiChatAssistant42/admin/settings.twig', $template);
                $this->assertSame($existingConfig, $params['config']);
                // 6モデルが渡されること（ai_models.json 由来）
                $this->assertArrayHasKey('modelsByProvider', $params);
                $this->assertArrayHasKey('allModelIds', $params);
                $this->assertGreaterThanOrEqual(6, count($params['allModelIds']));

                return new Response('rendered');
            });

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'GET');

        $response = $controller->settings($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ================================================================
    //  POST: 有効な CSRF トークンで設定を更新
    // ================================================================

    public function testSettingsPostUpdatesConfigAndRedirectsWhenTokenValid(): void
    {
        $existingConfig = (new Config())
            ->setProvider('openai')
            ->setModel('gpt-4o')
            ->setMaxTokens(4096)
            ->setIsEnabled(0)
            ->setResponseMode('hybrid');

        $this->configRepository
            ->method('findOneBy')
            ->willReturn($existingConfig);

        $this->entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->expects($this->once())->method('addSuccess')->with('設定を保存しました。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_settings')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/settings', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'POST', [
            'is_enabled' => '1',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'response_mode' => 'knowledge_only',
        ]);

        $response = $controller->settings($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals(1, $existingConfig->getIsEnabled());
        $this->assertEquals('anthropic', $existingConfig->getProvider());
        $this->assertEquals('claude-sonnet-5', $existingConfig->getModel());
        // max_tokens は設定画面から削除されたため変更されないことを検証
        $this->assertEquals(4096, $existingConfig->getMaxTokens());
        $this->assertEquals('knowledge_only', $existingConfig->getResponseMode());
    }

    // ================================================================
    //  POST: API キーが空文字の場合は上書きしない
    // ================================================================

    public function testSettingsPostDoesNotOverwriteApiKeysWhenEmpty(): void
    {
        $existingConfig = (new Config())
            ->setApiKeyOpenai('sk-existing-openai')
            ->setApiKeyAnthropic('sk-existing-anthropic')
            ->setApiKeyGemini('sk-existing-gemini');

        $this->configRepository->method('findOneBy')->willReturn($existingConfig);
        $this->entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('addSuccess')->willReturn(null);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/settings', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'POST', [
            'api_key_openai' => '',
            'api_key_anthropic' => '',
            'api_key_gemini' => '',
        ]);

        $controller->settings($request);

        $this->assertEquals('sk-existing-openai', $existingConfig->getApiKeyOpenai());
        $this->assertEquals('sk-existing-anthropic', $existingConfig->getApiKeyAnthropic());
        $this->assertEquals('sk-existing-gemini', $existingConfig->getApiKeyGemini());
    }

    // ================================================================
    //  POST: API キーが非空の場合は上書きする
    // ================================================================

    public function testSettingsPostOverwritesApiKeysWhenNonEmpty(): void
    {
        $existingConfig = new Config();
        $this->configRepository->method('findOneBy')->willReturn($existingConfig);
        $this->entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('addSuccess')->willReturn(null);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/settings', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'POST', [
            'api_key_openai' => 'sk-new-openai-key',
            'api_key_anthropic' => 'sk-new-anthropic-key',
            'api_key_gemini' => 'sk-new-gemini-key',
            'system_prompt' => 'You are a helpful assistant.',
        ]);

        $controller->settings($request);

        $this->assertEquals('sk-new-openai-key', $existingConfig->getApiKeyOpenai());
        $this->assertEquals('sk-new-anthropic-key', $existingConfig->getApiKeyAnthropic());
        $this->assertEquals('sk-new-gemini-key', $existingConfig->getApiKeyGemini());
        $this->assertEquals('You are a helpful assistant.', $existingConfig->getSystemPrompt());
    }

    // ================================================================
    //  POST: system_prompt が空の場合は上書きしない
    // ================================================================

    public function testSettingsPostDoesNotOverwriteSystemPromptWhenEmpty(): void
    {
        $existingConfig = (new Config())->setSystemPrompt('Existing prompt');
        $this->configRepository->method('findOneBy')->willReturn($existingConfig);
        $this->entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('addSuccess')->willReturn(null);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/settings', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'POST', [
            'system_prompt' => '',
        ]);

        $controller->settings($request);

        $this->assertEquals('Existing prompt', $existingConfig->getSystemPrompt());
    }

    // ================================================================
    //  POST: CSRF トークン無効時はエラー表示してリダイレクト（例外を捕捉）
    // ================================================================

    public function testSettingsPostWithInvalidTokenRedirectsWithError(): void
    {
        $existingConfig = new Config();
        $this->configRepository->method('findOneBy')->willReturn($existingConfig);

        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_settings')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/settings', 302));
        $this->entityManager->expects($this->never())->method('flush');

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'POST', [
            'provider' => 'openai',
        ]);

        $response = $controller->settings($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    // ================================================================
    //  型キャスト: int 変換が正しく行われる
    // ================================================================

    public function testSettingsPostCastsTypesCorrectly(): void
    {
        $existingConfig = new Config();
        // デフォルトは maxTokens 4096
        $this->configRepository->method('findOneBy')->willReturn($existingConfig);
        $this->entityManager->expects($this->once())->method('flush');

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('addSuccess')->willReturn(null);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/settings', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/settings', 'POST', [
            'is_enabled' => '1',
            // max_tokens は DB で管理し保存されることを検証
            'max_tokens' => '2048',
        ]);

        $controller->settings($request);

        $this->assertIsInt($existingConfig->getIsEnabled());
        $this->assertIsInt($existingConfig->getMaxTokens());
        $this->assertEquals(1, $existingConfig->getIsEnabled());
        // max_tokens は保存される（2048 に更新）
        $this->assertEquals(2048, $existingConfig->getMaxTokens());
    }

    // ================================================================
    //  Entity: getMaskedApiKey
    // ================================================================

    public function testGetMaskedApiKeyReturnsMaskedValue(): void
    {
        $config = (new Config())->setApiKeyOpenai('sk-1234567890abcdef');

        $masked = $config->getMaskedApiKey('openai');

        $this->assertStringEndsWith('cdef', $masked);
        $this->assertStringStartsWith('***', $masked);
        $this->assertEquals(strlen('sk-1234567890abcdef'), strlen($masked));
    }

    public function testGetMaskedApiKeyReturnsEmptyWhenNotSet(): void
    {
        $config = new Config();

        $this->assertEquals('', $config->getMaskedApiKey('openai'));
        $this->assertEquals('', $config->getMaskedApiKey('anthropic'));
        $this->assertEquals('', $config->getMaskedApiKey('gemini'));
    }

    public function testGetMaskedApiKeyReturnsAllStarsForShortKey(): void
    {
        $config = (new Config())->setApiKeyOpenai('abc');

        $this->assertEquals('***', $config->getMaskedApiKey('openai'));
    }

    // ================================================================
    //  Dashboard index — AiModelSyncService 連携（MJ02 6c）
    // ================================================================

    public function testIndexCallsTrySyncIfStaleOnce(): void
    {
        $syncService = $this->createMock(\Plugin\AiChatAssistant42\Service\AiModelSyncService::class);
        $syncService->expects($this->once())->method('trySyncIfStale')->willReturn(true);

        $chatLogRepo = $this->createStubChatLogRepository();
        $this->configRepository->method('findOneBy')->willReturn(null);

        $controllerMock = $this->buildDashboardControllerMock($chatLogRepo, $syncService, null);
        $this->injectSyncService($controllerMock, $syncService);

        $response = $controllerMock->index();

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testIndexSwallowsSyncExceptionAndStillRenders(): void
    {
        $syncService = $this->createMock(\Plugin\AiChatAssistant42\Service\AiModelSyncService::class);
        $syncService->expects($this->once())->method('trySyncIfStale')->willThrowException(new \RuntimeException('network down'));

        $chatLogRepo = $this->createStubChatLogRepository();
        $this->configRepository->method('findOneBy')->willReturn(null);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('AI model sync failed, keeping local', $this->anything());

        $controllerMock = $this->buildDashboardControllerMock($chatLogRepo, $syncService, $logger);

        $response = $controllerMock->index();

        $this->assertEquals(200, $response->getStatusCode());
    }

    private function createStubChatLogRepository(): \Plugin\AiChatAssistant42\Repository\ChatLogRepository
    {
        $repo = $this->createMock(\Plugin\AiChatAssistant42\Repository\ChatLogRepository::class);
        $repo->method('fetchKpi')->willReturn(['total' => 0, 'resolved' => 0, 'errors' => 0, 'avg_response_ms' => 0, 'resolution_rate' => 0, 'error_rate' => 0]);
        $repo->method('fetchRecentLogs')->willReturn([]);
        $repo->method('fetchProviderStats')->willReturn([]);
        $repo->method('fetchModelStats')->willReturn([]);
        $repo->method('fetchErrorBreakdown')->willReturn([]);
        $repo->method('countPendingEmailReplies')->willReturn(0);
        $repo->method('fetchHourlyDistribution')->willReturn([]);

        return $repo;
    }

    private function buildDashboardControllerMock($chatLogRepo, $syncService, $logger): DashboardController
    {
        $mock = $this->getMockBuilder(DashboardController::class)
            ->setConstructorArgs([null, null, $chatLogRepo, $syncService, $logger])
            ->onlyMethods(['render'])
            ->getMock();
        $mock->setEntityManager($this->entityManager);
        $mock->method('render')->willReturn(new Response('ok'));

        return $mock;
    }

    private function injectSyncService(DashboardController $controller, $syncService): void
    {
        $ref = new \ReflectionProperty(DashboardController::class, 'syncService');
        $ref->setAccessible(true);
        $ref->setValue($controller, $syncService);
    }
}
