<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * レート制限のIP分離と汎用化対応: plg_ai_chat_assistant_log に client_ip を追加し複合インデックスを作成。
 */
final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client_ip to plg_ai_chat_assistant_log for per-IP rate limit and composite indexes.';
    }

    public function up(Schema $schema): void
    {
        // 冪等性: 既存カラム/インデックスがあればスキップ（手動リトライ対応）
        $tableExists = $schema->hasTable('plg_ai_chat_assistant_log');
        $hasColumn = $tableExists && $schema->getTable('plg_ai_chat_assistant_log')->hasColumn('client_ip');
        if (!$hasColumn) {
            $this->addSql('ALTER TABLE plg_ai_chat_assistant_log ADD client_ip VARCHAR(45) DEFAULT NULL COMMENT "client IP for rate limit" AFTER session_id');
        }
        if ($tableExists) {
            $table = $schema->getTable('plg_ai_chat_assistant_log');
            if (!$table->hasIndex('idx_log_session_created')) {
                $this->addSql('CREATE INDEX idx_log_session_created ON plg_ai_chat_assistant_log (session_id, created_at)');
            }
            if (!$table->hasIndex('idx_log_ip_created')) {
                $this->addSql('CREATE INDEX idx_log_ip_created ON plg_ai_chat_assistant_log (client_ip, created_at)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_log_ip_created ON plg_ai_chat_assistant_log');
        $this->addSql('DROP INDEX idx_log_session_created ON plg_ai_chat_assistant_log');
        $this->addSql('ALTER TABLE plg_ai_chat_assistant_log DROP COLUMN client_ip');
    }
}
