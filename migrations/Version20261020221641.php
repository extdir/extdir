<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds plumb_scan: a third party's supply-chain security findings per extension.
 *
 * Plumb scores PHP packages on supply-chain security and publishes a guide for every
 * check it runs. This is the one question the directory has never been able to answer:
 * extdir measures whether an extension works with your Shopware, whether its licence
 * permits use and whether anyone still maintains it, and none of those say whether its
 * release pipeline can be hijacked.
 *
 * A table of its own rather than columns on extension, for three reasons. The findings
 * are a list rather than a number. They arrive on somebody else's weekly cadence, so
 * their freshness is a separate fact from the rest of the row. And keeping them apart
 * makes it obvious at the schema level that nothing here feeds the ranking, which the
 * conflict-of-interest rule requires to stay algorithmic and public.
 *
 * checked_at is NOT NULL and scanned_at is nullable on purpose. "We asked and Plumb has
 * not looked at this one yet" and "Plumb scanned it and it scored badly" are different
 * facts, and the page has to be able to tell them apart.
 */
final class Version20261020221641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds plumb_scan for third-party supply-chain security findings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plumb_scan (id BIGINT AUTO_INCREMENT NOT NULL, checked_at DATETIME NOT NULL, scanned_at DATETIME DEFAULT NULL, security_score DOUBLE PRECISION DEFAULT NULL, reference_version VARCHAR(64) DEFAULT NULL, checks JSON NOT NULL, extension_id BIGINT NOT NULL, UNIQUE INDEX UNIQ_BBCDB776812D5EB (extension_id), INDEX idx_plumb_security (security_score), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE plumb_scan ADD CONSTRAINT FK_BBCDB776812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plumb_scan DROP FOREIGN KEY FK_BBCDB776812D5EB');
        $this->addSql('DROP TABLE plumb_scan');
    }
}
