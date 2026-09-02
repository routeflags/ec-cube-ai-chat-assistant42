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
 * ナレッジベースと自動応答シナリオのテーブルを作成する。
 */
final class Version20260816000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plg_ai_chat_assistant_knowledge and plg_ai_chat_assistant_scenario tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_knowledge (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                category VARCHAR(64) DEFAULT NULL,
                is_active SMALLINT NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                create_date DATETIME NOT NULL,
                update_date DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_plg_ai_chat_assistant_knowledge_category (category),
                INDEX idx_plg_ai_chat_assistant_knowledge_active (is_active)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_scenario (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                trigger_keyword VARCHAR(128) NOT NULL,
                trigger_type VARCHAR(32) NOT NULL DEFAULT 'exact',
                response_text TEXT NOT NULL,
                response_type VARCHAR(32) NOT NULL DEFAULT 'text',
                priority INT NOT NULL DEFAULT 0,
                is_active SMALLINT NOT NULL DEFAULT 1,
                create_date DATETIME NOT NULL,
                update_date DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_plg_ai_chat_assistant_scenario_trigger (trigger_keyword),
                INDEX idx_plg_ai_chat_assistant_scenario_active (is_active)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_scenario');
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_knowledge');
    }
}
