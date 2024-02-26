<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240226002135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create gitlab_merge_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gitlab_merge_request (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, repo_url VARCHAR(512) NOT NULL, gitlab_id VARCHAR(255) NOT NULL, branch_from VARCHAR(255) NOT NULL, branch_to VARCHAR(255) NOT NULL, author VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, commit_names LONGTEXT NOT NULL, diff_files JSON NOT NULL, task_id INT NOT NULL, INDEX IDX_C3B019F8DB60186 (task_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE gitlab_merge_request ADD CONSTRAINT FK_C3B019F8DB60186 FOREIGN KEY (task_id) REFERENCES task (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gitlab_merge_request DROP FOREIGN KEY FK_C3B019F8DB60186');
        $this->addSql('DROP TABLE gitlab_merge_request');
    }
}
