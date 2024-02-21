<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240221000655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial migration for the project, agent, task, and github_pull_request tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_config (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, access_key VARCHAR(2048) DEFAULT NULL, access_name VARCHAR(2048) DEFAULT NULL, extra_data JSON DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE github_pull_request (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, repo_owner VARCHAR(255) NOT NULL, repo_name VARCHAR(255) NOT NULL, github_id VARCHAR(255) NOT NULL, branch_from VARCHAR(255) NOT NULL, branch_to VARCHAR(255) NOT NULL, author VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, commit_names LONGTEXT NOT NULL, diff_files JSON NOT NULL, task_id INT NOT NULL, INDEX IDX_3F4066B68DB60186 (task_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE llmapi_cache (id INT AUTO_INCREMENT NOT NULL, input LONGTEXT DEFAULT NULL, input_md5_hash VARCHAR(32) NOT NULL, output LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, source INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE project_agent_connection (id INT AUTO_INCREMENT NOT NULL, config JSON DEFAULT NULL, agent_id INT NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_E53461003414710B (agent_id), INDEX IDX_E5346100166D1F9C (project_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE task (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(2048) NOT NULL, status SMALLINT NOT NULL, source VARCHAR(255) NOT NULL, external_id VARCHAR(2048) DEFAULT NULL, description LONGTEXT NOT NULL, `references` LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, extra_data JSON DEFAULT NULL, project_id INT NOT NULL, INDEX IDX_527EDB25166D1F9C (project_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE task_result (id INT AUTO_INCREMENT NOT NULL, input LONGTEXT DEFAULT NULL, output LONGTEXT DEFAULT NULL, extra_data JSON DEFAULT NULL, created_at DATETIME NOT NULL, task_id INT DEFAULT NULL, INDEX IDX_28C345C08DB60186 (task_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE github_pull_request ADD CONSTRAINT FK_3F4066B68DB60186 FOREIGN KEY (task_id) REFERENCES task (id)');
        $this->addSql('ALTER TABLE project_agent_connection ADD CONSTRAINT FK_E53461003414710B FOREIGN KEY (agent_id) REFERENCES agent_config (id)');
        $this->addSql('ALTER TABLE project_agent_connection ADD CONSTRAINT FK_E5346100166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE task_result ADD CONSTRAINT FK_28C345C08DB60186 FOREIGN KEY (task_id) REFERENCES task (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE github_pull_request DROP FOREIGN KEY FK_3F4066B68DB60186');
        $this->addSql('ALTER TABLE project_agent_connection DROP FOREIGN KEY FK_E53461003414710B');
        $this->addSql('ALTER TABLE project_agent_connection DROP FOREIGN KEY FK_E5346100166D1F9C');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25166D1F9C');
        $this->addSql('ALTER TABLE task_result DROP FOREIGN KEY FK_28C345C08DB60186');
        $this->addSql('DROP TABLE agent_config');
        $this->addSql('DROP TABLE github_pull_request');
        $this->addSql('DROP TABLE llmapi_cache');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_agent_connection');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE task_result');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
