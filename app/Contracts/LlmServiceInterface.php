<?php

namespace App\Contracts;

interface LlmServiceInterface
{
    /**
     * Send a chat completion request to the LLM
     *
     * @param array $messages
     * @param array $options
     * @return array
     * @throws \Exception
     */
    public function chatCompletion(array $messages, array $options = []): array;

    /**
     * Generate embedding vector for text
     *
     * @param string $text
     * @return array|null
     * @throws \Exception
     */
    public function generateEmbedding(string $text): ?array;

    /**
     * Get the model name being used
     *
     * @return string
     */
    public function getModelName(): string;

    /**
     * Get the maximum tokens supported
     *
     * @return int
     */
    public function getMaxTokens(): int;
}
