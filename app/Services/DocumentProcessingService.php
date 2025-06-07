<?php

namespace App\Services;

use App\Models\Talent;
use App\Models\TalentScrapingResult;
use App\Models\TalentDocument;
use Illuminate\Support\Facades\Log;
use Exception;

class DocumentProcessingService
{
    protected DocumentDownloadService $downloadService;
    protected DocumentContentExtractorService $extractorService;

    public function __construct()
    {
        $this->downloadService = new DocumentDownloadService();
        $this->extractorService = new DocumentContentExtractorService();
    }

    /**
     * Process documents synchronously
     *
     * @param Talent $talent
     * @param TalentScrapingResult $scrapingResult
     * @param array $downloadableLinks
     * @return array
     */
    public function processDocuments(Talent $talent, TalentScrapingResult $scrapingResult, array $downloadableLinks): array
    {
        $processedDocuments = [];

        Log::info("Starting document processing for talent: {$talent->username}", [
            'document_count' => count($downloadableLinks)
        ]);

        foreach ($downloadableLinks as $linkData) {
            try {
                // Create document record
                $document = TalentDocument::create([
                    'talent_id' => $talent->id,
                    'scraping_result_id' => $scrapingResult->id,
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
                if ($this->downloadService->downloadDocument($document)) {
                    Log::info("Document downloaded successfully", ['document_id' => $document->id]);

                    // Extract content from the downloaded document
                    if ($this->extractorService->extractContent($document)) {
                        Log::info("Content extracted successfully", [
                            'document_id' => $document->id,
                            'content_length' => strlen($document->fresh()->extracted_content ?? '')
                        ]);

                        $processedDocuments[] = [
                            'document_id' => $document->id,
                            'filename' => $document->filename,
                            'original_name' => $linkData['text'],
                            'content' => $document->fresh()->extracted_content,
                            'content_length' => strlen($document->fresh()->extracted_content ?? ''),
                            'type' => 'extracted_text'
                        ];
                    } else {
                        Log::warning("Content extraction failed", ['document_id' => $document->id]);
                    }
                } else {
                    Log::warning("Document download failed", ['document_id' => $document->id]);
                }

            } catch (Exception $e) {
                Log::error("Error processing document", [
                    'url' => $linkData['url'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Update the scraping result with document processing completion
        $this->updateScrapingResultWithDocuments($scrapingResult);

        Log::info("Document processing completed for talent: {$talent->username}", [
            'processed_count' => count($processedDocuments)
        ]);

        return $processedDocuments;
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
    protected function updateScrapingResultWithDocuments(TalentScrapingResult $scrapingResult): void
    {
        $documents = TalentDocument::where('scraping_result_id', $scrapingResult->id)->get();

        $metadata = array_merge($scrapingResult->metadata ?? [], [
            'documents_processed' => [
                'total' => $documents->count(),
                'downloaded' => $documents->where('download_status', 'completed')->count(),
                'extracted' => $documents->where('extraction_status', 'completed')->count(),
                'failed_download' => $documents->where('download_status', 'failed')->count(),
                'failed_extraction' => $documents->where('extraction_status', 'failed')->count(),
                'processed_at' => now()->toDateTimeString(),
            ]
        ]);

        $scrapingResult->update(['metadata' => $metadata]);
    }
}
