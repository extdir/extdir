<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records when an extension's icon was last confirmed to exist.
 *
 * No backfill: the value is a claim about a remote file, and the only honest way to
 * obtain it is to ask. `app:verify-icons` fills it in on the next crawl, and until
 * then every extension shows its generated monogram — which is what an unverified
 * icon should look like anyway.
 */
final class Version20260819225638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when each extension icon was last confirmed to exist.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD icon_verified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP icon_verified_at');
    }
}
