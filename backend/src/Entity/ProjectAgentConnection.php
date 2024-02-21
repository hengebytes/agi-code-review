<?php

namespace App\Entity;

use App\Repository\AgentConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentConnectionRepository::class)]
class ProjectAgentConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $config = null;

    #[ORM\ManyToOne(cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    public ?AgentConfig $agent = null;

    #[ORM\ManyToOne(inversedBy: 'agents')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null;

    public function getConfigValue(string $fieldName): ?string
    {
        return $this->config[$fieldName] ??
            $this->agent->extraData[$fieldName] ?? null;
    }

    public function getAccessKey(): ?string
    {
        return $this->agent->accessKey;
    }

    public function getAccessName(): ?string
    {
        return $this->agent->accessName;
    }
}
