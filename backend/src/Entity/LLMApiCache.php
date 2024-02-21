<?php

namespace App\Entity;

use App\Enum\LLMApiCacheSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class LLMApiCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $input = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $inputMD5Hash;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $output = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(enumType: LLMApiCacheSource::class)]
    public LLMApiCacheSource $source = LLMApiCacheSource::OAI;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
