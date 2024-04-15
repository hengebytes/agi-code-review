<?php

namespace App\Github\DTO;

readonly class GithubPRResponseDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public string $state,
        public string $author,
        public string $headRefName,
        public string $baseRefName,
        public string $createdAt,
        public string $updatedAt,
        public array $commits,
        public ?array $files,
        public ?array $reviews,
    ) {
    }

    public static function fromGQLResponse(array $data): self
    {
        $minDate = '1970-01-01T00:00:00Z';
        $lastCommitDate = new \DateTime($minDate);
        foreach ($data['commits']['nodes'] ?? [] as $commitNode) {
            $commitDate = new \DateTime($commitNode['commit']['committedDate'] ?? $minDate);
            if ($commitDate > $lastCommitDate) {
                $lastCommitDate = $commitDate;
            }
        }

        return new self(
            $data['id'],
            $data['title'],
            $data['body'],
            $data['state'],
            $data['author']['login'],
            $data['headRefName'],
            $data['baseRefName'],
            $data['createdAt'],
            $lastCommitDate->format('c'),
            array_unique(array_map(static fn($commit) => $commit['commit']['message'], $data['commits']['nodes'])),
            $data['files'] ?? null,
            array_map(static fn($review) => [
                'body' => $review['body'],
                'comments' => array_map(static fn($comment) => [
                    'body' => $comment['body'],
                    'path' => $comment['path'],
                    'line' => $comment['line'],
                    'startLine' => $comment['startLine'],
                ], $review['comments']['nodes'] ?? []),
            ], $data['reviews']['nodes'] ?? []),
        );
    }
}