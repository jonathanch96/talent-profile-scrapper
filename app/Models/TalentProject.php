<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentProject extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'talent_id',
        'experience_id',
        'project_roles',
        'title',
        'description',
        'image',
        'link',
        'views',
        'likes',
    ];

    protected $dates = ['deleted_at'];

    protected $casts = [
        'project_roles' => 'array',
    ];

    public function talent()
    {
        return $this->belongsTo(Talent::class);
    }

    public function experience()
    {
        return $this->belongsTo(TalentExperience::class, 'experience_id');
    }
}
