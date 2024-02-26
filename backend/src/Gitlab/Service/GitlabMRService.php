<?php

namespace App\Gitlab\Service;

use App\Entity\Project;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Gitlab\DTO\GitlabMRResponseDTO;
use App\Gitlab\Entity\GitlabMergeRequest;
use App\Message\Async\TaskCreatedMessage;
use App\Message\Async\TaskUpdatedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class GitlabMRService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function getTaskByMRDetails(string $repoURL, int $gitlabPRId): ?Task
    {
        $entityRepository = $this->entityManager->getRepository(GitlabMergeRequest::class);
        /** @var GitlabMergeRequest $existingMR */
        $existingMR = $entityRepository->findOneBy(['repoURL' => $repoURL, 'gitlabId' => $gitlabPRId]);

        return $existingMR?->task;
    }

    public function refreshTaskPR(Task $existingTask): Task
    {
        /** @var GitlabMergeRequest $mr */
        $mr = $this->entityManager->getRepository(GitlabMergeRequest::class)->findOneBy(['task' => $existingTask]);
        if (!$mr) {
            throw new \RuntimeException('MR not found');
        }

        /** @var ProjectAgentConnection[] $connections */
        $connections = $existingTask->project->agents;
        $connection = null;
        foreach ($connections as $conn) {
            if ($conn->getConfigValue('repository') === $mr->repoURL) {
                $connection = $conn;
                break;
            }
        }
        if (!$connection) {
            throw new \RuntimeException('Connection not found');
        }

        $mrDetails = $this->getMergeRequestDetails($connection, $mr->gitlabId);
        $mr->name = $mrDetails->title;
        $mr->description = $mrDetails->body;
        $mr->status = $mrDetails->state;
        $mr->branchFrom = $mrDetails->headRefName;
        $mr->branchTo = $mrDetails->baseRefName;
        $mr->author = $mrDetails->author;
        $mr->createdAt = new \DateTimeImmutable($mrDetails->createdAt);
        $mr->updatedAt = new \DateTimeImmutable($mrDetails->updatedAt);
        $mr->commitNames = $mrDetails->commits;
        $mr->diffFiles = $mrDetails->files;

        $this->entityManager->persist($mr);
        $this->entityManager->flush();

        if ($existingTask->status === TaskStatus::COMPLETED) {
            $existingTask->status = TaskStatus::READY_TO_PROCESS;
            $this->entityManager->flush();
            $this->bus->dispatch(new TaskUpdatedMessage($existingTask->id));
        }

        return $existingTask;
    }

    public function createConnectionMRTask(ProjectAgentConnection $connection, int $gitlabMRId): ?Task
    {
        $prDetails = $this->getMergeRequestDetails($connection, $gitlabMRId);
        $commitNames = implode(",\n", $prDetails->commits);

        $repoURL = $connection->getConfigValue('repository');
        $task = new Task();
        $task->name = "#{$gitlabMRId}: {$prDetails->title}";
        $task->description = trim($prDetails->body . "\nCommits:\n {$commitNames}");
        $task->status = TaskStatus::NEW;
        $task->source = 'gitlab-pr';
        $task->externalId = $gitlabMRId;
        $task->externalRefs[] = rtrim($repoURL, '/') . '/merge_requests/' . $gitlabMRId;
        $task->project = $connection->project;

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $pr = new GitlabMergeRequest();
        $pr->task = $task;
        $pr->name = $prDetails->title;
        $pr->description = $prDetails->body;
        $pr->status = $prDetails->state;
        $pr->repoURL = $repoURL;
        $pr->gitlabId = $gitlabMRId;
        $pr->branchFrom = $prDetails->headRefName;
        $pr->branchTo = $prDetails->baseRefName;
        $pr->author = $prDetails->author;
        $pr->createdAt = new \DateTimeImmutable($prDetails->createdAt);
        $pr->updatedAt = new \DateTimeImmutable($prDetails->updatedAt);
        $pr->diffFiles = $prDetails->files;
        $pr->commitNames = $prDetails->commits;

        $this->entityManager->persist($pr);

        $task->status = TaskStatus::READY_TO_PROCESS;
        $this->entityManager->flush();

        $this->bus->dispatch(new TaskCreatedMessage($task->id));

        return $task;
    }

    public function createMRTask(string $repoURL, int $gitlabMRId): array
    {
        $tasks = [];
        $connections = $this->getRelatedAgentConnections($repoURL);
        foreach ($connections as $connection) {
            if (!$connection->agent || !$connection->project) {
                continue;
            }
            $task = $this->createConnectionMRTask($connection, $gitlabMRId);
            if (!$task) {
                continue;
            }

            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * @return int[]
     */
    public function getProjectMergeRequestIds(
        Project $project,
        string $state = 'opened',
    ): array {
        $connection = null;
        foreach ($project->agents as $conn) {
            if ($conn->agent && $conn->agent->type === 'GitlabContextAgent') {
                $connection = $conn;
                break;
            }
        }
        if (!$connection) {
            return [];
        }

        $MRs = $this->getGitlabProjectClient($connection)->request('GET', "merge_requests", [
            'query' => ['state' => $state],
        ])->toArray();

        return array_map('intval', array_column($MRs, 'iid'));
    }

    public function submitMRReview(
        ProjectAgentConnection $connection, Task $task,
        string $comment,
        string $reviewCommentPrefix = ''
    ): void {
        $pr = $this->entityManager->getRepository(GitlabMergeRequest::class)->findOneBy(['task' => $task]);
        if (!$pr) {
            throw new \RuntimeException('MR not found');
        }
        if ($reviewCommentPrefix) {
            $reviewCommentPrefix = trim($reviewCommentPrefix) . "\n";
        }

        $this->getGitlabProjectClient($connection, $pr->repoURL)->request('POST', "merge_requests/{$pr->gitlabId}/notes", [
            'json' => [
                'body' => $reviewCommentPrefix . $comment,
            ],
        ]);
    }

    public function getFileContent(ProjectAgentConnection $connection, Task $task, string $pathToFile): string
    {
        $mr = $this->entityManager->getRepository(GitlabMergeRequest::class)->findOneBy(['task' => $task]);
        if (!$mr) {
            throw new \RuntimeException('MR not found');
        }

        $content = $this->getGitlabProjectClient($connection)->request('GET', "repository/files/" . rawurlencode($pathToFile), [
            'query' => ['ref' => $mr->branchFrom],
        ])->toArray();
        if (!isset($content['content'])) {
            return 'File not found';
        }

        return base64_decode($content['content']);
    }

    private function getRelatedAgentConnections(string $repoURL): array
    {
        /** @var ProjectAgentConnection[] $connections */
        $connections = $this->entityManager->getRepository(ProjectAgentConnection::class)
            ->findAllByConfigValue('repository', rtrim($repoURL, '/'));

        return $connections;
    }

    private function getMergeRequestDetails(ProjectAgentConnection $connection, int $gitlabMRId): GitlabMRResponseDTO
    {
        $client = $this->getGitlabProjectClient($connection);

        $response = $client->request('GET', "merge_requests/{$gitlabMRId}")->toArray();
        $diffs = $client->request('GET', "merge_requests/{$gitlabMRId}/diffs")->toArray();
        $response['commits'] = $client->request('GET', "merge_requests/{$gitlabMRId}/commits")->toArray();

        $response['files'] = array_map(fn($file) => [
            'patch' => $file['diff'] ?? null,
            'filename' => $file['new_path'] ?? $file['old_path'] ?? null,
            'status' => $this->getDiffFileStatus($file),
        ], $diffs);
        // filter out non-text files
        $response['files'] = array_filter($response['files'], static fn($file) => $file['patch'] !== null);

        return GitlabMRResponseDTO::fromAPIResponse($response);
    }

    private function getGitlabProjectClient(ProjectAgentConnection $connection, string $repoURL = ''): HttpClientInterface
    {
        $repoURL = $connection->getConfigValue('repository') ?: $repoURL;
        if (!$repoURL) {
            throw new \RuntimeException('Repository URL not found');
        }
        if (!str_contains($repoURL, 'https://gitlab.com/')) {
            $parsedURL = parse_url($repoURL);
            if (!$parsedURL) {
                throw new \RuntimeException('Invalid repository URL');
            }
            $baseURL = $parsedURL['scheme'] . '://';
            if (isset($parsedURL['user'])) {
                $baseURL .= $parsedURL['user'];
            }
            if (isset($parsedURL['pass'])) {
                $baseURL .= ':' . $parsedURL['pass'];
            }
            if (isset($parsedURL['user'])) {
                $baseURL .= '@';
            }
            $baseURL .= $parsedURL['host'];
            if (isset($parsedURL['port'])) {
                $baseURL .= ':' . $parsedURL['port'];
            }
            $apiURL = $baseURL . '/api/v4';
        } else {
            $apiURL = 'https://gitlab.com/api/v4';
        }
        $apiURL .= '/projects/' . $this->getProjectPath($repoURL) . '/';

        // You can also use personal, project, or group access tokens with OAuth-compliant headers:
        $token = $connection->getConfigValue('gitlabToken') ?: $connection->agent->accessKey;

        return $this->httpClient->withOptions([
            'base_uri' => $apiURL,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
    }

    private function getProjectPath(string $repoURL): string
    {
        $parsedURL = parse_url($repoURL, PHP_URL_PATH);
        if (!$parsedURL) {
            throw new \RuntimeException('Invalid repository URL');
        }

        return rawurlencode(trim($parsedURL, '/'));
    }

    private function getDiffFileStatus(array $file): string
    {
        if (!empty($file['new_file'])) {
            return 'created';
        }
        if (!empty($file['deleted_file'])) {
            return 'deleted';
        }

        return 'modified';
    }
}