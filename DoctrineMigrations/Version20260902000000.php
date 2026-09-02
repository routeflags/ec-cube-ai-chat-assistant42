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
 * 返信先メールのハッシュ化（I-30）: email_reply_address_hash + enc を追加し平文保存を廃止。
 *
 * - 既存平文は backfill せず NULL のまま（互換保持）。新規保存は hash+enc のみ。
 * - enc は 30日で Command により NULL 化、hash は保持（集計用）。
 */
final class Version20260902000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_reply_address_hash and enc for hashed storage (I-30).';
    }

    public function up(Schema $schema): void
    {
        $tableExists = $schema->hasTable('plg_ai_chat_assistant_log');
        if (!$tableExists) {
            return;
        }
        $table = $schema->getTable('plg_ai_chat_assistant_log');
        if (!$table->hasColumn('email_reply_address_hash')) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_log ADD email_reply_address_hash VARCHAR(64) DEFAULT NULL COMMENT "hmac_sha256 64hex" AFTER email_reply_address');
        }
        if (!$table->hasColumn('email_reply_address_enc')) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_log ADD email_reply_address_enc TEXT DEFAULT NULL COMMENT "AES-256-GCM encrypted email" AFTER email_reply_address_hash');
        }
        if (!$table->hasIndex('idx_log_email_hash')) {
            $this->addSql('CREATE INDEX idx_log_email_hash ON plg_ai_chat_assistant_log (email_reply_address_hash)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('plg_ai_chat_assistant_log')) {
            $table = $schema->getTable('plg_ai_chat_assistant_log');
            if ($table->hasIndex('idx_log_email_hash')) {
                $this->addSql('DROP INDEX idx_log_email_hash ON plg_ai_chat_assistant_log');
            }
        }
        $this->addSql('ALTER TABLE plg_ai_chat_assistant_log DROP COLUMN email_reply_address_enc');
        $this->addSql('ALTER TABLE plg_ai_chat_assistant_log DROP COLUMN email_reply_address_hash');
    }
}
