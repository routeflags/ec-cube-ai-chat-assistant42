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

use Plugin\AiChatAssistant42\Repository\ChatLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai-chat-assistant:cleanup-email', description: '30日経過のメール返信先暗号文を削除する（hashは保持）')]
class CleanupEmailCommand extends Command
{
    public function __construct(private ChatLogRepository $chatLogRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_OPTIONAL, '保持日数（デフォルト30）', 30);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, '削除せず件数のみ表示');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        $dryRun = (bool) $input->getOption('dry-run');
        $before = new \DateTimeImmutable(sprintf('-%d days', max(1, $days)));

        if ($dryRun) {
            $io->note(sprintf('dry-run: %s より前の enc を削除対象とします', $before->format('Y-m-d H:i:s')));
            $io->success('dry-run のため削除は実行しません。件数確認は DB で行ってください。');

            return Command::SUCCESS;
        }

        $count = $this->chatLogRepository->purgeExpiredEmailEnc($before);
        $io->success(sprintf('%d 件のメール暗号文を削除しました（%d日経過分）', $count, $days));

        return Command::SUCCESS;
    }
}
