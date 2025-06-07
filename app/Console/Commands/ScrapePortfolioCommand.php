<?php

namespace App\Console\Commands;

use App\Services\PuppeteerScrapperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use Exception;

class ScrapePortfolioCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:portfolio
                            {url? : The URL to scrape (default: https://sonuchoudhary.my.canva.site/portfolio)}
                            {--output= : Custom output directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape portfolio website, download documents, and extract text content';

    protected PuppeteerScrapperService $scraper;
    protected int $timeout = 120;
    protected int $maxFileSize = 50 * 1024 * 1024; // 50MB max

    public function __construct(PuppeteerScrapperService $scraper)
    {
        parent::__construct();
        $this->scraper = $scraper;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->argument('url') ?? 'https://sonuchoudhary.my.canva.site/portfolio';
        $customOutput = $this->option('output');

        $this->info("🚀 Starting comprehensive portfolio scraping for: {$url}");

        // Check if Puppeteer service is healthy
        if (!$this->scraper->isHealthy()) {
            $this->error('❌ Puppeteer service is not healthy. Please check the service.');
            return 1;
        }

        try {
            // Step 1: Scrape the website using SPA mode
            $this->info('📄 Step 1: Scraping website using SPA mode...');
            $scrapedData = $this->scraper->scrapeSPA($url);

            // Generate timestamp for this scraping session
            $timestamp = now()->format('Y-m-d_H-i-s');
            $domain = parse_url($url, PHP_URL_HOST);

            // Determine output directory
            $outputDir = $customOutput ?? "scraped-data/scraped_{$domain}_{$timestamp}";
            Storage::makeDirectory($outputDir);

            // Save scraped JSON data
            $jsonFile = "{$outputDir}/scraped_data.json";
            Storage::put($jsonFile, json_encode($scrapedData, JSON_PRETTY_PRINT));
            $this->info("✅ Scraped data saved to: {$jsonFile}");

            // Step 2: Extract downloadable links
            $this->info('🔗 Step 2: Extracting downloadable links...');
            $downloadableLinks = $this->extractDownloadableLinks($scrapedData);

            if (empty($downloadableLinks)) {
                $this->warn('⚠️  No downloadable documents found');
                $this->displaySummary($scrapedData, [], $outputDir);
                return 0;
            }

            $this->info("📄 Found {" . count($downloadableLinks) . "} downloadable documents:");
            foreach ($downloadableLinks as $index => $link) {
                $this->line("  " . ($index + 1) . ". [{$link['document_type']}] {$link['text']}");
            }

            // Step 3: Download and extract text from documents
            $this->info('⬇️  Step 3: Downloading and extracting text from documents...');
            $processedDocuments = $this->processDocuments($downloadableLinks, $outputDir);

            // Step 4: Display final summary
            $this->displaySummary($scrapedData, $processedDocuments, $outputDir);

            return 0;

        } catch (Exception $e) {
            $this->error("❌ Scraping failed: " . $e->getMessage());
            Log::error('Portfolio scraping failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Extract downloadable links from scraped data
     */
    protected function extractDownloadableLinks(array $scrapedData): array
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
        $documentExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf'];

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

    /**
     * Process all downloadable documents
     */
    protected function processDocuments(array $downloadableLinks, string $outputDir): array
    {
        $processedDocuments = [];
        $documentsDir = "{$outputDir}/documents";
        Storage::makeDirectory($documentsDir);

        foreach ($downloadableLinks as $index => $linkData) {
            $this->info("  📄 Processing document " . ($index + 1) . ": {$linkData['text']}");

            try {
                // Download the document
                $downloadResult = $this->downloadDocument($linkData, $documentsDir);

                if ($downloadResult['success']) {
                    // Extract text content
                    $textResult = $this->extractTextFromDocument($downloadResult, $documentsDir);

                    $processedDocuments[] = array_merge($downloadResult, $textResult);

                    if ($textResult['success']) {
                        $this->info("    ✅ Downloaded and extracted: {$downloadResult['filename']}");
                    } else {
                        $this->warn("    ⚠️  Downloaded but extraction failed: {$downloadResult['filename']}");
                    }
                } else {
                    $this->error("    ❌ Download failed: {$downloadResult['error']}");
                    $processedDocuments[] = $downloadResult;
                }

            } catch (Exception $e) {
                $this->error("    ❌ Processing failed: " . $e->getMessage());
                $processedDocuments[] = [
                    'success' => false,
                    'url' => $linkData['url'],
                    'text' => $linkData['text'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return $processedDocuments;
    }

    /**
     * Download a document from URL
     */
    protected function downloadDocument(array $linkData, string $documentsDir): array
    {
        try {
            $url = $this->processUrl($linkData['url']);

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST),
            ])
            ->timeout($this->timeout)
            ->get($url);

            if (!$response->successful()) {
                throw new Exception("HTTP {$response->status()}: Failed to download");
            }

            $content = $response->body();

            if (strlen($content) > $this->maxFileSize) {
                throw new Exception("File too large: " . strlen($content) . " bytes");
            }

            if (strlen($content) < 100) {
                throw new Exception("File too small, possibly an error page");
            }

            // Generate filename
            $extension = $this->detectFileExtension($content, $linkData['url']);
            $sanitizedText = $this->sanitizeFilename($linkData['text']);
            $filename = $sanitizedText . '_' . time() . '.' . $extension;

            // Save file
            $filePath = "{$documentsDir}/{$filename}";
            Storage::put($filePath, $content);

            return [
                'success' => true,
                'url' => $linkData['url'],
                'text' => $linkData['text'],
                'filename' => $filename,
                'file_path' => $filePath,
                'file_size' => strlen($content),
                'document_type' => $extension,
                'content' => $content, // Keep content for text extraction
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'url' => $linkData['url'],
                'text' => $linkData['text'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract text from downloaded document
     */
    protected function extractTextFromDocument(array $downloadResult, string $documentsDir): array
    {
        try {
            $content = $downloadResult['content'];
            $documentType = $downloadResult['document_type'];
            $filename = $downloadResult['filename'];

            $extractedText = '';

            switch (strtolower($documentType)) {
                case 'pdf':
                    $extractedText = $this->extractTextFromPDF($content);
                    break;

                case 'doc':
                case 'docx':
                    $extractedText = $this->extractTextFromWord($content);
                    break;

                case 'txt':
                    $extractedText = $content;
                    break;

                default:
                    throw new Exception("Unsupported document type: {$documentType}");
            }

            if (empty(trim($extractedText))) {
                throw new Exception("No text content could be extracted");
            }

            // Clean the extracted text
            $cleanedText = $this->cleanExtractedText($extractedText);

            // Save extracted text to .txt file
            $textFilename = pathinfo($filename, PATHINFO_FILENAME) . '_extracted.txt';
            $textFilePath = "{$documentsDir}/{$textFilename}";
            Storage::put($textFilePath, $cleanedText);

            return [
                'success' => true,
                'extracted_text' => $cleanedText,
                'text_file' => $textFilename,
                'text_file_path' => $textFilePath,
                'content_length' => strlen($cleanedText),
                'extraction_method' => $this->getExtractionMethod($documentType),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'extraction_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract text from PDF using smalot/pdfparser
     */
    protected function extractTextFromPDF(string $content): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseContent($content);
            $text = $pdf->getText();

            if (empty(trim($text))) {
                throw new Exception("PDF text extraction returned empty result");
            }

            return $text;

        } catch (Exception $e) {
            throw new Exception("PDF text extraction failed: " . $e->getMessage());
        }
    }

    /**
     * Extract text from Word documents (basic implementation)
     */
    protected function extractTextFromWord(string $content): string
    {
        // For .docx files (ZIP-based format)
        if (strpos($content, 'PK') === 0) {
            // Create temporary file for processing
            $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
            file_put_contents($tempFile, $content);

            try {
                $zip = new \ZipArchive();
                if ($zip->open($tempFile) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    if ($xml !== false) {
                        // Extract text from XML
                        $text = strip_tags($xml);
                        $text = html_entity_decode($text);
                        $zip->close();
                        unlink($tempFile);
                        return $text;
                    }
                    $zip->close();
                }
                unlink($tempFile);
                throw new Exception("Could not extract text from DOCX");

            } catch (Exception $e) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                throw $e;
            }
        }

        // For .doc files (binary format) - basic extraction
        // This is a very basic implementation
        $text = preg_replace('/[^\x20-\x7E\s]/', '', $content);
        $text = preg_replace('/\s+/', ' ', $text);

        if (strlen(trim($text)) < 50) {
            throw new Exception("Could not extract meaningful text from DOC file");
        }

        return $text;
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
     * Detect file extension from content or URL
     */
    protected function detectFileExtension(string $content, string $url): string
    {
        // Check magic numbers in file content
        if (strpos($content, '%PDF') === 0) {
            return 'pdf';
        }

        if (strpos($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") === 0) {
            return 'doc';
        }

        if (strpos($content, 'PK') === 0) {
            return 'docx';
        }

        // Fallback to URL extension
        $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
        if (isset($pathInfo['extension'])) {
            return strtolower($pathInfo['extension']);
        }

        return 'pdf'; // Default
    }

    /**
     * Sanitize filename for storage
     */
    protected function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        $filename = trim($filename, '_');
        return substr($filename, 0, 50);
    }

    /**
     * Clean extracted text
     */
    protected function cleanExtractedText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove excessive line breaks
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Get extraction method used
     */
    protected function getExtractionMethod(string $documentType): string
    {
        switch (strtolower($documentType)) {
            case 'pdf':
                return 'smalot/pdfparser';
            case 'docx':
                return 'ZipArchive XML extraction';
            case 'doc':
                return 'Basic binary parsing';
            case 'txt':
                return 'Direct reading';
            default:
                return 'unknown';
        }
    }

    /**
     * Display final summary
     */
    protected function displaySummary(array $scrapedData, array $processedDocuments, string $outputDir): void
    {
        $this->info("\n🎯 SCRAPING SUMMARY");
        $this->info("==================");
        $this->line("URL: " . ($scrapedData['url'] ?? 'N/A'));
        $this->line("Title: " . ($scrapedData['title'] ?? 'N/A'));
        $this->line("Output Directory: {$outputDir}");
        $this->line("Links found: " . count($scrapedData['links'] ?? []));
        $this->line("Images found: " . count($scrapedData['images'] ?? []));
        $this->line("Videos found: " . count($scrapedData['videos'] ?? []));

        if (!empty($processedDocuments)) {
            $successful = array_filter($processedDocuments, fn($doc) => $doc['success'] && isset($doc['extracted_text']));
            $downloaded = array_filter($processedDocuments, fn($doc) => $doc['success']);

            $this->line("Documents found: " . count($processedDocuments));
            $this->line("Documents downloaded: " . count($downloaded));
            $this->line("Text extracted: " . count($successful));

            $this->info("\n📄 PROCESSED DOCUMENTS:");
            foreach ($processedDocuments as $index => $doc) {
                $status = $doc['success'] ? '✅' : '❌';
                $this->line("  {$status} " . ($index + 1) . ". {$doc['text']}");
                if ($doc['success'] && isset($doc['filename'])) {
                    $this->line("      File: {$doc['filename']}");
                    if (isset($doc['text_file'])) {
                        $this->line("      Text: {$doc['text_file']} ({$doc['content_length']} chars)");
                    }
                }
                if (!$doc['success'] && isset($doc['error'])) {
                    $this->line("      Error: {$doc['error']}");
                }
            }
        }

        $this->info("\n📁 OUTPUT FILES:");
        $this->line("  • scraped_data.json - Complete scraped data");
        if (!empty($processedDocuments)) {
            $this->line("  • documents/ - Downloaded files and extracted text");
        }
    }
}
