<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI チャットアシスタントのプラグイン設定テーブルを作成する。
 */
final class Version20260815000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plg_ai_chat_assistant_config table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_config (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                provider VARCHAR(32) NOT NULL DEFAULT 'openai',
                model VARCHAR(128) NOT NULL DEFAULT 'gpt-4o',
                api_key_openai VARCHAR(255) DEFAULT NULL,
                api_key_anthropic VARCHAR(255) DEFAULT NULL,
                api_key_gemini VARCHAR(255) DEFAULT NULL,
                system_prompt TEXT DEFAULT NULL,
                max_tokens INT UNSIGNED NOT NULL DEFAULT 4096,
                is_enabled SMALLINT NOT NULL DEFAULT 0,
                rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 30,
                create_date DATETIME NOT NULL,
                update_date DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS plg_ai_chat_assistant_config');
    }
}
