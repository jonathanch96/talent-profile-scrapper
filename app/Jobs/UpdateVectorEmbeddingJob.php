<?php

namespace App\Jobs;

use App\Models\Talent;
use App\Services\OpenAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateVectorEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes timeout
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Talent $talent
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting vector embedding update for talent: {$this->talent->username}");

            // Collect all relevant text data for embedding
            $textData = $this->collectTalentTextData();

            if (empty($textData)) {
                Log::warning("No text data found for talent: {$this->talent->username}");
                return;
            }

            // Use OpenAI service to generate embeddings
            $openAIService = new OpenAIService();
            $embedding = $openAIService->generateEmbedding($textData);

            if ($embedding) {
                // Update talent with new vector embedding
                $this->talent->update([
                    'vectordb' => $embedding
                ]);

                Log::info("Vector embedding updated for talent: {$this->talent->username}");
            } else {
                throw new \Exception("Failed to generate embedding from OpenAI");
            }

        } catch (\Exception $e) {
            Log::error("Vector embedding update failed for talent: {$this->talent->username}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Collect all relevant text data from talent profile (optimized for token limits)
     */
    private function collectTalentTextData(): string
    {
        $textParts = [];

        // Basic profile information (concise format)
        $basicInfo = [];
        if ($this->talent->name) $basicInfo[] = $this->talent->name;
        if ($this->talent->job_title) $basicInfo[] = $this->talent->job_title;
        if ($this->talent->location) $basicInfo[] = $this->talent->location;
        if (!empty($basicInfo)) {
            $textParts[] = implode(' | ', $basicInfo);
        }

        // Description (truncated to prevent token overflow)
        if ($this->talent->description) {
            $description = substr($this->talent->description, 0, 300);
            $textParts[] = $description;
        }

        // Load relationships for embedding
        $this->talent->load([
            'experiences',
            'projects',
            'contents.contentType',
            'contents.contentTypeValue',
            'documents' => function($query) {
                $query->where('extraction_status', 'completed')
                      ->whereNotNull('extracted_content')
                      ->limit(2); // Limit to 2 most recent documents
            }
        ]);

        // Experiences (condensed format)
        $experienceYears = $this->extractExperienceYears();
        if ($experienceYears) {
            $textParts[] = "{$experienceYears} years experience";
        }

        $companies = $this->talent->experiences->pluck('client_name')->filter()->unique()->take(5);
        if ($companies->isNotEmpty()) {
            $textParts[] = "Companies: " . $companies->implode(', ');
        }

        // Content types and skills (optimized)
        $contentGroups = [];
        foreach ($this->talent->contents as $content) {
            $typeName = $content->contentType->name ?? 'Unknown';
            $valueName = $content->contentTypeValue->title ?? 'Unknown';

            if (!isset($contentGroups[$typeName])) {
                $contentGroups[$typeName] = [];
            }
            $contentGroups[$typeName][] = $valueName;
        }

        // Optimized searchable content
        foreach ($contentGroups as $type => $values) {
            $valuesList = implode(', ', array_unique($values));

            // Single comprehensive line per type for better efficiency
            if ($type === 'Skills') {
                $textParts[] = "Skills: {$valuesList}";
                $textParts[] = "Expert: " . implode(' ', $values); // For "expert in X" searches
            } elseif ($type === 'Software') {
                $textParts[] = "Software: {$valuesList}";
                $textParts[] = "Uses: " . implode(' ', $values); // For "uses X" searches
            } elseif ($type === 'Job Type') {
                $textParts[] = "Roles: {$valuesList}";
            } elseif ($type === 'Content Vertical') {
                $textParts[] = "Content: {$valuesList}";
            } elseif ($type === 'Platform Specialty') {
                $textParts[] = "Platforms: {$valuesList}";
            } else {
                $textParts[] = "{$type}: {$valuesList}";
            }
        }

        // Projects (most important ones only)
        $projectTitles = $this->talent->projects->pluck('title')->filter()->take(3);
        if ($projectTitles->isNotEmpty()) {
            $textParts[] = "Projects: " . $projectTitles->implode(', ');
        }

        // Document content (heavily limited)
        $documentTexts = [];
        foreach ($this->talent->documents->take(2) as $document) {
            if (!empty($document->extracted_content)) {
                // Extract only the most relevant parts (first 500 chars)
                $content = substr($document->extracted_content, 0, 500);
                $documentTexts[] = $this->extractKeyInfo($content);
            }
        }

        if (!empty($documentTexts)) {
            $textParts[] = "CV: " . implode(' ', $documentTexts);
        }

        $finalText = implode('. ', $textParts);

        // Final safety check: truncate if still too long (approximately 6000 tokens max)
        if (strlen($finalText) > 24000) { // Rough estimate: 4 chars per token
            $finalText = substr($finalText, 0, 24000) . '...';
        }

        Log::info("Generated embedding text", [
            'talent_id' => $this->talent->id,
            'text_length' => strlen($finalText),
            'estimated_tokens' => intval(strlen($finalText) / 4)
        ]);

        return $finalText;
    }

    /**
     * Extract years of experience from descriptions
     */
    private function extractExperienceYears(): ?string
    {
        $experienceText = $this->talent->experiences->pluck('description')->implode(' ');

        if (preg_match('/(\d+)\+?\s*years?\s*(?:of\s*)?experience/i', $experienceText, $matches)) {
            return $matches[1];
        }

        // Alternative patterns
        if (preg_match('/(\d+)\+?\s*years?\s*in/i', $experienceText, $matches)) {
            return $matches[1];
        }

        // Count total experiences as fallback
        $totalExp = $this->talent->experiences->count();
        return $totalExp > 0 ? (string)$totalExp : null;
    }

    /**
     * Extract key information from document content
     */
    private function extractKeyInfo(string $content): string
    {
        // Look for key sections and skills
        $keywords = ['skills', 'experience', 'education', 'software', 'tools', 'languages'];
        $keyPhrases = [];

        foreach ($keywords as $keyword) {
            if (preg_match("/{$keyword}[:\s]+([^.\n]{1,100})/i", $content, $matches)) {
                $keyPhrases[] = trim($matches[1]);
            }
        }

        return implode(' ', array_slice($keyPhrases, 0, 3));
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("UpdateVectorEmbeddingJob failed for talent: {$this->talent->username}", [
            'error' => $exception->getMessage()
        ]);
    }
}
