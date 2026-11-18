<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suivi des exécutions de scripts SQL (#169) : état, instructions (sans texte), signe de vie, empreinte du script.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE script_run (id UUID NOT NULL, connection_id UUID NOT NULL, text_hash VARCHAR(64) NOT NULL, total INT NOT NULL, completed INT NOT NULL, dry_run BOOLEAN NOT NULL, status VARCHAR(16) NOT NULL, cancel_requested BOOLEAN NOT NULL, statements JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_script_run_status_heartbeat ON script_run (status, heartbeat_at)');
        $this->addSql('CREATE INDEX idx_script_run_user_created ON script_run (user_id, created_at)');
        $this->addSql('CREATE INDEX IDX_50685DBFA76ED395 ON script_run (user_id)');
        $this->addSql('ALTER TABLE script_run ADD CONSTRAINT FK_50685DBFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE script_run');
    }
}
