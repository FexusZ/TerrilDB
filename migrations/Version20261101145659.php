<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101145659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phrase de récupération (copie de la clé enveloppée par la phrase).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD recovery_key TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD recovery_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP recovery_key');
        $this->addSql('ALTER TABLE app_user DROP recovery_created_at');
    }
}
