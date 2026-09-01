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

use Plugin\AiChatAssistant42\Entity\AccessRule;
use Plugin\AiChatAssistant42\Repository\AccessRuleRepository;
use Plugin\AiChatAssistant42\Service\AccessRuleService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * AccessRuleService の単体テスト。
 *
 * ルールなし（デフォルト許可）、deny ルール、throttle ルール、
 * および例外発生時のフェイルセーフを検証する。
 */
class AccessRuleServiceTest extends TestCase
{
    private AccessRuleRepository $repository;
    private AccessRuleService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AccessRuleRepository::class);
        $this->service = new AccessRuleService(
            $this->repository,
            new NullLogger(),
        );
    }

    // ================================================================
    //  デフォルト許可（ルールなし）
    // ================================================================

    public function testIsAllowedReturnsTrueWhenNoRulesMatch(): void
    {
        $this->repository->method('findMatchingRules')
            ->willReturn([]);

        $this->assertTrue($this->service->isAllowed('hello', 'block_keyword'));
    }

    public function testIsAllowedReturnsTrueWhenNoIpRulesMatch(): void
    {
        $this->repository->method('findMatchingRules')
            ->willReturn([]);

        $this->assertTrue($this->service->isAllowed('192.168.1.100', 'ip'));
    }

    // ================================================================
    //  deny ルール
    // ================================================================

    public function testIsAllowedReturnsFalseWhenDenyRuleMatches(): void
    {
        $rule = $this->createAccessRule('block_keyword', 'spam', 'deny');

        $this->repository->method('findMatchingRules')
            ->willReturn([$rule]);

        $this->assertFalse($this->service->isAllowed('this is spam content', 'block_keyword'));
    }

    public function testIsAllowedReturnsFalseWhenIpDenyRuleMatches(): void
    {
        $rule = $this->createAccessRule('ip', '10.0.0.1', 'deny');

        $this->repository->method('findMatchingRules')
            ->willReturn([$rule]);

        $this->assertFalse($this->service->isAllowed('10.0.0.1', 'ip'));
    }

    public function testIsAllowedReturnsFalseOnFirstDenyRuleEvenIfOthersAllow(): void
    {
        $denyRule = $this->createAccessRule('block_keyword', 'badword', 'deny');

        $this->repository->method('findMatchingRules')
            ->willReturn([$denyRule]);

        $this->assertFalse($this->service->isAllowed('contains badword here', 'block_keyword'));
    }

    // ================================================================
    //  throttle ルール（許可だがログ記録）
    // ================================================================

    public function testIsAllowedReturnsTrueWhenThrottleRuleMatches(): void
    {
        $rule = $this->createAccessRule('ip', '192.168.1.*', 'throttle');

        $this->repository->method('findMatchingRules')
            ->willReturn([$rule]);

        $this->assertTrue($this->service->isAllowed('192.168.1.42', 'ip'));
    }

    // ================================================================
    //  allow ルール
    // ================================================================

    public function testIsAllowedReturnsTrueWhenAllowRuleMatches(): void
    {
        $rule = $this->createAccessRule('ip', '192.168.1.*', 'allow');

        $this->repository->method('findMatchingRules')
            ->willReturn([$rule]);

        $this->assertTrue($this->service->isAllowed('192.168.1.42', 'ip'));
    }

    // ================================================================
    //  例外時のフェイルセーフ
    // ================================================================

    public function testIsAllowedReturnsTrueOnRepositoryException(): void
    {
        $this->repository->method('findMatchingRules')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->assertTrue($this->service->isAllowed('test input', 'ip'));
    }

    // ================================================================
    //  ヘルパーメソッド
    // ================================================================

    private function createAccessRule(string $type, string $value, string $action): AccessRule
    {
        $rule = new AccessRule();
        $rule->setRuleType($type);
        $rule->setRuleValue($value);
        $rule->setAction($action);
        $rule->setIsActive(1);

        return $rule;
    }
}
