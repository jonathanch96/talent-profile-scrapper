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
use Illuminate\Support\Facades\Storage;

class ScrapePortfolioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Talent $talent
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting portfolio scraping for talent: {$this->talent->username}");

            // Update talent scraping status
            $this->talent->update(['scraping_status' => 'scraping_portfolio']);

            // Create or update scraping result record
            $scrapingResult = TalentScrapingResult::updateOrCreate(
                [
                    'talent_id' => $this->talent->id,
                    'website_url' => $this->talent->website_url,
                ],
                [
                    'status' => 'scraping',
                    'error_message' => null,
                ]
            );

            // Generate unique filename for scraped data
            $filename = "scraped_data/{$this->talent->id}_{$this->talent->username}_" . now()->timestamp . ".json";
            $filePath = storage_path("app/{$filename}");

            // Ensure directory exists
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Run the scraping command
            $exitCode = Artisan::call('scrape:portfolio', [
                'url' => $this->talent->website_url,
                '--spa' => true,
                '--json' => true,
                '--output' => $filePath,
            ]);

            if ($exitCode === 0 && file_exists($filePath)) {
                // Update scraping result with file path
                $scrapingResult->update([
                    'scraped_data_path' => $filename,
                    'status' => 'completed',
                    'metadata' => [
                        'file_size' => filesize($filePath),
                        'scraped_at' => now()->toDateTimeString(),
                    ]
                ]);

                // Update talent status and dispatch next job
                $this->talent->update(['scraping_status' => 'processing_with_llm']);

                // Dispatch job to process scraped data with LLM
                ProcessScrapedTalentJob::dispatch($this->talent, $scrapingResult);

                Log::info("Portfolio scraping completed for talent: {$this->talent->username}");
            } else {
                throw new \Exception("Scraping command failed or output file not created");
            }

        } catch (\Exception $e) {
            Log::error("Portfolio scraping failed for talent: {$this->talent->username}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update status to failed
            $this->talent->update(['scraping_status' => 'failed']);

            if (isset($scrapingResult)) {
                $scrapingResult->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ScrapePortfolioJob failed for talent: {$this->talent->username}", [
            'error' => $exception->getMessage()
        ]);

        $this->talent->update(['scraping_status' => 'failed']);
    }
}
