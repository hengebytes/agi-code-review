<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240221081842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix project_agent_connection index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_agent_connection DROP INDEX UNIQ_E53461003414710B, ADD INDEX IDX_E53461003414710B (agent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_agent_connection DROP INDEX IDX_E53461003414710B, ADD UNIQUE INDEX UNIQ_E53461003414710B (agent_id)');
    }
}
