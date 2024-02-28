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

        $PRs = $this->githubPRService->getProjectPullRequestIds($project);
        /** @var GithubPullRequest[] $existingPRs */
        $existingPRs = $this->entityManager->getRepository(GithubPullRequest::class)->findBy([
            'githubId' => $PRs,
        ]);
        foreach ($PRs as $PRId) {
            foreach ($existingPRs as $existingPR) {
                if ((int)$existingPR->githubId === $PRId && $existingPR->task->project->id === $project->id) {
                    $io->info('PR exists: ' . $PRId);
                    continue 2;
                }
            }
            $io->info('Creating task for PR: ' . $PRId);

            try {
                $task = $this->githubPRService->createConnectionPRTask($connection, $PRId);
                if ($task) {
                    $io->info('Task created for PR: ' . $PRId);
                } else {
                    $io->warning('Task not created for PR: ' . $PRId);
                }
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }
    }
}
