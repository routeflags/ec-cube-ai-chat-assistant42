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
 * MCP 接続設定（アクセストークン・CORS 許可オリジン）を追加する。
 *
 * どちらも nullable で、未設定なら従来どおり無認証・全オリジン許可。
 */
final class Version20260910000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add MCP access token and allowed origins to the plugin config table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('plg_ai_chat_assistant_config');
        if (!$table->hasColumn('mcp_access_token')) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_config ADD COLUMN mcp_access_token VARCHAR(128) DEFAULT NULL');
        }
        if (!$table->hasColumn('mcp_allowed_origins')) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_config ADD COLUMN mcp_allowed_origins VARCHAR(512) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('plg_ai_chat_assistant_config');
        if ($table->hasColumn('mcp_access_token')) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_config DROP COLUMN mcp_access_token');
        }
        if ($table->hasColumn('mcp_allowed_origins')) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_config DROP COLUMN mcp_allowed_origins');
        }
    }
}
