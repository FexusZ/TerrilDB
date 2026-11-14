<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112031000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons de confirmation à usage unique d\'une écriture préparée (empreintes seulement, supprimés avec le jeton d\'API).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE confirmation_ticket (id UUID NOT NULL, api_token_id UUID NOT NULL, ticket_hash VARCHAR(64) NOT NULL, connection_id UUID NOT NULL, kind VARCHAR(16) NOT NULL, text_hash VARCHAR(64) NOT NULL, decision VARCHAR(24) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_485F2A73E3637780 ON confirmation_ticket (ticket_hash)');
        $this->addSql('CREATE INDEX idx_confirmation_token_expiry ON confirmation_ticket (api_token_id, expires_at)');
        $this->addSql('CREATE INDEX IDX_485F2A7392E52D36 ON confirmation_ticket (api_token_id)');
        $this->addSql('ALTER TABLE confirmation_ticket ADD CONSTRAINT FK_485F2A7392E52D36 FOREIGN KEY (api_token_id) REFERENCES api_token (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE confirmation_ticket');
    }
}
