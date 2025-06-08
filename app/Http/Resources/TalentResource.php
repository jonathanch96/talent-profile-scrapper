<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\TalentService;

class TalentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $talentService = app(TalentService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'description' => $this->description,
            'experiences' => $this->when($this->relationLoaded('experiences'), function () {
                return $this->experiences->map(function ($experience) {
                    return [
                        'company' => $experience->client_name,
                        'role' => $experience->job_type,
                        'duration' => $experience->period,
                    ];
                });
            }),
            'languages' => $this->when($this->relationLoaded('languages'), function () {
                return $this->languages->map(function ($language) {
                    return [
                        'language' => $language->language->name,
                        'level' => $language->level,
                    ];
                });
            }),
            'projects' => $this->when($this->relationLoaded('projects'), function () {
                return $this->projects->map(function ($project) {
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
            'details' => $this->when($this->relationLoaded('contents'), function () use ($talentService) {
                return $talentService->formatTalentDetails($this->resource);
            }),
        ];
    }
}
