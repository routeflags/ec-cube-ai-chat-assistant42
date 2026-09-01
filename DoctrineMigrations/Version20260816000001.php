<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 通知ルールテーブルとアクセスルールテーブルを作成する。
 */
final class Version20260816000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification and access_rule tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_notification (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                notification_type VARCHAR(32) NOT NULL,
                trigger_event VARCHAR(64) NOT NULL,
                config_json JSON DEFAULT NULL,
                is_active SMALLINT NOT NULL DEFAULT 1,
                create_date DATETIME NOT NULL,
                update_date DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_plg_ai_chat_assistant_notification_event (trigger_event),
                INDEX idx_plg_ai_chat_assistant_notification_active (is_active)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_access_rule (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                rule_type VARCHAR(32) NOT NULL,
                rule_value VARCHAR(255) NOT NULL,
                action VARCHAR(32) NOT NULL DEFAULT 'deny',
                is_active SMALLINT NOT NULL DEFAULT 1,
                create_date DATETIME NOT NULL,
                update_date DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_plg_ai_chat_assistant_access_rule_type (rule_type),
                INDEX idx_plg_ai_chat_assistant_access_rule_active (is_active)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_access_rule');
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_notification');
    }
}
