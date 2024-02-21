<?php

namespace App\Command\Content;

use App\Entity\Project;
use Symfony\Component\Console\{Attribute\AsCommand,
    Command\Command,
    Input\InputInterface,
    Output\OutputInterface,
    Question\ChoiceQuestion,
    Style\SymfonyStyle};

#[AsCommand(name: 'agi:project:agents:add', description: 'Add agent to the project')]
class AddProjectAgentConnectionCommand extends AbstractContentManagementCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Add agent to the project');

        $io->section('Select project');

        $allProjects = $this->entityManager->getRepository(Project::class)->findAll();
        $projectChoise = [];
        /** @var Project $project */
        foreach ($allProjects as $project) {
            $projectChoise[] = $project->name . ' (' . $project->id . ')';
        }
        $projectSelection = $io->askQuestion(
            new ChoiceQuestion('Please select project:', $projectChoise)
        );
        foreach ($allProjects as $project) {
            if ($project->name . ' (' . $project->id . ')' === $projectSelection) {
                $this->createAgentConnection($io, $project);
                $io->success('Agent added to the project successfully');
                break;
            }
        }

        return Command::SUCCESS;
    }

}