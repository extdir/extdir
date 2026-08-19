<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819142334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE complaint (id BIGINT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, reporter VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, resolution LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, extension_id BIGINT NOT NULL, resolved_by_id BIGINT DEFAULT NULL, INDEX IDX_5F2732B5812D5EB (extension_id), INDEX IDX_5F2732B56713A32B (resolved_by_id), INDEX idx_complaint_queue (status, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE complaint ADD CONSTRAINT FK_5F2732B5812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id)');
        $this->addSql('ALTER TABLE complaint ADD CONSTRAINT FK_5F2732B56713A32B FOREIGN KEY (resolved_by_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE complaint DROP FOREIGN KEY FK_5F2732B5812D5EB');
        $this->addSql('ALTER TABLE complaint DROP FOREIGN KEY FK_5F2732B56713A32B');
        $this->addSql('DROP TABLE complaint');
    }
}
