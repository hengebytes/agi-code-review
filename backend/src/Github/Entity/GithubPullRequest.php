<?php

namespace App\Github\Entity;

use App\Entity\Task;
use App\Repository\GithubPullRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GithubPullRequestRepository::class)]
class GithubPullRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public ?Task $task = null;

    #[ORM\Column(length: 255)]
    public ?string $status = null;

    #[ORM\Column(length: 255)]
    public ?string $repoOwner = null;

    #[ORM\Column(length: 255)]
    public ?string $repoName = null;

    #[ORM\Column(length: 255)]
    public ?string $githubId = null;

    #[ORM\Column(length: 255)]
    public ?string $branchFrom = null;

    #[ORM\Column(length: 255)]
    public ?string $branchTo = null;

    #[ORM\Column(length: 255)]
    public ?string $author = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY)]
    public array $commitNames = [];

    /**
     * @var array = (['patch' => string|null, 'filename' => string, 'status' => string])[]
     */
    #[ORM\Column(type: Types::JSON, nullable: false)]
    public array $diffFiles = [];

    /**
     * @var null|array = (['body' => string, 'comments' => (['body' => string, 'path' => string, 'line' => int, 'startLine' => int])[]])[]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $reviews = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
