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

namespace Plugin\AiChatAssistant42\Command;

use Plugin\AiChatAssistant42\Service\LogSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * 未同期のチャットログをリモートエンドポイントに同期するコンソールコマンド。
 *
 * Usage:
 *   php bin/console app:ai-chat-assistant:sync-logs
 *   php bin/console app:ai-chat-assistant:sync-logs --batch-size=50
 */
#[AsCommand(
    name: 'app:ai-chat-assistant:sync-logs',
    description: '未同期のチャットログをリモートエンドポイントに同期する',
)]
class SyncChatLogsCommand extends Command
{
    public function __construct(
        private LogSyncService $logSyncService,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->addOption(
            'batch-size',
            'b',
            InputOption::VALUE_REQUIRED,
            '1回で取得する最大レコード数',
            100,
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');

        $output->writeln(sprintf('Syncing chat logs (batch size: %d)...', $batchSize));

        $syncedCount = $this->logSyncService->sync($batchSize);

        $output->writeln(sprintf('Done. %d record(s) synced.', $syncedCount));

        return Command::SUCCESS;
    }
}
