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

namespace Plugin\AiChatAssistant42\Command;

use Plugin\AiChatAssistant42\Service\McpServerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * AI チャットアシスタント MCP サーバーを起動するコンソールコマンド。
 *
 * Usage:
 *   php bin/console app:ai-chat-assistant
 *
 * STDIO transport で MCP プロトコルを開始し、
 * AI アシスタント（Claude Desktop 等）からのツール呼び出しに応答する。
 * Ctrl+C で停止。
 */
#[AsCommand(
    name: 'app:ai-chat-assistant',
    description: 'AI チャットアシスタント MCP サーバーを起動する',
)]
class AiChatAssistantCommand extends Command
{
    public function __construct(
        private McpServerService $mcpServerService,
    ) {
        parent::__construct();
    }

    /**
     * MCP サーバーを STDIO 起動する。
     *
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting AI Chat Assistant MCP Server...');
        $output->writeln('Press Ctrl+C to stop.');

        $this->mcpServerService->run();

        return Command::SUCCESS;
    }
}
