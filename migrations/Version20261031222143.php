<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031222143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexions aux bases cibles (paramètres chiffrés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE database_connection (id UUID NOT NULL, name VARCHAR(100) NOT NULL, engine VARCHAR(16) NOT NULL, encrypted_parameters TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_database_connection_owner_name ON database_connection (owner_id, name)');
        $this->addSql('CREATE INDEX IDX_BD7E76277E3C61F9 ON database_connection (owner_id)');
        $this->addSql('ALTER TABLE database_connection ADD CONSTRAINT FK_BD7E76277E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE database_connection DROP CONSTRAINT FK_BD7E76277E3C61F9');
        $this->addSql('DROP TABLE database_connection');
    }
}
