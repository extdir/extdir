<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816211221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial catalog schema: vendors, extensions, releases, compatibility claims, license findings and repository snapshots.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category (id BIGINT AUTO_INCREMENT NOT NULL, category_key VARCHAR(64) NOT NULL, label VARCHAR(128) NOT NULL, description LONGTEXT DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_category_key (category_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE compatibility_claim (id BIGINT AUTO_INCREMENT NOT NULL, satisfied TINYINT NOT NULL, tier VARCHAR(16) NOT NULL, release_id BIGINT NOT NULL, extension_id BIGINT NOT NULL, shopware_version_id BIGINT NOT NULL, INDEX IDX_25A99C85B12A727D (release_id), INDEX IDX_25A99C85812D5EB (extension_id), INDEX IDX_25A99C853EAD50C1 (shopware_version_id), INDEX idx_claim_lookup (extension_id, shopware_version_id, satisfied), UNIQUE INDEX uniq_claim_release_version (release_id, shopware_version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE extension (id BIGINT AUTO_INCREMENT NOT NULL, package_name VARCHAR(191) NOT NULL, slug VARCHAR(191) NOT NULL, label VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, labels JSON NOT NULL, descriptions JSON NOT NULL, repository_url VARCHAR(255) DEFAULT NULL, source_host VARCHAR(16) NOT NULL, plugin_class VARCHAR(255) DEFAULT NULL, icon_path VARCHAR(255) DEFAULT NULL, manufacturer_link VARCHAR(255) DEFAULT NULL, support_link VARCHAR(255) DEFAULT NULL, license_spdx VARCHAR(64) DEFAULT NULL, license_status VARCHAR(16) NOT NULL, index_status VARCHAR(16) NOT NULL, maintenance_status VARCHAR(16) NOT NULL, abandoned TINYINT DEFAULT 0 NOT NULL, replacement_package VARCHAR(191) DEFAULT NULL, rank_score DOUBLE PRECISION NOT NULL, delist_reason LONGTEXT DEFAULT NULL, first_seen_at DATETIME NOT NULL, last_crawled_at DATETIME DEFAULT NULL, last_commit_at DATETIME DEFAULT NULL, last_release_at DATETIME DEFAULT NULL, vendor_id BIGINT NOT NULL, INDEX IDX_9FB73D77F603EE73 (vendor_id), INDEX idx_extension_facets (index_status, license_status, maintenance_status), INDEX idx_extension_rank (rank_score), INDEX idx_extension_crawl (last_crawled_at), FULLTEXT INDEX ft_extension_search (package_name, label, description), UNIQUE INDEX uniq_extension_package (package_name), UNIQUE INDEX uniq_extension_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE extension_category (extension_id BIGINT NOT NULL, category_id BIGINT NOT NULL, INDEX IDX_AB6461A9812D5EB (extension_id), INDEX IDX_AB6461A912469DE2 (category_id), PRIMARY KEY (extension_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE extension_release (id BIGINT AUTO_INCREMENT NOT NULL, version VARCHAR(64) NOT NULL, version_raw VARCHAR(64) NOT NULL, stable TINYINT DEFAULT 1 NOT NULL, composer_json JSON NOT NULL, shopware_constraint VARCHAR(255) DEFAULT NULL, constraint_source VARCHAR(32) NOT NULL, constraint_tier VARCHAR(16) NOT NULL, dist_url VARCHAR(512) DEFAULT NULL, dist_source VARCHAR(32) DEFAULT NULL, source_reference VARCHAR(64) DEFAULT NULL, released_at DATETIME DEFAULT NULL, extension_id BIGINT NOT NULL, INDEX IDX_D1B72D77812D5EB (extension_id), INDEX idx_release_released_at (released_at), UNIQUE INDEX uniq_release_version (extension_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE license_finding (id BIGINT AUTO_INCREMENT NOT NULL, spdx VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(32) NOT NULL, confidence DOUBLE PRECISION DEFAULT NULL, detector_name VARCHAR(64) DEFAULT NULL, detector_version VARCHAR(32) DEFAULT NULL, commit_sha VARCHAR(64) DEFAULT NULL, raw_value LONGTEXT DEFAULT NULL, detected_at DATETIME NOT NULL, extension_id BIGINT NOT NULL, INDEX IDX_4E00586D812D5EB (extension_id), INDEX idx_finding_extension (extension_id, detected_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE repository_snapshot (id BIGINT AUTO_INCREMENT NOT NULL, stars INT DEFAULT 0 NOT NULL, forks INT DEFAULT 0 NOT NULL, open_issues INT DEFAULT 0 NOT NULL, closed_issues INT DEFAULT 0 NOT NULL, median_response_hours DOUBLE PRECISION DEFAULT NULL, last_commit_at DATETIME DEFAULT NULL, default_branch VARCHAR(64) DEFAULT NULL, ci_status VARCHAR(32) DEFAULT NULL, archived TINYINT DEFAULT 0 NOT NULL, captured_at DATETIME NOT NULL, extension_id BIGINT NOT NULL, INDEX IDX_5BEBC79D812D5EB (extension_id), INDEX idx_snapshot_extension (extension_id, captured_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE shopware_version (id BIGINT AUTO_INCREMENT NOT NULL, major_minor VARCHAR(16) NOT NULL, lower_bound VARCHAR(32) NOT NULL, upper_bound VARCHAR(32) NOT NULL, released_at DATE NOT NULL, end_of_life_at DATE DEFAULT NULL, current TINYINT DEFAULT 0 NOT NULL, shown_in_matrix TINYINT DEFAULT 1 NOT NULL, sort_order INT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_shopware_version_minor (major_minor), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vendor (id BIGINT AUTO_INCREMENT NOT NULL, name VARCHAR(128) NOT NULL, slug VARCHAR(128) NOT NULL, maintainer_operated TINYINT DEFAULT 0 NOT NULL, homepage VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_vendor_name (name), UNIQUE INDEX uniq_vendor_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE compatibility_claim ADD CONSTRAINT FK_25A99C85B12A727D FOREIGN KEY (release_id) REFERENCES extension_release (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compatibility_claim ADD CONSTRAINT FK_25A99C85812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compatibility_claim ADD CONSTRAINT FK_25A99C853EAD50C1 FOREIGN KEY (shopware_version_id) REFERENCES shopware_version (id)');
        $this->addSql('ALTER TABLE extension ADD CONSTRAINT FK_9FB73D77F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id)');
        $this->addSql('ALTER TABLE extension_category ADD CONSTRAINT FK_AB6461A9812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE extension_category ADD CONSTRAINT FK_AB6461A912469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE extension_release ADD CONSTRAINT FK_D1B72D77812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE license_finding ADD CONSTRAINT FK_4E00586D812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repository_snapshot ADD CONSTRAINT FK_5BEBC79D812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compatibility_claim DROP FOREIGN KEY FK_25A99C85B12A727D');
        $this->addSql('ALTER TABLE compatibility_claim DROP FOREIGN KEY FK_25A99C85812D5EB');
        $this->addSql('ALTER TABLE compatibility_claim DROP FOREIGN KEY FK_25A99C853EAD50C1');
        $this->addSql('ALTER TABLE extension DROP FOREIGN KEY FK_9FB73D77F603EE73');
        $this->addSql('ALTER TABLE extension_category DROP FOREIGN KEY FK_AB6461A9812D5EB');
        $this->addSql('ALTER TABLE extension_category DROP FOREIGN KEY FK_AB6461A912469DE2');
        $this->addSql('ALTER TABLE extension_release DROP FOREIGN KEY FK_D1B72D77812D5EB');
        $this->addSql('ALTER TABLE license_finding DROP FOREIGN KEY FK_4E00586D812D5EB');
        $this->addSql('ALTER TABLE repository_snapshot DROP FOREIGN KEY FK_5BEBC79D812D5EB');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE compatibility_claim');
        $this->addSql('DROP TABLE extension');
        $this->addSql('DROP TABLE extension_category');
        $this->addSql('DROP TABLE extension_release');
        $this->addSql('DROP TABLE license_finding');
        $this->addSql('DROP TABLE repository_snapshot');
        $this->addSql('DROP TABLE shopware_version');
        $this->addSql('DROP TABLE vendor');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
