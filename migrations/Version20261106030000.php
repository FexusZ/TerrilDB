<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexions : couleur de repère et rang choisi par le propriétaire (les rangs existants suivent l\'ancien tri par nom).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE database_connection ADD color VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE database_connection ADD position INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE database_connection c SET position = ranked.rank FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY owner_id ORDER BY name) - 1 AS rank FROM database_connection) ranked WHERE ranked.id = c.id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE database_connection DROP position');
        $this->addSql('ALTER TABLE database_connection DROP color');
    }
}
