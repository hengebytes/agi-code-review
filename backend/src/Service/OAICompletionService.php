<?php

namespace App\Service;

use App\DTO\AgentMessage;
use App\DTO\LLMAccessCredential;
use App\Enum\AgentMessageRole;
use OpenAI\Responses\Chat\CreateResponse;
use Yethee\Tiktoken\EncoderProvider;

/**
 * OpenAI (and OpenaAI-like API) completion service
 */
readonly class OAICompletionService
{
    public function __construct(
        private LLMCacheService $cacheService,
        private EncoderProvider $encoderProvider,
    ) {
    }

    /**
     * @param AgentMessage[] $messages
     */
    public function getCompletion(LLMAccessCredential $llmAccess, array $messages, array $tools = []): CreateResponse
    {
        $client = \OpenAI::factory()->withApiKey($llmAccess->token);
        if ($llmAccess->apiUrl) {
            $client = $client->withBaseUri($llmAccess->apiUrl);
        }
        $client = $client->make();

        $requestMessages = [];
        foreach ($messages as $message) {
            $msg = [
                'role' => $message->role === AgentMessageRole::USER ? 'user' : 'system',
                'content' => $message->content,
            ];
            if ($message->toolCallId) {
                $msg['tool_call_id'] = $message->toolCallId;
            }
            $requestMessages[] = $msg;
        }

        $variationKey = $tools ? 'tools' : '';
        $cachedResponse = $this->cacheService->getOAIResponse($messages, $variationKey);
        if ($cachedResponse) {
            return $cachedResponse;
        }

        $chatRequestParams = [
            'model' => $llmAccess->model,
            'messages' => $requestMessages,
        ];
        if ($tools) {
            $chatRequestParams['tools'] = $tools;
            // tool_choice=auto is the default if tools are present.
            // tool_choice=none is the default when no functions are present.
        }

        $response = $client->chat()->create($chatRequestParams);
        $response = $this->parseMultiToolResponse($response);

        try {
            $this->cacheService->saveOAIResponse($messages, $response, $variationKey);
        } catch (\Exception) {
        }

        return $response;
    }

    /**
     * @param AgentMessage[] $messages
     */
    public function countMessagesTokens(array $messages): int
    {
        return $this->countTextsTokens(array_column($messages, 'content'));
    }

    /**
     * @param string[] $messages
     */
    public function countTextsTokens(array $messages): int
    {
        $encoder = $this->encoderProvider->getForModel('gpt-4');
        $tokens = 0;
        foreach ($messages as $message) {
            if (!$message) {
                continue;
            }
            $tokens += 4; // every message follows <im_start>{role/name}\n{content}<im_end>\n

            try {
                $tokens += count($encoder->encode($message));
            } catch (\Yethee\Tiktoken\Exception\RegexError) {
                $tokens += mb_strlen($message) / 4;
            }

            $tokens += 2; // every reply is primed with <im_start>assistant
        }

        return $tokens;
    }

    private function parseMultiToolResponse(CreateResponse $response): CreateResponse
    {
        // sometimes the response includes a multi-tool response in message, which we need to parse and execute
        $prefix = "```multi_tool_use.parallel```\n```json\n";
        if (empty($response->choices[0]->message->content) || !str_starts_with($response->choices[0]->message->content, $prefix)) {
            return $response;
        }
        $responseData = $response->toArray();
        $stringResponse = trim(
            str_replace($prefix, '', $responseData['choices'][0]['message']['content']),
            "` \n\t"
        );

        try {
            $multitoolData = json_decode($stringResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $response;
        }
        if (empty($multitoolData['tool_uses']) || !is_array($multitoolData['tool_uses'])) {
            return $response;
        }
        $toolCalls = [];
        foreach ($multitoolData['tool_uses'] as $k => $toolUse) {
            if (!isset($toolUse['recipient_name'], $toolUse['parameters'])) {
                continue;
            }
            try {
                // validate parameters json
                json_decode($toolUse['parameters'], flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $toolCalls[] = [
                'id' => 'call_multitool' . $k,
                'type' => 'function',
                'function' => [
                    'name' => str_replace('functions.', '', $toolUse['recipient_name']),
                    'arguments' => $toolUse['parameters'],
                ],
            ];
        }
        $responseData['choices'][0]['message']['content'] = '';
        $responseData['choices'][0]['message']['tool_calls'] = $toolCalls;
        $responseData['choices'][0]['finish_reason'] = 'tool_calls';

        return CreateResponse::from($responseData, $response->meta());
    }
}
