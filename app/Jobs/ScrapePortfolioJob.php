<?php

namespace App\Jobs;

use App\Models\Talent;
use App\Models\TalentScrapingResult;
use App\Services\DocumentDownloadService;
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
                '--output' => dirname($filePath),
            ]);

                        if ($exitCode === 0) {
                // Find the generated scraped data file
                $outputDir = dirname($filePath);
                $scrapedFiles = glob($outputDir . "/scraped_*/*_data.json");

                if (empty($scrapedFiles)) {
                    throw new \Exception("No scraped data file found in output directory");
                }

                $actualScrapedFile = $scrapedFiles[0]; // Get the first (latest) file
                $relativePath = str_replace(storage_path('app/'), '', $actualScrapedFile);

                // Load and analyze scraped data for documents
                $scrapedData = json_decode(file_get_contents($actualScrapedFile), true);

                // Extract downloadable links
                $downloadService = new DocumentDownloadService();
                $downloadableLinks = $downloadService->extractDownloadableLinks($scrapedData);

                // Add downloadable links to scraped data
                $scrapedData['downloadable_links'] = $downloadableLinks;

                // Save updated scraped data
                file_put_contents($actualScrapedFile, json_encode($scrapedData, JSON_PRETTY_PRINT));

                // Update scraping result with file path and document info
                $scrapingResult->update([
                    'scraped_data_path' => $relativePath,
                    'status' => 'completed',
                    'metadata' => [
                        'file_size' => filesize($actualScrapedFile),
                        'scraped_at' => now()->toDateTimeString(),
                        'downloadable_links_found' => count($downloadableLinks),
                        'downloadable_links' => $downloadableLinks,
                    ]
                ]);

                // Update talent status and dispatch next jobs
                $this->talent->update(['scraping_status' => 'processing_with_llm']);

                // Dispatch job to process scraped data with LLM (includes document processing)
                ProcessScrapedTalentJob::dispatch($this->talent, $scrapingResult);

                Log::info("Dispatched scraped talent processing job", [
                    'talent_id' => $this->talent->id,
                    'downloadable_links_found' => count($downloadableLinks)
                ]);

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
