# Talent Profile Scrapper API

A Laravel-based API service for scraping talent profiles from websites, extracting data from PDFs, and providing intelligent search capabilities using natural language processing.

## Overview

This project is an **API-only service** designed to automate the collection and processing of talent profile data. It combines web scraping, PDF processing, and AI-powered data formatting to create a comprehensive talent database with natural language search capabilities.

## Key Features

- **Web Scraping**: Uses Puppeteer to scrape talent websites and download associated PDFs
- **PDF Processing**: Extracts and parses data from downloaded PDF documents
- **AI Integration**: Leverages OpenAI's LLM to format and standardize collected data
- **Vector Search**: Generates OpenAI embeddings for natural language talent search
- **Background Processing**: Uses Laravel Horizon and Redis for asynchronous scraping tasks
- **Enhanced Architecture**: Built on Laravel with an additional Services layer for reusable business logic
- **Vector Database**: PostgreSQL with pgvector extension for efficient similarity search

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Web Scraping  │    │  PDF Processing │    │  AI Processing  │
│   (Puppeteer)   │───▶│  (PDF Parser)   │───▶│   (OpenAI)      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         ▼                       ▼                       ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Redis Queue   │    │   PostgreSQL    │    │  Vector Store   │
│   (Horizon)     │    │   Database      │    │  (pgvector)     │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

The project follows Laravel's MVC pattern with an additional **Services layer** to encapsulate business logic and promote code reusability.

## Tech Stack

- **Backend**: Laravel (PHP)
- **Database**: PostgreSQL with pgvector extension
- **Queue System**: Redis + Laravel Horizon
- **Web Scraping**: Puppeteer
- **PDF Processing**: PDF Parser
- **AI/ML**: OpenAI API
- **Containerization**: Docker & Docker Compose

## Prerequisites

- Docker and Docker Compose installed on your system
- OpenAI API key
- Basic understanding of Laravel and API testing

## Installation Guide

### 1. Clone the Repository
```bash
git clone <your-repository-url>
cd talent-profile-scrapper
```

### 2. Environment Setup
```bash
# Copy environment file
cp .env.example .env
```

### 3. Configure Environment Variables
Edit your `.env` file and set the following required variables:
```env
OPENAI_API_KEY=your_openai_api_key_here
STATIC_API_TOKEN=a3f5d4b2c7893e0b5d6e4f9012c1a8f7
```

- `OPENAI_API_KEY`: Your OpenAI API key for LLM processing
- `STATIC_API_TOKEN`: Used as `X-API-TOKEN` header for API authentication

### 4. Build and Start Services
```bash
# Build Docker containers
docker compose build

# Start services in detached mode
docker compose up -d
```

### 5. Install Dependencies
```bash
# Install Composer dependencies
./talent-profile-scrapper composer install
# OR
docker exec -it talent-profile-scrapper composer install
```

### 6. Restart Services
```bash
docker compose restart
```

### 7. Database Setup
```bash
# Run migrations and seeders
./talent-profile-scrapper php artisan migrate:fresh --seed
# OR
docker exec -it talent-profile-scrapper php artisan migrate:fresh --seed
```

## API Endpoints

The API provides four main endpoints for talent management:

### 1. GET `/talents`
Retrieve all talent data with optional intelligent search.

**Parameters:**
- `search_using_llm` (optional): Enables natural language search using embedded vector data

**Features:**
- Returns all talents when no search parameter is provided
- Uses pgvector for similarity search when `search_using_llm` is provided
- Includes LLM-based reranking for improved accuracy (can be disabled for large-scale requests)

### 2. GET `/talents/{username}`
Retrieve detailed information for a specific talent by username.

### 3. PUT `/talents/{username}`
Update talent data manually.

**Features:**
- Only processes changed data fields
- Partial updates supported

### 4. DELETE `/talents/{username}`
Delete a talent profile from the database.

## Testing the API

A Postman collection is provided for easy API testing:

1. Import `documentation/Talent Scrapper.postman_collection.json` into Postman
2. Update the `APITOKEN` variable to match your `STATIC_API_TOKEN` from `.env`
3. Use port `8080` for all requests
4. Include `X-API-TOKEN: a3f5d4b2c7893e0b5d6e4f9012c1a8f7` header in requests

### Example cURL Request
```bash
curl -X GET "http://localhost:8080/talents" \
  -H "X-API-TOKEN: a3f5d4b2c7893e0b5d6e4f9012c1a8f7" \
  -H "Content-Type: application/json"
```

## Documentation

- **Deployment Guide**: `documentation/DEPLOY.md`
- **Prompt Documentation**: `documentation/PROMPT_DOCUMENTATION.md` (contains major prompts used in the system)
- **API Collection**: `documentation/Talent Scrapper.postman_collection.json`

## Development Notes

### Services Layer
This project extends Laravel's standard architecture with a Services layer to promote:
- **Code Reusability**: Common business logic can be shared across controllers
- **Separation of Concerns**: Business logic is separated from HTTP handling
- **Testability**: Services can be easily unit tested

### Background Processing
- **Laravel Horizon**: Provides dashboard and monitoring for queue jobs
- **Redis**: Handles job queuing for scraping tasks
- **Asynchronous Processing**: Web scraping and PDF processing run in background jobs

### AI Integration
- **Data Formatting**: LLM processes raw scraped data into standardized formats
- **Vector Embeddings**: OpenAI embeddings enable semantic search capabilities
- **Flexible Architecture**: LLM dependency can be reduced for high-volume operations

## Contributing

When contributing to this project, please:
1. Follow Laravel coding standards
2. Use the Services layer for business logic
3. Write tests for new features
4. Update documentation as needed

## License

This project is licensed under the MIT License.
