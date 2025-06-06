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
    ];

    protected $dates = ['deleted_at'];

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
}
