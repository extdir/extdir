<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817113256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which evidence an effective licence rests on, so a detected LICENSE file outranks a stale composer.json declaration.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE extension ADD license_evidence VARCHAR(32) DEFAULT \'composer_json\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE extension DROP license_evidence');
    }
}
