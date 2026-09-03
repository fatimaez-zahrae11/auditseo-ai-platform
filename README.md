# AuditSEO AI Platform

AuditSEO AI Platform is a project I developed during my initiation internship at Marqen Agency. The idea was to build a practical web platform that helps users run SEO audits, understand the main issues on a website, and get AI-assisted recommendations.

## About the project

The platform helps automate part of the SEO audit work of a digital agency. Users get a clear dashboard where they can review scores, issues, recommendations, and analytics.

The project is currently in its deployment preparation phase.

## Features

- User authentication
- Email verification
- Forgot and reset password
- Google OAuth
- SEO audit creation
- SEO scoring
- AI recommendations
- User dashboard
- Admin dashboard
- Web analytics
- IP intelligence and security insights
- Action logs
- Docker setup

## Tech stack

- Backend: Laravel 12, Laravel Sanctum, Laravel Socialite, and Google OAuth
- Email: Resend mail integration
- Frontend: React 19, Vite 6, TypeScript, and Tailwind CSS 4
- Dynamic globe: Three.js and React Three Fiber
- Database: PostgreSQL
- Cache and queues: Redis
- Containers and web server: Docker Compose and Nginx
- Testing: PHPUnit
- Dependency tools: Composer and npm

## Project architecture

```text
auditseo-ai-platform/
├── backend/
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── routes/
│   └── tests/
├── frontend/
│   ├── src/
│   ├── public/
│   └── package.json
├── docs/
│   └── docker.md
├── compose.yaml
├── .env.docker.example
├── requirements.txt
└── README.md
```

## Running the project

There are two ways to run the project.

### Option 1: Run with Docker Compose

This is the recommended and easiest option. Docker runs the Laravel backend, React frontend, PostgreSQL, Redis, queue worker, scheduler, and migration service.

The full Laravel, React, PostgreSQL, Redis, queue, and scheduler stack can run with Docker Compose. See [docs/docker.md](docs/docker.md) for setup, operations, testing, and production guidance.

```powershell
copy .env.docker.example .env.docker
docker compose run --rm backend php artisan key:generate --show
docker compose up --build
docker compose exec backend php artisan migrate
```

### Option 2: Run manually without Docker

Manual setup requires PHP, Composer, Node.js/npm, PostgreSQL, and Redis on your computer. You also need to install the project dependencies and configure `backend/.env` and the frontend environment file manually.

The full list of requirements is available in [requirements.txt](requirements.txt).

## Production notes

Production deployment is being prepared and is coming soon. 

## Personal note :

If the architecture looks a bit serious for an initiation internship project, know that i am an engineer 🤓. We like to understand the problem, design the system, secure the workflow, and then let the code do its job.
