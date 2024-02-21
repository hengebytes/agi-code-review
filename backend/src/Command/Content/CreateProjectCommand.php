<?php

namespace App\Command\Content;

use App\Entity\Project;
use Symfony\Component\Console\{Attribute\AsCommand,
    Command\Command,
    Input\InputInterface,
    Output\OutputInterface,
    Style\SymfonyStyle};

#[AsCommand(name: 'agi:project:create', description: 'Create new project')]
class CreateProjectCommand extends AbstractContentManagementCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create new project');

        $io->section('Create project');
        $project = new Project();
        $this->entityManager->persist($project);
        $project->name = $io->ask('Name', validator: function ($value) {
            if (empty($value)) {
                throw new \Exception('Project name cannot be empty');
            }

            return $value;
        });
        $project->description = $io->ask('Description');

        $io->section('Agent connections');
        while ($io->confirm('Connect agent to the project?')) {
            $this->createAgentConnection($io, $project);
        }
        $this->entityManager->flush();

        $io->success('New project created successfully');

        return Command::SUCCESS;
    }


}