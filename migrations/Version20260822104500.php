<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Packagist install counts, for the boards.
 *
 * Denormalised onto the extension for the same reason stars are: the boards sort the
 * whole corpus by these and a join per row would buy nothing.
 *
 * Three columns rather than one. A lifetime total only ever accumulates, so on its own
 * it ranks by age as much as by use; the trailing-thirty-day figure is what answers
 * whether anyone is installing the extension now, and the two disagree often enough to
 * be worth showing side by side.
 *
 * packagist_checked_at carries the difference between "nobody installs this" and "we
 * have no way to know". 170 of the indexed extensions are not on Packagist at all, and
 * without this column they would be indistinguishable from packages with no installs —
 * which would be a straightforward slander of exactly the agency-built repositories the
 * search channel was added to find.
 */
final class Version20260822104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Packagist download counts to extension, for the boards.';
    }

    public function up(Schema $schema): void
    {
        // Defaults are written out rather than left to Doctrine. This database does not
        // run in strict mode, and a NOT NULL integer column added without one has
        // already produced silently wrong values here before.
        $this->addSql('ALTER TABLE extension ADD downloads_total INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE extension ADD downloads_monthly INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE extension ADD packagist_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // The boards order by these across the whole catalogue on every uncached
        // request. Small table today, but the query is a sort of every row and the
        // index costs nothing to carry.
        $this->addSql('CREATE INDEX IDX_extension_downloads_total ON extension (downloads_total)');
        $this->addSql('CREATE INDEX IDX_extension_downloads_monthly ON extension (downloads_monthly)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_extension_downloads_total ON extension');
        $this->addSql('DROP INDEX IDX_extension_downloads_monthly ON extension');
        $this->addSql('ALTER TABLE extension DROP downloads_total');
        $this->addSql('ALTER TABLE extension DROP downloads_monthly');
        $this->addSql('ALTER TABLE extension DROP packagist_checked_at');
    }
}
