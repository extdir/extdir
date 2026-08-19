<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Screenshot URLs for the extension gallery.
 *
 * NOT NULL with a default of an empty array, so every existing row reads as "no
 * screenshots known yet" rather than null — the difference matters because the
 * template hides the gallery on an empty list, and a null would be a third state
 * meaning the same thing.
 *
 * Populated by the nightly enrichment; no backfill is possible here, since the URLs
 * come from reading each repository's README and store directory.
 */
final class Version20260819233107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extension.gallery_images for forge-hosted screenshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD gallery_images JSON NOT NULL');

        // This database does not run in strict mode, so the ALTER above fills the
        // existing rows with an empty string rather than refusing. An empty string is
        // not JSON: json_decode returns null, and the entity's getter is typed array.
        // Writing the literal is a one-line insurance against a mode setting that has
        // already caused silent truncation elsewhere.
        $this->addSql("UPDATE extension SET gallery_images = '[]' WHERE gallery_images IS NULL OR gallery_images = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP gallery_images');
    }
}
