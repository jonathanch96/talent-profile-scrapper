<?php

namespace App\Jobs;

use App\Models\Talent;
use App\Models\TalentScrapingResult;
use App\Services\OpenAIService;
use App\Services\DocumentProcessingService;
use App\Jobs\UpdateTalentDataJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessScrapedTalentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout for LLM processing
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Talent $talent,
        public TalentScrapingResult $scrapingResult,
        public array $folderStructure = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting scraped data processing for talent: {$this->talent->username}");

            // Update scraping result status
            $this->scrapingResult->update(['status' => 'processing']);

            // Load scraped data
            $scrapedData = json_decode(Storage::get($this->scrapingResult->scraped_data_path), true);

            if (!$scrapedData) {
                throw new \Exception("Invalid or empty scraped data");
            }

            // Process documents if any downloadable links exist
            $processedDocuments = [];
            if (!empty($scrapedData['downloadable_links'])) {
                $documentService = new DocumentProcessingService();

                // Use the new documents directory if provided, otherwise fallback to old logic
                $documentsDir = $this->folderStructure['documents_dir'] ?? null;

                $processedDocuments = $documentService->processDocuments(
                    $this->talent,
                    $this->scrapingResult,
                    $scrapedData['downloadable_links'],
                    $documentsDir
                );

                // Add processed documents to scraped data for AI processing
                if (!empty($processedDocuments)) {
                    $scrapedData['extracted_documents'] = $processedDocuments;
                }
            }

            // Process with OpenAI
            $openAIService = new OpenAIService();
            $processedData = $openAIService->processTalentPortfolio($scrapedData);

            // Use the new processed data directory if provided, otherwise use the old structure
            if (!empty($this->folderStructure['processed_data_dir'])) {
                $processedDataFile = "{$this->folderStructure['processed_data_dir']}/ai_processed_data.json";
            } else {
                // Fallback to old structure for backwards compatibility
                $processedDataFile = "processed_data/{$this->talent->id}_{$this->talent->username}_" . now()->timestamp . ".json";
            }

            // Save processed data
            $outputData = [
                'original_url' => $scrapedData['url'] ?? 'Unknown',
                'processed_at' => now()->toISOString(),
                'included_documents' => $processedDocuments,
                'extracted_data' => $processedData,
                'folder_structure' => $this->folderStructure
            ];

            Storage::put($processedDataFile, json_encode($outputData, JSON_PRETTY_PRINT));

            // Update scraping result with processed file path (use the one from DB if it was set earlier)
            $finalProcessedDataPath = $this->scrapingResult->processed_data_path ?: $processedDataFile;

            $this->scrapingResult->update([
                'processed_data_path' => $finalProcessedDataPath,
                'status' => 'completed',
                'metadata' => array_merge($this->scrapingResult->metadata ?? [], [
                    'processed_file_size' => strlen(json_encode($outputData)),
                    'ai_processed_at' => now()->toDateTimeString(),
                    'folder_structure' => $this->folderStructure
                ])
            ]);

            Log::info("AI processing completed for talent: {$this->talent->username}");

            // Dispatch separate job to update talent data in database
            // This separation ensures that AI costs are not repeated if DB insertion fails
            UpdateTalentDataJob::dispatch($this->talent, $processedData);

            Log::info("Database update job dispatched for talent: {$this->talent->username}");

        } catch (\Exception $e) {
            Log::error("Scraped data processing failed for talent: {$this->talent->username}", [
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
