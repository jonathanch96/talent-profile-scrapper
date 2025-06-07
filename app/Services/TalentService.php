<?php

namespace App\Services;

use App\Jobs\UpdateVectorEmbeddingJob;
use App\Models\Talent;
use App\Models\TalentExperience;
use App\Models\TalentProject;
use App\Models\TalentContent;
use App\Models\ContentType;
use App\Models\ContentTypeValue;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class TalentService
{
    /**
     * Get paginated talents
     */
    public function getAllTalents(int $perPage = 10): LengthAwarePaginator
    {
        return Talent::select(['id', 'name', 'username'])
            ->paginate($perPage);
    }

    /**
     * Get talent by username with all relationships
     */
    public function getTalentByUsername(string $username): Talent
    {
        $talent = Talent::where('username', $username)
            ->with([
                'experiences',
                'projects',
                'contents.contentType',
                'contents.contentTypeValue'
            ])
            ->first();

        if (!$talent) {
            throw new ModelNotFoundException("Talent with username '{$username}' not found.");
        }

        return $talent;
    }

    /**
     * Update talent by username
     */
    public function updateTalentByUsername(string $username, array $data): Talent
    {
        $talent = Talent::where('username', $username)->first();

        if (!$talent) {
            throw new ModelNotFoundException("Talent with username '{$username}' not found.");
        }

        // Update main talent fields
        $talentFields = array_intersect_key($data, array_flip([
            'name', 'job_title', 'description', 'image', 'location', 'timezone', 'talent_status', 'availability','website_url'
        ]));

        if (!empty($talentFields)) {
            $talent->update($talentFields);
        }

        // Track if we need to update vector embeddings
        $needsVectorUpdate = false;

        // Update experiences
        if (isset($data['experiences'])) {
            $this->updateTalentExperiences($talent, $data['experiences']);
            $needsVectorUpdate = true;
        }

        // Update projects
        if (isset($data['projects'])) {
            $this->updateTalentProjects($talent, $data['projects']);
            $needsVectorUpdate = true;
        }

        // Update details (contents)
        if (isset($data['details'])) {
            $this->updateTalentDetails($talent, $data['details']);
            $needsVectorUpdate = true;
        }

        // Dispatch vector update job if relationships changed
        if ($needsVectorUpdate) {
            UpdateVectorEmbeddingJob::dispatch($talent);
        }

        return $talent->fresh();
    }

    /**
     * Delete talent by username
     */
    public function deleteTalentByUsername(string $username): bool
    {
        $talent = Talent::where('username', $username)->first();

        if (!$talent) {
            throw new ModelNotFoundException("Talent with username '{$username}' not found.");
        }

        return $talent->delete();
    }

    /**
     * Update talent experiences
     */
    private function updateTalentExperiences(Talent $talent, array $experiences): void
    {
        // Delete existing experiences
        $talent->experiences()->delete();

        // Create new experiences
        foreach ($experiences as $experience) {
            $talent->experiences()->create([
                'client_name' => $experience['company'] ?? '',
                'job_type' => $experience['role'] ?? '',
                'period' => $experience['duration'] ?? '',
                'client_sub_title' => $experience['client_sub_title'] ?? '',
                'client_logo' => $experience['client_logo'] ?? '',
                'description' => $experience['description'] ?? '',
            ]);
        }
    }

    /**
     * Update talent projects
     */
    private function updateTalentProjects(Talent $talent, array $projects): void
    {
        // Delete existing projects
        $talent->projects()->delete();

        // Create new projects
        foreach ($projects as $project) {
            $talent->projects()->create([
                'title' => $project['title'] ?? '',
                'description' => $project['description'] ?? '',
                'image' => $project['image'] ?? '',
                'link' => $project['link'] ?? '',
                'views' => $project['views'] ?? 0,
                'likes' => $project['likes'] ?? 0,
                'project_roles' => $project['project_roles'] ?? [],
            ]);
        }
    }

    /**
     * Update talent details (contents)
     */
    private function updateTalentDetails(Talent $talent, array $details): void
    {
        // Delete existing contents
        $talent->contents()->delete();

        foreach ($details as $detail) {
            $contentTypeName = $detail['name'] ?? '';
            $values = $detail['values'] ?? [];

            // Find or create content type
            $contentType = ContentType::where('name', $contentTypeName)->first();
            if (!$contentType) {
                continue; // Skip if content type doesn't exist
            }

            foreach ($values as $value) {
                $valueTitle = $value['title'] ?? '';

                // Find or create content type value
                $contentTypeValue = ContentTypeValue::where('content_type_id', $contentType->id)
                    ->where('title', $valueTitle)
                    ->first();

                if (!$contentTypeValue) {
                    $contentTypeValue = ContentTypeValue::firstOrCreate([
                        'content_type_id' => $contentType->id,
                        'title' => $valueTitle,
                        'icon' => $value['icon'] ?? '',
                        'description' => $value['description'] ?? '',
                        'order' => 0,
                    ]);
                }

                // Create talent content
                $talent->contents()->create([
                    'content_type_id' => $contentType->id,
                    'content_type_value_id' => $contentTypeValue->id,
                ]);
            }
        }
    }

    /**
     * Format talent details for API response
     */
    public function formatTalentDetails(Talent $talent): array
    {
        $details = [];
        $groupedContents = $talent->contents->groupBy('contentType.name');

        foreach ($groupedContents as $contentTypeName => $contents) {
            $values = [];
            foreach ($contents as $content) {
                $values[] = [
                    'title' => $content->contentTypeValue->title,
                    'icon' => $content->contentTypeValue->icon ?? '',
                ];
            }

            $details[] = [
                'name' => $contentTypeName,
                'values' => $values,
            ];
        }

        return $details;
    }
}
