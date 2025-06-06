<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentLanguage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'talent_id',
        'language_id',
        'level',
    ];

    protected $dates = ['deleted_at'];

    public function talent()
    {
        return $this->belongsTo(Talent::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}
