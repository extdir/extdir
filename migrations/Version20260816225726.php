<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816225726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add search_text and move the FULLTEXT index onto it, so non-English metadata becomes searchable.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX ft_extension_search ON extension');
        $this->addSql('ALTER TABLE extension ADD search_text LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE FULLTEXT INDEX ft_extension_search ON extension (search_text)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX ft_extension_search ON extension');
        $this->addSql('ALTER TABLE extension DROP search_text');
        $this->addSql('CREATE FULLTEXT INDEX ft_extension_search ON extension (package_name, label, description)');
    }
}
