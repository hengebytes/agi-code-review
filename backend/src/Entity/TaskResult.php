<?php

namespace App\Entity;

use App\Repository\TaskResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskResultRepository::class)]
class TaskResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'results')]
    public ?Task $task = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $input = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $output = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    public ?string $agentName = null;

    #[ORM\ManyToOne]
    public AgentConfig|null $agent = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $extraData = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function fromOutput(string $output): self
    {
        $result = new self();
        $result->output = $output;

        return $result;
    }
}
