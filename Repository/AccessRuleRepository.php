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

namespace Plugin\AiChatAssistant42\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Plugin\AiChatAssistant42\Entity\AccessRule;
use Eccube\Repository\AbstractRepository;

/**
 * アクセスルールリポジトリ。
 *
 * IP・時間帯・禁止キーワード等のアクセス制御ルールを管理する。
 */
class AccessRuleRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($managerRegistry, AccessRule::class);
    }

    /**
     * 有効なアクセスルールをすべて取得する。
     *
     * @return AccessRule[]
     */
    public function findAllActive(): array
    {
        return $this->entityManager->getRepository(AccessRule::class)
            ->findBy(['is_active' => 1], ['id' => 'ASC']);
    }

    /**
     * 入力値とルール種別に一致するルールを検索する。
     *
     * IP ルール: 入力 IP がルール値と一致するか
     * 時間ルール: 現在時刻がルール値の範囲内か
     * キーワードルール: 入力テキストに禁止キーワードが含まれるか
     *
     * @return AccessRule[]
     */
    public function findMatchingRules(string $input, string $type): array
    {
        $allRules = $this->entityManager->getRepository(AccessRule::class)
            ->findBy(['rule_type' => $type, 'is_active' => 1], ['id' => 'ASC']);

        $matched = [];
        foreach ($allRules as $rule) {
            if ($this->isRuleMatch($input, $rule)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    /**
     * 指定 ID のアクセスルールを取得する。
     */

    /**
     * アクセスルールを永続化する。
     */
    public function save($entity)
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * アクセスルールを削除する。
     */
    public function delete($entity)
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    /**
     * 入力値がルールに一致するか判定する。
     */
    private function isRuleMatch(string $input, AccessRule $rule): bool
    {
        return match ($rule->getRuleType()) {
            'ip' => $this->isIpMatch($input, $rule->getRuleValue()),
            'time' => $this->isTimeMatch($rule->getRuleValue()),
            'block_keyword' => $this->isKeywordMatch($input, $rule->getRuleValue()),
            default => false,
        };
    }

    /**
     * IP アドレスが CIDR パターンと一致するか。
     *
     * 簡易一致: 完全一致またはワイルドカード (例: 192.168.1.*)
     */
    private function isIpMatch(string $inputIp, string $ruleValue): bool
    {
        if ($inputIp === $ruleValue) {
            return true;
        }

        // ワイルドカード末尾 (例: 192.168.1.*) でのプレフィックス一致
        if (str_ends_with($ruleValue, '.*')) {
            $prefix = substr($ruleValue, 0, -2);

            return str_starts_with($inputIp, $prefix);
        }

        return false;
    }

    /**
     * 現在時刻が時間帯範囲内か。
     *
     * ルール値形式: "HH:MM-HH:MM" (例: "22:00-06:00" = 深夜帯)
     */
    private function isTimeMatch(string $ruleValue): bool
    {
        if (!str_contains($ruleValue, '-')) {
            return false;
        }

        [$startStr, $endStr] = explode('-', $ruleValue, 2);
        $startMinutes = $this->timeToMinutes($startStr);
        $endMinutes = $this->timeToMinutes($endStr);

        $now = (int) date('Gi');
        $currentMinutes = (int) floor($now / 100) * 60 + ($now % 100);

        if ($startMinutes <= $endMinutes) {
            // 同日内 (例: 09:00-18:00)
            return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
        }

        // 日をまたぐ (例: 22:00-06:00)
        return $currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes;
    }

    /**
     * "HH:MM" 形式を分単位に変換する。
     */
    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    /**
     * 入力テキストに禁止キーワードが含まれるか。
     *
     * ルール値がカンマ区切りの場合、いずれかが一致すればマッチ。
     */
    private function isKeywordMatch(string $inputText, string $ruleValue): bool
    {
        $keywords = array_map('trim', explode(',', $ruleValue));
        $lowerInput = mb_strtolower($inputText);

        foreach ($keywords as $keyword) {
            if (mb_strtolower($keyword) !== '' && str_contains($lowerInput, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
