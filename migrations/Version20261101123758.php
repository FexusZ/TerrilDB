<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101123758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations à créer un compte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation (id UUID NOT NULL, note VARCHAR(100) NOT NULL, verifier VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_by_id UUID NOT NULL, used_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F11D61A2B03A8386 ON invitation (created_by_id)');
        $this->addSql('CREATE INDEX IDX_F11D61A24C2B72A8 ON invitation (used_by_id)');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A24C2B72A8 FOREIGN KEY (used_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2B03A8386');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A24C2B72A8');
        $this->addSql('DROP TABLE invitation');
    }
}
