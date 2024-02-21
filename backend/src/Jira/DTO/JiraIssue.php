<?php

namespace App\Jira\DTO;

class JiraIssue
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public string $description = '',
        public array $comments = [],
    ) {
    }
}
