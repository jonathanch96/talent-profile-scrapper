<?php

namespace App\Services;

use App\Jobs\UpdateVectorEmbeddingJob;
use App\Models\Talent;
use App\Models\TalentExperience;
use App\Models\TalentProject;
use App\Models\TalentContent;
use App\Models\ContentType;
use App\Models\ContentTypeValue;
use App\Services\AiAgentService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class TalentService
{
    protected AiAgentService $aiAgentService;

    public function __construct(AiAgentService $aiAgentService)
    {
        $this->aiAgentService = $aiAgentService;
    }

    /**
     * Get paginated talents with optional LLM search
     */
    public function getAllTalents(int $perPage = 10, ?string $searchUsingLlm = null): LengthAwarePaginator
    {
        if ($searchUsingLlm) {
            return $this->searchTalentsUsingLLM($searchUsingLlm, $perPage);
        }

        return Talent::with(['experiences', 'projects', 'contents.contentType', 'contents.contentTypeValue', 'languages'])
            ->paginate($perPage);
    }

    /**
     * Search talents using LLM-powered vector search and ranking
     */
    private function searchTalentsUsingLLM(string $searchQuery, int $perPage): LengthAwarePaginator
    {
        try {
            // Step 1: Generate embedding for the search query
            $queryEmbedding = $this->aiAgentService->generateEmbedding($searchQuery);

            if (!$queryEmbedding) {
                Log::warning('Failed to generate embedding for search query', ['query' => $searchQuery]);
                return $this->getAllTalents($perPage); // Fallback to regular search
            }

            // Step 2: Perform vector similarity search with more results than needed for ranking
            $vectorSearchLimit = min($perPage * 3, 100); // Get 3x more results for better ranking
            $vectorResults = $this->performVectorSearch($queryEmbedding, $vectorSearchLimit);

            if (empty($vectorResults)) {
                Log::info('No vector search results found', ['query' => $searchQuery]);
                return new LengthAwarePaginator([], 0, $perPage, 1);
            }

            // Step 3: Get full talent data for LLM ranking
            $talentIds = collect($vectorResults)->pluck('id')->toArray();
            $talents = Talent::with([
                'experiences',
                'projects',
                'contents.contentType',
                'contents.contentTypeValue',
                'languages'
            ])->whereIn('id', $talentIds)->get();

            // Step 4: Rank results using LLM
            $rankedResults = $this->aiAgentService->rankTalentsUsingLLM($searchQuery, $talents);

            // Step 5: Sort by ranking and paginate
            $sortedTalents = collect($rankedResults)
                ->sortByDesc('ranking_score')
                ->take($perPage)
                ->values();

            // Step 6: Create pagination with collection that preserves arrays
            $currentPage = 1;
            $total = count($rankedResults);

            // Convert to a simple collection to prevent auto-wrapping
            $talentCollection = collect($sortedTalents->toArray());

            return new LengthAwarePaginator(
                $talentCollection,
                $total,
                $perPage,
                $currentPage,
                ['path' => request()->url(), 'pageName' => 'page']
            );
        } catch (Exception $e) {
            Log::error('LLM search failed', [
                'query' => $searchQuery,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback to regular search (but avoid recursion)
            return Talent::select(['id', 'name', 'username'])
                ->paginate($perPage);
        }
    }

    /**
     * Perform vector similarity search using pgvector
     */
    private function performVectorSearch(array $queryEmbedding, int $limit): array
    {
        try {
            $embeddingString = '[' . implode(',', $queryEmbedding) . ']';

            $results = DB::select("
                SELECT id, username, name, job_title, description,
                       (vectordb <=> ?) as distance
                FROM talent
                WHERE vectordb IS NOT NULL
                ORDER BY vectordb <=> ?
                LIMIT ?
            ", [$embeddingString, $embeddingString, $limit]);

            $processedResults = array_map(function ($result) {
                return [
                    'id' => $result->id,
                    'username' => $result->username,
                    'name' => $result->name,
                    'job_title' => $result->job_title,
                    'description' => $result->description,
                    'distance' => $result->distance
                ];
            }, $results);

            return $processedResults;
        } catch (Exception $e) {
            Log::error('Vector search failed', [
                'error' => $e->getMessage(),
                'limit' => $limit
            ]);
            return [];
        }
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
                'contents.contentTypeValue',
                'languages'
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
            'name',
            'username',
            'job_title',
            'description',
            'image',
            'location',
            'timezone',
            'talent_status',
            'availability',
            'website_url'
        ]));

        // Only update username if provided and different from current
        if (isset($data['username']) && $data['username'] !== $talent->username) {
            // Check if username is already taken by another talent
            $existingTalent = Talent::where('username', $data['username'])
                ->where('id', '!=', $talent->id)
                ->first();

            if ($existingTalent) {
                throw new \Exception("Username '{$data['username']}' is already taken by another talent.");
            }
        }

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
            $projectData = [
                'title' => $project['title'] ?? '',
                'description' => $project['description'] ?? null,
                'image' => $project['image'] ?? null,
                'link' => $project['link'] ?? null,
                'views' => $project['views'] ?? 0,
                'likes' => $project['likes'] ?? 0,
                'project_roles' => $project['project_roles'] ?? [],
            ];

            // Only add experience_id if we have experiences to link to
            $firstExperience = $talent->experiences()->first();
            if ($firstExperience) {
                $projectData['experience_id'] = $firstExperience->id;
            }

            $talent->projects()->create($projectData);
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

            // Find content type by name (case insensitive)
            $contentType = ContentType::whereRaw('LOWER(name) = LOWER(?)', [$contentTypeName])->first();
            if (!$contentType) {
                // Create new content type if it doesn't exist
                $contentType = ContentType::create([
                    'name' => $contentTypeName,
                    'order' => 0,
                ]);
            }

            foreach ($values as $valueTitle) {
                // Handle string values directly
                if (is_string($valueTitle) && !empty($valueTitle)) {
                    // Find or create content type value
                    $contentTypeValue = ContentTypeValue::where('content_type_id', $contentType->id)
                        ->where('title', $valueTitle)
                        ->first();

                    if (!$contentTypeValue) {
                        $contentTypeValue = ContentTypeValue::create([
                            'content_type_id' => $contentType->id,
                            'title' => $valueTitle,
                            'icon' => '',
                            'description' => '',
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
