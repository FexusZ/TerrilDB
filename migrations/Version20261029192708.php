<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029192708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table des utilisateurs (e-mail et nom d\'utilisateur uniques, TOTP, codes de récupération).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(254) NOT NULL, username VARCHAR(32) NOT NULL, password_hash TEXT NOT NULL, kdf_parameters VARCHAR(255) NOT NULL, totp_secret TEXT DEFAULT NULL, totp_last_step INT DEFAULT 0 NOT NULL, recovery_code_hashes JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9F85E0677 ON app_user (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_user');
    }
}
