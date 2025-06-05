<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Exception;

class PuppeteerScrapperService
{
    protected string $puppeteerUrl;
    protected int $timeout;
    protected array $userAgents;

    public function __construct()
    {
        $this->puppeteerUrl = env('PUPPETEER_SERVICE_URL', 'http://puppeteer:3000');
        $this->timeout = 120;
        $this->userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ];
    }

    /**
     * Scrape a URL using Puppeteer service
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeUrl(string $url, array $options = []): array
    {
        if (!$this->isHealthy()) {
            throw new Exception('Puppeteer service is not healthy');
        }

        try {
            $html = $this->fetchPuppeteerHtml($url, $options);
            $crawler = new Crawler($html);

            return [
                'url' => $url,
                'title' => $this->extractTitle($crawler),
                'meta' => $this->extractMeta($crawler),
                'links' => $this->extractLinks($crawler, $url),
                'images' => $this->extractImages($crawler, $url),
                'videos' => $this->extractVideos($crawler, $url),
                'text' => $this->extractText($crawler),
                'headings' => $this->extractHeadings($crawler),
                'scraped_at' => now()->toISOString(),
                'method' => 'puppeteer_service',
            ];

        } catch (Exception $e) {
            Log::error('Puppeteer Scrapper Service Error', [
                'url' => $url,
                'message' => $e->getMessage(),
                'service_healthy' => $this->isHealthy()
            ]);
            throw $e;
        }
    }

    /**
     * Scrape Single Page Application
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeSPA(string $url, array $options = []): array
    {
        $defaultOptions = [
            'waitTime' => 3000,
            'waitUntil' => 'networkidle0',
            'executeScript' => 'window.scrollTo(0, document.body.scrollHeight);'
        ];

        $mergedOptions = array_merge($defaultOptions, $options);
        return $this->scrapeUrl($url, $mergedOptions);
    }

    /**
     * Scrape with infinite scroll
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeWithInfiniteScroll(string $url, array $options = []): array
    {
        $options['infiniteScroll'] = true;
        $options['waitTime'] = $options['waitTime'] ?? 5000;

        return $this->scrapeUrl($url, $options);
    }

    /**
     * Scrape and take screenshot
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeWithScreenshot(string $url, array $options = []): array
    {
        $options['screenshot'] = true;
        $options['fullPageScreenshot'] = $options['fullPageScreenshot'] ?? false;

        $result = $this->scrapeUrl($url, $options);

        // Save screenshot if base64 data is provided
        if (isset($result['screenshot']) && $options['save_screenshot'] ?? false) {
            $screenshotPath = storage_path('app/screenshots/' . md5($url) . '_' . time() . '.png');

            if (!is_dir(dirname($screenshotPath))) {
                mkdir(dirname($screenshotPath), 0755, true);
            }

            file_put_contents($screenshotPath, base64_decode($result['screenshot']));
            $result['screenshot_path'] = $screenshotPath;
        }

        return $result;
    }

    /**
     * Generate PDF from URL
     *
     * @param string $url
     * @param array $options
     * @return string Binary PDF content
     * @throws Exception
     */
    public function generatePDF(string $url, array $options = []): string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post($this->puppeteerUrl . '/pdf', [
                    'url' => $url,
                    'options' => $options
                ]);

            if (!$response->successful()) {
                throw new Exception("Puppeteer PDF service error: {$response->status()} - {$response->body()}");
            }

            return $response->body();

        } catch (Exception $e) {
            Log::error('Puppeteer PDF Service Error', [
                'url' => $url,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Take screenshot only
     *
     * @param string $url
     * @param array $options
     * @return string Binary image content
     * @throws Exception
     */
    public function takeScreenshot(string $url, array $options = []): string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post($this->puppeteerUrl . '/screenshot', [
                    'url' => $url,
                    'options' => $options
                ]);

            if (!$response->successful()) {
                throw new Exception("Puppeteer screenshot service error: {$response->status()} - {$response->body()}");
            }

            return $response->body();

        } catch (Exception $e) {
            Log::error('Puppeteer Screenshot Service Error', [
                'url' => $url,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if Puppeteer service is healthy
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->puppeteerUrl . '/health');
            return $response->successful() && $response->json('status') === 'healthy';
        } catch (Exception $e) {
            Log::warning('Puppeteer health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Fetch HTML using Puppeteer service
     *
     * @param string $url
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function fetchPuppeteerHtml(string $url, array $options = []): string
    {
        try {
            $userAgent = $this->userAgents[array_rand($this->userAgents)];

            $payload = [
                'url' => $url,
                'options' => array_merge([
                    'userAgent' => $userAgent,
                    'timeout' => ($options['timeout'] ?? $this->timeout) * 1000, // Convert to milliseconds
                    'waitUntil' => $options['waitUntil'] ?? 'networkidle2',
                    'blockResources' => $options['blockResources'] ?? false, // Add option to block images/css for faster loading
                ], $options)
            ];

            // Use connectTimeout and timeout separately for better control
            $response = Http::connectTimeout(30) // 30 seconds to connect
                ->timeout($this->timeout + 30) // Total timeout with buffer
                ->retry(2, 5000) // Retry 2 times with 5 second delay
                ->post($this->puppeteerUrl . '/scrape', $payload);

            if (!$response->successful()) {
                throw new Exception("Puppeteer service error: {$response->status()} - {$response->body()}");
            }

            $data = $response->json();

            if (!$data['success']) {
                throw new Exception("Puppeteer scraping failed: " . ($data['error'] ?? 'Unknown error'));
            }

            return $data['data']['html'];

        } catch (Exception $e) {
            // Log more detailed error information
            Log::error('Failed to fetch HTML via Puppeteer', [
                'url' => $url,
                'error' => $e->getMessage(),
                'puppeteer_url' => $this->puppeteerUrl,
                'options' => $options
            ]);
            throw new Exception("Failed to fetch HTML via Puppeteer: " . $e->getMessage());
        }
    }

    /**
     * Extract page title
     *
     * @param Crawler $crawler
     * @return string|null
     */
    protected function extractTitle(Crawler $crawler): ?string
    {
        try {
            return $crawler->filter('title')->text();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Extract meta tags
     *
     * @param Crawler $crawler
     * @return array
     */
    protected function extractMeta(Crawler $crawler): array
    {
        $meta = [];

        try {
            $crawler->filter('meta')->each(function (Crawler $node) use (&$meta) {
                $name = $node->attr('name') ?: $node->attr('property') ?: $node->attr('http-equiv');
                $content = $node->attr('content');

                if ($name && $content) {
                    $meta[$name] = $content;
                }
            });
        } catch (Exception $e) {
            Log::warning('Error extracting meta tags', ['error' => $e->getMessage()]);
        }

        return $meta;
    }

    /**
     * Extract all links from the page
     *
     * @param Crawler $crawler
     * @param string $baseUrl
     * @return array
     */
    protected function extractLinks(Crawler $crawler, string $baseUrl): array
    {
        $links = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $href = $node->attr('href');
                $text = trim($node->text());

                if ($href) {
                    $absoluteUrl = $this->makeAbsoluteUrl($href, $baseUrl);

                    $links[] = [
                        'url' => $absoluteUrl,
                        'text' => $text,
                        'title' => $node->attr('title'),
                        'target' => $node->attr('target'),
                    ];
                }
            });
        } catch (Exception $e) {
            Log::warning('Error extracting links', ['error' => $e->getMessage()]);
        }

        return array_unique($links, SORT_REGULAR);
    }

    /**
     * Extract all images from the page
     *
     * @param Crawler $crawler
     * @param string $baseUrl
     * @return array
     */
    protected function extractImages(Crawler $crawler, string $baseUrl): array
    {
        $images = [];

        try {
            $crawler->filter('img[src]')->each(function (Crawler $node) use (&$images, $baseUrl) {
                $src = $node->attr('src');

                if ($src) {
                    $absoluteUrl = $this->makeAbsoluteUrl($src, $baseUrl);

                    $images[] = [
                        'url' => $absoluteUrl,
                        'alt' => $node->attr('alt'),
                        'title' => $node->attr('title'),
                        'width' => $node->attr('width'),
                        'height' => $node->attr('height'),
                    ];
                }
            });
        } catch (Exception $e) {
            Log::warning('Error extracting images', ['error' => $e->getMessage()]);
        }

        return array_unique($images, SORT_REGULAR);
    }

    /**
     * Extract all videos from the page
     *
     * @param Crawler $crawler
     * @param string $baseUrl
     * @return array
     */
    protected function extractVideos(Crawler $crawler, string $baseUrl): array
    {
        $videos = [];

        try {
            // Extract video tags
            $crawler->filter('video')->each(function (Crawler $node) use (&$videos, $baseUrl) {
                $src = $node->attr('src');

                if ($src) {
                    $absoluteUrl = $this->makeAbsoluteUrl($src, $baseUrl);

                    $videos[] = [
                        'type' => 'video',
                        'url' => $absoluteUrl,
                        'poster' => $node->attr('poster') ? $this->makeAbsoluteUrl($node->attr('poster'), $baseUrl) : null,
                        'controls' => $node->attr('controls') !== null,
                        'autoplay' => $node->attr('autoplay') !== null,
                    ];
                }

                // Extract source tags within video
                $node->filter('source[src]')->each(function (Crawler $source) use (&$videos, $baseUrl) {
                    $src = $source->attr('src');

                    if ($src) {
                        $absoluteUrl = $this->makeAbsoluteUrl($src, $baseUrl);

                        $videos[] = [
                            'type' => 'video_source',
                            'url' => $absoluteUrl,
                            'mime_type' => $source->attr('type'),
                        ];
                    }
                });
            });

            // Extract iframe videos (YouTube, Vimeo, etc.)
            $crawler->filter('iframe[src]')->each(function (Crawler $node) use (&$videos) {
                $src = $node->attr('src');

                if ($src && $this->isVideoIframe($src)) {
                    $videos[] = [
                        'type' => 'iframe_video',
                        'url' => $src,
                        'width' => $node->attr('width'),
                        'height' => $node->attr('height'),
                        'title' => $node->attr('title'),
                    ];
                }
            });

        } catch (Exception $e) {
            Log::warning('Error extracting videos', ['error' => $e->getMessage()]);
        }

        return array_unique($videos, SORT_REGULAR);
    }

    /**
     * Extract text content from the page
     *
     * @param Crawler $crawler
     * @return array
     */
    protected function extractText(Crawler $crawler): array
    {
        $text = [];

        try {
            // Extract paragraphs
            $paragraphs = [];
            $crawler->filter('p')->each(function (Crawler $node) use (&$paragraphs) {
                $content = trim($node->text());
                if (!empty($content)) {
                    $paragraphs[] = $content;
                }
            });
            $text['paragraphs'] = $paragraphs;

            // Extract lists
            $lists = [];
            $crawler->filter('ul, ol')->each(function (Crawler $node) use (&$lists) {
                $items = [];
                $node->filter('li')->each(function (Crawler $li) use (&$items) {
                    $content = trim($li->text());
                    if (!empty($content)) {
                        $items[] = $content;
                    }
                });
                if (!empty($items)) {
                    $lists[] = [
                        'type' => $node->nodeName(),
                        'items' => $items
                    ];
                }
            });
            $text['lists'] = $lists;

            // Extract all text content (cleaned)
            $allText = $crawler->filter('body')->text();
            $text['full_text'] = $this->cleanText($allText);

        } catch (Exception $e) {
            Log::warning('Error extracting text', ['error' => $e->getMessage()]);
        }

        return $text;
    }

    /**
     * Extract headings from the page
     *
     * @param Crawler $crawler
     * @return array
     */
    protected function extractHeadings(Crawler $crawler): array
    {
        $headings = [];

        try {
            $crawler->filter('h1, h2, h3, h4, h5, h6')->each(function (Crawler $node) use (&$headings) {
                $content = trim($node->text());
                if (!empty($content)) {
                    $headings[] = [
                        'level' => $node->nodeName(),
                        'text' => $content,
                    ];
                }
            });
        } catch (Exception $e) {
            Log::warning('Error extracting headings', ['error' => $e->getMessage()]);
        }

        return $headings;
    }

    /**
     * Make relative URLs absolute
     *
     * @param string $url
     * @param string $baseUrl
     * @return string
     */
    protected function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if ($url[0] === '/') {
            return $base['scheme'] . '://' . $base['host'] . $url;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Check if iframe is a video embed
     *
     * @param string $src
     * @return bool
     */
    protected function isVideoIframe(string $src): bool
    {
        $videoSites = [
            'youtube.com',
            'youtu.be',
            'vimeo.com',
            'dailymotion.com',
            'twitch.tv',
            'facebook.com/plugins/video',
        ];

        foreach ($videoSites as $site) {
            if (strpos($src, $site) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean extracted text
     *
     * @param string $text
     * @return string
     */
    protected function cleanText(string $text): string
    {
        // Remove extra whitespace and normalize line breaks
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }
}
