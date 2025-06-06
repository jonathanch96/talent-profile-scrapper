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
    public $tries = 3;

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
     * Collect all relevant text data from talent profile
     */
    private function collectTalentTextData(): string
    {
        $textParts = [];

        // Basic profile information
        if ($this->talent->name) {
            $textParts[] = "Name: " . $this->talent->name;
        }

        if ($this->talent->job_title) {
            $textParts[] = "Job Title: " . $this->talent->job_title;
        }

        if ($this->talent->description) {
            $textParts[] = "Description: " . $this->talent->description;
        }

        if ($this->talent->location) {
            $textParts[] = "Location: " . $this->talent->location;
        }

        // Load relationships for embedding
        $this->talent->load(['experiences', 'projects', 'contents.contentType', 'contents.contentTypeValue']);

        // Experiences
        foreach ($this->talent->experiences as $experience) {
            $expText = [];
            if ($experience->client_name) $expText[] = "Company: " . $experience->client_name;
            if ($experience->job_type) $expText[] = "Role: " . $experience->job_type;
            if ($experience->period) $expText[] = "Period: " . $experience->period;
            if ($experience->description) $expText[] = "Description: " . $experience->description;

            if (!empty($expText)) {
                $textParts[] = "Experience: " . implode(', ', $expText);
            }
        }

        // Projects
        foreach ($this->talent->projects as $project) {
            $projText = [];
            if ($project->title) $projText[] = "Title: " . $project->title;
            if ($project->description) $projText[] = "Description: " . $project->description;
            if ($project->project_roles && is_array($project->project_roles)) {
                $projText[] = "Roles: " . implode(', ', $project->project_roles);
            }

            if (!empty($projText)) {
                $textParts[] = "Project: " . implode(', ', $projText);
            }
        }

        // Content types and skills
        $contentGroups = [];
        foreach ($this->talent->contents as $content) {
            $typeName = $content->contentType->name ?? 'Unknown';
            $valueName = $content->contentTypeValue->title ?? 'Unknown';

            if (!isset($contentGroups[$typeName])) {
                $contentGroups[$typeName] = [];
            }
            $contentGroups[$typeName][] = $valueName;
        }

        foreach ($contentGroups as $type => $values) {
            $textParts[] = "{$type}: " . implode(', ', $values);
        }

        return implode('. ', $textParts);
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
