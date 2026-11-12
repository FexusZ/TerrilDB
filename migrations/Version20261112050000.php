<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes : date de désactivation par un administrateur et date de la dernière connexion (toutes deux facultatives).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD disabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
        $this->addSql('ALTER TABLE app_user DROP disabled_at');
    }
}
