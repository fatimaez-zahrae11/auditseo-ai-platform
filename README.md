# AuditSEO AI Platform

This project was developed as part of my initiation internship. AuditSEO AI Platform is a web platform that helps users run automated SEO audits and receive AI-assisted recommendations.

## About the project

The goal was to build a simple platform where users can check websites, review SEO results, and follow useful recommendations from their dashboard.

## Features

- User authentication
- Email verification
- Forgot and reset password
- Google OAuth
- SEO audit creation
- AI recommendations
- User dashboard
- Admin dashboard
- Analytics
- Docker setup

## Tech stack

- Backend: Laravel
- Frontend: React, Vite, and TypeScript
- Database: PostgreSQL
- Cache and queues: Redis
- Containerization: Docker Compose

## Project architecture

```text
auditseo-ai-platform/
├── backend/
├── frontend/
├── docs/
├── compose.yaml
├── .env.docker.example
├── requirements.txt
└── README.md
```

## Run with Docker

From the project folder, run:

```powershell
copy .env.docker.example .env.docker
docker compose run --rm backend php artisan key:generate --show
docker compose up --build
docker compose exec backend php artisan migrate
```

The frontend is available at [http://localhost:5173](http://localhost:5173), and the backend API is available at [http://localhost:8000/api](http://localhost:8000/api).

## Run without Docker

For a manual setup, install PHP, Composer, Node.js, PostgreSQL, and Redis locally. Then install the backend and frontend dependencies from their dependency files.

## Requirements

The required tools and dependency files are listed in [requirements.txt](requirements.txt).

## Production notes

Before using the project in production, it still needs:

- A real domain
- HTTPS/TLS
- Production secrets
- Google OAuth production credentials
- Resend configuration
- Database backups
- Monitoring
- A reverse proxy using Cloudflare, Nginx, or a similar service
