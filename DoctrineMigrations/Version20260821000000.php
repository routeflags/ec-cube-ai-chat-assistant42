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

namespace Plugin\AiChatAssistant42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ポジティブフィードバック機能用のフィードバックテーブルを作成する。
 */
final class Version20260821000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plg_ai_chat_assistant_feedback table for positive/negative feedback.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_feedback (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                session_id VARCHAR(64) NOT NULL,
                feedback VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetimetz)',
                UNIQUE INDEX uniq_session_feedback (session_id),
                INDEX idx_feedback (feedback),
                INDEX idx_feedback_created_at (created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_feedback');
    }
}
