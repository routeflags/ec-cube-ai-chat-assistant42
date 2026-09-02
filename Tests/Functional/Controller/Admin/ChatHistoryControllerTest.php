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

namespace Plugin\AiChatAssistant42\Tests\Functional\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use Plugin\AiChatAssistant42\Controller\Admin\ChatHistoryController;
use Plugin\AiChatAssistant42\Entity\ChatLog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * ChatHistoryController::delete のテスト。
 *
 * 正常系: 削除で 302、一覧から消える
 * 異常系: CSRF無効は302+エラー、存在しないIDは404
 * 境界: page保持、末尾ページ丸め
 */
class ChatHistoryControllerTest extends TestCase
{
    private function createMockLog(int $id): ChatLog
    {
        $log = new ChatLog();
        // id は private なのでリフレクションで設定
        $ref = new \ReflectionClass($log);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($log, $id);

        return $log;
    }

    private function createControllerWithMocks(
        EntityManagerInterface $em,
        bool $csrfValid = true
    ): ChatHistoryController {
        // ChatHistoryController を部分モック化し、CSRF/フラッシュ/リダイレクトをスタブ
        $controller = $this->getMockBuilder(ChatHistoryController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'createNotFoundException', 'redirectToRoute'])
            ->getMock();

        // isCsrfTokenValid
        $controller->method('isCsrfTokenValid')->willReturn($csrfValid);

        // addError / addSuccess は何もしない
        $controller->method('addError')->willReturn(null);
        $controller->method('addSuccess')->willReturn(null);

        // createNotFoundException は NotFoundHttpException を投げる
        $controller->method('createNotFoundException')->willReturnCallback(function (string $msg) {
            return new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException($msg);
        });

        // redirectToRoute は RedirectResponse を返す（Location ヘッダに page を含む）
        $controller->method('redirectToRoute')->willReturnCallback(function (string $route, array $params = []) {
            $url = '/' . $route;
            if (isset($params['page'])) {
                $url .= '?page=' . $params['page'];
            }

            return new \Symfony\Component\HttpFoundation\RedirectResponse($url, 302);
        });

        // entityManager プロパティを注入
        $ref = new \ReflectionClass($controller);
        $emProp = null;
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getName() === 'entityManager') {
                $emProp = $prop;
                break;
            }
        }
        if ($emProp === null) {
            $parentRef = $ref->getParentClass();
            if ($parentRef) {
                try {
                    $emProp = $parentRef->getProperty('entityManager');
                } catch (\ReflectionException $e) {
                    $controller->entityManager = $em;
                }
            }
        }
        if ($emProp !== null) {
            $emProp->setAccessible(true);
            $emProp->setValue($controller, $em);
        } else {
            $controller->entityManager = $em;
        }

        return $controller;
    }

    private function createMockEntityManagerForDelete(?ChatLog $foundLog, bool $expectRemove = false, int $totalAfterDelete = 0): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        // find
        $em->method('find')->willReturnCallback(function (string $class, $id) use ($foundLog) {
            if ($class === ChatLog::class) {
                return $foundLog;
            }

            return null;
        });

        if ($expectRemove) {
            $em->expects($this->once())->method('remove')->with($foundLog);
            $em->expects($this->once())->method('flush');
        } else {
            $em->expects($this->never())->method('remove');
        }

        // ChatLogRepository mock for countAll (リポジトリ移譲後)
        $chatLogRepo = $this->createMock(\Plugin\AiChatAssistant42\Repository\ChatLogRepository::class);
        if ($expectRemove) {
            $chatLogRepo->method('countAll')->willReturn($totalAfterDelete);
        }

        $em->method('getRepository')->willReturnCallback(function (string $class) use ($chatLogRepo) {
            if ($class === ChatLog::class) {
                return $chatLogRepo;
            }
            return null;
        });

        return $em;
    }

    public function testHistoryDeleteRemovesLog(): void
    {
        $log = $this->createMockLog(123);
        $em = $this->createMockEntityManagerForDelete($log, true, 19); // 19件残り → lastPage=1 (20 per page)
        $controller = $this->createControllerWithMocks($em, true);

        $request = new Request([], ['_token' => 'valid_token', 'page' => '2'], [], [], [], [], null);
        $request->query->set('page', '2');

        $response = $controller->delete($request, 123);

        $this->assertEquals(302, $response->getStatusCode());
        // page 2 だったが total 19 → lastPage 1 → redirect は page=1 に丸められる
        $this->assertStringContainsString('page=1', $response->headers->get('Location'));
    }

    public function testHistoryDeleteRequiresCsrf(): void
    {
        $log = $this->createMockLog(123);
        $em = $this->createMockEntityManagerForDelete($log, false, 0);
        $controller = $this->createControllerWithMocks($em, false); // CSRF 無効

        $request = new Request([], ['_token' => 'invalid'], [], [], [], [], null);
        $request->query->set('page', '1');

        $response = $controller->delete($request, 123);

        $this->assertEquals(302, $response->getStatusCode());
        // CSRF失敗は remove が呼ばれない
        // Location は history 一覧へ（page=1）
        $this->assertStringContainsString('admin_ai_chat_assistant_history', $response->headers->get('Location'));
    }

    public function testHistoryDeleteNotFound(): void
    {
        $em = $this->createMockEntityManagerForDelete(null, false, 0);
        $controller = $this->createControllerWithMocks($em, true);

        $request = new Request([], ['_token' => 'valid'], [], [], [], [], null);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->delete($request, 9999);
    }

    public function testHistoryDeletePreservesPageWhenNotOverflow(): void
    {
        $log = $this->createMockLog(123);
        $em = $this->createMockEntityManagerForDelete($log, true, 40); // 40件 → lastPage=2
        $controller = $this->createControllerWithMocks($em, true);

        $request = new Request([], ['_token' => 'valid', 'page' => '1'], [], [], [], [], null);
        $request->query->set('page', '1');

        $response = $controller->delete($request, 123);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('page=1', $response->headers->get('Location'));
    }
}
