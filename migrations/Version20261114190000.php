<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droits de jeton « readSchema » et « export » : refusés par défaut, donc un jeton existant garde exactement ses droits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD read_schema BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD export_data BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP read_schema');
        $this->addSql('ALTER TABLE api_token DROP export_data');
    }
}
