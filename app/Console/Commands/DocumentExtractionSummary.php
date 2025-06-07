<?php

namespace App\Console\Commands;

use App\Models\TalentDocument;
use App\Models\TalentScrapingResult;
use App\Services\DocumentDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DocumentExtractionSummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summary:document-extraction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show comprehensive summary of document extraction system and capabilities';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->showHeader();
        $this->showCapabilities();
        $this->showSupportedSources();
        $this->showSupportedFormats();
        $this->showCurrentResults();
        $this->showUsageExamples();

        return 0;
    }

    protected function showHeader()
    {
        $this->info("🚀 DOCUMENT EXTRACTION SYSTEM SUMMARY");
        $this->info("=====================================");
        $this->newLine();
    }

    protected function showCapabilities()
    {
        $this->info("📋 SYSTEM CAPABILITIES:");
        $this->line("  ✅ Automatic detection of downloadable links from scraped websites");
        $this->line("  ✅ Support for cloud storage services (Google Drive, Dropbox, OneDrive)");
        $this->line("  ✅ Direct file downloads with proper headers and error handling");
        $this->line("  ✅ Multiple content extraction methods (pdftotext, Python libraries, basic parsing)");
        $this->line("  ✅ Automatic file type detection using magic numbers");
        $this->line("  ✅ Content cleaning and formatting");
        $this->line("  ✅ Integration with vector embedding system");
        $this->line("  ✅ Comprehensive error handling and retry logic");
        $this->line("  ✅ File size limits and security checks");
        $this->newLine();
    }

    protected function showSupportedSources()
    {
        $this->info("🔗 SUPPORTED DOWNLOAD SOURCES:");
        $this->line("  • Google Drive (drive.google.com) - Automatic direct download conversion");
        $this->line("  • Dropbox (dropbox.com) - Direct download link conversion");
        $this->line("  • OneDrive (onedrive.live.com, 1drv.ms) - Direct download parameter addition");
        $this->line("  • SharePoint (sharepoint.com) - Direct file access");
        $this->line("  • Direct file URLs with document extensions");
        $this->line("  • Any URL with document-related keywords in link text");
        $this->newLine();
    }

    protected function showSupportedFormats()
    {
        $this->info("📄 SUPPORTED DOCUMENT FORMATS:");

        $formats = [
            'PDF' => [
                'extensions' => ['pdf'],
                'methods' => ['pdftotext (poppler-utils)', 'pdfplumber (Python)', 'PyPDF2 (Python)', 'Basic PHP parsing'],
                'status' => '✅ Fully Supported'
            ],
            'Microsoft Word' => [
                'extensions' => ['doc', 'docx'],
                'methods' => ['antiword', 'docx2txt', 'python-docx (Python)'],
                'status' => '✅ Fully Supported'
            ],
            'Plain Text' => [
                'extensions' => ['txt'],
                'methods' => ['file_get_contents'],
                'status' => '✅ Fully Supported'
            ],
            'Rich Text Format' => [
                'extensions' => ['rtf'],
                'methods' => ['unrtf', 'Basic RTF parsing'],
                'status' => '✅ Supported'
            ],
            'Other Formats' => [
                'extensions' => ['odt', 'xls', 'xlsx', 'ppt', 'pptx'],
                'methods' => ['Detection only - can be extended'],
                'status' => '⚠️ Detection Only'
            ]
        ];

        foreach ($formats as $format => $info) {
            $this->line("  {$info['status']} {$format}");
            $this->line("    Extensions: " . implode(', ', $info['extensions']));
            $this->line("    Methods: " . implode(', ', $info['methods']));
        }
        $this->newLine();
    }

    protected function showCurrentResults()
    {
        $this->info("📊 CURRENT SYSTEM STATUS:");

        $totalDocuments = TalentDocument::count();
        $downloaded = TalentDocument::where('download_status', 'completed')->count();
        $extracted = TalentDocument::where('extraction_status', 'completed')->count();
        $failedDownloads = TalentDocument::where('download_status', 'failed')->count();
        $failedExtractions = TalentDocument::where('extraction_status', 'failed')->count();

        $this->line("  Total Documents Processed: {$totalDocuments}");
        $this->line("  Successfully Downloaded: {$downloaded}");
        $this->line("  Successfully Extracted: {$extracted}");
        $this->line("  Failed Downloads: {$failedDownloads}");
        $this->line("  Failed Extractions: {$failedExtractions}");

        if ($totalDocuments > 0) {
            $downloadRate = round(($downloaded / $totalDocuments) * 100, 1);
            $extractionRate = round(($extracted / $totalDocuments) * 100, 1);
            $this->line("  Download Success Rate: {$downloadRate}%");
            $this->line("  Extraction Success Rate: {$extractionRate}%");
        }

        // Show recent documents
        $recentDocuments = TalentDocument::with('talent')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        if ($recentDocuments->isNotEmpty()) {
            $this->newLine();
            $this->line("  📄 Recent Documents:");
            foreach ($recentDocuments as $doc) {
                $status = $doc->extraction_status === 'completed' ? '✅' :
                         ($doc->extraction_status === 'failed' ? '❌' : '⏳');
                $this->line("    {$status} {$doc->source_link_text} ({$doc->document_type}) - {$doc->talent->name}");
            }
        }
        $this->newLine();
    }

    protected function showUsageExamples()
    {
        $this->info("🛠️ USAGE EXAMPLES:");
        $this->newLine();

        $this->line("1. Test document extraction from scraped data:");
        $this->line("   php artisan test:document-extraction [file-path]");
        $this->newLine();

        $this->line("2. View all document results:");
        $this->line("   php artisan show:document-results");
        $this->newLine();

        $this->line("3. View specific scraping result:");
        $this->line("   php artisan show:document-results --scraping-result-id=1");
        $this->newLine();

        $this->line("4. Process documents manually:");
        $this->line("   // In your code:");
        $this->line("   use App\\Jobs\\ProcessDocumentsJob;");
        $this->line("   ProcessDocumentsJob::dispatch(\$talent, \$scrapingResult, \$downloadableLinks);");
        $this->newLine();

        $this->line("5. Extract content from existing document:");
        $this->line("   // In your code:");
        $this->line("   use App\\Services\\DocumentContentExtractorService;");
        $this->line("   \$extractor = new DocumentContentExtractorService();");
        $this->line("   \$extractor->extractContent(\$document);");
        $this->newLine();

        $this->info("🔄 AUTOMATIC INTEGRATION:");
        $this->line("  • Documents are automatically detected during portfolio scraping");
        $this->line("  • ProcessDocumentsJob is dispatched when downloadable links are found");
        $this->line("  • Extracted content is included in vector embeddings");
        $this->line("  • All processing is logged and tracked in the database");
        $this->newLine();

        $this->info("📁 FILE STORAGE:");
        $this->line("  • Downloaded files: storage/app/documents/talent_documents/");
        $this->line("  • Temporary Python scripts: storage/app/temp/");
        $this->line("  • File naming: {talent_id}_{sanitized_name}_{timestamp}.{ext}");
        $this->newLine();

        $this->info("🔧 SYSTEM REQUIREMENTS:");
        $this->line("  • Optional: pdftotext (poppler-utils) for better PDF extraction");
        $this->line("  • Optional: antiword for .doc files");
        $this->line("  • Optional: docx2txt for .docx files");
        $this->line("  • Optional: unrtf for .rtf files");
        $this->line("  • Optional: Python with pdfplumber, PyPDF2, python-docx libraries");
        $this->line("  • Fallback: Basic PHP parsing (always available)");
    }
}
