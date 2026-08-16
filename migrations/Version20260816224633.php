<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816224633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Denormalise star and fork counts onto extensions for listing and sorting.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE extension ADD stars INT DEFAULT 0 NOT NULL, ADD forks INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE extension DROP stars, DROP forks');
    }
}
