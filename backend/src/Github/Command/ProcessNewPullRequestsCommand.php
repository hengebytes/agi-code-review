<?php

namespace App\Github\Command;

use App\Entity\Project;
use App\Github\Entity\GithubPullRequest;
use App\Github\Service\GithubPRService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'agi:github:check-new-requests',
    description: 'Create tasks by new pull requests in all projects'
)]
class ProcessNewPullRequestsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GithubPRService $githubPRService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Task creation by pull request');

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
            if ($conn->agent && $conn->agent->type === 'GithubContextAgent') {
                $connection = $conn;
                break;
            }
        }
        if (!$connection) {
            $io->info('No Github agent connection found for project: ' . $project->name);

            return;
        }

        $PRs = $this->githubPRService->getProjectPullRequestsSummary($project);
        /** @var GithubPullRequest[] $existingPRs */
        $existingPRs = $this->entityManager->getRepository(GithubPullRequest::class)->findBy([
            'githubId' => array_column($PRs, 'number'),
        ]);
        foreach ($PRs as $PRSummary) {
            foreach ($existingPRs as $existingPR) {
                if ((int)$existingPR->githubId === $PRSummary['number'] && $existingPR->task->project->id === $project->id) {
                    $io->info('PR exists: ' . $PRSummary['number']);

                    if ($existingPR->updatedAt->getTimestamp() < $PRSummary['updatedAt']->getTimestamp()) {
                        $io->info('Updating PR: ' . $PRSummary['number']);
                        if (!$existingPR->task) {
                            $io->error('Task not created for PR: ' . $PRSummary['number']);
                            continue 2;
                        }
                        $this->githubPRService->refreshTaskPR($existingPR->task);
                    }
                    continue 2;
                }
            }
            $io->info('Creating task for PR: ' . $PRSummary['number']);

            try {
                $task = $this->githubPRService->createConnectionPRTask($connection, $PRSummary['number']);
                if ($task) {
                    $io->info('Task created for PR: ' . $PRSummary['number']);
                } else {
                    $io->warning('Task not created for PR: ' . $PRSummary['number']);
                }
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }
    }
}
