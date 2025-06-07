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
        // First analyze YouTube videos if available
        $youTubeAnalysis = [];
        if (!empty($scrapedData['videos'])) {
            $youTubeService = app(YouTubeAnalysisService::class);
            $youTubeAnalysis = $youTubeService->analyzeYouTubeVideos($scrapedData);
        }

        $prompt = $this->buildTalentExtractionPrompt($scrapedData, $youTubeAnalysis);

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
                        'content' => 'You are an expert talent acquisition specialist. Your job is to analyze portfolio data and extract relevant information to create a comprehensive talent profile that matches the expected format exactly.'
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

            // Add YouTube analysis to the result
            if (!empty($youTubeAnalysis)) {
                $extractedData['youtube_analysis'] = $youTubeAnalysis;
            }

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
     * @param array $youTubeAnalysis
     * @return string
     */
    private function buildTalentExtractionPrompt(array $scrapedData, array $youTubeAnalysis = []): string
    {
        $prompt = "Analyze the following scraped portfolio data and extract talent information to match the EXACT format of the expected mapping structure.\n\n";

        $prompt .= "**SCRAPED DATA:**\n";
        $prompt .= "URL: " . ($scrapedData['url'] ?? 'N/A') . "\n";
        $prompt .= "Title: " . ($scrapedData['title'] ?? 'N/A') . "\n";
        $prompt .= "Meta Description: " . ($scrapedData['meta']['description'] ?? 'N/A') . "\n\n";

        // Include paragraphs
        if (!empty($scrapedData['text']['paragraphs'])) {
            $prompt .= "Content Paragraphs:\n";
            foreach (array_slice($scrapedData['text']['paragraphs'], 0, 25) as $paragraph) {
                $prompt .= "- " . $paragraph . "\n";
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

        // Include extracted documents content
        if (!empty($scrapedData['extracted_documents'])) {
            $prompt .= "**EXTRACTED CV/RESUME CONTENT:**\n";
            foreach ($scrapedData['extracted_documents'] as $doc) {
                $prompt .= "Document: {$doc['original_name']}\n";
                $prompt .= $doc['content'] . "\n\n";
            }
        }

        if (!empty($scrapedData['videos'])) {
            $prompt .= "Videos Found: " . count($scrapedData['videos']) . " videos\n";
            foreach ($scrapedData['videos'] as $video) {
                $prompt .= "- {$video['url']}\n";
            }
            $prompt .= "\n";
        }

        // Include YouTube analysis if available
        if (!empty($youTubeAnalysis)) {
            $prompt .= "YouTube Video Analysis:\n";
            foreach ($youTubeAnalysis as $video) {
                $prompt .= "- Video ID: {$video['video_id']}\n";
                $prompt .= "  URL: {$video['youtube_url']}\n";
                $prompt .= "  Content Vertical: {$video['content_vertical']}\n";
                $prompt .= "  Confidence: {$video['confidence']}\n";
                $prompt .= "  Reasoning: {$video['reasoning']}\n\n";
            }
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

        $prompt .= "**EXPECTED OUTPUT FORMAT:**\n";
        $prompt .= "You MUST return data in this EXACT JSON structure. Use the actual values from the scraped data:\n\n";

        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"name\": \"Extract full name from content\",\n";
        $prompt .= "  \"job_title\": \"Primary job title (e.g., Video Editor, Content Creator)\",\n";
        $prompt .= "  \"description\": \"Professional bio from content (keep original tone and style)\",\n";
        $prompt .= "  \"image\": \"Profile image URL if found, otherwise null\",\n";
        $prompt .= "  \"location\": \"Location if mentioned, otherwise null\",\n";
        $prompt .= "  \"timezone\": \"Timezone if location suggests one, otherwise null\",\n";
        $prompt .= "  \"talent_status\": \"Open to work/Busy/Available or '-' if not specified\",\n";
        $prompt .= "  \"availability\": \"Full-time/Part-time/Freelance/Contract or '-' if not specified\",\n";
        $prompt .= "  \"experiences\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"client_name\": \"Company/Client name\",\n";
        $prompt .= "      \"client_sub_title\": null,\n";
        $prompt .= "      \"client_logo\": \"Logo URL if found, otherwise null\",\n";
        $prompt .= "      \"job_type\": \"Full Time/Part Time/Freelance/Contract\",\n";
        $prompt .= "      \"period\": \"Duration (e.g., 'Jan 2023 - Jan 2024')\",\n";
        $prompt .= "      \"description\": \"Description of work done\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"projects\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"project_roles\": [\"Video Editor\", \"Script Writer\"],\n";
        $prompt .= "      \"title\": \"Project title from content\",\n";
        $prompt .= "      \"views\": 5000000,\n";
        $prompt .= "      \"likes\": 50000,\n";
        $prompt .= "      \"image\": \"Project thumbnail/image URL if found\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"job_types\": [\"Video Editor\", \"Script Writer\"],\n";
        $prompt .= "  \"languages\": [\n";
        $prompt .= "    { \"language\": \"English\", \"proficiency\": \"Native/Fluent/Intermediate/Basic\" }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"content_vertical\": [\n";
        $prompt .= "    \"Travel\", \"Food\", \"Fashion\", \"Beauty\", \"Lifestyle\", \"Technology\", \"Sports\", \"Business\", \"Education\", \"Entertainment\"\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"platform_specialties\": [\n";
        $prompt .= "    \"YouTube\", \"TikTok\", \"Instagram\", \"Facebook\", \"LinkedIn\", \"Website\"\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"softwares\": [\n";
        $prompt .= "    \"Adobe Premiere Pro\", \"Adobe After Effects\", \"Adobe Photoshop\", \"Adobe Illustrator\"\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"skills\": [\n";
        $prompt .= "    \"Video Editing\", \"Motion Graphics\", \"Graphic Design\", \"Photo Editing\"\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";

                 $prompt .= "**EXTRACTION INSTRUCTIONS:**\n";
         $prompt .= "1. Extract the talent's name from the content (look for names in headers, titles, or about sections)\n";
         $prompt .= "2. Identify the main job title/role from the content\n";
         $prompt .= "3. Use the professional description as written in the content (maintain original style)\n";
         $prompt .= "4. For experiences: Extract ALL work history from CV/Resume with accurate company names, periods, and job types\n";
         $prompt .= "5. Include testimonial companies as experiences if mentioned in the portfolio content\n";
         $prompt .= "6. For content_vertical: Analyze the YouTube videos and content to categorize by industry/niche\n";
         $prompt .= "7. For platform_specialties: Identify which platforms they create content for\n";
         $prompt .= "8. For softwares: Extract any software/tools mentioned (Adobe Suite, etc.) from both CV and portfolio\n";
         $prompt .= "9. For skills: Extract specific skills mentioned (Video Editing, Motion Graphics, etc.)\n";
         $prompt .= "10. For projects: Create project entries based on the video content and portfolio pieces\n";
         $prompt .= "11. Use the YouTube analysis to help categorize content verticals\n";
         $prompt .= "12. Extract location information from CV if available\n";
         $prompt .= "13. If information is not available, use null or appropriate placeholder\n";
         $prompt .= "14. Maintain the exact JSON structure and field names\n";
         $prompt .= "15. Pay special attention to the CV/Resume content for detailed work experience\n";
         $prompt .= "16. **CRITICAL**: For views and likes, ALWAYS return INTEGER values, not strings:\n";
         $prompt .= "    - Convert '5 million' to 5000000\n";
         $prompt .= "    - Convert '1.2K' to 1200\n";
         $prompt .= "    - Convert '500k' to 500000\n";
         $prompt .= "    - If no data available, use null (not 0)\n";
         $prompt .= "17. Ensure all numeric fields are actual numbers, not strings\n\n";

        $prompt .= "**IMPORTANT:** Return ONLY the JSON object. Do not include any additional text or explanations.";

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
     * Map extracted talent data to expected format
     *
     * @param array $extractedData
     * @return array
     */
    private function mapTalentData(array $extractedData): array
    {
        // Transform the AI response to match expected mapping structure
        $mappedData = [
            'name' => $extractedData['name'] ?? null,
            'job_title' => $extractedData['job_title'] ?? null,
            'description' => $extractedData['description'] ?? null,
            'image' => $extractedData['image'] ?? null,
            'location' => $extractedData['location'] ?? null,
            'timezone' => $extractedData['timezone'] ?? null,
            'talent_status' => $extractedData['talent_status'] ?? '-',
            'availability' => $extractedData['availability'] ?? '-',
            'experiences' => $extractedData['experiences'] ?? [],
            'projects' => $extractedData['projects'] ?? [],
            'job_types' => $extractedData['job_types'] ?? [],
            'languages' => $extractedData['languages'] ?? [],
            'content_vertical' => $extractedData['content_vertical'] ?? [],
            'platform_specialties' => $extractedData['platform_specialties'] ?? [],
            'softwares' => $extractedData['softwares'] ?? [],
            'skills' => $extractedData['skills'] ?? []
        ];

        // Include YouTube analysis if available
        if (!empty($extractedData['youtube_analysis'])) {
            $mappedData['youtube_analysis'] = $extractedData['youtube_analysis'];
        }

        // Create content mappings for database storage
        $mappedData['content_mappings'] = [];

        // Map job_types
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

        // Map content_vertical
        if (!empty($extractedData['content_vertical'])) {
            foreach ($extractedData['content_vertical'] as $vertical) {
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

        // Map platform_specialties
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

        // Map skills
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

        // Map softwares
        if (!empty($extractedData['softwares'])) {
            foreach ($extractedData['softwares'] as $software) {
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

        Log::info('Content mappings created', [
            'total_mappings' => count($mappedData['content_mappings']),
            'job_types_count' => count($extractedData['job_types'] ?? []),
            'content_vertical_count' => count($extractedData['content_vertical'] ?? []),
            'platform_specialties_count' => count($extractedData['platform_specialties'] ?? []),
            'skills_count' => count($extractedData['skills'] ?? []),
            'softwares_count' => count($extractedData['softwares'] ?? [])
        ]);

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
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
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
}
