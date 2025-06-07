<?php

namespace App\Services;

use App\Models\TalentDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process;
use Exception;

class DocumentContentExtractorService
{
    /**
     * Extract text content from document
     */
    public function extractContent(TalentDocument $document): bool
    {
        try {
            Log::info("Starting content extraction", [
                'document_id' => $document->id,
                'document_type' => $document->document_type
            ]);

            $document->update(['extraction_status' => 'extracting']);

            if (!$document->file_path || !Storage::exists($document->file_path)) {
                throw new Exception('Document file not found');
            }

            $filePath = Storage::path($document->file_path);
            $extractedText = $this->extractByFileType($filePath, $document->document_type);

            if (empty($extractedText)) {
                throw new Exception('No text content could be extracted');
            }

            // Clean and format the extracted text
            $cleanedText = $this->cleanExtractedText($extractedText);

            $document->update([
                'extraction_status' => 'completed',
                'extracted_content' => $cleanedText,
                'metadata' => array_merge($document->metadata ?? [], [
                    'extracted_at' => now()->toDateTimeString(),
                    'content_length' => strlen($cleanedText),
                    'extraction_method' => $this->getExtractionMethod($document->document_type),
                ])
            ]);

            Log::info("Content extraction completed", [
                'document_id' => $document->id,
                'content_length' => strlen($cleanedText)
            ]);

            return true;

        } catch (Exception $e) {
            Log::error("Content extraction failed", [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            $document->update([
                'extraction_status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Extract content based on file type
     */
    protected function extractByFileType(string $filePath, string $documentType): string
    {
        switch (strtolower($documentType)) {
            case 'pdf':
                return $this->extractFromPDF($filePath);
            case 'doc':
            case 'docx':
                return $this->extractFromWord($filePath);
            case 'txt':
                return $this->extractFromText($filePath);
            case 'rtf':
                return $this->extractFromRTF($filePath);
            default:
                // Try PDF extraction as fallback
                return $this->extractFromPDF($filePath);
        }
    }

    /**
     * Extract text from PDF using multiple methods
     */
    protected function extractFromPDF(string $filePath): string
    {
        // Method 1: Try pdftotext (poppler-utils)
        try {
            $result = Process::run("pdftotext '{$filePath}' -");
            if ($result->successful() && !empty($result->output())) {
                return $result->output();
            }
        } catch (Exception $e) {
            Log::debug("pdftotext failed, trying alternative", ['error' => $e->getMessage()]);
        }

        // Method 2: Try pdfplumber via Python
        try {
            $pythonScript = $this->createPythonPDFExtractor();
            $result = Process::run("python3 '{$pythonScript}' '{$filePath}'");
            if ($result->successful() && !empty($result->output())) {
                return $result->output();
            }
        } catch (Exception $e) {
            Log::debug("Python PDF extraction failed", ['error' => $e->getMessage()]);
        }

        // Method 3: Basic PHP PDF parsing (limited)
        return $this->extractFromPDFBasic($filePath);
    }

    /**
     * Basic PDF text extraction using PHP
     */
    protected function extractFromPDFBasic(string $filePath): string
    {
        try {
            $content = file_get_contents($filePath);

            // Very basic PDF text extraction
            // This won't work for all PDFs but can handle simple ones
            if (preg_match_all('/\((.*?)\)/', $content, $matches)) {
                return implode(' ', $matches[1]);
            }

            // Try to extract text between stream objects
            if (preg_match_all('/stream\s*(.*?)\s*endstream/s', $content, $matches)) {
                $text = '';
                foreach ($matches[1] as $stream) {
                    // Decode if it's FlateDecode
                    if (function_exists('gzuncompress')) {
                        $decoded = @gzuncompress($stream);
                        if ($decoded) {
                            $text .= $decoded . ' ';
                        }
                    }
                }

                if (!empty($text)) {
                    // Clean up extracted text
                    $text = preg_replace('/[^\x20-\x7E\s]/', '', $text);
                    return $text;
                }
            }

            throw new Exception('Basic PDF extraction failed');

        } catch (Exception $e) {
            throw new Exception("PDF extraction failed: " . $e->getMessage());
        }
    }

    /**
     * Extract text from Word documents
     */
    protected function extractFromWord(string $filePath): string
    {
        // Method 1: Try antiword for .doc files
        try {
            $result = Process::run("antiword '{$filePath}'");
            if ($result->successful() && !empty($result->output())) {
                return $result->output();
            }
        } catch (Exception $e) {
            Log::debug("antiword failed, trying alternative", ['error' => $e->getMessage()]);
        }

        // Method 2: Try docx2txt for .docx files
        try {
            $result = Process::run("docx2txt '{$filePath}' -");
            if ($result->successful() && !empty($result->output())) {
                return $result->output();
            }
        } catch (Exception $e) {
            Log::debug("docx2txt failed, trying alternative", ['error' => $e->getMessage()]);
        }

        // Method 3: Try Python python-docx
        try {
            $pythonScript = $this->createPythonWordExtractor();
            $result = Process::run("python3 '{$pythonScript}' '{$filePath}'");
            if ($result->successful() && !empty($result->output())) {
                return $result->output();
            }
        } catch (Exception $e) {
            Log::debug("Python Word extraction failed", ['error' => $e->getMessage()]);
        }

        throw new Exception('All Word extraction methods failed');
    }

    /**
     * Extract text from plain text files
     */
    protected function extractFromText(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception('Failed to read text file');
        }
        return $content;
    }

    /**
     * Extract text from RTF files
     */
    protected function extractFromRTF(string $filePath): string
    {
        try {
            $result = Process::run("unrtf --text '{$filePath}'");
            if ($result->successful() && !empty($result->output())) {
                return $result->output();
            }
        } catch (Exception $e) {
            Log::debug("unrtf failed", ['error' => $e->getMessage()]);
        }

        // Fallback: Basic RTF parsing
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception('Failed to read RTF file');
        }

        // Remove RTF formatting codes
        $text = preg_replace('/\{[^}]*\}/', '', $content);
        $text = preg_replace('/\\\\[a-z]+\d*\s?/', '', $text);

        return $text;
    }

    /**
     * Create temporary Python script for PDF extraction
     */
    protected function createPythonPDFExtractor(): string
    {
        $script = <<<'PYTHON'
#!/usr/bin/env python3
import sys
try:
    import pdfplumber
    with pdfplumber.open(sys.argv[1]) as pdf:
        text = ""
        for page in pdf.pages:
            text += page.extract_text() or ""
        print(text)
except ImportError:
    try:
        import PyPDF2
        with open(sys.argv[1], 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            text = ""
            for page in reader.pages:
                text += page.extract_text()
            print(text)
    except ImportError:
        print("No PDF libraries available")
        sys.exit(1)
except Exception as e:
    print(f"Error: {e}")
    sys.exit(1)
PYTHON;

        $scriptPath = storage_path('app/temp/pdf_extractor.py');
        if (!is_dir(dirname($scriptPath))) {
            mkdir(dirname($scriptPath), 0755, true);
        }
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        return $scriptPath;
    }

    /**
     * Create temporary Python script for Word document extraction
     */
    protected function createPythonWordExtractor(): string
    {
        $script = <<<'PYTHON'
#!/usr/bin/env python3
import sys
try:
    from docx import Document
    doc = Document(sys.argv[1])
    text = ""
    for paragraph in doc.paragraphs:
        text += paragraph.text + "\n"
    print(text)
except ImportError:
    print("python-docx not available")
    sys.exit(1)
except Exception as e:
    print(f"Error: {e}")
    sys.exit(1)
PYTHON;

        $scriptPath = storage_path('app/temp/word_extractor.py');
        if (!is_dir(dirname($scriptPath))) {
            mkdir(dirname($scriptPath), 0755, true);
        }
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        return $scriptPath;
    }

    /**
     * Clean extracted text
     */
    protected function cleanExtractedText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Remove non-printable characters except common ones
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $text);

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
                return 'pdftotext/python/basic';
            case 'doc':
                return 'antiword/python';
            case 'docx':
                return 'docx2txt/python';
            case 'txt':
                return 'file_get_contents';
            case 'rtf':
                return 'unrtf/basic';
            default:
                return 'unknown';
        }
    }
}
