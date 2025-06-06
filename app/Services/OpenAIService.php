<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ContentType;
use App\Models\ContentTypeValue;
use Exception;

class OpenAIService
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
     * Process scraped portfolio data and extract talent information
     *
     * @param array $scrapedData
     * @return array
     * @throws Exception
     */
    public function processTalentPortfolio(array $scrapedData): array
    {
        $prompt = $this->buildTalentExtractionPrompt($scrapedData);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(120)
            ->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert talent acquisition specialist. Your job is to analyze portfolio data and extract relevant information to create a comprehensive talent profile.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'response_format' => ['type' => 'json_object']
            ]);

            if (!$response->successful()) {
                throw new Exception("OpenAI API error: {$response->status()} - {$response->body()}");
            }

            $result = $response->json();
            $extractedData = json_decode($result['choices'][0]['message']['content'], true);

            // Process and map content types
            return $this->mapTalentData($extractedData);

        } catch (Exception $e) {
            Log::error('OpenAI Talent Processing Error', [
                'message' => $e->getMessage(),
                'scraped_url' => $scrapedData['url'] ?? 'unknown'
            ]);
            throw $e;
        }
    }

    /**
     * Build the prompt for talent extraction
     *
     * @param array $scrapedData
     * @return string
     */
    private function buildTalentExtractionPrompt(array $scrapedData): string
    {
        // Get existing content type values from database
        $existingValues = $this->getExistingContentTypeValues();

        $prompt = "Analyze the following scraped portfolio data and extract talent information. You must map the data to existing content type values when possible, and only suggest new values when absolutely necessary.\n\n";

        $prompt .= "**SCRAPED DATA:**\n";
        $prompt .= "URL: " . ($scrapedData['url'] ?? 'N/A') . "\n";
        $prompt .= "Title: " . ($scrapedData['title'] ?? 'N/A') . "\n";

        // Include paragraphs instead of full_text since we're using SPA scraping
        if (!empty($scrapedData['text']['paragraphs'])) {
            $prompt .= "Content Paragraphs:\n";
            foreach (array_slice($scrapedData['text']['paragraphs'], 0, 20) as $paragraph) {
                $prompt .= "- " . substr($paragraph, 0, 200) . "...\n";
            }
            $prompt .= "\n";
        }

        if (!empty($scrapedData['headings'])) {
            $prompt .= "Headings:\n";
            foreach ($scrapedData['headings'] as $heading) {
                $prompt .= "- {$heading['level']}: {$heading['text']}\n";
            }
            $prompt .= "\n";
        }

        if (!empty($scrapedData['videos'])) {
            $prompt .= "Videos Found: " . count($scrapedData['videos']) . " videos (indicates video content creation)\n\n";
        }

        if (!empty($scrapedData['images'])) {
            $prompt .= "Images Found: " . count($scrapedData['images']) . " images\n";
            foreach (array_slice($scrapedData['images'], 0, 5) as $image) {
                if (!empty($image['alt'])) {
                    $prompt .= "- Alt text: {$image['alt']}\n";
                }
            }
            $prompt .= "\n";
        }

        if (!empty($scrapedData['links'])) {
            $prompt .= "Important Links:\n";
            foreach (array_slice($scrapedData['links'], 0, 10) as $link) {
                if (!empty($link['text']) && !empty($link['url'])) {
                    $prompt .= "- {$link['text']}: {$link['url']}\n";
                }
            }
            $prompt .= "\n";
        }

        // Add existing content type values to the prompt
        $prompt .= "**EXISTING CONTENT TYPE VALUES (USE THESE WHEN POSSIBLE):**\n";
        foreach ($existingValues as $typeName => $values) {
            $prompt .= "{$typeName}:\n";
            foreach ($values as $value) {
                $prompt .= "  - ID {$value['id']}: {$value['title']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "**EXTRACTION REQUIREMENTS:**\n";
        $prompt .= "Extract and return the following information in JSON format. You MUST use the existing content type value IDs from above when the content matches. Only suggest new values (with negative IDs) when the content doesn't match any existing values:\n\n";

        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"talent\": {\n";
        $prompt .= "    \"name\": \"Full name of the talent\",\n";
        $prompt .= "    \"job_title\": \"Primary job title/role\",\n";
        $prompt .= "    \"description\": \"Professional description/bio (2-3 sentences)\",\n";
        $prompt .= "    \"location\": \"Location if mentioned or 'Not specified'\",\n";
        $prompt .= "    \"talent_status\": \"Available\" or \"Busy\" or \"Not specified\",\n";
        $prompt .= "    \"availability\": \"Full-time\" or \"Part-time\" or \"Freelance\" or \"Contract\" or \"Not specified\"\n";
        $prompt .= "  },\n";
        $prompt .= "  \"content_mappings\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"content_type_id\": 1,\n";
        $prompt .= "      \"content_type_value_id\": 23,\n";
        $prompt .= "      \"matched_text\": \"Text from portfolio that matches this value\",\n";
        $prompt .= "      \"confidence\": \"high\" or \"medium\" or \"low\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"new_content_values\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"content_type_id\": 1,\n";
        $prompt .= "      \"title\": \"New Value Title\",\n";
        $prompt .= "      \"reason\": \"Why this new value is needed\",\n";
        $prompt .= "      \"evidence\": \"Portfolio text that supports this new value\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"experiences\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"client_name\": \"Client or Company name\",\n";
        $prompt .= "      \"job_type\": \"Full-time\" or \"Part-time\" or \"Freelance\" or \"Contract\",\n";
        $prompt .= "      \"period\": \"Duration (e.g., 'Jan 2020 - Dec 2022')\",\n";
        $prompt .= "      \"description\": \"Brief description of work done\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"projects\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"title\": \"Project title\",\n";
        $prompt .= "      \"description\": \"Project description\",\n";
        $prompt .= "      \"link\": \"Project URL if available\",\n";
        $prompt .= "      \"project_roles\": [\"Roles in this project\"]\n";
        $prompt .= "    }\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";

        $prompt .= "**IMPORTANT MAPPING INSTRUCTIONS:**\n";
        $prompt .= "1. ALWAYS use existing content type value IDs when the portfolio content matches\n";
        $prompt .= "2. Be flexible with matching - 'Adobe Premiere' should match 'Adobe Premiere Pro' (ID 23)\n";
        $prompt .= "3. Only suggest new_content_values when you find skills/software/etc that don't exist\n";
        $prompt .= "4. Include 'matched_text' to show which portfolio text matches each mapping\n";
        $prompt .= "5. Set confidence level based on how clearly the skill/software is mentioned\n";
        $prompt .= "6. Focus on video editing, content creation, and creative skills\n";
        $prompt .= "7. Extract information ONLY from the provided data\n";
        $prompt .= "8. Be conservative - only include information you're confident about\n";
        $prompt .= "9. Return valid JSON format\n\n";

        $prompt .= "**CONTENT TYPE IDS REFERENCE:**\n";
        $prompt .= "- 1: Job Type\n";
        $prompt .= "- 2: Content Vertical  \n";
        $prompt .= "- 3: Platform Specialty\n";
        $prompt .= "- 4: Skills\n";
        $prompt .= "- 5: Software\n";

        return $prompt;
    }

    /**
     * Get existing content type values from database
     *
     * @return array
     */
    private function getExistingContentTypeValues(): array
    {
        $contentTypes = ContentType::with('contentTypeValues')->get();
        $existingValues = [];

        foreach ($contentTypes as $contentType) {
            $existingValues[$contentType->name] = $contentType->contentTypeValues->map(function ($value) {
                return [
                    'id' => $value->id,
                    'title' => $value->title
                ];
            })->toArray();
        }

        return $existingValues;
    }

    /**
     * Map extracted talent data to database format
     *
     * @param array $extractedData
     * @return array
     */
    private function mapTalentData(array $extractedData): array
    {
        $mappedData = [
            'talent' => $extractedData['talent'] ?? [],
            'content_mappings' => [],
            'experiences' => $extractedData['experiences'] ?? [],
            'projects' => $extractedData['projects'] ?? []
        ];

        // Handle direct content mappings from AI
        if (!empty($extractedData['content_mappings'])) {
            foreach ($extractedData['content_mappings'] as $mapping) {
                if (isset($mapping['content_type_id']) && isset($mapping['content_type_value_id'])) {
                    $mappedData['content_mappings'][] = [
                        'content_type_id' => $mapping['content_type_id'],
                        'content_type_value_id' => $mapping['content_type_value_id'],
                        'matched_text' => $mapping['matched_text'] ?? '',
                        'confidence' => $mapping['confidence'] ?? 'medium'
                    ];
                }
            }
        }

        // Handle new content values suggested by AI
        if (!empty($extractedData['new_content_values'])) {
            foreach ($extractedData['new_content_values'] as $newValue) {
                if (isset($newValue['content_type_id']) && isset($newValue['title'])) {
                    // Create the new content type value
                    $contentTypeValueId = $this->createContentTypeValue(
                        $newValue['content_type_id'],
                        $newValue['title']
                    );

                    if ($contentTypeValueId) {
                        $mappedData['content_mappings'][] = [
                            'content_type_id' => $newValue['content_type_id'],
                            'content_type_value_id' => $contentTypeValueId,
                            'matched_text' => $newValue['evidence'] ?? '',
                            'confidence' => 'high', // High confidence since it was specifically suggested by AI
                            'newly_created' => true
                        ];
                    }
                }
            }
        }

        // Legacy support for old format (job_types, skills, software arrays)
        if (!empty($extractedData['job_types'])) {
            foreach ($extractedData['job_types'] as $jobType) {
                $contentTypeValueId = $this->getOrCreateContentTypeValue(
                    ContentType::JOB_TYPE,
                    $jobType
                );
                if ($contentTypeValueId) {
                    $mappedData['content_mappings'][] = [
                        'content_type_id' => ContentType::JOB_TYPE,
                        'content_type_value_id' => $contentTypeValueId
                    ];
                }
            }
        }

        if (!empty($extractedData['content_verticals'])) {
            foreach ($extractedData['content_verticals'] as $vertical) {
                $contentTypeValueId = $this->getOrCreateContentTypeValue(
                    ContentType::CONTENT_VERTICAL,
                    $vertical
                );
                if ($contentTypeValueId) {
                    $mappedData['content_mappings'][] = [
                        'content_type_id' => ContentType::CONTENT_VERTICAL,
                        'content_type_value_id' => $contentTypeValueId
                    ];
                }
            }
        }

        if (!empty($extractedData['platform_specialties'])) {
            foreach ($extractedData['platform_specialties'] as $platform) {
                $contentTypeValueId = $this->getOrCreateContentTypeValue(
                    ContentType::PLATFORM_SPECIALTY,
                    $platform
                );
                if ($contentTypeValueId) {
                    $mappedData['content_mappings'][] = [
                        'content_type_id' => ContentType::PLATFORM_SPECIALTY,
                        'content_type_value_id' => $contentTypeValueId
                    ];
                }
            }
        }

        if (!empty($extractedData['skills'])) {
            foreach ($extractedData['skills'] as $skill) {
                $contentTypeValueId = $this->getOrCreateContentTypeValue(
                    ContentType::SKILLS,
                    $skill
                );
                if ($contentTypeValueId) {
                    $mappedData['content_mappings'][] = [
                        'content_type_id' => ContentType::SKILLS,
                        'content_type_value_id' => $contentTypeValueId
                    ];
                }
            }
        }

        if (!empty($extractedData['software'])) {
            foreach ($extractedData['software'] as $software) {
                $contentTypeValueId = $this->getOrCreateContentTypeValue(
                    ContentType::SOFTWARE,
                    $software
                );
                if ($contentTypeValueId) {
                    $mappedData['content_mappings'][] = [
                        'content_type_id' => ContentType::SOFTWARE,
                        'content_type_value_id' => $contentTypeValueId
                    ];
                }
            }
        }

        return $mappedData;
    }

    /**
     * Create new ContentTypeValue
     *
     * @param int $contentTypeId
     * @param string $title
     * @return int|null
     */
    private function createContentTypeValue(int $contentTypeId, string $title): ?int
    {
        try {
            $contentTypeValue = ContentTypeValue::create([
                'content_type_id' => $contentTypeId,
                'title' => trim($title)
            ]);

            Log::info('Created new content type value', [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'id' => $contentTypeValue->id
            ]);

            return $contentTypeValue->id;
        } catch (Exception $e) {
            Log::error('Failed to create content type value', [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get existing or create new ContentTypeValue
     *
     * @param int $contentTypeId
     * @param string $title
     * @return int|null
     */
    private function getOrCreateContentTypeValue(int $contentTypeId, string $title): ?int
    {
        try {
            // Try to find existing content type value (case-insensitive)
            $existing = ContentTypeValue::where('content_type_id', $contentTypeId)
                ->whereRaw('LOWER(title) = LOWER(?)', [trim($title)])
                ->first();

            if ($existing) {
                return $existing->id;
            }

            // Create new content type value
            $newContentTypeValue = ContentTypeValue::create([
                'content_type_id' => $contentTypeId,
                'title' => trim($title),
                'description' => trim($title),
                'order' => ContentTypeValue::where('content_type_id', $contentTypeId)->count() + 1
            ]);

            Log::info('Created new ContentTypeValue', [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'id' => $newContentTypeValue->id
            ]);

            return $newContentTypeValue->id;

        } catch (Exception $e) {
            Log::error('Error creating ContentTypeValue', [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
