<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentExperience extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'talent_id',
        'client_name',
        'client_sub_title',
        'client_logo',
        'job_type',
        'period',
        'description',
    ];

    protected $dates = ['deleted_at'];

    public function talent()
    {
        return $this->belongsTo(Talent::class);
    }

    public function projects()
    {
        return $this->hasMany(TalentProject::class, 'experience_id');
    }
}
