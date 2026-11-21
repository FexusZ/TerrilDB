<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexions Vitess sans réplica : date de la dernière recherche et réplica trouvé (rappel hebdomadaire, #40).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE database_connection ADD replica_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE database_connection ADD replica_found BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE database_connection DROP replica_checked_at');
        $this->addSql('ALTER TABLE database_connection DROP replica_found');
    }
}
