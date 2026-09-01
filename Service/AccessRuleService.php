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

use Plugin\AiChatAssistant42\Repository\AccessRuleRepository;
use Psr\Log\LoggerInterface;

/**
 * アクセスルールに基づく入力フィルタリングを担当するサービス。
 *
 * ユーザーの入力に対して IP・時間帯・禁止キーワードの
 * 3種ルールを評価し、チャットへのアクセス可否を判定する。
 */
class AccessRuleService
{
    public function __construct(
        private AccessRuleRepository $accessRuleRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 入力値がルールに合致していないか（＝アクセス許可されるか）を判定する。
     *
     * すべての有効ルールを評価し、いずれかのルールで deny と判定された場合は false を返す。
     * throttle の場合はスロットリング処理のトリガーとしてログに記録する。
     *
     * @param string $input ユーザー入力文字列
     * @param string $type  ルール種別 (ip / time / block_keyword)
     */
    public function isAllowed(string $input, string $type): bool
    {
        try {
            $matchedRules = $this->accessRuleRepository->findMatchingRules($input, $type);

            if (empty($matchedRules)) {
                return true;
            }

            foreach ($matchedRules as $rule) {
                if ($rule->getAction() === 'deny') {
                    $this->logger->info('アクセスルールにより拒否されました', [
                        'rule_id' => $rule->getId(),
                        'rule_type' => $rule->getRuleType(),
                        'rule_value' => $rule->getRuleValue(),
                        'input' => mb_substr($input, 0, 100),
                    ]);
                    return false;
                }

                if ($rule->getAction() === 'throttle') {
                    $this->logger->info('アクセススロットリングが適用されました', [
                        'rule_id' => $rule->getId(),
                        'rule_type' => $rule->getRuleType(),
                        'rule_value' => $rule->getRuleValue(),
                    ]);
                    // throttle は許可するが、ログに記録して後続の処理で速度制限を適用可能
                }
            }

            return true;
        } catch (\Throwable $e) {
            // ルール評価に失敗した場合はフェイルセーフで許可する
            $this->logger->error('アクセスルール評価中にエラーが発生しました', [
                'error' => $e->getMessage(),
                'input' => mb_substr($input, 0, 100),
                'type' => $type,
            ]);
            return true;
        }
    }
}
