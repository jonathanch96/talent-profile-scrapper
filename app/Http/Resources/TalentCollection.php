<?php

namespace App\Http\Resources;

use App\Services\TalentService;
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
        $talentService = app(TalentService::class);

        return [
            'success' => true,
            'data' => $this->collection->map(function ($talent) use ($talentService) {
                // Handle regular talent model
                return [
                    'id' => $talent->id,
                    'name' => $talent->name,
                    'username' => $talent->username,
                    'description' => $talent->description,
                    'experiences' => $talent->when($talent->relationLoaded('experiences'), function () use ($talent) {
                        return $talent->experiences->map(function ($experience) {
                            return [
                                'company' => $experience->client_name,
                                'role' => $experience->job_type,
                                'duration' => $experience->period,
                            ];
                        });
                    }),
                    'projects' => $talent->when($talent->relationLoaded('projects'), function () use ($talent) {
                        return $talent->projects->map(function ($project) {
                            return [
                                'title' => $project->title,
                                'description' => $project->description,
                                'image' => $project->image,
                                'link' => $project->link,
                                'views' => $project->views,
                                'likes' => $project->likes,
                                'project_roles' => $project->project_roles,
                            ];
                        });
                    }),
                    'details' => $talent->when($talent->relationLoaded('contents'), function () use ($talentService, $talent) {
                        return $talentService->formatTalentDetails($talent->resource);
                    }),
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
