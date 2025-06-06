<?php

namespace App\Console\Commands;

use App\Models\Talent;
use Illuminate\Console\Command;

class TestScrapingFlow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:scraping-flow {username : Username of the talent to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the scraping flow by updating a talent\'s website URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $username = $this->argument('username');

        $talent = Talent::where('username', $username)->first();

        if (!$talent) {
            $this->error("Talent with username '{$username}' not found.");
            return 1;
        }

        $this->info("Found talent: {$talent->name} ({$talent->username})");

        $websiteUrl = $this->ask('Enter a website URL to scrape', 'https://example.com');

        $this->info("Updating website URL to: {$websiteUrl}");
        $this->info("This will trigger the scraping flow...");

        // Update the website URL - this will trigger the scraping flow
        $talent->update(['website_url' => $websiteUrl]);

        $this->info("✅ Website URL updated!");
        $this->info("📋 Current scraping status: {$talent->fresh()->scraping_status}");
        $this->info("");
        $this->info("The following jobs should be dispatched:");
        $this->info("1. ScrapePortfolioJob - Scrapes the website");
        $this->info("2. ProcessScrapedTalentJob - Processes data with LLM");
        $this->info("3. UpdateVectorEmbeddingJob - Updates vector embeddings");
        $this->info("");
        $this->info("Run 'php artisan queue:work' to process the jobs.");

        return 0;
    }
}
