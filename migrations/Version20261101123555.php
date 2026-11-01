<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101123555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rôle des comptes (le plus ancien devient administrateur).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD role VARCHAR(10) DEFAULT \'member\' NOT NULL');
        $this->addSql('UPDATE app_user SET role = \'admin\' WHERE id = (SELECT id FROM app_user ORDER BY created_at, id LIMIT 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP role');
    }
}
