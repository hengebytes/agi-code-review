<?php

namespace App\Service;

use App\DTO\AgentMessage;
use App\DTO\LLMAccessCredential;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Chat\CreateResponseMessage;
use OpenAI\Responses\Meta\MetaInformation;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Yethee\Tiktoken\EncoderProvider;

/**
 * OpenAI (and OpenaAI-like API) completion service
 */
readonly class OAICompletionService
{
    public function __construct(
        private LLMCacheService $cacheService,
        private EncoderProvider $encoderProvider,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param AgentMessage[] $messages
     */
    public function getCompletion(LLMAccessCredential $llmAccess, array $messages, array $tools = []): CreateResponse
    {
        $variationKey = $tools ? 'tools' : '';

        $isClaude = str_starts_with($llmAccess->model, 'claude-');
        if ($isClaude) {
            $cachedResponse = $this->cacheService->getOAIResponse($messages, $tools ? 'tools' : '');
            if ($cachedResponse) {
                return $cachedResponse;
            }

            $response = $this->getCompletionFromClaude($llmAccess, $messages, $tools);

            try {
                $this->cacheService->saveOAIResponse($messages, $response, $variationKey . '+claude');
            } catch (\Exception) {
            }

            return $response;
        }

        $client = \OpenAI::factory()->withApiKey($llmAccess->token);
        if ($llmAccess->apiUrl) {
            $client = $client->withBaseUri($llmAccess->apiUrl);
        }
        $client = $client->make();

        $requestMessages = [];
        foreach ($messages as $message) {
            if ($message instanceof CreateResponseMessage) {
                $msg = $message->toArray();
                $msg['content'] = $msg['content'] ?: '';
                $requestMessages[] = $msg;
                continue;
            }

            $msg = [
                'role' => strtolower($message->role->value),
                'content' => $message->content ?: '-',
            ];
            if ($message->toolCallId) {
                $msg['tool_call_id'] = $message->toolCallId;
            }
            $requestMessages[] = $msg;
        }

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
        $response = $this->parseMultiToolResponseHermes($response);

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

    private function parseMultiToolResponseHermes(CreateResponse $response)
    {
        $toolStart = "<tool_call>";
        if (
            empty($response->choices[0]->message->content)
            || !str_starts_with($response->choices[0]->message->content, $toolStart)
        ) {
            return $response;
        }
        $toolEnd = "</tool_call>";

        $responseData = $response->toArray();
        $stringResponse = trim($responseData['choices'][0]['message']['content']);

        $toolCalls = []; // {"name": "addReviewCommentToCodeBlock"
        // use regex to extract tool calls
        $pattern = "/$toolStart(.*?)$toolEnd/s";
        preg_match_all($pattern, $stringResponse, $matches);
        foreach ($matches[1] as $match) {
            try {
                $toolCall = json_decode($match, true, 512, JSON_THROW_ON_ERROR);
                $toolCalls[] = [
                    'id' => $toolCall['name'],
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall['name'],
                        'arguments' => $toolCall['arguments'],
                    ],
                ];
            } catch (\JsonException) {
                continue;
            }
        }

        // remove tool calls from response using regex
        $responseData['choices'][0]['message']['content'] = preg_replace($pattern, '', $stringResponse);

        // find tool calls in outside of tags, starting with {"name": "addReviewCommentToCodeBlock"
        $pattern = "/\{.*?\"name\":\s*\"addReviewCommentToCodeBlock\".*?\}/s";
        preg_match_all($pattern, $stringResponse, $matches);
        foreach ($matches[0] as $match) {
            try {
                $toolCall = json_decode($match, true, 512, JSON_THROW_ON_ERROR);
                $toolCalls[] = [
                    'id' => $toolCall['name'],
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall['name'],
                        'arguments' => $toolCall['arguments'],
                    ],
                ];
            } catch (\JsonException) {
                continue;
            }
        }
        // remove tool calls from response using regex
        $responseData['choices'][0]['message']['content'] = preg_replace($pattern, '', $responseData['choices'][0]['message']['content']);

        $responseData['choices'][0]['message']['tool_calls'] = $toolCalls;
        $responseData['choices'][0]['finish_reason'] = 'tool_calls';

        return CreateResponse::from($responseData, $response->meta());
    }

    /**
     * @param AgentMessage[] $messages
     */
    private function getCompletionFromClaude(LLMAccessCredential $llmAccess, array $messages, array $tools): CreateResponse
    {
        // Extract system message
        $systemMessage = '';
        $requestMessages = [];
        foreach ($messages as $key => $message) {
            if ($message instanceof CreateResponseMessage && $message->toolCalls) {
                $content = [];
                foreach ($message->toolCalls as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall->id,
                        'name' => $toolCall->function->name,
                        'input' => json_decode($toolCall->function->arguments, true),
                    ];
                }
                $requestMessages[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];
                continue;
            }


            if ($message->role->value === 'system') {
                $systemMessage = $message->content;
                continue;  // Skip adding system message to requestMessages
            }

            if ($message->toolCallId) {
                // This is a tool response message
                $requestMessages[] = [
                    'role' => 'user',
                    'content' => [
                        'type' => 'tool_result',
                        'tool_use_id' => $message->toolCallId,
                        'content' => $message->content ?: '',
                    ],
                ];
                continue;
            }

            // Regular message handling
            if ($message->role->value === 'assistant') {
                $role = 'assistant';
            } else {
                $role = 'user';  // Claude only accepts 'user' and 'assistant' roles
            }
            $requestMessages[] = [
                'role' => $role,
                'content' => $message->content ?: '',
            ];
        }

        // Convert OpenAI tools format to Claude tools format
        $claudeTools = [];
        foreach ($tools as $tool) {
            $claudeTool = [
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'input_schema' => $tool['function']['parameters'],
            ];
            $claudeTools[] = $claudeTool;
        }

        try {
            $response = $this->httpClient->request('POST', $llmAccess->apiUrl ?: 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $llmAccess->token,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $llmAccess->model,
                    'max_tokens' => 4000,
                    'system' => $systemMessage,
                    'messages' => $requestMessages,
                    'tools' => $claudeTools,
                ],
            ]);
            $responseData = $response->toArray();
        } catch (\Exception $e) {
            $errorResponse = $e->getResponse()->getContent(false);

            throw new \Exception($errorResponse);
        }

        // Convert Claude response to OpenAI format
        $openAIResponse = [
            'id' => $responseData['id'],
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $llmAccess->model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $responseData['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $responseData['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($responseData['usage']['input_tokens'] ?? 0) + ($responseData['usage']['output_tokens'] ?? 0),
            ],
        ];

        // Handle content blocks and tool calls
        if (!empty($responseData['content'])) {
            $textContent = '';
            $toolCalls = [];

            foreach ($responseData['content'] as $block) {
                if ($block['type'] === 'text') {
                    $textContent .= $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    $toolCalls[] = [
                        'id' => $block['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'],
                            'arguments' => json_encode($block['input']),
                        ],
                    ];
                }
            }

            if (!empty($toolCalls)) {
                $openAIResponse['choices'][0]['message']['tool_calls'] = $toolCalls;
                $openAIResponse['choices'][0]['finish_reason'] = 'tool_calls';
            }
            $openAIResponse['choices'][0]['message']['content'] = $textContent;
        }

        // Create meta information
        $meta = MetaInformation::from([
            'x-request-id' => [$responseData['id']],
            'openai-model' => [$llmAccess->model],
            'openai-organization' => ['anthropic'],
            'openai-processing-ms' => ['0'],
            'openai-version' => ['2023-06-01'],
        ]);

        return CreateResponse::from($openAIResponse, $meta);
    }
}
