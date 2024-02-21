<?php

namespace App\Entity;

use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
class Task
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 2048)]
    public ?string $name = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null;

    #[ORM\Column(type: Types::SMALLINT, enumType: TaskStatus::class)]
    public TaskStatus $status = TaskStatus::NEW;

    #[ORM\Column(length: 255)]
    public ?string $source = null;

    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $externalId = null;

    #[ORM\Column(type: Types::TEXT)]
    public ?string $description = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY)]
    public array $externalRefs = [];

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(targetEntity: TaskResult::class, mappedBy: 'task')]
    public Collection $results;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $extraData = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->results = new ArrayCollection();
    }
}
