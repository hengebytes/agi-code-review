<?php

namespace App\Command\Content;

use Symfony\Component\Console\{Attribute\AsCommand,
    Command\Command,
    Input\InputInterface,
    Output\OutputInterface,
    Style\SymfonyStyle};

#[AsCommand(name: 'agi:agent:create', description: 'Create new agent config')]
class CreateAgentConfigCommand extends AbstractContentManagementCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create new agent config');

        $this->createAgentConfig($io);

        return Command::SUCCESS;
    }
}