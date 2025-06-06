<?php

namespace App\Console\Commands;

use App\Services\OpenAIService;
use App\Models\Talent;
use App\Models\TalentContent;
use App\Models\TalentExperience;
use App\Models\TalentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessScrapedTalentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'talent:process-scraped
                            {file : The scraped JSON file path}
                            {--save : Save to database}
                            {--output= : Output file path for processed data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process scraped portfolio data using AI to extract talent information';

    protected OpenAIService $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        parent::__construct();
        $this->openAIService = $openAIService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');
        $saveToDb = $this->option('save');
        $outputPath = $this->option('output');

        $this->info("🤖 Processing scraped data with AI...");
        $this->info("📄 File: {$filePath}");

        // Check if file exists
        if (!Storage::exists($filePath)) {
            $this->error("❌ File not found: {$filePath}");
            return 1;
        }

        try {
            // Load scraped data
            $scrapedData = json_decode(Storage::get($filePath), true);

            if (!$scrapedData) {
                $this->error("❌ Invalid JSON file or empty data");
                return 1;
            }

            $this->info("📊 Loaded scraped data from: " . ($scrapedData['url'] ?? 'Unknown URL'));

            // Process with AI
            $this->line("🔄 Sending data to OpenAI for processing...");
            $processedData = $this->openAIService->processTalentPortfolio($scrapedData);

            $this->info("✅ AI processing completed!");

            // Display extracted information
            $this->displayProcessedData($processedData);

            // Save processed data to file if requested
            if ($outputPath) {
                $outputData = [
                    'original_url' => $scrapedData['url'] ?? 'Unknown',
                    'processed_at' => now()->toISOString(),
                    'extracted_data' => $processedData
                ];

                Storage::put($outputPath, json_encode($outputData, JSON_PRETTY_PRINT));
                $this->info("💾 Processed data saved to: " . storage_path("app/{$outputPath}"));
            }

            // Save to database if requested
            if ($saveToDb) {
                $this->line("💾 Saving to database...");
                $talentId = $this->saveTalentToDatabase($processedData);

                if ($talentId) {
                    $this->info("✅ Talent saved to database with ID: {$talentId}");
                } else {
                    $this->error("❌ Failed to save talent to database");
                    return 1;
                }
            }

            return 0;

        } catch (Exception $e) {
            $this->error("❌ Processing failed: " . $e->getMessage());
            $this->line("💡 Make sure your OpenAI API key is configured in the .env file");
            return 1;
        }
    }

    /**
     * Display the processed data in a readable format
     */
    private function displayProcessedData(array $data): void
    {
        $this->line("");
        $this->line("🎯 <fg=cyan>EXTRACTED TALENT INFORMATION</>");
        $this->line(str_repeat("=", 50));

        // Talent basic info
        if (!empty($data['talent'])) {
            $talent = $data['talent'];
            $this->line("<fg=yellow>👤 TALENT PROFILE</>");
            $this->line("Name: " . ($talent['name'] ?? 'Not specified'));
            $this->line("Job Title: " . ($talent['job_title'] ?? 'Not specified'));
            $this->line("Description: " . ($talent['description'] ?? 'Not specified'));
            $this->line("Location: " . ($talent['location'] ?? 'Not specified'));
            $this->line("Status: " . ($talent['talent_status'] ?? 'Not specified'));
            $this->line("Availability: " . ($talent['availability'] ?? 'Not specified'));
            $this->line("");
        }

        // Content mappings
        if (!empty($data['content_mappings'])) {
            $this->line("<fg=yellow>🏷️  CONTENT MAPPINGS</>");

            $groupedMappings = [];
            foreach ($data['content_mappings'] as $mapping) {
                $contentTypeId = $mapping['content_type_id'];
                if (!isset($groupedMappings[$contentTypeId])) {
                    $groupedMappings[$contentTypeId] = [];
                }
                $groupedMappings[$contentTypeId][] = $mapping['content_type_value_id'];
            }

            foreach ($groupedMappings as $typeId => $valueIds) {
                $typeName = $this->getContentTypeName($typeId);
                $this->line("• {$typeName}: " . count($valueIds) . " items");
            }
            $this->line("");
        }

        // Experiences
        if (!empty($data['experiences'])) {
            $this->line("<fg=yellow>💼 EXPERIENCES</>");
            foreach ($data['experiences'] as $index => $experience) {
                $this->line("• " . ($experience['client_name'] ?? 'Unknown Client'));
                $this->line("  Period: " . ($experience['period'] ?? 'Not specified'));
                $this->line("  Type: " . ($experience['job_type'] ?? 'Not specified'));
            }
            $this->line("");
        }

        // Projects
        if (!empty($data['projects'])) {
            $this->line("<fg=yellow>🎬 PROJECTS</>");
            foreach ($data['projects'] as $index => $project) {
                $this->line("• " . ($project['title'] ?? 'Untitled Project'));
                if (!empty($project['link'])) {
                    $this->line("  Link: " . $project['link']);
                }
            }
            $this->line("");
        }
    }

    /**
     * Get content type name by ID
     */
    private function getContentTypeName(int $typeId): string
    {
        $contentTypes = [
            1 => 'Job Types',
            2 => 'Content Verticals',
            3 => 'Platform Specialties',
            4 => 'Skills',
            5 => 'Software'
        ];

        return $contentTypes[$typeId] ?? 'Unknown';
    }

    /**
     * Save talent data to database
     */
    private function saveTalentToDatabase(array $data): ?int
    {
        try {
            // Create talent record
            $talentData = $data['talent'] ?? [];

            // Set default image if not provided
            if (empty($talentData['image'])) {
                $talentData['image'] = 'https://via.placeholder.com/300x300?text=No+Image';
            }

            // Set default timezone if not provided
            if (empty($talentData['timezone'])) {
                $talentData['timezone'] = 'UTC';
            }

            $talent = Talent::create([
                'name' => $talentData['name'] ?? 'Unknown',
                'job_title' => $talentData['job_title'] ?? 'Not specified',
                'description' => $talentData['description'] ?? 'No description available',
                'image' => $talentData['image'],
                'location' => $talentData['location'] ?? 'Not specified',
                'timezone' => $talentData['timezone'],
                'talent_status' => $talentData['talent_status'] ?? 'Not specified',
                'availability' => $talentData['availability'] ?? 'Not specified',
            ]);

            $this->line("✅ Created talent record with ID: {$talent->id}");

            // Create content mappings
            if (!empty($data['content_mappings'])) {
                foreach ($data['content_mappings'] as $mapping) {
                    TalentContent::create([
                        'talent_id' => $talent->id,
                        'content_type_id' => $mapping['content_type_id'],
                        'content_type_value_id' => $mapping['content_type_value_id'],
                    ]);
                }
                $this->line("✅ Created " . count($data['content_mappings']) . " content mappings");
            }

            // Create experiences
            if (!empty($data['experiences'])) {
                foreach ($data['experiences'] as $experienceData) {
                    TalentExperience::create([
                        'talent_id' => $talent->id,
                        'client_name' => $experienceData['client_name'] ?? 'Unknown Client',
                        'job_type' => $experienceData['job_type'] ?? 'Not specified',
                        'period' => $experienceData['period'] ?? 'Not specified',
                        'description' => $experienceData['description'] ?? 'No description available',
                    ]);
                }
                $this->line("✅ Created " . count($data['experiences']) . " experience records");
            }

            // Create projects
            if (!empty($data['projects'])) {
                foreach ($data['projects'] as $projectData) {
                    TalentProject::create([
                        'talent_id' => $talent->id,
                        'title' => $projectData['title'] ?? 'Untitled Project',
                        'description' => $projectData['description'] ?? 'No description available',
                        'link' => $projectData['link'] ?? null,
                        'project_roles' => $projectData['project_roles'] ?? [],
                    ]);
                }
                $this->line("✅ Created " . count($data['projects']) . " project records");
            }

            return $talent->id;

        } catch (Exception $e) {
            $this->error("Database error: " . $e->getMessage());
            return null;
        }
    }
}
