# Deployment Guide - Talent Profile Scrapper

This guide provides comprehensive instructions for deploying the Talent Profile Scrapper API in various environments.

## Table of Contents

- [System Requirements](#system-requirements)
- [Environment Setup](#environment-setup)
- [Docker Deployment](#docker-deployment)
- [Database Configuration](#database-configuration)
- [Service Configuration](#service-configuration)
- [Post-Deployment Testing](#post-deployment-testing)
- [Production Considerations](#production-considerations)
- [Troubleshooting](#troubleshooting)
- [Maintenance](#maintenance)

## System Requirements

### Hardware Requirements
- **CPU**: Minimum 2 cores, Recommended 4+ cores
- **RAM**: Minimum 4GB, Recommended 8GB+ (due to Puppeteer and PDF processing)
- **Storage**: Minimum 10GB free space (for PDFs and database)
- **Network**: Stable internet connection for API calls and web scraping

### Software Requirements
- **Docker**: Version 20.10+ 
- **Docker Compose**: Version 2.0+
- **Git**: For cloning the repository
- **OpenAI API Key**: For LLM processing

### Supported Operating Systems
- Linux (Ubuntu 20.04+, CentOS 8+, RHEL 8+)
- macOS (10.15+)
- Windows 10/11 with WSL2

## Environment Setup

### 1. Clone Repository
```bash
# Clone the project
git clone <your-repository-url>
cd talent-profile-scrapper

# Verify project structure
ls -la
```

### 2. Environment Configuration
```bash
# Copy environment template
cp .env.example .env
```

### 3. Required Environment Variables

Edit `.env` file with the following configurations:

#### Core API Configuration
```env
STATIC_API_TOKEN=your-api-token
```

#### OpenAI Configuration
```env
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_MODEL=gpt-3.5-turbo
OPENAI_MAX_TOKENS=4000
```


## Docker Deployment

### 1. Build Services
```bash
# Build all Docker containers
docker compose build

# Verify build completion
docker images | grep talent-profile-scrapper
```

### 2. Start Services
```bash
# Start all services in background
docker compose up -d

# Check service status
docker compose ps
```

Expected output:
```
NAME                    COMMAND                  SERVICE             STATUS              PORTS
talent-profile-scrapper "/usr/local/bin/dock…"   app                 running             0.0.0.0:8080->80/tcp
postgres                "docker-entrypoint.s…"   postgres            running             5432/tcp
redis                   "docker-entrypoint.s…"   redis               running             6379/tcp
horizon                 "/usr/local/bin/dock…"   horizon             running             
```

### 3. Install Dependencies
```bash
# Install PHP dependencies
./talent-profile-scrapper composer install

# Alternative method
docker exec -it talent-profile-scrapper composer install

```

### 4. Application Key Generation
```bash
# Generate Laravel application key
./talent-profile-scrapper php artisan key:generate

# Verify key is set in .env
grep APP_KEY .env
```

## Database Configuration

### 1. Database Migration
```bash
# Run fresh migrations with seeders
./talent-profile-scrapper php artisan migrate:fresh --seed

# Verify migration status
./talent-profile-scrapper php artisan migrate:status
```

### 2. Database Seeding Verification
```bash
# Check if data was seeded properly
./talent-profile-scrapper php artisan tinker

# In tinker console:
>>> App\Models\Talent::count()
>>> exit
```



## Post-Deployment Testing

### 1. Health Check
```bash
# Test basic API connectivity
curl -X GET "http://localhost:8080/api/health" \
  -H "X-API-TOKEN: your-api-token"
```

### 2. API Endpoint Testing
```bash
# Test talents listing endpoint
curl -X GET "http://localhost:8080/talents" \
  -H "X-API-TOKEN: your-api-token" \
  -H "Content-Type: application/json"

# Test with search functionality
curl -X GET "http://localhost:8080/talents?search_using_llm=developer" \
  -H "X-API-TOKEN: your-api-token" \
  -H "Content-Type: application/json"
```

### 3. Import Postman Collection
1. Open Postman
2. Import `documentation/Talent Scrapper.postman_collection.json`
3. Set environment variable `APITOKEN` to your `STATIC_API_TOKEN`
4. Set `baseUrl` to `http://localhost:8080`
5. Run the test collection

## Production Considerations

### 1. Security
```env
# Production environment settings
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-domain.com

# Use strong passwords
DB_PASSWORD=very_secure_random_password
REDIS_PASSWORD=another_secure_password

# Use secure API tokens
STATIC_API_TOKEN=generate_secure_random_token_here
```

### 2. Monitoring
```bash
# Set up log monitoring
# Configure application logs
LOG_CHANNEL=daily
LOG_LEVEL=info

# Monitor queue performance
./talent-profile-scrapper php artisan horizon:status
```


## Troubleshooting

### Common Issues

#### 1. Container Start Failures
```bash
# Check container logs
docker compose logs app
docker compose logs postgres
docker compose logs redis

# Restart specific service
docker compose restart app
```

#### 2. Database Connection Issues
```bash
# Verify database container is running
docker compose ps postgres

# Test database connection
docker exec -it postgres psql -U postgres -d talent_scrapper

# Check database configuration
./talent-profile-scrapper php artisan config:show database
```

#### 3. Queue Jobs Not Processing
```bash
# Check Horizon status
./talent-profile-scrapper php artisan horizon:status

# Restart Horizon
docker compose restart horizon

# Clear failed jobs
./talent-profile-scrapper php artisan queue:flush
```

#### 4. Memory Issues with Puppeteer
```env
# Increase memory limits in docker-compose.yml
services:
  app:
    deploy:
      resources:
        limits:
          memory: 2G
```

#### 5. OpenAI API Errors
```bash
# Test OpenAI API key
curl -H "Authorization: Bearer $OPENAI_API_KEY" \
  https://api.openai.com/v1/models

# Check API usage and limits
# Verify environment variable is set correctly
./talent-profile-scrapper php artisan config:show services.openai
```

### 6. Permission Errors
# RUN on your server 
```
    chown -R root:1000 talent-profile-scrapper/
    chmod -R g+rwX talent-profile-scrapper/
```

## Support

For additional support:
1. Check application logs in `storage/logs/`
2. Review Docker container logs using `docker compose logs [service_name]`
3. Consult Laravel documentation for framework-specific issues
4. Check OpenAI API documentation for LLM-related issues

---

**Last Updated**: $(date +%Y-%m-%d)
**Version**: 1.0.0 
