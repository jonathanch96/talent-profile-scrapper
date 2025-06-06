<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentTypeValue extends Model
{
    use HasFactory, SoftDeletes;

    // Job Types
    const VIDEO_EDITOR = 1;
    const SCRIPT_WRITER = 2;
    const CREATIVE_DIRECTOR = 3;
    const VIDEOGRAPHER = 4;
    const SCRIPTWRITER = 5;

    // Content Verticals
    const TRAVEL = 6;
    const EDUCATION = 7;
    const LIFESTYLE_VLOGS = 8;
    const ENTERTAINMENT = 9;
    const TECH_STARTUP = 10;
    const SELF_HELP = 11;
    const KIDS_FAMILY = 12;
    const IRL = 13;
    const SCRIPTED_SKITS = 14;
    const HOW_TO_DIY = 15;

    // Platform Specialty
    const TIKTOK = 16;

    // Skills
    const GRAPHIC_DESIGN = 17;
    const PHOTOGRAPHY = 18;
    const COLOR_GRADING = 19;

    // Software
    const ADOBE_ILLUSTRATOR = 20;
    const ADOBE_PHOTOSHOP = 21;
    const FIGMA = 22;
    const ADOBE_PREMIERE_PRO = 23;
    const ADOBE_AFTER_EFFECTS = 24;
    const ADOBE_LIGHTROOM = 25;
    const CANVA = 26;
    const MONDAY_COM = 27;
    const SLACK = 28;
    const TRELLO = 29;
    const EXCEL = 30;

    protected $fillable = [
        'content_type_id',
        'title',
        'description',
        'icon',
        'order',
    ];

    protected $dates = ['deleted_at'];


    public function contentType()
    {
        return $this->belongsTo(ContentType::class);
    }

    public function talentContents()
    {
        return $this->hasMany(TalentContent::class);
    }

}
