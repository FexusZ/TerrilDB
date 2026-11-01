<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101112434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons d’accès à l’API (empreinte et clé enveloppée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (id UUID NOT NULL, name VARCHAR(100) NOT NULL, verifier VARCHAR(64) NOT NULL, wrapped_key TEXT NOT NULL, connection_ids JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, version INT DEFAULT 1 NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7BA2F5EB7E3C61F9 ON api_token (owner_id)');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EB7E3C61F9');
        $this->addSql('DROP TABLE api_token');
    }
}
