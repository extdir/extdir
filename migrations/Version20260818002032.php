<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260818002032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users, ownership claims and the moderation audit log for maintainer self-service.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id BIGINT AUTO_INCREMENT NOT NULL, github_id BIGINT NOT NULL, login VARCHAR(128) NOT NULL, avatar_url VARCHAR(255) DEFAULT NULL, moderator TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_github_id (github_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE moderation_action (id BIGINT AUTO_INCREMENT NOT NULL, actor_label VARCHAR(128) NOT NULL, action VARCHAR(32) NOT NULL, reason LONGTEXT NOT NULL, created_at DATETIME NOT NULL, extension_id BIGINT NOT NULL, actor_id BIGINT DEFAULT NULL, INDEX IDX_B05D8128812D5EB (extension_id), INDEX IDX_B05D812810DAF24A (actor_id), INDEX idx_moderation_extension (extension_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ownership_claim (id BIGINT AUTO_INCREMENT NOT NULL, method VARCHAR(32) NOT NULL, evidence LONGTEXT NOT NULL, verified_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, user_id BIGINT NOT NULL, extension_id BIGINT NOT NULL, INDEX IDX_E9F75F5CA76ED395 (user_id), INDEX IDX_E9F75F5C812D5EB (extension_id), UNIQUE INDEX uniq_claim_user_extension (user_id, extension_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D8128812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D812810DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ownership_claim ADD CONSTRAINT FK_E9F75F5CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ownership_claim ADD CONSTRAINT FK_E9F75F5C812D5EB FOREIGN KEY (extension_id) REFERENCES extension (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action DROP FOREIGN KEY FK_B05D8128812D5EB');
        $this->addSql('ALTER TABLE moderation_action DROP FOREIGN KEY FK_B05D812810DAF24A');
        $this->addSql('ALTER TABLE ownership_claim DROP FOREIGN KEY FK_E9F75F5CA76ED395');
        $this->addSql('ALTER TABLE ownership_claim DROP FOREIGN KEY FK_E9F75F5C812D5EB');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE moderation_action');
        $this->addSql('DROP TABLE ownership_claim');
    }
}
