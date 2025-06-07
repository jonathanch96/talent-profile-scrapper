<?php

namespace App\Jobs;

use App\Models\Talent;
use App\Models\TalentScrapingResult;
use App\Models\TalentDocument;
use App\Services\DocumentDownloadService;
use App\Services\DocumentContentExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Talent $talent,
        public TalentScrapingResult $scrapingResult,
        public array $downloadableLinks
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting document processing for talent: {$this->talent->username}", [
                'document_count' => count($this->downloadableLinks)
            ]);

            $downloadService = new DocumentDownloadService();
            $extractorService = new DocumentContentExtractorService();

            foreach ($this->downloadableLinks as $linkData) {
                try {
                    // Create document record
                    $document = TalentDocument::create([
                        'talent_id' => $this->talent->id,
                        'scraping_result_id' => $this->scrapingResult->id,
                        'original_url' => $linkData['url'],
                        'source_link_text' => $linkData['text'],
                        'document_type' => $linkData['document_type'],
                        'filename' => $this->generateTempFilename($linkData),
                        'download_status' => 'pending',
                        'extraction_status' => 'pending',
                    ]);

                    Log::info("Processing document", [
                        'document_id' => $document->id,
                        'url' => $linkData['url'],
                        'type' => $linkData['document_type']
                    ]);

                    // Download the document
                    if ($downloadService->downloadDocument($document)) {
                        Log::info("Document downloaded successfully", ['document_id' => $document->id]);

                        // Extract content from the downloaded document
                        if ($extractorService->extractContent($document)) {
                            Log::info("Content extracted successfully", [
                                'document_id' => $document->id,
                                'content_length' => strlen($document->fresh()->extracted_content ?? '')
                            ]);
                        } else {
                            Log::warning("Content extraction failed", ['document_id' => $document->id]);
                        }
                    } else {
                        Log::warning("Document download failed", ['document_id' => $document->id]);
                    }

                } catch (\Exception $e) {
                    Log::error("Error processing document", [
                        'url' => $linkData['url'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update the scraping result with document processing completion
            $this->updateScrapingResultWithDocuments();

            // Trigger vector embedding update if any documents were processed successfully
            $this->triggerVectorUpdateIfNeeded();

            Log::info("Document processing completed for talent: {$this->talent->username}");

        } catch (\Exception $e) {
            Log::error("Document processing job failed for talent: {$this->talent->username}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Generate temporary filename for document
     */
    protected function generateTempFilename(array $linkData): string
    {
        $baseName = $linkData['text']
            ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $linkData['text'])
            : 'document';

        return substr($baseName, 0, 50) . '.' . $linkData['document_type'];
    }

    /**
     * Update scraping result with document processing information
     */
    protected function updateScrapingResultWithDocuments(): void
    {
        $documents = TalentDocument::where('scraping_result_id', $this->scrapingResult->id)->get();

        $metadata = array_merge($this->scrapingResult->metadata ?? [], [
            'documents_processed' => [
                'total' => $documents->count(),
                'downloaded' => $documents->where('download_status', 'completed')->count(),
                'extracted' => $documents->where('extraction_status', 'completed')->count(),
                'failed_download' => $documents->where('download_status', 'failed')->count(),
                'failed_extraction' => $documents->where('extraction_status', 'failed')->count(),
                'processed_at' => now()->toDateTimeString(),
            ]
        ]);

        $this->scrapingResult->update(['metadata' => $metadata]);
    }

    /**
     * Trigger vector update if documents were successfully processed
     */
    protected function triggerVectorUpdateIfNeeded(): void
    {
        $successfulDocuments = TalentDocument::where('talent_id', $this->talent->id)
            ->where('extraction_status', 'completed')
            ->whereNotNull('extracted_content')
            ->count();

        if ($successfulDocuments > 0) {
            Log::info("Triggering vector update due to new document content", [
                'talent_id' => $this->talent->id,
                'successful_documents' => $successfulDocuments
            ]);

            // Dispatch vector embedding update job
            UpdateVectorEmbeddingJob::dispatch($this->talent);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessDocumentsJob failed for talent: {$this->talent->username}", [
            'error' => $exception->getMessage()
        ]);
    }
}
