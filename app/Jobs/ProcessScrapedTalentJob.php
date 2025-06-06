<?php

namespace App\Jobs;

use App\Models\Talent;
use App\Models\TalentScrapingResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ProcessScrapedTalentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout for LLM processing
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Talent $talent,
        public TalentScrapingResult $scrapingResult
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting LLM processing for talent: {$this->talent->username}");

            // Update scraping result status
            $this->scrapingResult->update(['status' => 'processing']);

            // Generate unique filename for processed data
            $filename = "processed_data/{$this->talent->id}_{$this->talent->username}_" . now()->timestamp . ".json";
            $filePath = storage_path("app/{$filename}");

            // Ensure directory exists
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Get the scraped data file path
            $scrapedDataPath = storage_path("app/{$this->scrapingResult->scraped_data_path}");

            if (!file_exists($scrapedDataPath)) {
                throw new \Exception("Scraped data file not found: {$scrapedDataPath}");
            }

            // Run the processing command
            $exitCode = Artisan::call('process:scraped-talent', [
                'input' => $scrapedDataPath,
                '--output' => $filePath,
                '--talent-id' => $this->talent->id,
            ]);

            if ($exitCode === 0 && file_exists($filePath)) {
                // Update scraping result with processed file path
                $this->scrapingResult->update([
                    'processed_data_path' => $filename,
                    'status' => 'completed',
                    'metadata' => array_merge($this->scrapingResult->metadata ?? [], [
                        'processed_file_size' => filesize($filePath),
                        'processed_at' => now()->toDateTimeString(),
                    ])
                ]);

                // Update talent status
                $this->talent->update(['scraping_status' => 'completed']);

                // Dispatch job to update vector embeddings
                UpdateVectorEmbeddingJob::dispatch($this->talent);

                Log::info("LLM processing completed for talent: {$this->talent->username}");
            } else {
                throw new \Exception("Processing command failed or output file not created");
            }

        } catch (\Exception $e) {
            Log::error("LLM processing failed for talent: {$this->talent->username}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update status to failed
            $this->talent->update(['scraping_status' => 'failed']);
            $this->scrapingResult->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessScrapedTalentJob failed for talent: {$this->talent->username}", [
            'error' => $exception->getMessage()
        ]);

        $this->talent->update(['scraping_status' => 'failed']);
        $this->scrapingResult->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}
