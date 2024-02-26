<?php

namespace App\Gitlab\Message\Async;

readonly class GitlabMergeRequestUpdate
{
    public function __construct(
        public string $repoURL,
        public int $prId,
        public string $status,
    ) {
    }
}