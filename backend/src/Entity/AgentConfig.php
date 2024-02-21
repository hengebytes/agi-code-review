<?php

namespace App\Entity;

use App\Repository\AgentConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentConfigRepository::class)]
class AgentConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public ?string $name = null;

    #[ORM\Column(length: 255)]
    public ?string $type = null;

    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $accessKey = null;

    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $accessName = null;

    #[ORM\Column(nullable: true)]
    public ?array $extraData = null;
}
