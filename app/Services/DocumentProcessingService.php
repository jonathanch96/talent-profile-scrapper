<?php

namespace App\Services;

use App\Models\Talent;
use App\Models\TalentScrapingResult;
use App\Models\TalentDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * @param string|null $documentsDir Custom documents directory path
     * @return array
     */
    public function processDocuments(Talent $talent, TalentScrapingResult $scrapingResult, array $downloadableLinks, ?string $documentsDir = null): array
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
                    'type' => $linkData['document_type'],
                    'custom_documents_dir' => $documentsDir
                ]);

                // Set custom documents directory in the download service if provided
                if ($documentsDir) {
                    $this->downloadService->setCustomDocumentsDirectory($documentsDir);
                }

                // Download the document
                if ($this->downloadService->downloadDocument($document)) {
                    Log::info("Document downloaded successfully", ['document_id' => $document->id]);

                    // First, check if there's already an extracted text file from command-line processing
                    $extractedContent = $this->tryGetExistingExtractedContent($document, $documentsDir);

                    if ($extractedContent) {
                        Log::info("Using existing extracted content", [
                            'document_id' => $document->id,
                            'content_length' => strlen($extractedContent)
                        ]);

                        // Update document with the clean extracted content
                        $document->update([
                            'extraction_status' => 'completed',
                            'extracted_content' => $extractedContent,
                        ]);

                        $processedDocuments[] = [
                            'document_id' => $document->id,
                            'filename' => $document->filename,
                            'original_name' => $linkData['text'],
                            'content' => $extractedContent,
                            'content_length' => strlen($extractedContent),
                            'type' => 'extracted_text'
                        ];
                    } else {
                        // Fallback to extraction service if no pre-extracted content exists
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
     * Try to get existing extracted content from command-line processing
     */
    protected function tryGetExistingExtractedContent(TalentDocument $document, ?string $documentsDir = null): ?string
    {
        if (!$document->file_path) {
            return null;
        }

        // Get the directory where the document is stored
        $documentPath = $document->file_path;
        $pathInfo = pathinfo($documentPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = $pathInfo['extension'];

        // Look for extracted files with common patterns
        $extractedPatterns = [
            // Pattern 1: filename_extracted.txt (most common from command-line)
            $directory . '/' . $filename . '_extracted.txt',
            // Pattern 2: filename.txt
            $directory . '/' . $filename . '.txt',
            // Pattern 3: In case it's in a subdirectory
            $directory . '/documents/' . $filename . '_extracted.txt',
            $directory . '/scraped-data/documents/' . $filename . '_extracted.txt',
        ];

        // Also check if we're in a custom documents directory
        if ($documentsDir) {
            $baseFilename = basename($filename);
            $extractedPatterns[] = $documentsDir . '/' . $baseFilename . '_extracted.txt';
            $extractedPatterns[] = str_replace('/documents/', '/scraped-data/documents/', $documentsDir) . '/' . $baseFilename . '_extracted.txt';
        }

        foreach ($extractedPatterns as $pattern) {
            try {
                if (Storage::exists($pattern)) {
                    $content = Storage::get($pattern);
                    if (!empty(trim($content))) {
                        Log::info("Found existing extracted content", [
                            'document_id' => $document->id,
                            'extracted_file' => $pattern,
                            'content_length' => strlen($content)
                        ]);
                        return $content;
                    }
                }
            } catch (Exception $e) {
                Log::debug("Failed to read extracted file", [
                    'pattern' => $pattern,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::debug("No existing extracted content found", [
            'document_id' => $document->id,
            'checked_patterns' => $extractedPatterns
        ]);

        return null;
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
