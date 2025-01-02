<?php

namespace App\Github\Service;

use App\Entity\Project;
use App\Entity\ProjectAgentConnection;
use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Github\DTO\GithubPRResponseDTO;
use App\Github\Entity\GithubPullRequest;
use App\Message\Async\TaskCreatedMessage;
use App\Message\Async\TaskUpdatedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Github\AuthMethod;
use Github\Client;
use Symfony\Component\HttpClient\HttplugClient;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class GithubPRService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function getTaskByPRDetails(string $owner, string $repo, int $githubPRId): ?Task
    {
        /** @var GithubPullRequest $existingPR */
        $existingPR = $this->entityManager->getRepository(GithubPullRequest::class)
            ->findOneBy(['repoOwner' => $owner, 'repoName' => $repo, 'githubId' => $githubPRId]);

        return $existingPR?->task;
    }

    public function refreshTaskPR(Task $existingTask): Task
    {
        /** @var GithubPullRequest $pr */
        $pr = $this->entityManager->getRepository(GithubPullRequest::class)->findOneBy(['task' => $existingTask]);

        if (!$pr) {
            throw new \RuntimeException('PR not found');
        }
        /** @var ProjectAgentConnection[] $connections */
        $connections = $existingTask->project->agents;
        $connection = null;
        foreach ($connections as $conn) {
            if ($conn->getConfigValue('repository') === $pr->repoOwner . '/' . $pr->repoName) {
                $connection = $conn;
                break;
            }
        }
        if (!$connection) {
            throw new \RuntimeException('Connection not found');
        }

        $prDetails = $this->getPullRequestDetails($connection, $pr->githubId);
        $pr->name = $prDetails->title;
        $pr->description = $prDetails->body;
        $pr->status = $prDetails->state;
        $pr->branchFrom = $prDetails->headRefName;
        $pr->branchTo = $prDetails->baseRefName;
        $pr->author = $prDetails->author;
        $pr->createdAt = new \DateTimeImmutable($prDetails->createdAt);
        $pr->updatedAt = new \DateTimeImmutable($prDetails->updatedAt);
        $pr->commitNames = $prDetails->commits;
        $pr->diffFiles = $prDetails->files;
        $pr->reviews = $prDetails->reviews;

        $this->entityManager->persist($pr);
        $this->entityManager->flush();

        if ($existingTask->status === TaskStatus::COMPLETED) {
            $existingTask->status = TaskStatus::READY_TO_PROCESS;
            $this->entityManager->flush();
            $this->bus->dispatch(new TaskUpdatedMessage($existingTask->id));
        }

        return $existingTask;
    }

    public function createConnectionPRTask(ProjectAgentConnection $connection, int $githubPRId): ?Task
    {
        $prDetails = $this->getPullRequestDetails($connection, $githubPRId);
        $commitNames = implode(",\n", $prDetails->commits);

        [$owner, $repo] = explode('/', $connection->getConfigValue('repository'));
        $task = new Task();
        $task->name = "{$repo} #{$githubPRId}: {$prDetails->title}";
        $task->description = trim($prDetails->body . "\nCommits:\n {$commitNames}");
        $task->status = TaskStatus::NEW;
        $task->source = 'github-pr';
        $task->externalId = $githubPRId;
        $task->externalRefs[] = "https://github.com/{$owner}/{$repo}/pull/{$githubPRId}";
        $task->project = $connection->project;

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $pr = new GithubPullRequest();
        $pr->task = $task;
        $pr->name = $prDetails->title;
        $pr->description = $prDetails->body;
        $pr->status = $prDetails->state;
        $pr->repoOwner = $owner;
        $pr->repoName = $repo;
        $pr->githubId = $githubPRId;
        $pr->branchFrom = $prDetails->headRefName;
        $pr->branchTo = $prDetails->baseRefName;
        $pr->author = $prDetails->author;
        $pr->createdAt = new \DateTimeImmutable($prDetails->createdAt);
        $pr->updatedAt = new \DateTimeImmutable($prDetails->updatedAt);
        $pr->diffFiles = $prDetails->files;
        $pr->commitNames = $prDetails->commits;
        $pr->reviews = $prDetails->reviews;

        $this->entityManager->persist($pr);

        $task->status = TaskStatus::READY_TO_PROCESS;
        $this->entityManager->flush();

        $this->bus->dispatch(new TaskCreatedMessage($task->id));

        return $task;
    }

    public function createPRTask(string $owner, string $repo, int $githubPRId): array
    {
        $tasks = [];
        $connections = $this->getRelatedAgentConnections($owner, $repo);
        foreach ($connections as $connection) {
            if (!$connection->agent || !$connection->project) {
                continue;
            }
            $task = $this->createConnectionPRTask($connection, $githubPRId);
            if (!$task) {
                continue;
            }

            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * @return array<int, array{number: int, updatedAt: \DateTimeImmutable}>
     */
    public function getProjectPullRequestsSummary(
        Project $project,
        string $state = 'OPEN',
        int $limit = 100
    ): array {
        $connection = null;
        foreach ($project->agents as $conn) {
            if ($conn->agent && $conn->agent->type === 'GithubContextAgent') {
                $connection = $conn;
                break;
            }
        }
        if (!$connection) {
            return [];
        }

        [$owner, $repo] = explode('/', $connection->getConfigValue('repository'));
        $query = <<<GQL
        query { repository(owner: "{$owner}", name: "{$repo}") {
            pullRequests(first: {$limit}, states: {$state}) {
              nodes { number commits(last: 1) { nodes { commit { committedDate } } } }
            }
        }}
        GQL;
        $response = $this->getGithubClient($connection)->graphql()->execute($query);
        if (empty($response['data']['repository']['pullRequests']['nodes'])) {
            return [];
        }

        $PRs = [];
        foreach ($response['data']['repository']['pullRequests']['nodes'] as $pr) {
            $PRs[] = [
                'number' => (int)$pr['number'],
                'updatedAt' => new \DateTimeImmutable(
                    $pr['commits']['nodes'][0]['commit']['committedDate']
                        ?? null
                ),
            ];
        }

        return $PRs;
    }

    public function submitPRReview(
        ProjectAgentConnection $connection, Task $task,
        string $comment,
        array $fileComments = [],
        string $reviewCommentPrefix = ''
    ): void {
        $pr = $this->entityManager->getRepository(GithubPullRequest::class)->findOneBy(['task' => $task]);
        if (!$pr) {
            throw new \RuntimeException('PR not found');
        }
        $client = $this->getGithubClient($connection);

        if ($reviewCommentPrefix) {
            $reviewCommentPrefix = trim($reviewCommentPrefix) . "\n";
        }

        $params = [
            'body' => $reviewCommentPrefix . $comment,
            'event' => 'COMMENT',
            //'event' => $fileComments ? 'REQUEST_CHANGES' : 'COMMENT', // request changes fails on own PRs
        ];
        if ($fileComments && $reviewCommentPrefix) {
            $params['comments'] = array_map(static function ($fileComment) use ($reviewCommentPrefix) {
                if (isset($fileComment['start_line'])) {
                    if (!isset($fileComment['line'])) {
                        $fileComment['line'] = $fileComment['start_line'];
                        unset($fileComment['start_line']);
                    } elseif ($fileComment['line'] === $fileComment['start_line']) {
                        unset($fileComment['start_line']);
                    } elseif ($fileComment['line'] < $fileComment['start_line']) {
                        $line = $fileComment['line'];
                        $fileComment['line'] = $fileComment['start_line'];
                        $fileComment['start_line'] = $line;
                    }
                }

                return [
                    ...$fileComment,
                    'body' => $reviewCommentPrefix . $fileComment['body'],
                ];
            }, $fileComments);
        }

        $pullRequest = $client->pullRequest();
        $reviewerUserLogin = $pullRequest->reviewRequests()->all($pr->repoOwner, $pr->repoName, $pr->githubId)['users'][0]['login'] ?? null;
        $reviews = $pullRequest->reviews();
        try {
            $reviews->create($pr->repoOwner, $pr->repoName, $pr->githubId, $params);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Validation Failed')) {
                if (!empty($params['comments'])) {
                    $comment .= "Per File comments\n\n";
                    foreach ($params['comments'] as $fileComment) {
                        $comment .= "\n"
                            . 'File: ' . $fileComment['path']
                            . ' (' . (isset($fileComment['start_line']) ? $fileComment['start_line'] . ':' : '')
                            . ($fileComment['line'] ?? '-') . ")\n"
                            . $fileComment['body'];
                    }
                }

                $reviews->create($pr->repoOwner, $pr->repoName, $pr->githubId, [
                    'body' => $reviewCommentPrefix . PHP_EOL . $comment,
                    'event' => 'COMMENT',
                ]);
            } else {
                throw $e;
            }
        }

        if ($reviewerUserLogin) {
            $pullRequest->reviewRequests()->create(
                $pr->repoOwner, $pr->repoName, $pr->githubId,
                [$reviewerUserLogin]
            );
        }
    }

    public function getFileContent(ProjectAgentConnection $connection, Task $task, string $pathToFile): string
    {
        $pr = $this->entityManager->getRepository(GithubPullRequest::class)->findOneBy(['task' => $task]);
        if (!$pr) {
            throw new \RuntimeException('PR not found');
        }

        $client = $this->getGithubClient($connection);
        $content = $client->repo()->contents()->show(
            $pr->repoOwner, $pr->repoName, $pathToFile,
            $pr->branchFrom
        );
        if (!isset($content['content'])) {
            return 'File not found';
        }

        return base64_decode($content['content']);
    }

    private function getRelatedAgentConnections(string $owner, string $repo): array
    {
        /** @var ProjectAgentConnection[] $connections */
        $connections = $this->entityManager->getRepository(ProjectAgentConnection::class)
            ->findAllByConfigValue('repository', $owner . '/' . $repo);

        return $connections;
    }

    private function getPullRequestDetails(ProjectAgentConnection $connection, int $githubPRId): GithubPRResponseDTO
    {
        [$owner, $repo] = explode('/', $connection->getConfigValue('repository'));

        $client = $this->getGithubClient($connection);
        $gqlClient = $client->graphql();
        $query = <<<GQL
        query { repository(owner: "{$owner}", name: "{$repo}") {
            pullRequest(number: {$githubPRId}) {
              id title body state author { login }
              headRefName baseRefName changedFiles
              createdAt
              commits(last: 100) { nodes { commit { message committedDate } } }
              reviews(first: 100) { nodes {
                  body
                  comments(first: 50) {
                    nodes {
                      body
                      path
                      line
                      startLine
                    }
                  }}
              }
            }
        }}
        GQL;

        $response = $gqlClient->execute($query);
        if (!isset($response['data']['repository']['pullRequest'])) {
            throw new \RuntimeException('Pull request not found');
        }
        $data = $response['data']['repository']['pullRequest'];

        // there's no files in GQL response :(
        $comparison = $client->repo()->commits()->compare($owner, $repo, $data['baseRefName'], $data['headRefName']);
        $data['files'] = array_map(static fn($file) => [
            'patch' => $file['patch'] ?? null,
            'filename' => $file['filename'],
            'status' => $file['status'],
        ], $comparison['files'] ?? []);

        // filter out non-text files
        $data['files'] = array_filter($data['files'], static fn($file) => $file['filename'] !== null);

        return GithubPRResponseDTO::fromGQLResponse($data);
    }

    private function getGithubClient(ProjectAgentConnection $connection): Client
    {
        $accessKey = $connection->getConfigValue('githubToken')
            ?: $connection->agent->accessKey;
        // @see https://docs.github.com/en/graphql/overview/explorer
        $client = Client::createWithHttpClient(new HttplugClient());
        $client->authenticate($accessKey, null, AuthMethod::ACCESS_TOKEN);

        return $client;
    }
}
