<?php

namespace App\Console\Commands;

use App\Services\AiAgentService;
use App\Models\Talent;
use App\Models\TalentContent;
use App\Models\TalentExperience;
use App\Models\TalentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;
use App\Services\DocumentProcessingService;
use App\Jobs\UpdateTalentDataJob;

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
                            {--output= : Output file path for processed data}
                            {--talent-id= : Talent ID for database operations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process scraped portfolio data using AI to extract talent information and optionally save to database';

    protected AiAgentService $aiAgentService;
    protected DocumentProcessingService $documentService;

    public function __construct(AiAgentService $aiAgentService)
    {
        parent::__construct();
        $this->aiAgentService = $aiAgentService;
        $this->documentService = new DocumentProcessingService();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');
        $saveToDb = $this->option('save');
        $outputPath = $this->option('output');
        $talentId = $this->option('talent-id');

        $this->info("🤖 Processing scraped data with AI...");
        $this->info("📄 File: {$filePath}");

        // Check if file exists (handle both relative and private paths)
        $actualPath = $filePath;
        if (!Storage::exists($filePath)) {
            // Try with private prefix
            $actualPath = 'private/' . $filePath;
            if (!Storage::exists($actualPath)) {
                $this->error("❌ File not found: {$filePath}");
                $this->line("💡 Available files in scraped-data:");
                $files = Storage::files('private/scraped-data');
                foreach (array_slice($files, 0, 10) as $file) {
                    $this->line("  • " . basename(dirname($file)) . '/' . basename($file));
                }
                return 1;
            }
            $filePath = $actualPath;
        }

        try {
            // Load scraped data
            $scrapedData = json_decode(Storage::get($filePath), true);

            if (!$scrapedData) {
                $this->error("❌ Invalid JSON file or empty data");
                return 1;
            }

            $this->info("📊 Loaded scraped data from: " . ($scrapedData['url'] ?? 'Unknown URL'));

            // Process documents if available
            $processedDocuments = [];
            if (!empty($scrapedData['downloadable_links']) && $talentId) {
                $talent = Talent::find($talentId);
                if ($talent) {
                    $scrapingResult = $talent->scrapingResults()->latest()->first();
                    if ($scrapingResult) {
                        $this->line("📄 Processing documents...");
                        $processedDocuments = $this->documentService->processDocuments(
                            $talent,
                            $scrapingResult,
                            $scrapedData['downloadable_links']
                        );

                        if (!empty($processedDocuments)) {
                            $this->info("📄 Found {" . count($processedDocuments) . "} extracted document(s)");
                            $scrapedData['extracted_documents'] = $processedDocuments;
                        }
                    }
                }
            } else {
                // Load extracted documents text if available (legacy support)
                $documentsData = $this->loadExtractedDocuments($filePath);
                if (!empty($documentsData)) {
                    $this->info("📄 Found {" . count($documentsData) . "} extracted document(s)");
                    $scrapedData['extracted_documents'] = $documentsData;
                }
            }

            // Process with AI
            $this->line("🔄 Sending data to OpenAI for processing...");
            $processedData = $this->aiAgentService->processTalentPortfolio($scrapedData);

            $this->info("✅ AI processing completed!");

            // Display extracted information
            $this->displayProcessedData($processedData);

            // Save processed data to file if requested
            if ($outputPath) {
                $outputData = [
                    'original_url' => $scrapedData['url'] ?? 'Unknown',
                    'processed_at' => now()->toISOString(),
                    'included_documents' => $processedDocuments,
                    'extracted_data' => $processedData
                ];

                Storage::makeDirectory(dirname($outputPath));
                Storage::put($outputPath, json_encode($outputData, JSON_PRETTY_PRINT));
                $this->info("💾 Processed data saved to: " . storage_path("app/{$outputPath}"));
            }

            // Save to database if requested
            if ($saveToDb && $talentId) {
                $talent = Talent::find($talentId);
                if ($talent) {
                    $this->line("💾 Saving to database...");

                    // Use the new UpdateTalentDataJob to save the data
                    UpdateTalentDataJob::dispatchSync($talent, $processedData);

                    $this->info("✅ Talent data updated in database for ID: {$talentId}");
                } else {
                    $this->error("❌ Talent not found with ID: {$talentId}");
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
     * Load extracted documents text from the same directory structure
     */
    private function loadExtractedDocuments(string $scrapedFilePath): array
    {
        $documentsData = [];

        // Get the directory of the scraped file
        $baseDir = dirname($scrapedFilePath);
        $documentsDir = $baseDir . '/documents';

        // Check if documents directory exists
        if (!Storage::exists($documentsDir)) {
            return $documentsData;
        }

        // Get all files in the documents directory
        $files = Storage::files($documentsDir);

        foreach ($files as $file) {
            // Only process extracted text files
            if (strpos($file, '_extracted.txt') !== false) {
                try {
                    $content = Storage::get($file);
                    $filename = basename($file);

                    // Extract original document name
                    $originalName = str_replace('_extracted.txt', '', $filename);

                    $documentsData[] = [
                        'filename' => $filename,
                        'original_name' => $originalName,
                        'file_path' => $file,
                        'content' => $content,
                        'content_length' => strlen($content),
                        'type' => 'extracted_text'
                    ];

                    $this->line("  📄 Loaded: {$originalName} ({" . strlen($content) . "} chars)");

                } catch (Exception $e) {
                    $this->warn("  ⚠️  Could not load: {$file}");
                }
            }
        }

        return $documentsData;
    }

    /**
     * Display the processed data in a readable format
     */
    private function displayProcessedData(array $data): void
    {
        $this->line("");
        $this->line("🎯 <fg=cyan>EXTRACTED TALENT INFORMATION</>");
        $this->line(str_repeat("=", 50));

        // Show if documents were included
        if (!empty($data['extracted_documents_info'])) {
            $this->line("<fg=green>📄 Included extracted documents in AI processing</>");
            foreach ($data['extracted_documents_info'] as $doc) {
                $this->line("  • {$doc['original_name']} ({$doc['content_length']} chars)");
            }
            $this->line("");
        }

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
