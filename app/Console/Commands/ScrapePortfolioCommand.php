<?php

namespace App\Console\Commands;

use App\Services\PuppeteerScrapperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
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
                            {--format=json : Output format (json, txt, html)}
                            {--screenshot : Include screenshot}
                            {--spa : Use SPA scraping mode}
                            {--output= : Custom output filename}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape a portfolio website using Puppeteer service and save results to file';

    protected PuppeteerScrapperService $scraper;

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
        $format = $this->option('format');
        $includeScreenshot = $this->option('screenshot');
        $useSPA = $this->option('spa');
        $customOutput = $this->option('output');

        $this->info("Starting to scrape: {$url}");

        // Check if Puppeteer service is healthy
        if (!$this->scraper->isHealthy()) {
            $this->error('Puppeteer service is not healthy. Please check the service.');
            return 1;
        }

        $this->info('Puppeteer service is healthy. Proceeding with scraping...');

        try {
            // Choose scraping method based on options
            if ($includeScreenshot) {
                $this->info('Scraping with screenshot...');
                $data = $this->scraper->scrapeWithScreenshot($url, [
                    'save_screenshot' => true,
                    'fullPageScreenshot' => true
                ]);
            } elseif ($useSPA) {
                $this->info('Using SPA scraping mode...');
                $data = $this->scraper->scrapeSPA($url);
            } else {
                $this->info('Using standard scraping mode...');
                $data = $this->scraper->scrapeUrl($url);
            }

            // Generate filename
            $timestamp = now()->format('Y-m-d_H-i-s');
            $domain = parse_url($url, PHP_URL_HOST);
            $filename = $customOutput ?? "scraped_{$domain}_{$timestamp}";

            // Save based on format
            switch ($format) {
                case 'json':
                    $content = json_encode($data, JSON_PRETTY_PRINT);
                    $filepath = "scraped-data/{$filename}.json";
                    break;

                case 'txt':
                    $content = $this->formatAsText($data);
                    $filepath = "scraped-data/{$filename}.txt";
                    break;

                case 'html':
                    $content = $this->formatAsHtml($data);
                    $filepath = "scraped-data/{$filename}.html";
                    break;

                default:
                    $this->error("Unsupported format: {$format}");
                    return 1;
            }

            // Ensure directory exists
            Storage::makeDirectory('scraped-data');

            // Save file
            Storage::put($filepath, $content);

            $this->info("✅ Scraping completed successfully!");
            $this->info("📁 File saved to: " . storage_path("app/{$filepath}"));

            // Display summary
            $this->displaySummary($data);

            return 0;

        } catch (Exception $e) {
            $this->error("❌ Scraping failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Format scraped data as plain text
     */
    private function formatAsText(array $data): string
    {
        $output = [];
        $output[] = "=== SCRAPED DATA ===";
        $output[] = "URL: " . $data['url'];
        $output[] = "Title: " . ($data['title'] ?? 'N/A');
        $output[] = "Scraped at: " . $data['scraped_at'];
        $output[] = "Method: " . $data['method'];
        $output[] = "";

        // Meta information
        if (!empty($data['meta'])) {
            $output[] = "=== META TAGS ===";
            foreach ($data['meta'] as $name => $content) {
                $output[] = "{$name}: {$content}";
            }
            $output[] = "";
        }

        // Headings
        if (!empty($data['headings'])) {
            $output[] = "=== HEADINGS ===";
            foreach ($data['headings'] as $heading) {
                $output[] = "{$heading['level']}: {$heading['text']}";
            }
            $output[] = "";
        }

        // Text content
        if (!empty($data['text']['paragraphs'])) {
            $output[] = "=== PARAGRAPHS ===";
            foreach ($data['text']['paragraphs'] as $paragraph) {
                $output[] = $paragraph;
                $output[] = "";
            }
        }

        // Links
        if (!empty($data['links'])) {
            $output[] = "=== LINKS ===";
            foreach ($data['links'] as $link) {
                $output[] = "{$link['text']} -> {$link['url']}";
            }
            $output[] = "";
        }

        // Images
        if (!empty($data['images'])) {
            $output[] = "=== IMAGES ===";
            foreach ($data['images'] as $image) {
                $output[] = "{$image['alt']} -> {$image['url']}";
            }
        }

        return implode("\n", $output);
    }

    /**
     * Format scraped data as HTML
     */
    private function formatAsHtml(array $data): string
    {
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scraped Data Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 40px; line-height: 1.6; }
        .header { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .section { margin-bottom: 30px; }
        .section h2 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px; }
        .meta-item { background: #ecf0f1; padding: 10px; margin: 5px 0; border-radius: 4px; }
        .link-item, .image-item { background: #f8f9fa; padding: 10px; margin: 5px 0; border-left: 3px solid #3498db; }
        .full-text { background: #f8f9fa; padding: 15px; border-radius: 4px; white-space: pre-wrap; }
    </style>
</head>
<body>';

        $html .= '<div class="header">';
        $html .= '<h1>Scraped Data Report</h1>';
        $html .= '<p><strong>URL:</strong> ' . htmlspecialchars($data['url']) . '</p>';
        $html .= '<p><strong>Title:</strong> ' . htmlspecialchars($data['title'] ?? 'N/A') . '</p>';
        $html .= '<p><strong>Scraped at:</strong> ' . htmlspecialchars($data['scraped_at']) . '</p>';
        $html .= '<p><strong>Method:</strong> ' . htmlspecialchars($data['method']) . '</p>';
        $html .= '</div>';

        // Meta tags
        if (!empty($data['meta'])) {
            $html .= '<div class="section"><h2>Meta Tags</h2>';
            foreach ($data['meta'] as $name => $content) {
                $html .= '<div class="meta-item"><strong>' . htmlspecialchars($name) . ':</strong> ' . htmlspecialchars($content) . '</div>';
            }
            $html .= '</div>';
        }

        // Headings
        if (!empty($data['headings'])) {
            $html .= '<div class="section"><h2>Headings</h2>';
            foreach ($data['headings'] as $heading) {
                $html .= '<' . $heading['level'] . '>' . htmlspecialchars($heading['text']) . '</' . $heading['level'] . '>';
            }
            $html .= '</div>';
        }

        // Full text
        if (!empty($data['text']['full_text'])) {
            $html .= '<div class="section"><h2>Full Text Content</h2>';
            $html .= '<div class="full-text">' . htmlspecialchars($data['text']['full_text']) . '</div>';
            $html .= '</div>';
        }

        // Links
        if (!empty($data['links'])) {
            $html .= '<div class="section"><h2>Links (' . count($data['links']) . ')</h2>';
            foreach (array_slice($data['links'], 0, 20) as $link) { // Show first 20 links
                $html .= '<div class="link-item">';
                $html .= '<strong>' . htmlspecialchars($link['text']) . '</strong><br>';
                $html .= '<a href="' . htmlspecialchars($link['url']) . '" target="_blank">' . htmlspecialchars($link['url']) . '</a>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Images
        if (!empty($data['images'])) {
            $html .= '<div class="section"><h2>Images (' . count($data['images']) . ')</h2>';
            foreach (array_slice($data['images'], 0, 10) as $image) { // Show first 10 images
                $html .= '<div class="image-item">';
                $html .= '<strong>Alt:</strong> ' . htmlspecialchars($image['alt'] ?? 'N/A') . '<br>';
                $html .= '<strong>URL:</strong> <a href="' . htmlspecialchars($image['url']) . '" target="_blank">' . htmlspecialchars($image['url']) . '</a>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Display summary of scraped data
     */
    private function displaySummary(array $data): void
    {
        $this->info("\n📊 SUMMARY:");
        $this->line("Title: " . ($data['title'] ?? 'N/A'));
        $this->line("Links found: " . count($data['links'] ?? []));
        $this->line("Images found: " . count($data['images'] ?? []));
        $this->line("Videos found: " . count($data['videos'] ?? []));
        $this->line("Headings found: " . count($data['headings'] ?? []));
        $this->line("Paragraphs found: " . count($data['text']['paragraphs'] ?? []));

        if (isset($data['screenshot_path'])) {
            $this->line("Screenshot saved: " . $data['screenshot_path']);
        }
    }
}
