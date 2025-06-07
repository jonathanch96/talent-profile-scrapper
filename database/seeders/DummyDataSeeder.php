<?php

namespace Database\Seeders;

use App\Models\ContentType;
use App\Models\ContentTypeValue;
use App\Models\Talent;
use App\Models\TalentContent;
use App\Models\TalentExperience;
use App\Models\TalentProject;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DummyDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Talent::create([
            'id' => 1,
            'username' => 'rizkyoctavianus',
            'name' => 'Rizky Octavianus',
            'job_title' => 'Video Editor',
            'description' => 'HI, I\'M A SENIOR MOTION AND VIDEO EDITOR WITH 5 YEARS OF EXPERIENCE IN VIDEO EDITING, MOTION GRAPHICS, GRAPHIC DESIGN, AND PHOTO EDITING. I ENJOY CREATING VISUALLY ENGAGING CONTENT AND TURNING IDEAS INTO POLISHED, PROFESSIONAL VISUALS.',
            'image' => 'https://tenet-api.joinroster.co/file/51b65643-fec7-4d00-a1f1-39b2e4482671',
            'location' => 'Jakarta, Indonesia',
            'timezone' => 'Asia/Jakarta',
            'talent_status' => 'Open to work',
            'availability' => 'Freelance',
        ]);
        TalentExperience::create([
            'id' => 1,
            'talent_id' => 1,
            'client_name' => 'GudangAda',
            'client_sub_title' => null,
            'client_logo' => 'https://media.licdn.com/dms/image/D4E12AQFuCmxN72C2yQ/article-cover_image-shrink_720_1280/0/1702503196049?e=2147483647&v=beta&t=9HHff4rJDnxuWrqfzPqX9j2dncDBKQeShXf2Wt5nrUc',
            'job_type' => 'Full Time',
            'period' => 'Jan 2023 - Jan 2024',
            'description' => '- Assisted in creating graphic designs and motion graphics. - Supported the marketing team with visual content.',
        ]);

        TalentProject::create([
            'id' => 1,
            'talent_id' => 1,
            'experience_id' => 1,
            'project_roles' => json_encode(['Video Editor', 'Script Writer']),
            'title' => '#TemanUla: Cerita Pak Roby dan Kampung Ula dalam Membawa Perubahan dimasa Pandemi',
            'views' => 100,
            'likes' => 100,
            'image' => 'https://i.ytimg.com/vi/eDVUgrcvJHQ/hqdefault.jpg',
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::JOB_TYPE,
            'content_type_value_id' => ContentTypeValue::VIDEO_EDITOR,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::JOB_TYPE,
            'content_type_value_id' => ContentTypeValue::SCRIPT_WRITER,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'content_type_value_id' => ContentTypeValue::TRAVEL,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'content_type_value_id' => ContentTypeValue::ENTERTAINMENT,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::CONTENT_VERTICAL,
            'content_type_value_id' => ContentTypeValue::EDUCATION,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::PLATFORM_SPECIALTY,
            'content_type_value_id' => ContentTypeValue::TIKTOK,
        ]);


        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::SKILLS,
            'content_type_value_id' => ContentTypeValue::GRAPHIC_DESIGN,
        ]);


        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::SKILLS,
            'content_type_value_id' => ContentTypeValue::PHOTOGRAPHY,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::SOFTWARE,
            'content_type_value_id' => ContentTypeValue::ADOBE_PREMIERE_PRO,
        ]);

        TalentContent::create([
            'talent_id' => 1,
            'content_type_id' => ContentType::SOFTWARE,
            'content_type_value_id' => ContentTypeValue::ADOBE_AFTER_EFFECTS,
        ]);

        Talent::create([
            'id' => 2,
            'username' => 'sonu-choudhary',
            'name' => 'Sonu Choudhary',
        ]);
    }
}
