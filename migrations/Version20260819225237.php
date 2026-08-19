<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The repository's default branch, denormalised onto the extension.
 *
 * The branch is needed to build raw-file URLs for extension icons. It was already
 * being crawled into repository_snapshot and thrown away for this purpose, so the
 * column is backfilled from the newest snapshot rather than waiting a crawl cycle —
 * 435 of the extensions already have the answer stored.
 *
 * Guessing was measured first, on the assumption that `main` with a `master`
 * fallback would be close enough: it found the icon for 23 of 40 sampled
 * repositories, and the corpus also contains `develop`, `trunk`, `stable` and
 * `main_65`. Guessing is not close enough.
 */
final class Version20260819225237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extension.default_branch, backfilled from the newest repository_snapshot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD default_branch VARCHAR(64) DEFAULT NULL');

        // Newest snapshot that actually recorded a branch. A repository whose latest
        // crawl failed keeps the last branch we did see, which is a better guess than
        // null and far better than assuming `main`.
        $this->addSql(<<<'SQL'
            UPDATE extension e
            SET e.default_branch = (
                SELECT s.default_branch
                FROM repository_snapshot s
                WHERE s.extension_id = e.id
                  AND s.default_branch IS NOT NULL
                ORDER BY s.captured_at DESC, s.id DESC
                LIMIT 1
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP default_branch');
    }
}
