<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'talent_id',
        'scraping_result_id',
        'original_url',
        'source_link_text',
        'document_type',
        'file_path',
        'filename',
        'file_size',
        'download_status',
        'extraction_status',
        'extracted_content',
        'metadata',
        'error_message',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected $dates = ['deleted_at'];

    public function talent()
    {
        return $this->belongsTo(Talent::class);
    }

    public function scrapingResult()
    {
        return $this->belongsTo(TalentScrapingResult::class);
    }

    /**
     * Check if document is downloadable
     */
    public function isDownloadable(): bool
    {
        return in_array($this->document_type, ['pdf', 'doc', 'docx', 'txt', 'rtf']);
    }

    /**
     * Get file size in human readable format
     */
    public function getFileSizeHumanAttribute(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
