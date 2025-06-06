<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentType extends Model
{
    use HasFactory, SoftDeletes;
    const JOB_TYPE = 1;
    const CONTENT_VERTICAL = 2;
    const PLATFORM_SPECIALTY = 3;
    const SKILLS = 4;
    const SOFTWARE = 5;

    protected $fillable = [
        'name',
        'order',
    ];

    protected $dates = ['deleted_at'];

    public function talentContents()
    {
        return $this->hasMany(TalentContent::class);
    }

    public function contentTypeValues()
    {
        return $this->hasMany(ContentTypeValue::class);
    }


}
