<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentScrapingResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'talent_id',
        'website_url',
        'scraped_data_path',
        'processed_data_path',
        'status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected $dates = ['deleted_at'];

    public function talent()
    {
        return $this->belongsTo(Talent::class);
    }

    public function documents()
    {
        return $this->hasMany(TalentDocument::class, 'scraping_result_id');
    }
}
