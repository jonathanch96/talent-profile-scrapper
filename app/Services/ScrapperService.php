<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Spatie\Browsershot\Browsershot;
use Exception;

class ScrapperService
{
    protected int $timeout;
    protected array $userAgents;
    protected array $defaultHeaders;

    public function __construct()
    {
        $this->timeout = 30;
        $this->userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ];

        $this->defaultHeaders = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Encoding' => 'gzip, deflate',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /**
     * Scrape a URL and extract all data (static HTML)
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeUrl(string $url, array $options = []): array
    {
        try {
            $html = $this->fetchHtml($url, $options);
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
                'method' => 'static_html',
            ];

        } catch (Exception $e) {
            Log::error('Scrapper Service Error', [
                'url' => $url,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Scrape JavaScript-rendered content using headless browser
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeJavaScriptUrl(string $url, array $options = []): array
    {
        try {
            $html = $this->fetchJavaScriptHtml($url, $options);
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
                'method' => 'javascript_rendered',
            ];

        } catch (Exception $e) {
            Log::error('JavaScript Scrapper Service Error', [
                'url' => $url,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Smart scrape - try static first, fallback to JavaScript if needed
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function smartScrapeUrl(string $url, array $options = []): array
    {
        try {
            // First try static HTML scraping
            $staticResult = $this->scrapeUrl($url, $options);

            // Check if we got meaningful content
            if ($this->hasEnoughContent($staticResult)) {
                return $staticResult;
            }

            // If not enough content, try JavaScript rendering
            Log::info('Static scraping insufficient, trying JavaScript rendering', ['url' => $url]);
            return $this->scrapeJavaScriptUrl($url, $options);

        } catch (Exception $e) {
            // If static fails, try JavaScript as fallback
            Log::info('Static scraping failed, trying JavaScript rendering', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return $this->scrapeJavaScriptUrl($url, $options);
        }
    }

    /**
     * Fetch HTML content using headless browser for JavaScript-rendered pages
     *
     * @param string $url
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function fetchJavaScriptHtml(string $url, array $options = []): string
    {
        try {
            $browsershot = Browsershot::url($url)
                ->waitUntilNetworkIdle()
                ->timeout($options['timeout'] ?? 60)
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox']);

            // Set viewport if provided
            if (isset($options['viewport'])) {
                $browsershot->windowSize($options['viewport']['width'] ?? 1920, $options['viewport']['height'] ?? 1080);
            }

            // Wait for specific selector if provided
            if (isset($options['wait_for_selector'])) {
                $browsershot->waitForSelector($options['wait_for_selector']);
            }

            // Additional wait time if provided
            if (isset($options['wait_time'])) {
                $browsershot->delay($options['wait_time']);
            }

            // Execute custom JavaScript if provided
            if (isset($options['execute_script'])) {
                $browsershot->evaluate($options['execute_script']);
            }

            return $browsershot->bodyHtml();

        } catch (Exception $e) {
            throw new Exception("Failed to fetch JavaScript-rendered content from {$url}: " . $e->getMessage());
        }
    }

    /**
     * Check if the scraped content has enough meaningful data
     *
     * @param array $result
     * @return bool
     */
    protected function hasEnoughContent(array $result): bool
    {
        // Check if we have meaningful text content
        $textLength = strlen($result['text']['full_text'] ?? '');
        $hasLinks = count($result['links'] ?? []) > 0;
        $hasImages = count($result['images'] ?? []) > 0;
        $hasHeadings = count($result['headings'] ?? []) > 0;

        // Consider content sufficient if:
        // - Text is longer than 500 characters, OR
        // - Has multiple content types (links + images, or headings + text)
        return $textLength > 500 ||
               ($hasLinks && $hasImages) ||
               ($hasHeadings && $textLength > 100);
    }

    /**
     * Extract content from Single Page Application (SPA)
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeSPA(string $url, array $options = []): array
    {
        $defaultOptions = [
            'wait_time' => 3000, // Wait 3 seconds for SPA to load
            'wait_for_selector' => null,
            'viewport' => ['width' => 1920, 'height' => 1080],
            'execute_script' => 'window.scrollTo(0, document.body.scrollHeight);', // Scroll to trigger lazy loading
        ];

        $mergedOptions = array_merge($defaultOptions, $options);

        return $this->scrapeJavaScriptUrl($url, $mergedOptions);
    }

    /**
     * Scrape with infinite scroll support
     *
     * @param string $url
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeWithInfiniteScroll(string $url, array $options = []): array
    {
        $scrollScript = '
            async function autoScroll() {
                await new Promise((resolve) => {
                    let totalHeight = 0;
                    const distance = 100;
                    const timer = setInterval(() => {
                        const scrollHeight = document.body.scrollHeight;
                        window.scrollBy(0, distance);
                        totalHeight += distance;

                        if(totalHeight >= scrollHeight){
                            clearInterval(timer);
                            resolve();
                        }
                    }, 100);
                });
            }
            await autoScroll();
        ';

        $options['execute_script'] = $scrollScript;
        $options['wait_time'] = $options['wait_time'] ?? 5000;

        return $this->scrapeJavaScriptUrl($url, $options);
    }

    /**
     * Take screenshot while scraping
     *
     * @param string $url
     * @param string $screenshotPath
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function scrapeWithScreenshot(string $url, string $screenshotPath, array $options = []): array
    {
        try {
            $browsershot = Browsershot::url($url)
                ->waitUntilNetworkIdle()
                ->timeout($options['timeout'] ?? 60)
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox']);

            if (isset($options['viewport'])) {
                $browsershot->windowSize($options['viewport']['width'] ?? 1920, $options['viewport']['height'] ?? 1080);
            }

            if (isset($options['wait_for_selector'])) {
                $browsershot->waitForSelector($options['wait_for_selector']);
            }

            if (isset($options['wait_time'])) {
                $browsershot->delay($options['wait_time']);
            }

            // Take screenshot
            $browsershot->save($screenshotPath);

            // Get HTML content
            $html = $browsershot->bodyHtml();
            $crawler = new Crawler($html);

            $result = [
                'url' => $url,
                'title' => $this->extractTitle($crawler),
                'meta' => $this->extractMeta($crawler),
                'links' => $this->extractLinks($crawler, $url),
                'images' => $this->extractImages($crawler, $url),
                'videos' => $this->extractVideos($crawler, $url),
                'text' => $this->extractText($crawler),
                'headings' => $this->extractHeadings($crawler),
                'screenshot' => $screenshotPath,
                'scraped_at' => now()->toISOString(),
                'method' => 'javascript_with_screenshot',
            ];

            return $result;

        } catch (Exception $e) {
            Log::error('Screenshot Scrapper Service Error', [
                'url' => $url,
                'screenshot_path' => $screenshotPath,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Fetch HTML content from URL
     *
     * @param string $url
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function fetchHtml(string $url, array $options = []): string
    {
        $userAgent = $this->userAgents[array_rand($this->userAgents)];

        $headers = array_merge($this->defaultHeaders, [
            'User-Agent' => $userAgent
        ], $options['headers'] ?? []);

        $response = Http::withHeaders($headers)
            ->timeout($options['timeout'] ?? $this->timeout)
            ->get($url);

        if (!$response->successful()) {
            throw new Exception("Failed to fetch URL: {$url}. Status: {$response->status()}");
        }

        return $response->body();
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

    /**
     * Extract specific elements by CSS selector
     *
     * @param string $url
     * @param string $selector
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function extractBySelector(string $url, string $selector, array $options = []): array
    {
        try {
            $html = $this->fetchHtml($url, $options);
            $crawler = new Crawler($html);

            $elements = [];
            $crawler->filter($selector)->each(function (Crawler $node) use (&$elements) {
                $elements[] = [
                    'text' => trim($node->text()),
                    'html' => $node->html(),
                    'attributes' => $this->getNodeAttributes($node),
                ];
            });

            return $elements;

        } catch (Exception $e) {
            Log::error('Scrapper Service Error - Extract by Selector', [
                'url' => $url,
                'selector' => $selector,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get all attributes of a node
     *
     * @param Crawler $node
     * @return array
     */
    protected function getNodeAttributes(Crawler $node): array
    {
        $attributes = [];

        if ($node->count() > 0) {
            $domNode = $node->getNode(0);
            if ($domNode && $domNode->attributes) {
                foreach ($domNode->attributes as $attr) {
                    $attributes[$attr->name] = $attr->value;
                }
            }
        }

        return $attributes;
    }
}
