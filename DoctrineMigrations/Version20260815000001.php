<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI チャットの会話ログテーブルを作成する。
 */
final class Version20260815000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plg_ai_chat_assistant_log table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_log (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                session_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                model VARCHAR(128) NOT NULL,
                user_message TEXT NOT NULL,
                assistant_reply TEXT NOT NULL,
                tools_used JSON DEFAULT NULL,
                response_time_ms INT UNSIGNED DEFAULT NULL,
                token_input INT UNSIGNED DEFAULT NULL,
                token_output INT UNSIGNED DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                satisfaction_rating SMALLINT DEFAULT NULL,
                is_resolved SMALLINT NOT NULL DEFAULT 0,
                error_type VARCHAR(32) DEFAULT NULL,
                product_id INT UNSIGNED DEFAULT NULL,
                order_id INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL,
                synced_at DATETIME DEFAULT NULL,
                PRIMARY KEY(id),
                INDEX idx_plg_ai_chat_assistant_log_session (session_id),
                INDEX idx_plg_ai_chat_assistant_log_created (created_at),
                INDEX idx_plg_ai_chat_assistant_log_provider (provider, model)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_log');
    }
}
