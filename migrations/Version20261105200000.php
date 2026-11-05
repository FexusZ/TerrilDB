<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons d\'API émis pour toutes les connexions du compte (liste actualisable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD all_connections BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP all_connections');
    }
}
