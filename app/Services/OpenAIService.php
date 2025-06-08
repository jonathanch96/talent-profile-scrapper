<?php

namespace App\Services;

use App\Contracts\LlmServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OpenAIService implements LlmServiceInterface
{
    protected string $apiKey;
    protected ?string $organization;
    protected string $model;
    protected int $maxTokens;
    protected float $temperature;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->organization = config('services.openai.organization');
        $this->model = config('services.openai.model', 'gpt-4o-mini');
        $this->maxTokens = config('services.openai.max_tokens', 4000);
        $this->temperature = config('services.openai.temperature', 0.7);

        if (empty($this->apiKey)) {
            throw new Exception('OpenAI API key is not configured');
        }
    }

    /**
     * Send a chat completion request to OpenAI
     *
     * @param array $messages
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function chatCompletion(array $messages, array $options = []): array
    {
        try {
            $requestData = [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
                'temperature' => $options['temperature'] ?? $this->temperature,
            ];

            // Add response format if specified
            if (isset($options['response_format'])) {
                $requestData['response_format'] = $options['response_format'];
            }

            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ];

            // Add organization header if configured
            if ($this->organization) {
                $headers['OpenAI-Organization'] = $this->organization;
            }

            $response = Http::withHeaders($headers)
                ->timeout(120)
                ->post($this->baseUrl . '/chat/completions', $requestData);

            if (!$response->successful()) {
                throw new Exception("OpenAI API error: {$response->status()} - {$response->body()}");
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('OpenAI Chat Completion Error', [
                'message' => $e->getMessage(),
                'model' => $this->model,
                'messages_count' => count($messages)
            ]);
            throw $e;
        }
    }

    /**
     * Generate embedding vector for text using OpenAI
     *
     * @param string $text
     * @return array|null
     * @throws Exception
     */
    public function generateEmbedding(string $text): ?array
    {
        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ];

            // Add organization header if configured
            if ($this->organization) {
                $headers['OpenAI-Organization'] = $this->organization;
            }

            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->post($this->baseUrl . '/embeddings', [
                    'model' => 'text-embedding-3-small',
                    'input' => $text,
                    'encoding_format' => 'float'
                ]);

            if (!$response->successful()) {
                throw new Exception("OpenAI Embedding API error: {$response->status()} - {$response->body()}");
            }

            $result = $response->json();

            if (isset($result['data'][0]['embedding'])) {
                return $result['data'][0]['embedding'];
            }

            throw new Exception("Invalid embedding response format");

        } catch (Exception $e) {
            Log::error('OpenAI Embedding Error', [
                'message' => $e->getMessage(),
                'text_length' => strlen($text)
            ]);
            throw $e;
        }
    }

    /**
     * Get the model name being used
     *
     * @return string
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Get the maximum tokens supported
     *
     * @return int
     */
    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }
}
