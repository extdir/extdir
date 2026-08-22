<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the DC2Type comment from extension.packagist_checked_at.
 *
 * Yesterday's migration wrote the column as
 * `DATETIME COMMENT '(DC2Type:datetime_immutable)'`, copied from an older migration in
 * this directory. That annotation is how Doctrine used to record the PHP type it would
 * hydrate a column into; current DBAL infers datetime_immutable from the mapping and
 * writes no comment, so the column it produced no longer matches the schema the mapping
 * describes.
 *
 * Nothing was broken by it: the column stores and reads the same values either way. It
 * failed `doctrine:schema:validate`, which CI runs after applying migrations precisely
 * to catch a database drifting from its mapping, and a check that is allowed to fail
 * for a harmless reason stops being a check.
 *
 * Written as a correction rather than an edit to the migration that caused it. That one
 * has already run in production, so changing it would leave the deployed database
 * carrying a comment no migration in the history admits to writing.
 */
final class Version20260823091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the obsolete DC2Type comment from extension.packagist_checked_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension CHANGE packagist_checked_at packagist_checked_at DATETIME DEFAULT NULL');
    }

    /**
     * Restores the comment, so rolling back lands on the schema the previous migration
     * actually produced rather than on a third state that never existed.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE extension CHANGE packagist_checked_at packagist_checked_at '
            ."DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'",
        );
    }
}
