<?php

namespace App\Services;

use App\Models\TalentDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class DocumentDownloadService
{
    protected int $timeout = 120;
    protected int $maxFileSize = 50 * 1024 * 1024; // 50MB max

    /**
     * Download document from URL
     */
    public function downloadDocument(TalentDocument $document): bool
    {
        try {
            Log::info("Starting document download", [
                'document_id' => $document->id,
                'url' => $document->original_url
            ]);

            $document->update(['download_status' => 'downloading']);

            $url = $this->processUrl($document->original_url);
            $response = $this->fetchDocument($url);

            if (!$response) {
                throw new Exception('Failed to fetch document');
            }

            $filename = $this->generateFilename($document, $response);
            $filePath = $this->saveDocument($response, $filename);

            $document->update([
                'download_status' => 'completed',
                'file_path' => $filePath,
                'filename' => $filename,
                'file_size' => strlen($response),
                'metadata' => array_merge($document->metadata ?? [], [
                    'downloaded_at' => now()->toDateTimeString(),
                    'content_type' => $this->getContentType($response),
                ])
            ]);

            Log::info("Document downloaded successfully", [
                'document_id' => $document->id,
                'file_path' => $filePath,
                'file_size' => strlen($response)
            ]);

            return true;

        } catch (Exception $e) {
            Log::error("Document download failed", [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            $document->update([
                'download_status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Process URL to handle special cases like Google Drive
     */
    protected function processUrl(string $url): string
    {
        // Google Drive direct download
        if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $fileId = $matches[1];
            return "https://drive.google.com/uc?export=download&id={$fileId}";
        }

        // Dropbox direct download
        if (strpos($url, 'dropbox.com') !== false && strpos($url, 'dl=0') !== false) {
            return str_replace('dl=0', 'dl=1', $url);
        }

        // OneDrive direct download
        if (strpos($url, '1drv.ms') !== false || strpos($url, 'onedrive.live.com') !== false) {
            return $url . '&download=1';
        }

        return $url;
    }

    /**
     * Fetch document with proper headers and error handling
     */
    protected function fetchDocument(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST),
            ])
            ->timeout($this->timeout)
            ->get($url);

            if (!$response->successful()) {
                throw new Exception("HTTP {$response->status()}: {$response->body()}");
            }

            $content = $response->body();

            if (strlen($content) > $this->maxFileSize) {
                throw new Exception("File too large: " . strlen($content) . " bytes (max: {$this->maxFileSize})");
            }

            if (strlen($content) < 100) {
                throw new Exception("File too small, possibly an error page");
            }

            return $content;

        } catch (Exception $e) {
            throw new Exception("Failed to download document: " . $e->getMessage());
        }
    }

    /**
     * Generate appropriate filename for the document
     */
    protected function generateFilename(TalentDocument $document, string $content): string
    {
        $extension = $this->detectFileExtension($content, $document->original_url);
        $baseName = $document->source_link_text
            ? $this->sanitizeFilename($document->source_link_text)
            : "document_{$document->id}";

        return "{$document->talent_id}_{$baseName}_" . now()->timestamp . ".{$extension}";
    }

    /**
     * Detect file extension from content or URL
     */
    protected function detectFileExtension(string $content, string $url): string
    {
        // Check magic numbers in file content
        $magicNumbers = [
            'pdf' => '%PDF',
            'doc' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
            'docx' => 'PK', // ZIP-based format
        ];

        foreach ($magicNumbers as $ext => $magic) {
            if (strpos($content, $magic) === 0) {
                return $ext;
            }
        }

        // Fallback to URL extension
        $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
        if (isset($pathInfo['extension'])) {
            return strtolower($pathInfo['extension']);
        }

        // Default to PDF if uncertain
        return 'pdf';
    }

    /**
     * Save document to storage
     */
    protected function saveDocument(string $content, string $filename): string
    {
        $directory = 'documents/talent_documents';
        $filePath = "{$directory}/{$filename}";

        Storage::makeDirectory($directory);
        Storage::put($filePath, $content);

        return $filePath;
    }

    /**
     * Get content type from response
     */
    protected function getContentType(string $content): string
    {
        if (strpos($content, '%PDF') === 0) {
            return 'application/pdf';
        }

        if (strpos($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") === 0) {
            return 'application/msword';
        }

        if (strpos($content, 'PK') === 0) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        return 'application/octet-stream';
    }

    /**
     * Sanitize filename for storage
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Remove any path information
        $filename = basename($filename);

        // Replace invalid characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Remove multiple underscores
        $filename = preg_replace('/_+/', '_', $filename);

        // Trim underscores from start/end
        $filename = trim($filename, '_');

        // Limit length
        return substr($filename, 0, 100);
    }

    /**
     * Extract downloadable links from scraped data
     */
    public function extractDownloadableLinks(array $scrapedData): array
    {
        $downloadableLinks = [];

        if (empty($scrapedData['links'])) {
            return $downloadableLinks;
        }

        foreach ($scrapedData['links'] as $link) {
            if ($this->isDownloadableLink($link['url'], $link['text'] ?? '')) {
                $downloadableLinks[] = [
                    'url' => $link['url'],
                    'text' => $link['text'] ?? '',
                    'document_type' => $this->guessDocumentType($link['url'], $link['text'] ?? ''),
                ];
            }
        }

        return $downloadableLinks;
    }

    /**
     * Check if a link is potentially downloadable
     */
    protected function isDownloadableLink(string $url, string $text): bool
    {
        // File extensions that indicate downloadable documents
        $documentExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'ppt', 'pptx'];

        // Check URL for document extensions
        foreach ($documentExtensions as $ext) {
            if (preg_match("/\.{$ext}(\?|$)/i", $url)) {
                return true;
            }
        }

        // Check for cloud storage services
        $cloudServices = [
            'drive.google.com',
            'dropbox.com',
            'onedrive.live.com',
            '1drv.ms',
            'docs.google.com',
            'sharepoint.com',
        ];

        foreach ($cloudServices as $service) {
            if (strpos($url, $service) !== false) {
                return true;
            }
        }

        // Check link text for document-related keywords
        $documentKeywords = [
            'resume', 'cv', 'portfolio', 'document', 'pdf', 'download',
            'file', 'attachment', 'doc', 'certificate', 'report'
        ];

        $lowerText = strtolower($text);
        foreach ($documentKeywords as $keyword) {
            if (strpos($lowerText, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guess document type from URL and text
     */
    protected function guessDocumentType(string $url, string $text): string
    {
        // Check URL for explicit extension
        if (preg_match('/\.([a-z]{2,4})(\?|$)/i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        // Guess from link text
        $lowerText = strtolower($text);

        if (strpos($lowerText, 'pdf') !== false) {
            return 'pdf';
        }

        if (strpos($lowerText, 'resume') !== false || strpos($lowerText, 'cv') !== false) {
            return 'pdf'; // Most resumes are PDFs
        }

        if (strpos($lowerText, 'doc') !== false) {
            return 'docx';
        }

        // Default to PDF
        return 'pdf';
    }
}
