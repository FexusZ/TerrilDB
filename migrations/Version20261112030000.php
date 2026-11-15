<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons d\'API : option « une seule étape » pour écrire (refusée par défaut, les jetons existants passent donc en deux étapes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD single_step BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP single_step');
    }
}
