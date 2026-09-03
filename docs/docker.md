# Docker development and deployment

The Compose stack runs the React/Vite frontend, Laravel through PHP-FPM and Nginx, PostgreSQL, Redis, the queue worker, the Laravel scheduler, and a one-shot migration service. PHP, Composer, Node.js, PostgreSQL, and Redis do not need to be installed on the host.

## Prerequisites

- Docker Desktop with Docker Compose v2
- Enough Docker memory for the PHP and frontend builds
- Ports `5173` and `8000` available locally

On Windows, Docker Desktop with the WSL2 engine is recommended. The frontend source is bind-mounted for Vite hot reload, while `node_modules` stays in a Linux named volume.

## First-time setup

From the repository root, create the ignored Docker environment file:

```powershell
Copy-Item .env.docker.example .env.docker
```

On macOS or Linux, use:

```sh
cp .env.docker.example .env.docker
```

Generate the application key once:

```sh
docker compose run --rm backend php artisan key:generate --show
```

Paste the displayed value into `APP_KEY` in `.env.docker`. Keep this key stable; generating a different key on every start would invalidate Laravel-encrypted values and sessions.

Start and build the complete stack:

```sh
docker compose up --build
```

Compose waits for PostgreSQL and Redis health checks. The one-shot `migrate` service runs `php artisan migrate --force` before Nginx, the queue, and the scheduler start.

Open:

- Frontend: <http://localhost:5173>
- Backend API: <http://localhost:8000/api>
- Backend health: <http://localhost:8000/api/health>

## Common operations

Run migrations manually when needed:

```sh
docker compose exec backend php artisan migrate
```

Inspect the stack and logs:

```sh
docker compose ps
docker compose logs -f backend backend-web frontend
docker compose logs -f queue
docker compose logs -f scheduler
```

Inspect Laravel routes and scheduled commands:

```sh
docker compose exec backend php artisan route:list
docker compose exec backend php artisan schedule:list
```

Run backend and frontend validation:

```sh
docker compose run --rm --no-deps \
  -e APP_ENV=testing \
  -e CACHE_STORE=array \
  -e CACHE_LIMITER=array \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  -e MAIL_MAILER=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  backend sh -c 'touch .env && exec php artisan test'
docker compose exec backend composer validate --strict
docker compose exec frontend npm run lint
```

The explicit testing overrides keep the database, cache, session, mail, and queue state isolated from the running development stack. The empty `.env` exists only in the disposable test container and prevents PHPUnit from reporting a warning for every test when Laravel checks for an environment file.

Stop the stack without deleting data:

```sh
docker compose down
```

To also delete PostgreSQL, Redis, Laravel storage, and frontend dependency volumes:

```sh
docker compose down --volumes
```

This permanently removes local container data. Back up anything needed before running it.

## Optional database administration

Adminer is isolated behind the `tools` profile and is not started by default:

```sh
docker compose --profile tools up -d db-admin
```

Open <http://localhost:8080> and use server `db` with the `POSTGRES_*` credentials from `.env.docker`. The port binds to loopback only.

PostgreSQL and Redis are not published to the host by the default stack. Use `docker compose exec db psql` or `docker compose exec redis redis-cli` for direct access.

## Local integrations

### Email and password reset

`MAIL_MAILER=log` writes password-reset and verification messages to container logs:

```sh
docker compose logs -f backend queue
```

Do not place a production Resend key in a committed file.

### Google OAuth

To test Google OAuth locally, add a development OAuth client ID and secret to the ignored `.env.docker` file. Register this exact callback with Google:

```text
http://localhost:8000/api/auth/google/callback
```

The application OAuth flow, Sanctum bearer tokens, CORS rules, and trusted-proxy behavior are unchanged by Docker.

## Images and process supervision

- `backend`, `queue`, `scheduler`, and `migrate` use the same Laravel development image.
- `backend-web` is an Nginx image containing Laravel's public files and forwards FastCGI traffic over the private Compose network.
- The queue command is `php artisan queue:work redis --queue=default --sleep=3 --tries=2 --timeout=180`.
- The queue has a 300-second Docker stop grace period so an audit can finish cleanly.
- The scheduler is a single service that executes `php artisan schedule:run --no-interaction` every minute.
- Redis uses append-only persistence and the `noeviction` policy to protect queued jobs.
- Docker restart policies supervise the long-running services; Supervisor is not needed inside the containers.

After deploying new backend code, gracefully restart long-running workers:

```sh
docker compose exec backend php artisan queue:restart
```

## Production images

The backend Dockerfile provides separate `development`, `production`, and `nginx` targets. The frontend Dockerfile provides `development` and `production` targets.

A production frontend image must be built with an HTTPS API URL; the existing Vite validation intentionally rejects HTTP production URLs:

```sh
docker build \
  --target production \
  --build-arg VITE_API_BASE_URL=https://api.example.com/api \
  -t auditseo-frontend:production \
  frontend
```

Before production deployment:

- Build immutable images in CI and pin reviewed base-image versions or digests.
- Supply secrets through the deployment platform or a secret manager, not image layers.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and valid HTTPS application, frontend, CORS, and OAuth URLs.
- Configure a verified Resend sender and production credentials.
- Use the PostgreSQL provider's required SSL mode.
- Back up PostgreSQL and persistent Laravel files before migrations.
- Use durable, monitored Redis; consider separate queue and cache instances at larger scale.
- Put the HTTP services behind the existing TLS, Cloudflare, or reverse-proxy layer.
- Keep PHP-FPM, PostgreSQL, and Redis private.
- Run migrations as one controlled release job before switching traffic.
- Send container logs to centralized storage and monitor web health, queue backlog, failed jobs, and scheduler execution.

The provided `compose.yaml` is optimized for local development. A production deployment should add an environment-specific override that selects the production image targets, removes source mounts and development ports, and integrates with the target infrastructure.
