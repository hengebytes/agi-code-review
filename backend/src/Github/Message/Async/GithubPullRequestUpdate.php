<?php

namespace App\Github\Message\Async;

readonly class GithubPullRequestUpdate
{
    public function __construct(
        public string $owner,
        public string $repo,
        public int $prId,
        public string $status,
    ) {
    }
}