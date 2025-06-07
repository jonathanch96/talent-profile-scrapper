<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class YouTubeAnalysisService
{
    protected OpenAIService $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Extract and analyze YouTube videos from scraped data
     *
     * @param array $scrapedData
     * @return array
     */
    public function analyzeYouTubeVideos(array $scrapedData): array
    {
        $videos = $scrapedData['videos'] ?? [];
        $youTubeVideos = [];

        foreach ($videos as $video) {
            $youTubeUrl = $this->extractYouTubeUrl($video['url']);

            if ($youTubeUrl) {
                $videoId = $this->extractVideoId($youTubeUrl);

                if ($videoId) {
                    $youTubeVideos[] = [
                        'original_url' => $video['url'],
                        'youtube_url' => $youTubeUrl,
                        'video_id' => $videoId,
                        'type' => $video['type'] ?? 'unknown'
                    ];
                }
            }
        }

        if (empty($youTubeVideos)) {
            return [];
        }

        // Get video details and categorize content verticals
        return $this->categorizeVideoContent($youTubeVideos);
    }

    /**
     * Extract clean YouTube URL from iframe or embedded URLs
     *
     * @param string $url
     * @return string|null
     */
    private function extractYouTubeUrl(string $url): ?string
    {
        // Handle iframe.ly wrapped URLs
        if (strpos($url, 'iframe.ly') !== false) {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                if (isset($queryParams['url'])) {
                    $decodedUrl = urldecode($queryParams['url']);
                    if (strpos($decodedUrl, 'youtube.com') !== false || strpos($decodedUrl, 'youtu.be') !== false) {
                        return $decodedUrl;
                    }
                }
            }
        }

        // Handle direct YouTube embed URLs
        if (strpos($url, 'youtube.com/embed/') !== false) {
            $videoId = basename(parse_url($url, PHP_URL_PATH));
            return "https://www.youtube.com/watch?v={$videoId}";
        }

        // Handle youtu.be URLs
        if (strpos($url, 'youtu.be/') !== false) {
            return $url;
        }

        // Handle direct YouTube URLs
        if (strpos($url, 'youtube.com') !== false) {
            return $url;
        }

        return null;
    }

    /**
     * Extract video ID from YouTube URL
     *
     * @param string $url
     * @return string|null
     */
    private function extractVideoId(string $url): ?string
    {
        // Handle youtu.be URLs
        if (strpos($url, 'youtu.be/') !== false) {
            $path = parse_url($url, PHP_URL_PATH);
            return trim($path, '/');
        }

        // Handle youtube.com URLs
        if (strpos($url, 'youtube.com') !== false) {
            $parsedUrl = parse_url($url);

            // Handle /watch URLs
            if (strpos($parsedUrl['path'] ?? '', '/watch') !== false && isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                return $queryParams['v'] ?? null;
            }

            // Handle /shorts URLs
            if (strpos($parsedUrl['path'] ?? '', '/shorts/') !== false) {
                return basename($parsedUrl['path']);
            }

            // Handle /embed URLs
            if (strpos($parsedUrl['path'] ?? '', '/embed/') !== false) {
                return basename($parsedUrl['path']);
            }
        }

        return null;
    }

    /**
     * Categorize video content using OpenAI
     *
     * @param array $youTubeVideos
     * @return array
     */
    private function categorizeVideoContent(array $youTubeVideos): array
    {
        try {
            $prompt = $this->buildVideoCategorizationPrompt($youTubeVideos);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert content analyst. Analyze YouTube video URLs and categorize them by content vertical based on the video ID and URL patterns.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object']
            ]);

            if (!$response->successful()) {
                Log::warning('YouTube video categorization failed', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                return $this->getFallbackCategories($youTubeVideos);
            }

            $result = $response->json();
            $categories = json_decode($result['choices'][0]['message']['content'], true);

            return $categories['videos'] ?? $this->getFallbackCategories($youTubeVideos);

        } catch (Exception $e) {
            Log::error('YouTube video categorization error', [
                'message' => $e->getMessage(),
                'video_count' => count($youTubeVideos)
            ]);

            return $this->getFallbackCategories($youTubeVideos);
        }
    }

    /**
     * Build prompt for video categorization
     *
     * @param array $youTubeVideos
     * @return string
     */
    private function buildVideoCategorizationPrompt(array $youTubeVideos): string
    {
        $prompt = "Analyze the following YouTube videos and categorize them by content vertical. Based on the video IDs and URLs, determine the most likely content category for each video.\n\n";

        $prompt .= "**YouTube Videos to Analyze:**\n";
        foreach ($youTubeVideos as $index => $video) {
            $prompt .= ($index + 1) . ". Video ID: {$video['video_id']}\n";
            $prompt .= "   URL: {$video['youtube_url']}\n";
            $prompt .= "   Type: {$video['type']}\n\n";
        }

        $prompt .= "**Available Content Verticals:**\n";
        $prompt .= "- Travel\n";
        $prompt .= "- Food\n";
        $prompt .= "- Fashion\n";
        $prompt .= "- Beauty\n";
        $prompt .= "- Lifestyle\n";
        $prompt .= "- Technology\n";
        $prompt .= "- Sports\n";
        $prompt .= "- Business\n";
        $prompt .= "- Education\n";
        $prompt .= "- Entertainment\n";
        $prompt .= "- Health & Fitness\n";
        $prompt .= "- Gaming\n";
        $prompt .= "- Music\n";
        $prompt .= "- Comedy\n";
        $prompt .= "- News & Politics\n";

        $prompt .= "\n**Instructions:**\n";
        $prompt .= "1. For each video, predict the most likely content vertical based on patterns in the video ID and URL\n";
        $prompt .= "2. If uncertain, use 'General' as the category\n";
        $prompt .= "3. Return your analysis in the following JSON format:\n\n";

        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"videos\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"video_id\": \"video_id_here\",\n";
        $prompt .= "      \"youtube_url\": \"full_youtube_url\",\n";
        $prompt .= "      \"content_vertical\": \"predicted_category\",\n";
        $prompt .= "      \"confidence\": \"high/medium/low\",\n";
        $prompt .= "      \"reasoning\": \"Brief explanation for the categorization\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";
        $prompt .= "```\n";

        return $prompt;
    }

    /**
     * Get fallback categories when AI analysis fails
     *
     * @param array $youTubeVideos
     * @return array
     */
    private function getFallbackCategories(array $youTubeVideos): array
    {
        return array_map(function ($video) {
            return [
                'video_id' => $video['video_id'],
                'youtube_url' => $video['youtube_url'],
                'content_vertical' => 'General',
                'confidence' => 'low',
                'reasoning' => 'Unable to analyze - using fallback category'
            ];
        }, $youTubeVideos);
    }
}
