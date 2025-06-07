<?php

namespace App\Console\Commands;

use App\Models\Talent;
use App\Jobs\UpdateTalentDataJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessAIDataToDbCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'talent:process-ai-data-to-db
                            {file : The processed AI data JSON file path}
                            {--talent-id= : Talent ID for database operations}
                            {--sync : Run synchronously instead of dispatching job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process already-saved AI data to database (cost-efficient retry for DB failures)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');
        $talentId = $this->option('talent-id');
        $sync = $this->option('sync');

        $this->info("💾 Processing AI data to database...");
        $this->info("📄 File: {$filePath}");

        if (!$talentId) {
            $this->error("❌ --talent-id is required");
            return 1;
        }

        // Check if file exists (handle both relative and private paths)
        $actualPath = $filePath;
        if (!Storage::exists($filePath)) {
            // Try with private prefix
            $actualPath = 'private/' . $filePath;
            if (!Storage::exists($actualPath)) {
                $this->error("❌ File not found: {$filePath}");
                $this->line("💡 Available files in processed_data:");
                $files = Storage::files('private/processed_data');
                foreach (array_slice($files, 0, 10) as $file) {
                    $this->line("  • " . basename($file));
                }
                return 1;
            }
            $filePath = $actualPath;
        }

        try {
            // Load processed AI data
            $fileData = json_decode(Storage::get($filePath), true);

            if (!$fileData || !isset($fileData['extracted_data'])) {
                $this->error("❌ Invalid processed data file format");
                return 1;
            }

            $processedData = $fileData['extracted_data'];
            $talent = Talent::find($talentId);

            if (!$talent) {
                $this->error("❌ Talent not found with ID: {$talentId}");
                return 1;
            }

            $this->info("👤 Processing data for: {$talent->name} ({$talent->username})");

            // Display what will be processed
            $this->displayDataSummary($processedData);

            if ($sync) {
                $this->line("🔄 Processing synchronously...");

                // Run the job synchronously
                UpdateTalentDataJob::dispatchSync($talent, $processedData);

                $this->info("✅ Talent data updated successfully in database!");
            } else {
                $this->line("🔄 Dispatching database update job...");

                // Dispatch as background job
                UpdateTalentDataJob::dispatch($talent, $processedData);

                $this->info("✅ Database update job dispatched successfully!");
                $this->line("💡 Run 'php artisan queue:work' to process the job");
            }

            return 0;

        } catch (Exception $e) {
            $this->error("❌ Processing failed: " . $e->getMessage());
            $this->line("💡 Use --sync option to see detailed error messages");
            return 1;
        }
    }

    /**
     * Display summary of data to be processed
     */
    private function displayDataSummary(array $processedData): void
    {
        $this->line("\n📊 DATA SUMMARY:");

        if (isset($processedData['experiences'])) {
            $this->line("💼 Experiences: " . count($processedData['experiences']));
        }

        if (isset($processedData['projects'])) {
            $this->line("🎬 Projects: " . count($processedData['projects']));
        }

        if (isset($processedData['skills'])) {
            $this->line("🛠️ Skills: " . count($processedData['skills']));
        }

        if (isset($processedData['softwares'])) {
            $this->line("💻 Software: " . count($processedData['softwares']));
        }

        if (isset($processedData['content_vertical'])) {
            $this->line("📱 Content Verticals: " . count($processedData['content_vertical']));
        }

        if (isset($processedData['platform_specialties'])) {
            $this->line("🌐 Platform Specialties: " . count($processedData['platform_specialties']));
        }

        if (isset($processedData['languages'])) {
            $this->line("🗣️ Languages: " . count($processedData['languages']));
        }

        $this->line("");
    }
}
