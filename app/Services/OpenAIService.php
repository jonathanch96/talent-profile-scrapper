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
            $prompt .= "**EXTRACTED CV/RESUME CONTENT (CONTAINS EXACT WORK EXPERIENCE DATES):**\n";
            $prompt .= "This CV content contains the most accurate work experience information with exact company names, job titles, and date periods.\n";
            $prompt .= "Pay special attention to the EXPERIENCE section for date ranges like 'December 2023 - Present'.\n\n";

            foreach ($scrapedData['extracted_documents'] as $doc) {
                $prompt .= "Document: {$doc['original_name']}\n";
                $prompt .= "Content:\n" . $doc['content'] . "\n\n";

                // Extract and highlight experience section if it exists
                if (stripos($doc['content'], 'EXPERIENCE') !== false) {
                    $prompt .= "*** WORK EXPERIENCE SECTION IDENTIFIED IN CV - USE THESE EXACT DATES FOR PERIODS ***\n\n";
                }
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
         $prompt .= "4. **CRITICAL FOR EXPERIENCES**: Extract ALL work history from CV/Resume content with EXACT dates:\n";
         $prompt .= "   - Look for date patterns like 'December 2023 - Present', 'March 2022 - December 2023', 'September 2021 - March 2022'\n";
         $prompt .= "   - Extract company names exactly as written (e.g., 'UP10 Media', 'Gold Cosmetics & Skin Care', 'Marketmen Group')\n";
         $prompt .= "   - Copy the period EXACTLY from the CV (do NOT abbreviate or change format)\n";
         $prompt .= "   - If CV shows 'Present', keep it as 'Present' not 'current' or other variations\n";
         $prompt .= "   - Extract job titles exactly as written (e.g., 'Senior Video editor', 'Video editor', 'Content Creator')\n";
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
         $prompt .= "15. **PAY SPECIAL ATTENTION**: The CV/Resume content contains the most accurate work experience data with exact dates\n";
         $prompt .= "16. **EXPERIENCE PERIOD EXAMPLES** from CV format:\n";
         $prompt .= "    - 'December 2023 - Present' → use exactly as 'December 2023 - Present'\n";
         $prompt .= "    - 'March 2022 - December 2023' → use exactly as 'March 2022 - December 2023'\n";
         $prompt .= "    - 'September 2021 - March 2022' → use exactly as 'September 2021 - March 2022'\n";
         $prompt .= "17. **CRITICAL**: For views and likes, ALWAYS return INTEGER values, not strings:\n";
         $prompt .= "    - Convert '5 million' to 5000000\n";
         $prompt .= "    - Convert '1.2K' to 1200\n";
         $prompt .= "    - Convert '500k' to 500000\n";
         $prompt .= "    - If no data available, use null (not 0)\n";
         $prompt .= "18. Ensure all numeric fields are actual numbers, not strings\n\n";

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
     * Get existing or create new ContentTypeValue
     *
     * @param int $contentTypeId
     * @param string $title
     * @return int|null
     */
    private function getOrCreateContentTypeValue(int $contentTypeId, string $title): ?int
    {
        try {
            // Use firstOrCreate to handle concurrent insertions gracefully
            $contentTypeValue = ContentTypeValue::firstOrCreate(
                [
                    'content_type_id' => $contentTypeId,
                    'title' => trim($title)
                ],
                [
                    'description' => trim($title),
                    'order' => ContentTypeValue::where('content_type_id', $contentTypeId)->count() + 1
                ]
            );

            Log::info('Found/Created ContentTypeValue', [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'id' => $contentTypeValue->id,
                'was_created' => $contentTypeValue->wasRecentlyCreated
            ]);

            return $contentTypeValue->id;

        } catch (Exception $e) {
            Log::error('Error creating/finding ContentTypeValue', [
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

    /**
     * Rank talents using LLM based on search relevance
     *
     * @param string $searchQuery
     * @param \Illuminate\Database\Eloquent\Collection $talents
     * @return array
     * @throws Exception
     */
    public function rankTalentsUsingLLM(string $searchQuery, $talents): array
    {
        try {
            // Convert collection to array for processing
            $talentsArray = $talents->toArray();
            $prompt = $this->buildTalentRankingPrompt($searchQuery, $talentsArray);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert talent matching specialist. You MUST respond with ONLY a JSON object in this exact format: {"rankings": {"id": score}}. No markdown, no explanations, just the JSON object. Analyze the search query and rank talents based on relevance, returning integer scores from 0-100.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 2000,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object']
            ]);

            if (!$response->successful()) {
                throw new Exception("OpenAI API error: {$response->status()} - {$response->body()}");
            }

            $result = $response->json();
            $content = $result['choices'][0]['message']['content'];

            // Clean the content in case there's any markdown or extra text
            $content = trim($content);
            $content = preg_replace('/^```json\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);

            $rankings = json_decode($content, true);

            // Validate response format strictly
            if (!isset($rankings['rankings']) || !is_array($rankings['rankings'])) {
                Log::error('Invalid ranking response format', [
                    'content' => $content,
                    'parsed' => $rankings
                ]);
                throw new Exception("Invalid ranking response format - missing 'rankings' key or not an array");
            }

                        // Validate that all talent IDs are present
            $expectedIds = array_map(fn($talent) => (string)$talent['id'], $talentsArray);
            $receivedIds = array_keys($rankings['rankings']);

            $missingIds = array_diff($expectedIds, $receivedIds);
            if (!empty($missingIds)) {
                Log::warning('Missing talent IDs in ranking response', [
                    'expected' => $expectedIds,
                    'received' => $receivedIds,
                    'missing' => $missingIds
                ]);
            }

            // Validate scores are integers between 0-100
            foreach ($rankings['rankings'] as $id => $score) {
                if (!is_numeric($score) || $score < 0 || $score > 100) {
                    Log::warning('Invalid score in ranking response', [
                        'talent_id' => $id,
                        'score' => $score
                    ]);
                    $rankings['rankings'][$id] = max(0, min(100, (int)$score));
                }
            }

            // Ensure we have rankings for all talents (add missing ones with score 0)
            foreach ($talentsArray as $talent) {
                $talentId = (string)$talent['id'];
                if (!isset($rankings['rankings'][$talentId])) {
                    $rankings['rankings'][$talentId] = 0;
                    Log::warning('Missing ranking for talent, setting to 0', ['talent_id' => $talentId]);
                }
            }

            // Apply rankings to talents
            $rankedTalents = [];
            foreach ($talentsArray as $talent) {
                $talentId = (string)$talent['id'];
                $rankingScore = $rankings['rankings'][$talentId];

                $rankedTalents[] = [
                    'id' => $talent['id'],
                    'username' => $talent['username'],
                    'name' => $talent['name'],
                    'job_title' => $talent['job_title'],
                    'description' => $talent['description'],
                    'ranking_score' => (int)$rankingScore,
                    'full_data' => $talent
                ];
            }

            Log::info('LLM ranking completed', [
                'query' => $searchQuery,
                'talents_ranked' => count($rankedTalents),
                'avg_score' => round(collect($rankedTalents)->avg('ranking_score'), 2)
            ]);

            return $rankedTalents;

        } catch (Exception $e) {
            Log::error('LLM ranking failed', [
                'query' => $searchQuery,
                'error' => $e->getMessage()
            ]);

            // Convert collection to array for fallback processing
            $talentsArray = $talents->toArray();

            // Return talents with fallback ranking
            return array_map(function($talent, $index) {
                return [
                    'id' => $talent['id'],
                    'username' => $talent['username'],
                    'name' => $talent['name'],
                    'job_title' => $talent['job_title'],
                    'description' => $talent['description'],
                    'ranking_score' => max(100 - ($index * 5), 10), // Descending scores
                    'full_data' => $talent
                ];
            }, $talentsArray, array_keys($talentsArray));
        }
    }

    /**
     * Build prompt for LLM talent ranking with fixed format
     *
     * @param string $searchQuery
     * @param array $talents
     * @return string
     */
    private function buildTalentRankingPrompt(string $searchQuery, array $talents): string
    {
        $prompt = "**SEARCH QUERY:** \"{$searchQuery}\"\n\n";

        $prompt .= "**INSTRUCTION:** Rank the following talents based on how well they match the search query. ";
        $prompt .= "Consider job titles, skills, experience, projects, and overall relevance. ";
        $prompt .= "Return scores from 0-100 (100 being perfect match, 0 being no relevance).\n\n";

        $prompt .= "**TALENTS TO RANK:**\n";

        foreach ($talents as $index => $talent) {
            $prompt .= "**Talent ID {$talent['id']}:**\n";
            $prompt .= "- Name: {$talent['name']}\n";
            $prompt .= "- Job Title: " . ($talent['job_title'] ?? 'N/A') . "\n";
            $prompt .= "- Description: " . (substr($talent['description'] ?? '', 0, 300)) . "\n";

            // Add experiences
            if (!empty($talent['experiences'])) {
                $prompt .= "- Experience: ";
                $experiences = array_slice($talent['experiences'], 0, 3);
                foreach ($experiences as $exp) {
                    $prompt .= "{$exp['job_type']} at {$exp['client_name']}; ";
                }
                $prompt .= "\n";
            }

            // Add skills from contents
            if (!empty($talent['contents'])) {
                $skills = [];
                foreach ($talent['contents'] as $content) {
                    if (isset($content['content_type']['name']) &&
                        (in_array($content['content_type']['name'], ['Skills', 'Software', 'Job Type']))) {
                        $skills[] = $content['content_type_value']['title'] ?? '';
                    }
                }
                if (!empty($skills)) {
                    $prompt .= "- Skills: " . implode(', ', array_slice(array_filter($skills), 0, 10)) . "\n";
                }
            }

            // Add projects
            if (!empty($talent['projects'])) {
                $prompt .= "- Projects: ";
                $projects = array_slice($talent['projects'], 0, 2);
                foreach ($projects as $project) {
                    $prompt .= "{$project['title']}; ";
                }
                $prompt .= "\n";
            }

            $prompt .= "\n";
        }

                $prompt .= "**REQUIRED OUTPUT FORMAT:**\n";
        $prompt .= "You MUST return ONLY a JSON object with this EXACT structure. No additional text before or after:\n\n";

        // Build exact format example
        $prompt .= "{\n";
        $prompt .= '  "rankings": {' . "\n";
        foreach ($talents as $index => $talent) {
            $prompt .= '    "' . $talent['id'] . '": ' . 'INTEGER_SCORE_0_TO_100';
            if ($index < count($talents) - 1) {
                $prompt .= ',';
            }
            $prompt .= "\n";
        }
        $prompt .= "  }\n";
        $prompt .= "}\n\n";

        $prompt .= "**EXAMPLE:**\n";
        $prompt .= "{\n";
        $prompt .= '  "rankings": {' . "\n";
        $prompt .= '    "1": 95,' . "\n";
        $prompt .= '    "2": 40' . "\n";
        $prompt .= "  }\n";
        $prompt .= "}\n\n";

        $prompt .= "**RANKING CRITERIA:**\n";
        $prompt .= "- 90-100: Perfect or near-perfect match for the search query\n";
        $prompt .= "- 70-89: Strong match with relevant skills/experience\n";
        $prompt .= "- 50-69: Moderate match with some relevant aspects\n";
        $prompt .= "- 20-49: Weak match with minimal relevance\n";
        $prompt .= "- 0-19: No meaningful relevance to search query\n\n";

        $prompt .= "**CRITICAL REQUIREMENTS:**\n";
        $prompt .= "- Return ONLY the JSON object, no markdown, no explanation, no additional text\n";
        $prompt .= "- Use integer scores only (0-100)\n";
        $prompt .= "- Include ALL talent IDs as strings in the rankings object\n";
        $prompt .= "- Ensure valid JSON format\n";
        $prompt .= "- Must have exactly this structure: {\"rankings\": {\"id\": score}}\n";

        return $prompt;
    }
}
