<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentContent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'talent_id',
        'content_type_id',
        'content_type_value_id',
    ];

    protected $dates = ['deleted_at'];

    public function talent()
    {
        return $this->belongsTo(Talent::class);
    }

    public function contentType()
    {
        return $this->belongsTo(ContentType::class);
    }

    public function contentTypeValue()
    {
        return $this->belongsTo(ContentTypeValue::class);
    }
}
