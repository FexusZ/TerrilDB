<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101114744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal d’audit (événements immuables).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_event (id UUID NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, actor VARCHAR(8) NOT NULL, token_id UUID DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, action VARCHAR(40) NOT NULL, outcome VARCHAR(8) NOT NULL, subject_id UUID DEFAULT NULL, details JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_user_time ON audit_event (user_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_event');
    }
}
