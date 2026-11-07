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
 * MCP OAuth の認可コード・トークン保存テーブル。
 *
 * 平文は保存せず SHA-256 ハッシュのみを持つ。
 * 実装設計は Documents/MCP_OAUTH_DESIGN.md を参照。
 */
final class Version20260910000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plg_ai_chat_assistant_mcp_oauth table for MCP OAuth codes and tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_mcp_oauth ('
            . 'id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, '
            . 'code_hash CHAR(64) DEFAULT NULL, '
            . 'code_expires_at DATETIME DEFAULT NULL, '
            . 'code_used_at DATETIME DEFAULT NULL, '
            . 'access_hash CHAR(64) DEFAULT NULL, '
            . 'access_expires_at DATETIME DEFAULT NULL, '
            . 'refresh_hash CHAR(64) DEFAULT NULL, '
            . 'refresh_expires_at DATETIME DEFAULT NULL, '
            . 'client_id VARCHAR(255) NOT NULL, '
            . 'redirect_uri VARCHAR(255) NOT NULL, '
            . 'code_challenge VARCHAR(128) DEFAULT NULL, '
            . 'code_challenge_method VARCHAR(8) DEFAULT NULL, '
            . 'scopes VARCHAR(255) NOT NULL, '
            . 'customer_id INT DEFAULT NULL, '
            . 'resource VARCHAR(255) NOT NULL, '
            . 'revoked_at DATETIME DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX idx_mcp_oauth_code ON plg_ai_chat_assistant_mcp_oauth (code_hash)');
        $this->addSql('CREATE INDEX idx_mcp_oauth_access ON plg_ai_chat_assistant_mcp_oauth (access_hash)');
        $this->addSql('CREATE INDEX idx_mcp_oauth_refresh ON plg_ai_chat_assistant_mcp_oauth (refresh_hash)');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('plg_ai_chat_assistant_mcp_oauth')) {
            $this->addSql('DROP TABLE plg_ai_chat_assistant_mcp_oauth');
        }
    }
}
