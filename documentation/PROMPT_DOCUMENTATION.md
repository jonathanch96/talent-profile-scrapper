### Use Of AI

- ask: installing Laravel 12 on MacBook (ChatGPT)
- prompt: make me a service in `app/Services` for connecting to the LLM model. I have this in `.env` as `OPENAI_API_KEY`, and please create a config for it
- ask: discussing what to store in the database so we can search it later, and I learned that we will use vector (I'm learning something new)
- prompt: refactor `LlmService` / rename that service to use OpenAPI, and the config as well
  edit: remove the unused function in the service; I didn't instruct it to create that function
- prompt: help me change the database to pgsql (was using MySQL) because we will use vector later
- prompt: make `ScraperService` under `app/Services`, that accepts a URL and will scrape the data, get all links, videos, images, text
- ask: how to scrape if the page content is loaded using JavaScript
- prompt: how about we use Puppeteer, add it to Docker Compose so we can scrape the web better
- edit: debugging Puppeteer not working
- edit: design a model
- prompt: add soft deletes, fillable fields & relationships
- prompt: run scrape for this site @https://sonuchoudhary.my.canva.site/portfolio
  use tinker or other methods and I want to store it as a file. Create a command for it because I already have the logic in `PuppeteerScraper`. You can create a command for it. I'm using Laravel 12
- prompt: I want to pass this scraped data to LLM. Create a function and prompt for it. The data should be mapped like `DummyDataSeeder`.
  If there are new `content_type` values, you should add them. If they already exist, just reuse them
- prompt: Please help me implement 4 Laravel API endpoints using a **service-based architecture**.

Each endpoint must:
- Use a **Service layer** for all business/data logic
- Use the **Controller** to format responses using Laravel Resources
- Follow a consistent success/error structure

---

### 1. `GET /api/talents` – Get All Talents (Paginated)

**Responsibilities:**
- Controller formats the paginated response
- Service handles fetching the talent list with pagination

**Expected Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "username": "johndoe"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 100,
    "last_page": 10
  },
  "errors": []
}
````

---

### 2. `GET /api/talents/{username}` – Get Talent by Username

**Responsibilities:**

-   Controller handles response formatting
-   Service fetches talent by username and eager-loads relationships: experiences, projects, details

**Expected Response:**

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "John Doe",
        "username": "johndoe",
        "experiences": [
            {
                "company": "ABC Corp",
                "role": "Video Editor",
                "duration": "2022 - 2023"
            }
        ],
        "projects": [],
        "details": [
            {
                "name": "job_type",
                "values": [
                    { "title": "Video Editor", "icon": "" },
                    { "title": "Script Writer", "icon": "" }
                ]
            },
            {
                "name": "content_vertical",
                "values": [
                    { "title": "Education", "icon": "" },
                    { "title": "Lifestyle", "icon": "" }
                ]
            }
        ]
    }
}
```

---

### 3. `PUT /api/talents/{username}` – Edit Talent by Username

**Responsibilities:**

-   Controller receives and validates the update request
-   Service updates the talent's main fields and associated relations (experiences, projects, details)

**Request Payload Example:**

```json
{
    "name": "Updated Name",
    "experiences": [
        {
            "company": "New Company",
            "role": "Creative Director",
            "duration": "2024 - Present"
        }
    ],
    "details": [
        {
            "name": "job_type",
            "values": [{ "title": "Director", "icon": "" }]
        }
    ]
}
```

**Expected Response:**

```json
{
    "success": true,
    "message": "Talent updated successfully",
    "data": {
        "username": "johndoe",
        "name": "Updated Name"
    },
    "errors": []
}
```

---

### 4. `DELETE /api/talents/{username}` – Delete Talent by Username

**Responsibilities:**

-   Controller handles the deletion response
-   Service handles find-and-delete logic

**Expected Response:**

```json
{
    "success": true,
    "message": "Talent deleted successfully",
    "errors": []
}
```

---

-   prompt: help me protect the route using a rate limiter and auth using API TOKEN `STATIC_API_TOKEN=a3f5d4b2c7893e0b5d6e4f9012c1a8f7`
    I have it in my `.env`. You don’t need to check it. I'm using Laravel 12, and I think we can use `X-API-TOKEN` or `X-API_TOKEN`

---

-   prompt: edit migration to add new fields `website_url`, `vectordb` (embedded OpenAPI), `scraping_status`: `['done', 'ongoing scrape', 'processing scrape to LLM', 'failed']`
    → suggest me status names. For vector we will use `pgvector`.
    Each time `website_url` is changed, we will update `scraping_status` to `ongoing` and dispatch a job (create a new job; details below)
    Each time profile data changes — especially `description`, `skills`, `experience`, and `content_type` — we will also dispatch a job

Create 3 new jobs:

1. Job to run `ScrapePortfolioCommand` with SPA and JSON — result will be stored in a file and its path stored in the DB, then change status to `processing to LLM`
2. Job to run `ProcessScrapedTalentCommand`, result also stored in file and path saved in DB
   (we will need to create a new model + migration for this — store: talent_id, result path, source URL)
3. Job to update vector embedded data (sent to LLM)

---

-   ask/prompt: debug error, I need to change the supported pgSQL version for vector

---

-   prompt: help me get all downloadable links, like Google Drive links, etc.
    Give me the result with file paths, and help me extract content from downloadable PDFs/docs

---

-   prompt: when I run `ScrapePortfolioCommand`, I don't want it to run other commands
    I want the command to:
    -   scrape images using SPA and return as JSON
    -   download all PDF/Word files
    -   extract text from the files using `smalot/pdfparser` and store the text as `.txt`
        Only these 2 commands should exist: `ScrapePortfolioCommand` and `ProcessScrapedTalentCommand`

---

-   prompt: test scrape @ https://sonuchoudhary.my.canva.site/portfolio
    I want the result to match expected mapping
    Update the prompt when sending data to LLM to achieve better results
    Add a function to send all YouTube links (from scraping) and label them for `content_vertical` (categories) before sending the main prompt

    For all commands, we will use `./talent-profile-scrapper`, like:
    `./talent-profile-scrapper php artisan tinker`

---

-   prompt: the result is already great, let’s try end-to-end:
    1. Let’s update `Talent::find(2)->website_url` to trigger the job
       → @https://sonuchoudhary.my.canva.site/portfolio
    2. I think `ProcessDocumentJob` is not needed — move its logic to a service since we don’t run it concurrently. We need this before calling `process:scraped-talent`
    3. Create another job to update talent data.
        - If the `content_type`, `skills`, `software`, `platform_specialties`, or `content_vertical` doesn’t exist, create it using `upsert`
        - Then insert to `TalentContent`
        - Finally, move the **update vector** job after this
    4. On the update vector job, embed the **content data** too when creating the vector
       → So we can search like:
       `"creator that has more than 5 years of experience, can use Adobe Premiere, and has AI-enhanced editing skills"`

---

-   prompt: when calling `getAllTalents`, if params include `search_using_llm`, then:
    -   pass the search to LLM to generate a pgvector-friendly query
    -   fetch limited data
    -   send again to LLM to **rank** the result from 0–100
    -   sort and return final results

---

-prompt: Fix the `PUT` endpoint using curl with a JSON payload that supports the following structure.

The payload may include:
- `name`
- `username`
- `experiences`: an array of work history
- `projects`: an array of projects with roles
- `details`: an array of categorized attributes like job type, skills, platforms, etc.

For the `experiences`, `projects`, and `details` keys:
- If the key exists in the payload, first clear the existing related records in the database, then insert the new values.
- If the key is not present in the payload, leave existing records untouched (do not delete).

Example payload:
```
{
    "name": "Sonu Choudhary",
    "username": "sonu-choudhary",
    "experiences": [
        {
            "company": "UP10 Media",
            "role": "Full Time",
            "duration": "December 2023 - Present"
        },
        {
            "company": "Gold Cosmetics & Skin Care",
            "role": "Full Time",
            "duration": "March 2022 - December 2023"
        },
        {
            "company": "Marketmen Group",
            "role": "Full Time",
            "duration": "September 2021 - March 2022"
        }
    ],
    "projects": [
        {
            "title": "YouTube Video",
            "description": null,
            "image": null,
            "link": null,
            "views": 5000000,
            "likes": 0,
            "project_roles": [
                "Video Editor",
                "Script Writer"
            ]
        }
    ],
    "details": [
        {
            "name": "Job Type",
            "values": [
                "Video Editor",
                "Script Writer"
            ]
        },
        {
            "name": "Content Vertical",
            "values": [
                "Education",
                "Entertainment"
            ]
        },
        {
            "name": "Platform Specialty",
            "values": [
                "YouTube",
                "Instagram"
            ]
        },
        {
            "name": "Skills",
            "values": [
                "Video Editing",
                "Color grading",
                "Motion graphics",
                "Storytelling techniques",
                "High-retention editing style",
                "Video post-production",
                "AI-enhanced editing",
                "Project Management & Workflow Optimization"
            ]
        },
        {
            "name": "Software",
            "values": [
                "Adobe Premiere Pro",
                "Adobe After Effects",
                "Adobe Photoshop"
            ]
        }
    ]
}
```
