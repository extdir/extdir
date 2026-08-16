<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816232109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add artifact provenance records and build requests for the distribution pipeline.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE artifact (id BIGINT AUTO_INCREMENT NOT NULL, download_url VARCHAR(512) NOT NULL, source VARCHAR(32) NOT NULL, commit_sha VARCHAR(64) DEFAULT NULL, sha256 VARCHAR(64) DEFAULT NULL, size_bytes BIGINT DEFAULT NULL, build_log_url VARCHAR(512) DEFAULT NULL, sbom_url VARCHAR(512) DEFAULT NULL, shopware_cli_version VARCHAR(32) DEFAULT NULL, recorded_at DATETIME NOT NULL, release_id BIGINT NOT NULL, UNIQUE INDEX uniq_artifact_release (release_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE build_request (id BIGINT AUTO_INCREMENT NOT NULL, state VARCHAR(16) NOT NULL, callback_token VARCHAR(64) NOT NULL, workflow_run_id VARCHAR(64) DEFAULT NULL, failure_reason LONGTEXT DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, requested_at DATETIME NOT NULL, dispatched_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, release_id BIGINT NOT NULL, INDEX idx_build_state (state), UNIQUE INDEX uniq_build_release (release_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE artifact ADD CONSTRAINT FK_48E5602CB12A727D FOREIGN KEY (release_id) REFERENCES extension_release (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE build_request ADD CONSTRAINT FK_1602F213B12A727D FOREIGN KEY (release_id) REFERENCES extension_release (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE artifact DROP FOREIGN KEY FK_48E5602CB12A727D');
        $this->addSql('ALTER TABLE build_request DROP FOREIGN KEY FK_1602F213B12A727D');
        $this->addSql('DROP TABLE artifact');
        $this->addSql('DROP TABLE build_request');
    }
}
