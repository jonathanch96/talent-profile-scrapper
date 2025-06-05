const express = require('express');
const puppeteer = require('puppeteer');
const cors = require('cors');
const helmet = require('helmet');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(helmet());
app.use(cors());
app.use(express.json({ limit: '10mb' }));

// Browser instance
let browser = null;

// Initialize browser
async function initBrowser() {
    try {
        browser = await puppeteer.launch({
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-features=VizDisplayCompositor,TranslateUI',
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
                '--disable-hang-monitor',
                '--disable-ipc-flooding-protection',
                '--disable-sync',
                '--metrics-recording-only',
                '--no-default-browser-check',
                '--password-store=basic',
                '--use-mock-keychain',
                '--enable-automation',
                '--mute-audio'
            ],
            headless: 'new',
            timeout: 60000,
            ignoreDefaultArgs: ['--disable-extensions'],
            defaultViewport: {
                width: 1920,
                height: 1080
            }
        });
        console.log('Browser initialized successfully');

        // Test the browser by creating and closing a page
        const testPage = await browser.newPage();
        await testPage.close();
        console.log('Browser test completed successfully');

    } catch (error) {
        console.error('Failed to initialize browser:', error);
        // Retry once
        setTimeout(async () => {
            try {
                await initBrowser();
            } catch (retryError) {
                console.error('Browser retry failed:', retryError);
                process.exit(1);
            }
        }, 5000);
    }
}

// Health check endpoint
app.get('/health', async (req, res) => {
    let browserStatus = 'not initialized';
    let pageCount = 0;

    if (browser) {
        try {
            const pages = await browser.pages();
            pageCount = pages.length;
            browserStatus = 'running';
        } catch (error) {
            browserStatus = 'error';
        }
    }

    res.json({
        status: 'healthy',
        browser: browserStatus,
        pages: pageCount,
        timestamp: new Date().toISOString()
    });
});

// Scrape endpoint
app.post('/scrape', async (req, res) => {
    const { url, options = {} } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'URL is required' });
    }

    let page = null;
    const startTime = Date.now();

    try {
        console.log(`Starting scrape for: ${url}`);

        // Check if browser is available
        if (!browser) {
            throw new Error('Browser not initialized');
        }

        page = await browser.newPage();

        // Set longer timeouts for the page
        page.setDefaultTimeout(options.timeout || 60000);
        page.setDefaultNavigationTimeout(options.timeout || 60000);

        // Set viewport
        await page.setViewport({
            width: options.viewport?.width || 1920,
            height: options.viewport?.height || 1080
        });

        // Set user agent
        if (options.userAgent) {
            await page.setUserAgent(options.userAgent);
        }

        // Set extra headers
        if (options.headers) {
            await page.setExtraHTTPHeaders(options.headers);
        }

        // Add request interception for better performance
        await page.setRequestInterception(true);
        page.on('request', (req) => {
            const resourceType = req.resourceType();
            if (options.blockResources && ['image', 'stylesheet', 'font'].includes(resourceType)) {
                req.abort();
            } else {
                req.continue();
            }
        });

        console.log(`Navigating to: ${url}`);

        // Navigate to URL with retry logic
        let navigationSuccess = false;
        let retries = 0;
        const maxRetries = 2;

        while (!navigationSuccess && retries < maxRetries) {
            try {
                await page.goto(url, {
                    waitUntil: options.waitUntil || 'networkidle2',
                    timeout: options.timeout || 60000
                });
                navigationSuccess = true;
            } catch (navError) {
                retries++;
                console.log(`Navigation attempt ${retries} failed:`, navError.message);
                if (retries >= maxRetries) {
                    throw navError;
                }
                await new Promise(resolve => setTimeout(resolve, 2000));
            }
        }

        console.log(`Navigation completed in ${Date.now() - startTime}ms`);

        // Wait for selector if specified
        if (options.waitForSelector) {
            console.log(`Waiting for selector: ${options.waitForSelector}`);
            await page.waitForSelector(options.waitForSelector, {
                timeout: options.selectorTimeout || 10000
            });
        }

        // Additional wait time
        if (options.waitTime) {
            console.log(`Additional wait: ${options.waitTime}ms`);
            await page.waitForTimeout(options.waitTime);
        }

        // Execute custom JavaScript
        if (options.executeScript) {
            console.log('Executing custom script');
            await page.evaluate(options.executeScript);
        }

        // Handle infinite scroll
        if (options.infiniteScroll) {
            console.log('Performing infinite scroll');
            await autoScroll(page);
        }

        console.log('Extracting page content');

        // Get page content
        const html = await page.content();
        const title = await page.title();
        const finalUrl = page.url();

        // Take screenshot if requested
        let screenshot = null;
        if (options.screenshot) {
            console.log('Taking screenshot');
            screenshot = await page.screenshot({
                type: 'png',
                fullPage: options.fullPageScreenshot || false,
                encoding: 'base64'
            });
        }

        // Get page metrics
        const metrics = await page.metrics();

        const totalTime = Date.now() - startTime;
        console.log(`Scraping completed in ${totalTime}ms`);

        res.json({
            success: true,
            data: {
                html,
                title,
                url: finalUrl,
                screenshot,
                metrics,
                timing: {
                    total: totalTime,
                    started: new Date(startTime).toISOString()
                },
                timestamp: new Date().toISOString()
            }
        });

    } catch (error) {
        const totalTime = Date.now() - startTime;
        console.error(`Scraping error after ${totalTime}ms:`, error.message);

        res.status(500).json({
            success: false,
            error: error.message,
            timing: {
                total: totalTime,
                started: new Date(startTime).toISOString()
            },
            timestamp: new Date().toISOString()
        });
    } finally {
        if (page && !page.isClosed()) {
            try {
                await page.close();
                console.log('Page closed successfully');
            } catch (closeError) {
                console.error('Error closing page:', closeError.message);
            }
        }
    }
});

// PDF generation endpoint
app.post('/pdf', async (req, res) => {
    const { url, options = {} } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'URL is required' });
    }

    let page = null;
    try {
        page = await browser.newPage();

        await page.goto(url, {
            waitUntil: 'networkidle2',
            timeout: options.timeout || 60000
        });

        const pdf = await page.pdf({
            format: options.format || 'A4',
            printBackground: options.printBackground || true,
            margin: options.margin || {
                top: '20px',
                right: '20px',
                bottom: '20px',
                left: '20px'
            }
        });

        res.setHeader('Content-Type', 'application/pdf');
        res.setHeader('Content-Disposition', 'attachment; filename="page.pdf"');
        res.send(pdf);

    } catch (error) {
        console.error('PDF generation error:', error);
        res.status(500).json({
            success: false,
            error: error.message
        });
    } finally {
        if (page) {
            await page.close();
        }
    }
});

// Screenshot endpoint
app.post('/screenshot', async (req, res) => {
    const { url, options = {} } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'URL is required' });
    }

    let page = null;
    try {
        page = await browser.newPage();

        await page.setViewport({
            width: options.width || 1920,
            height: options.height || 1080
        });

        await page.goto(url, {
            waitUntil: 'networkidle2',
            timeout: options.timeout || 60000
        });

        if (options.waitTime) {
            await page.waitForTimeout(options.waitTime);
        }

        const screenshot = await page.screenshot({
            type: options.type || 'png',
            fullPage: options.fullPage || false,
            quality: options.quality || 90
        });

        res.setHeader('Content-Type', `image/${options.type || 'png'}`);
        res.send(screenshot);

    } catch (error) {
        console.error('Screenshot error:', error);
        res.status(500).json({
            success: false,
            error: error.message
        });
    } finally {
        if (page) {
            await page.close();
        }
    }
});

// Auto scroll function for infinite scroll pages
async function autoScroll(page) {
    await page.evaluate(async () => {
        await new Promise((resolve) => {
            let totalHeight = 0;
            const distance = 100;
            const timer = setInterval(() => {
                const scrollHeight = document.body.scrollHeight;
                window.scrollBy(0, distance);
                totalHeight += distance;

                if (totalHeight >= scrollHeight) {
                    clearInterval(timer);
                    resolve();
                }
            }, 100);
        });
    });
}

// Graceful shutdown
process.on('SIGINT', async () => {
    console.log('Shutting down gracefully...');
    if (browser) {
        await browser.close();
    }
    process.exit(0);
});

process.on('SIGTERM', async () => {
    console.log('Shutting down gracefully...');
    if (browser) {
        await browser.close();
    }
    process.exit(0);
});

// Start server
async function start() {
    await initBrowser();
    app.listen(PORT, '0.0.0.0', () => {
        console.log(`Puppeteer service running on port ${PORT}`);
    });
}

start().catch(console.error);
