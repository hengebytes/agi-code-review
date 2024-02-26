<?php

namespace App\Gitlab\DTO;

readonly class GitlabMRResponseDTO
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
    ) {
    }

    public static function fromAPIResponse(array $data): self
    {
        return new self(
            $data['iid'],
            $data['title'],
            $data['description'],
            $data['state'],
            $data['author']['username'],
            $data['source_branch'],
            $data['target_branch'],
            $data['created_at'],
            $data['updated_at'],
            array_unique(array_map(static fn($commit) => trim($commit['message'] ?? $commit['title'] ?? ''), $data['commits'] ?? [])),
            $data['files'] ?? null,
        );
    }
}