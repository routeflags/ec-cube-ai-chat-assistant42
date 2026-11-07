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

use RuntimeException;

/**
 * OAuth のエラー応答（RFC 6749 4.1.2.1 / 5.2）。
 *
 * `error` はクライアントにそのまま返せる値に限る。内部情報や例外メッセージは含めない。
 */
final class McpOAuthException extends RuntimeException
{
    public function __construct(
        private string $error,
        string $description = '',
    ) {
        parent::__construct($description !== '' ? $description : $error);
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getErrorDescription(): string
    {
        return $this->getMessage();
    }
}
