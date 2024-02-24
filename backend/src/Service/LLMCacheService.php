<?php

namespace App\Service;

use App\DTO\AgentMessage;
use App\Entity\LLMApiCache;
use App\Enum\LLMApiCacheSource;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;

class LLMCacheService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param AgentMessage[] $messages
     */
    public function getOAIResponse(array $messages, string $variationKey = ''): ?CreateResponse
    {
        try {
            $inputHash = md5(json_encode($messages, JSON_THROW_ON_ERROR) . $variationKey);
        } catch (\JsonException) {
            return null;
        }
        $cache = $this->entityManager->getRepository(LLMApiCache::class)->findOneBy([
            'inputMD5Hash' => $inputHash,
            'source' => LLMApiCacheSource::OAI,
        ]);
        if (!$cache) {
            return null;
        }

        try {
            $cacheData = json_decode($cache->output, true, 512, JSON_THROW_ON_ERROR);

            return CreateResponse::from(
                $cacheData['response'] ?? '',
                MetaInformation::from($cacheData['meta'] ?? []),
            );
        } catch (\JsonException) {
            return null;
        }
    }

    public function saveOAIResponse(array $messages, CreateResponse $response, string $variationKey = ''): void
    {
        try {
            $inputHash = md5(json_encode($messages, JSON_THROW_ON_ERROR) . $variationKey);
        } catch (\JsonException) {
            return;
        }
        $cache = new LLMApiCache();
        $cache->inputMD5Hash = $inputHash;
        $cache->source = LLMApiCacheSource::OAI;
        $cache->input = json_encode($messages, JSON_THROW_ON_ERROR);
        $cache->output = json_encode([
            'response' => $response->toArray(),
            'meta' => $response->meta()->toArray(),
        ], JSON_THROW_ON_ERROR);
        $this->entityManager->persist($cache);
        $this->entityManager->flush();
    }
}
