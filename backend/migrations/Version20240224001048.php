<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240224001048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent name and id to task result';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_result ADD agent_name VARCHAR(255) DEFAULT NULL, ADD agent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task_result ADD CONSTRAINT FK_28C345C03414710B FOREIGN KEY (agent_id) REFERENCES agent_config (id)');
        $this->addSql('CREATE INDEX IDX_28C345C03414710B ON task_result (agent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_result DROP FOREIGN KEY FK_28C345C03414710B');
        $this->addSql('DROP INDEX IDX_28C345C03414710B ON task_result');
        $this->addSql('ALTER TABLE task_result DROP agent_name, DROP agent_id');
    }
}
