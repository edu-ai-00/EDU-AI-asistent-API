# EDU-AI API

Laravel 12 (PHP 8.2+) REST API backing the EDU-AI Admin (Next.js) and EDU-AI App (Flutter) clients. Provides authentication, course/block content, AI-assisted endpoints, and supporting services (mail, file storage).

## Tech stack

- **Framework**: Laravel 12, PHP 8.2+
- **Auth**: Laravel Sanctum (token-based)
- **Database**: SQLite by default; MySQL/PostgreSQL supported via `DB_CONNECTION`
- **Mail**: Resend (`resend/resend-laravel`)
- **Storage**: AWS S3 / S3-compatible (`league/flysystem-aws-s3-v3`)
- **Tooling**: Pint (formatter), Pail (logs), Sail (Docker dev env), PHPUnit 11
- **Doctrine DBAL** for advanced schema operations

## Project structure

```
app/
  Console/        # Artisan commands
  Http/           # controllers, requests, middleware, resources
  Mail/           # mailable classes
  Models/         # Eloquent models
  Providers/      # service providers
  Services/       # domain services / business logic
  Support/        # shared helpers
bootstrap/        # Laravel bootstrap (app, cache)
config/           # configuration files
database/
  migrations/     # schema migrations
  factories/      # model factories
  seeders/        # database seeders
public/           # web entry point (index.php) + public assets
resources/        # views, lang, frontend assets
routes/
  api.php         # API routes
  web.php         # web routes
  console.php     # scheduled / console commands
storage/          # logs, framework cache, file storage
tests/            # Pest/PHPUnit tests
docs/             # API + integration docs
demo/             # demo fixtures
Procfile          # process definition (Railway / Heroku-style)
```

## Prerequisites

- PHP 8.2+ with `pdo_sqlite` (or your chosen DB driver), `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `bcmath`, `fileinfo`
- Composer 2+
- Node 20+ (only if you build front-end assets via Vite)

## Local development

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve            # http://127.0.0.1:8000
```

All-in-one dev (server + queue + log tail + vite):

```bash
composer dev
```

## Run with Docker

### Option A — Laravel Sail (recommended for local dev)

Sail is already a dev dependency. After `composer install`:

```bash
cp .env.example .env
php artisan sail:install      # pick services (mysql, redis, mailpit, …)
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

API at `http://localhost` (default Sail port). Stop with `./vendor/bin/sail down`.

### Option B — plain `docker run`

A production Dockerfile is not committed yet. For a quick container using the official PHP image:

```bash
docker run --rm -it \
  -p 8000:8000 \
  -v "$PWD":/app -w /app \
  -e DB_CONNECTION=sqlite \
  php:8.3-cli \
  sh -c "apt-get update && apt-get install -y unzip libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-interaction \
    && php artisan key:generate \
    && touch database/database.sqlite \
    && php artisan migrate --force \
    && php artisan serve --host=0.0.0.0 --port=8000"
```

## Environment

Configure via `.env` (see `.env.example`). Important variables:

| Variable                      | Purpose                                  |
|-------------------------------|------------------------------------------|
| `APP_KEY`                     | App encryption key (`php artisan key:generate`) |
| `APP_URL`                     | Public URL of the API                    |
| `DB_CONNECTION` / `DB_*`      | Database driver + credentials            |
| `SESSION_DRIVER`              | `database` by default                    |
| `RESEND_API_KEY`              | Resend mail key                          |
| `AWS_*`                       | S3 credentials, bucket, region           |
| `SANCTUM_STATEFUL_DOMAINS`    | Comma list of frontend domains for SPA auth |

## Useful commands

| Command                              | Purpose                          |
|--------------------------------------|----------------------------------|
| `php artisan migrate`                | Run pending migrations           |
| `php artisan migrate:fresh --seed`   | Reset DB and seed                |
| `php artisan tinker`                 | REPL                             |
| `php artisan queue:listen`           | Process queued jobs              |
| `php artisan pail`                   | Tail application logs            |
| `./vendor/bin/pint`                  | Format code                      |
| `php artisan test`                   | Run PHPUnit suite                |

## Deployment

`Procfile` runs migrations and starts the built-in PHP server on `$PORT` — suitable for platforms like Railway. For production, prefer PHP-FPM behind nginx or a Laravel-aware container image.

## Related

- **EDU-AI-asistent-ADM** — Next.js admin (separate repository)
- **EDU-AI-asistent-APP** — Flutter mobile/web client (separate repository)

## License

See `LICENSE`.
