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
 * MCP 監査テーブル追加（WebMCP設計書 機能2b）。
 *
 * 引数・応答内容・個人情報は記録しない。
 * tool名・結果区分・所要時間・エラーコード・IPハッシュのみ。
 */
final class Version20260908000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plg_ai_chat_assistant_mcp_audit table for MCP audit logging.';
    }

    public function up(Schema $schema): void
    {
        // hasTable() は Entity メタデータ由来で true を返す場合があるため
        // 生SQL の IF NOT EXISTS でガードする
        $this->addSql('CREATE TABLE IF NOT EXISTS plg_ai_chat_assistant_mcp_audit ('
            . 'id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, '
            . 'tool_name VARCHAR(64) NOT NULL, '
            . 'result VARCHAR(16) NOT NULL, '
            . 'error_code VARCHAR(32) DEFAULT NULL, '
            . 'duration_ms INT UNSIGNED DEFAULT NULL, '
            . 'client_ip_hash VARCHAR(64) DEFAULT NULL, '
            . 'created_at DATETIME COMMENT "(DC2Type:datetimetz)" NOT NULL, '
            . 'PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX idx_mcp_audit_tool_created ON plg_ai_chat_assistant_mcp_audit (tool_name, created_at)');
        $this->addSql('CREATE INDEX idx_mcp_audit_created ON plg_ai_chat_assistant_mcp_audit (created_at)');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('plg_ai_chat_assistant_mcp_audit')) {
            $this->addSql('DROP TABLE plg_ai_chat_assistant_mcp_audit');
        }
    }
}
