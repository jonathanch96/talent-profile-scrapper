<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ContentType;
use App\Models\ContentTypeValue;
use App\Models\JobType;
use App\Models\Language;
use App\Models\ProjectRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Mail\Mailables\Content;

class MasterDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Language::create([
            'name' => 'English',
        ]);

        Language::create([
            'name' => 'Spanish',
        ]);

        Language::create([
            'name' => 'French',
        ]);

        Language::create([
            'name' => 'German',
        ]);

        Language::create([
            'name' => 'Italian',
        ]);

        ContentType::create([
            'name' => 'Job Type',
            'order' => 1,
        ]);

        ContentType::create([
            'name' => 'Content Vertical',
            'order' => 2,
        ]);

        ContentType::create([
            'name' => 'Platform specialty',
            'order' => 3,
        ]);

        ContentType::create([
            'name' => 'Skills',
            'order' => 4,
        ]);

        ContentType::create([
            'name' => 'Software',
            'order' => 5,
        ]);

        // Job Types
        ContentTypeValue::create([
            'id' => ContentTypeValue::VIDEO_EDITOR,
            'content_type_id' => ContentType::JOB_TYPE,
            'title' => 'Video Editor',
            'description' => 'Video Editor',
            'icon' => 'https://tenet-api.joinroster.co/file/0c4a8f06-7923-4309-a0e4-0f7b94cd7033',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::SCRIPT_WRITER,
            'content_type_id' => ContentType::JOB_TYPE,
            'title' => 'Script Writer',
            'description' => 'Script Writer',
            'icon' => 'https://tenet-api.joinroster.co/file/b8edb832-a6ee-47a2-9de4-673ce2ea4528',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::CREATIVE_DIRECTOR,
            'content_type_id' => ContentType::JOB_TYPE,
            'title' => 'Creative Director',
            'description' => 'Creative Director',
            'icon' => 'https://tenet-api.joinroster.co/file/ac9d9cea-61b4-48b0-b9e8-5e1625290c0a',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::VIDEOGRAPHER,
            'content_type_id' => ContentType::JOB_TYPE,
            'title' => 'Videographer',
            'description' => 'Videographer',
            'icon' => 'https://tenet-api.joinroster.co/file/2d4f00db-f7f6-45b3-bee4-dece513ae7a7',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::SCRIPTWRITER,
            'content_type_id' => ContentType::JOB_TYPE,
            'title' => 'Scriptwriter',
            'description' => 'Scriptwriter',
        ]);

        // Content Verticals
        ContentTypeValue::create([
            'id' => ContentTypeValue::TRAVEL,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Travel',
            'description' => 'Travel',
            'icon' => 'https://tenet-api.joinroster.co/file/48e08852-a98a-4643-8356-50ebcae71002',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::EDUCATION,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Education',
            'description' => 'Education',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::LIFESTYLE_VLOGS,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Lifestyle & Vlogs',
            'description' => 'Lifestyle & Vlogs',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::ENTERTAINMENT,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Entertainment',
            'description' => 'Entertainment',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::TECH_STARTUP,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Tech & Startup',
            'description' => 'Tech & Startup',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::SELF_HELP,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Self-Help',
            'description' => 'Self-Help',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::KIDS_FAMILY,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Kids & Family',
            'description' => 'Kids & Family',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::IRL,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'IRL',
            'description' => 'IRL',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::SCRIPTED_SKITS,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'Scripted & Skits',
            'description' => 'Scripted & Skits',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::HOW_TO_DIY,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'title' => 'How-To & DIY',
            'description' => 'How-To & DIY',
        ]);

        // Platform Specialty
        ContentTypeValue::create([
            'id' => ContentTypeValue::TIKTOK,
            'content_type_id' => ContentType::PLATFORM_SPECIALTY,
            'title' => 'TikTok',
            'description' => 'TikTok',
            'icon' => 'https://tenet-api.joinroster.co/assets/images/svg/tiktok.svg',
        ]);

        // Skills
        ContentTypeValue::create([
            'id' => ContentTypeValue::GRAPHIC_DESIGN,
            'content_type_id' => ContentType::SKILLS,
            'title' => 'Graphic Design',
            'description' => 'Graphic Design',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::PHOTOGRAPHY,
            'content_type_id' => ContentType::SKILLS,
            'title' => 'Photography',
            'description' => 'Photography',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::COLOR_GRADING,
            'content_type_id' => ContentType::SKILLS,
            'title' => 'Color Grading',
            'description' => 'Color Grading',
        ]);

        // Software
        ContentTypeValue::create([
            'id' => ContentTypeValue::ADOBE_ILLUSTRATOR,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Adobe Illustrator',
            'description' => 'Adobe Illustrator',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::ADOBE_PHOTOSHOP,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Adobe Photoshop',
            'description' => 'Adobe Photoshop',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::FIGMA,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Figma',
            'description' => 'Figma',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::ADOBE_PREMIERE_PRO,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Adobe Premiere Pro',
            'description' => 'Adobe Premiere Pro',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::ADOBE_AFTER_EFFECTS,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Adobe After Effects',
            'description' => 'Adobe After Effects',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::ADOBE_LIGHTROOM,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Adobe Lightroom',
            'description' => 'Adobe Lightroom',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::CANVA,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Canva',
            'description' => 'Canva',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::MONDAY_COM,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Monday.com',
            'description' => 'Monday.com',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::SLACK,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Slack',
            'description' => 'Slack',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::TRELLO,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Trello',
            'description' => 'Trello',
        ]);

        ContentTypeValue::create([
            'id' => ContentTypeValue::EXCEL,
            'content_type_id' => ContentType::SOFTWARE,
            'title' => 'Excel',
            'description' => 'Excel',
        ]);
    }
}
