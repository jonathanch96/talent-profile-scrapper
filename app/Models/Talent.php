<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Talent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent';

    protected $fillable = [
        'username',
        'name',
        'job_title',
        'description',
        'image',
        'location',
        'timezone',
        'talent_status',
        'availability',
        'website_url',
        'vectordb',
        'scraping_status',
    ];

    protected $dates = ['deleted_at'];

    protected $hidden = [
        'vectordb',
    ];

    protected $casts = [
        'vectordb' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (Talent $talent) {
            // If website_url is changed, trigger scraping
            if ($talent->isDirty('website_url') && $talent->website_url) {
                $talent->scraping_status = 'scraping_portfolio';
                \App\Jobs\ScrapePortfolioJob::dispatch($talent);
            }

            // If profile data changed, trigger vector update
            $profileFields = ['description', 'job_title', 'name'];
            if ($talent->isDirty($profileFields)) {
                \App\Jobs\UpdateVectorEmbeddingJob::dispatch($talent);
            }
        });

        static::updated(function (Talent $talent) {
            // Check if experiences, contents relationships changed
            // This will be handled in the TalentService when relationships are updated
        });
    }

    public function experiences()
    {
        return $this->hasMany(TalentExperience::class);
    }

    public function projects()
    {
        return $this->hasMany(TalentProject::class);
    }

    public function contents()
    {
        return $this->hasMany(TalentContent::class);
    }

    public function languages()
    {
        return $this->hasMany(TalentLanguage::class);
    }

    public function scrapingResults()
    {
        return $this->hasMany(TalentScrapingResult::class);
    }

    public function documents()
    {
        return $this->hasMany(TalentDocument::class);
    }
}
