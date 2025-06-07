<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TalentCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'success' => true,
                                                            'data' => $this->collection->map(function ($talent) {
                // Handle LLM search results (array format with ranking)
                if (is_array($talent)) {
                    return [
                        'id' => $talent['id'],
                        'name' => $talent['name'],
                        'username' => $talent['username'],
                        'job_title' => $talent['job_title'] ?? null,
                        'description' => $talent['description'] ?? null,
                        'ranking_score' => $talent['ranking_score'] ?? null,
                    ];
                }

                // Handle regular talent model objects
                if (is_object($talent) && method_exists($talent, 'toArray')) {
                    $talentArray = $talent->toArray();
                    return [
                        'id' => $talentArray['id'] ?? null,
                        'name' => $talentArray['name'] ?? null,
                        'username' => $talentArray['username'] ?? null,
                        'job_title' => $talentArray['job_title'] ?? null,
                        'description' => $talentArray['description'] ?? null,
                        'ranking_score' => $talentArray['ranking_score'] ?? null,
                    ];
                }

                // Handle regular talent model
                return [
                    'id' => $talent->id ?? null,
                    'name' => $talent->name ?? null,
                    'username' => $talent->username ?? null,
                ];
            }),
            'pagination' => [
                'page' => $this->currentPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
                'last_page' => $this->lastPage(),
            ],
            'errors' => [],
        ];
    }
}
