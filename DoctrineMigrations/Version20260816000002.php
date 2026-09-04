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
 * チャットログにメール返信依頼機能のカラムを追加する。
 */
final class Version20260816000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_reply_address and email_replied_at columns to chat log.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('plg_ai_chat_assistant_log')) {
            $table = $schema->getTable('plg_ai_chat_assistant_log');
            if (!$table->hasColumn('email_reply_address')) {
                $this->addSql('ALTER TABLE plg_ai_chat_assistant_log ADD COLUMN email_reply_address VARCHAR(255) DEFAULT NULL');
            }
            if (!$table->hasColumn('email_replied_at')) {
                $this->addSql('ALTER TABLE plg_ai_chat_assistant_log ADD COLUMN email_replied_at DATETIME DEFAULT NULL');
            }
            if (!$table->hasIndex('idx_email_reply')) {
                $this->addSql('CREATE INDEX idx_email_reply ON plg_ai_chat_assistant_log (email_reply_address)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_email_reply ON plg_ai_chat_assistant_log');
        $this->addSql('ALTER TABLE plg_ai_chat_assistant_log DROP COLUMN email_reply_address');
        $this->addSql('ALTER TABLE plg_ai_chat_assistant_log DROP COLUMN email_replied_at');
    }
}
