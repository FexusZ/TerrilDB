<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons d\'API sans expiration (expires_at facultatif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ALTER expires_at DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE api_token SET expires_at = created_at + interval '365 days' WHERE expires_at IS NULL");
        $this->addSql('ALTER TABLE api_token ALTER expires_at SET NOT NULL');
    }
}
