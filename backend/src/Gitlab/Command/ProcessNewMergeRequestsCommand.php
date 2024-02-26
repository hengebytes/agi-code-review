<?php

namespace App\Gitlab\Command;

use App\Entity\Project;
use App\Gitlab\Entity\GitlabMergeRequest;
use App\Gitlab\Service\GitlabMRService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'agi:gitlab:check-new-requests',
    description: 'Create tasks by new merge requests in all projects'
)]
class ProcessNewMergeRequestsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GitlabMRService $gitlabMRService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Task creation by merge request');

        /** @var Project[] $allProjects */
        $allProjects = $this->entityManager->getRepository(Project::class)->findAll();
        foreach ($allProjects as $project) {
            $io->section($project->name);
            $this->processProject($io, $project);
        }

        return Command::SUCCESS;
    }

    private function processProject(SymfonyStyle $io, Project $project): void
    {
        $connection = null;
        foreach ($project->agents as $conn) {
            if ($conn->agent && $conn->agent->type === 'GitlabContextAgent') {
                $connection = $conn;
                break;
            }
        }
        if (!$connection) {
            $io->info('No Gitlab agent connection found for project: ' . $project->name);

            return;
        }

        $MRs = $this->gitlabMRService->getProjectMergeRequestIds($project);
        /** @var GitlabMergeRequest[] $existingMRs */
        $existingMRs = $this->entityManager->getRepository(GitlabMergeRequest::class)->findBy([
            'gitlabId' => $MRs,
        ]);
        foreach ($MRs as $MRId) {
            foreach ($existingMRs as $existingMR) {
                if ((int)$existingMR->gitlabId === $MRId && $existingMR->task->project->id === $project->id) {
                    $io->info('MR exists: ' . $MRId);
                    continue 2;
                }
            }
            $io->info('Creating task for MR: ' . $MRId);

            try {
                $task = $this->gitlabMRService->createConnectionMRTask($connection, $MRId);
                if ($task) {
                    $io->success('Task created for MR: ' . $MRId);
                } else {
                    $io->warning('Task not created for MR: ' . $MRId);
                }
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }
    }
}
