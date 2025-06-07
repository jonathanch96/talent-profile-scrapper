<?php

namespace App\Jobs;

use App\Models\Talent;
use App\Models\ContentType;
use App\Models\ContentTypeValue;
use App\Models\TalentContent;
use App\Models\TalentExperience;
use App\Models\TalentProject;
use App\Models\TalentLanguage;
use App\Models\Language;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UpdateTalentDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Talent $talent,
        public array $processedData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting talent data update for: {$this->talent->username}", [
                'talent_id' => $this->talent->id
            ]);

            DB::transaction(function () {
                // Update basic talent information
                $this->updateBasicTalentInfo();

                // Update experiences
                $this->updateExperiences();

                // Update projects
                $this->updateProjects();

                // Update languages
                $this->updateLanguages();

                // Update content type mappings (skills, software, platforms, etc.)
                $this->updateContentMappings();

                Log::info("Talent data update completed for: {$this->talent->username}");
            });

            // Dispatch vector embedding update job after successful data update
            UpdateVectorEmbeddingJob::dispatch($this->talent);

        } catch (\Exception $e) {
            Log::error("Talent data update failed for: {$this->talent->username}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Update basic talent information
     */
    private function updateBasicTalentInfo(): void
    {
        $updateData = [];

        if (!empty($this->processedData['name'])) {
            $updateData['name'] = $this->processedData['name'];
        }

        if (!empty($this->processedData['job_title'])) {
            $updateData['job_title'] = $this->processedData['job_title'];
        }

        if (!empty($this->processedData['description'])) {
            $updateData['description'] = $this->processedData['description'];
        }

        if (!empty($this->processedData['location'])) {
            $updateData['location'] = $this->processedData['location'];
        }

        if (!empty($this->processedData['timezone'])) {
            $updateData['timezone'] = $this->processedData['timezone'];
        }

        if (!empty($this->processedData['image'])) {
            $updateData['image'] = $this->processedData['image'];
        }

        if (!empty($this->processedData['talent_status']) && $this->processedData['talent_status'] !== '-') {
            $updateData['talent_status'] = $this->processedData['talent_status'];
        }

        if (!empty($this->processedData['availability']) && $this->processedData['availability'] !== '-') {
            $updateData['availability'] = $this->processedData['availability'];
        }

        if (!empty($updateData)) {
            $this->talent->update($updateData);
            Log::info("Updated basic talent info", ['updated_fields' => array_keys($updateData)]);
        }
    }

    /**
     * Update talent experiences
     */
    private function updateExperiences(): void
    {
        if (empty($this->processedData['experiences'])) {
            return;
        }

        // Clear existing experiences
        TalentExperience::where('talent_id', $this->talent->id)->delete();

        foreach ($this->processedData['experiences'] as $experienceData) {
            TalentExperience::create([
                'talent_id' => $this->talent->id,
                'client_name' => $experienceData['client_name'] ?? null,
                'client_sub_title' => $experienceData['client_sub_title'] ?? null,
                'client_logo' => $experienceData['client_logo'] ?? null,
                'job_type' => $experienceData['job_type'] ?? null,
                'period' => $experienceData['period'] ?? null,
                'description' => $experienceData['description'] ?? null,
            ]);
        }

        Log::info("Updated experiences", ['count' => count($this->processedData['experiences'])]);
    }

    /**
     * Update talent projects
     */
    private function updateProjects(): void
    {
        if (empty($this->processedData['projects'])) {
            return;
        }

        // Clear existing projects
        TalentProject::where('talent_id', $this->talent->id)->delete();

        foreach ($this->processedData['projects'] as $projectData) {
            // Try to link project to an experience if possible
            $experienceId = null;
            if (!empty($this->processedData['experiences'])) {
                // Use the first experience as default, or try to match by company name
                $firstExperience = TalentExperience::where('talent_id', $this->talent->id)->first();
                if ($firstExperience) {
                    $experienceId = $firstExperience->id;
                }
            }

            TalentProject::create([
                'talent_id' => $this->talent->id,
                'experience_id' => $experienceId,
                'title' => $projectData['title'] ?? null,
                'description' => $projectData['description'] ?? null,
                'image' => $projectData['image'] ?? null,
                'views' => $this->ensureInteger($projectData['views'] ?? null),
                'likes' => $this->ensureInteger($projectData['likes'] ?? null),
                'project_roles' => $projectData['project_roles'] ?? null,
            ]);
        }

        Log::info("Updated projects", ['count' => count($this->processedData['projects'])]);
    }

    /**
     * Update talent languages
     */
    private function updateLanguages(): void
    {
        if (empty($this->processedData['languages'])) {
            return;
        }

        // Clear existing language mappings
        TalentLanguage::where('talent_id', $this->talent->id)->delete();

        foreach ($this->processedData['languages'] as $languageData) {
            // Get or create language
            $language = Language::firstOrCreate([
                'name' => $languageData['language']
            ]);

            TalentLanguage::create([
                'talent_id' => $this->talent->id,
                'language_id' => $language->id,
                'level' => $languageData['proficiency'] ?? 'Unknown',
            ]);
        }

        Log::info("Updated languages", ['count' => count($this->processedData['languages'])]);
    }

    /**
     * Update content type mappings (skills, software, platforms, etc.)
     */
    private function updateContentMappings(): void
    {
        // Clear existing content mappings
        TalentContent::where('talent_id', $this->talent->id)->delete();

        $mappings = [
            'job_types' => ContentType::JOB_TYPE,
            'content_vertical' => ContentType::CONTENT_VERTICAL,
            'platform_specialties' => ContentType::PLATFORM_SPECIALTY,
            'skills' => ContentType::SKILLS,
            'softwares' => ContentType::SOFTWARE,
        ];

        $totalMappings = 0;

        foreach ($mappings as $dataKey => $contentTypeId) {
            if (!empty($this->processedData[$dataKey])) {
                foreach ($this->processedData[$dataKey] as $value) {
                    $contentTypeValueId = $this->getOrCreateContentTypeValue($contentTypeId, $value);

                    if ($contentTypeValueId) {
                        TalentContent::create([
                            'talent_id' => $this->talent->id,
                            'content_type_id' => $contentTypeId,
                            'content_type_value_id' => $contentTypeValueId,
                        ]);

                        $totalMappings++;
                    }
                }
            }
        }

        Log::info("Updated content mappings", [
            'total_mappings' => $totalMappings,
            'job_types' => count($this->processedData['job_types'] ?? []),
            'content_vertical' => count($this->processedData['content_vertical'] ?? []),
            'platform_specialties' => count($this->processedData['platform_specialties'] ?? []),
            'skills' => count($this->processedData['skills'] ?? []),
            'softwares' => count($this->processedData['softwares'] ?? []),
        ]);
    }

    /**
     * Get existing or create new ContentTypeValue using firstOrCreate
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

            Log::debug("Content type value found/created", [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'id' => $contentTypeValue->id,
                'was_created' => $contentTypeValue->wasRecentlyCreated
            ]);

            return $contentTypeValue->id;

        } catch (\Exception $e) {
            Log::error("Error creating/finding ContentTypeValue", [
                'content_type_id' => $contentTypeId,
                'title' => $title,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Ensure value is an integer, with better handling for DB constraints
     */
    private function ensureInteger($value): int
    {
        if ($value === null || $value === '') {
            return 0; // Default for DB NOT NULL constraints
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        // Fallback to parse numeric if it's a string like "5 million"
        return $this->parseNumericValue($value) ?? 0;
    }

    /**
     * Parse numeric values from strings (e.g., "5 million" -> 5000000)
     */
    private function parseNumericValue($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            // Handle common formats like "5 million", "1.2K", etc.
            $value = strtolower(trim($value));

            // Extract number part
            if (preg_match('/^([\d.]+)\s*(million|m|thousand|k|billion|b)?/', $value, $matches)) {
                $number = (float) $matches[1];
                $unit = $matches[2] ?? '';

                switch ($unit) {
                    case 'billion':
                    case 'b':
                        return (int) ($number * 1000000000);
                    case 'million':
                    case 'm':
                        return (int) ($number * 1000000);
                    case 'thousand':
                    case 'k':
                        return (int) ($number * 1000);
                    default:
                        return (int) $number;
                }
            }
        }

        return null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("UpdateTalentDataJob failed for talent: {$this->talent->username}", [
            'error' => $exception->getMessage()
        ]);
    }
}
