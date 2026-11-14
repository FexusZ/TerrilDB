<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droit de jeton « diagnostic » : lecture de la supervision du serveur cible (refusé par défaut).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD diagnostic BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP diagnostic');
    }
}
