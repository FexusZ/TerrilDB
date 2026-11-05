<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droits des jetons d\'API : écriture des données, de la structure, création de connexions (tous refusés par défaut).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD write_data BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD write_schema BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD create_connections BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP write_data');
        $this->addSql('ALTER TABLE api_token DROP write_schema');
        $this->addSql('ALTER TABLE api_token DROP create_connections');
    }
}
