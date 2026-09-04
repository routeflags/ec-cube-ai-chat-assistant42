<?php

declare(strict_types=1);

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

namespace Plugin\AiChatAssistant42\Service;

use RuntimeException;

/**
 * レート制限超過例外。
 */
class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        private int $limit,
        private int $retryAfterSeconds = 60,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf('Rate limit exceeded. Retry after %d seconds.', $retryAfterSeconds));
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
