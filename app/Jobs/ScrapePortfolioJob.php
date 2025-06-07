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
    public $tries = 1;

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

            // Create a new scraping result record for each attempt
            $scrapingResult = TalentScrapingResult::create([
                'talent_id' => $this->talent->id,
                'website_url' => $this->talent->website_url,
                'status' => 'scraping',
                'error_message' => null,
            ]);

            Log::info("Created new scraping result record", [
                'scraping_result_id' => $scrapingResult->id,
                'talent_id' => $this->talent->id
            ]);

            // Generate improved folder structure: {username}/{scraping_result_id}/scraped-data/
            $baseDir = "{$this->talent->username}/{$scrapingResult->id}";
            $scrapedDataDir = "{$baseDir}/scraped-data";
            $documentsDir = "{$baseDir}/documents";
            $processedDataDir = "{$baseDir}/processed_data";

            $scrapedDataPath = "{$scrapedDataDir}/scraped_data.json";
            $processedDataPath = "{$processedDataDir}/ai_processed_data.json";

            // Update scraping result with both paths immediately
            $scrapingResult->update([
                'scraped_data_path' => $scrapedDataPath,
                'processed_data_path' => $processedDataPath,
            ]);

            Log::info("Running scrape command with improved folder structure", [
                'url' => $this->talent->website_url,
                'base_dir' => $baseDir,
                'scraped_data_dir' => $scrapedDataDir,
                'documents_dir' => $documentsDir,
                'processed_data_dir' => $processedDataDir,
                'scraping_result_id' => $scrapingResult->id
            ]);

            $exitCode = Artisan::call('scrape:portfolio', [
                'url' => $this->talent->website_url,
                '--output' => $scrapedDataDir,
            ]);

            if ($exitCode === 0) {
                // Use Storage facade to check if file exists
                if (!Storage::exists($scrapedDataPath)) {
                    throw new \Exception("Scraped data file not found at expected location: {$scrapedDataPath}");
                }

                Log::info("Scrape command completed successfully", [
                    'exit_code' => $exitCode,
                    'scraped_data_path' => $scrapedDataPath,
                    'absolute_path' => Storage::path($scrapedDataPath),
                    'file_size' => Storage::size($scrapedDataPath)
                ]);

                // Update status to processing (path was already saved earlier)
                $scrapingResult->update([
                    'status' => 'processing',
                ]);

                // Load and analyze scraped data for documents using Storage facade
                $scrapedDataContent = Storage::get($scrapedDataPath);
                $scrapedData = json_decode($scrapedDataContent, true);

                if (!$scrapedData) {
                    throw new \Exception("Failed to parse scraped JSON data");
                }

                // Extract downloadable links
                $downloadService = new DocumentDownloadService();
                $downloadableLinks = $downloadService->extractDownloadableLinks($scrapedData);

                // Add downloadable links to scraped data
                $scrapedData['downloadable_links'] = $downloadableLinks;

                // Save updated scraped data using Storage facade
                Storage::put($scrapedDataPath, json_encode($scrapedData, JSON_PRETTY_PRINT));

                // Update scraping result with final status and metadata
                Log::info("Updating scraping result with final status", [
                    'scraped_data_path' => $scrapedDataPath,
                    'file_size' => Storage::size($scrapedDataPath),
                    'downloadable_links_found' => count($downloadableLinks)
                ]);

                $scrapingResult->update([
                    'status' => 'completed',
                    'metadata' => [
                        'file_size' => Storage::size($scrapedDataPath),
                        'scraped_at' => now()->toDateTimeString(),
                        'downloadable_links_found' => count($downloadableLinks),
                        'downloadable_links' => $downloadableLinks,
                    ]
                ]);

                Log::info("Scraping result updated successfully", [
                    'id' => $scrapingResult->id,
                    'scraped_data_path' => $scrapingResult->fresh()->scraped_data_path
                ]);

                // Update talent status and dispatch next jobs
                $this->talent->update(['scraping_status' => 'processing_with_llm']);

                // Dispatch job to process scraped data with LLM (includes document processing)
                // Pass the organized folder structure information
                ProcessScrapedTalentJob::dispatch($this->talent, $scrapingResult, [
                    'base_dir' => $baseDir,
                    'documents_dir' => $documentsDir,
                    'processed_data_dir' => $processedDataDir
                ]);

                Log::info("Dispatched scraped talent processing job", [
                    'talent_id' => $this->talent->id,
                    'downloadable_links_found' => count($downloadableLinks),
                    'folder_structure' => [
                        'base_dir' => $baseDir,
                        'documents_dir' => $documentsDir,
                        'processed_data_dir' => $processedDataDir
                    ]
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
