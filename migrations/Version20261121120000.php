<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécutions de scripts détachées : le jeton d\'API qui les a lancées (#169).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE script_run ADD api_token_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE script_run DROP api_token_id');
    }
}
