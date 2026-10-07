# AG Shop

Admin-first webshop platform (Livewire admin), with planned Vue-based front templates (desktop + mobile/PWA).

## Requirements

- PHP `8.4`
- Composer `2.x`
- Node.js `22.x` and npm
- MySQL/MariaDB

## Local Development Setup

1. Install dependencies:
```bash
composer install
npm install
```

2. Create environment file:
```bash
cp .env.example .env
```

3. Configure `.env`:
- `APP_URL` (for Herd/local domain)
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

4. Generate app key:
```bash
php artisan key:generate
```

5. After `.env` DB credentials are valid and the target database already exists, run migrations:
```bash
php artisan migrate
```

6. Seed data:
```bash
php artisan db:seed
```
Expected prompt:
```text
Seed large dummy webshop dataset (100 categories, 1000 products, 3000 orders, 500 users, etc.)? (yes/no) [no]:
```
- Answer `no` for standard local baseline data.
- Answer `yes` when you need a large dummy dataset for performance/testing.
- You can also force this non-interactively with `SEED_DUMMY_DATA=true`.

7. Link storage:
```bash
php artisan storage:link
```

8. Build/start frontend assets:
```bash
npm run dev
```

9. Start app/runtime processes (if needed):
```bash
php artisan serve
php artisan queue:work
```

## Default Seeded Users (Non-Superadmin)

These are default local users for quick testing:

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@agshop.local` | `admin` |
| Editor | `editor@agshop.local` | `editor` |
| Customer | `customer@agshop.local` | `customer` |

Super-admin users are intentionally not listed in this table.

## API Access Notes

- Wholesale API endpoints are under `/api/v1/wholesale`.
- API access is controlled per user (`api_access_enabled`) and via token abilities.
- CLI token creation:
```bash
php artisan wholesale:token user@example.com client-name
```

## Nabava.net XML feed

The outgoing product feed is available at `/feeds/nabava.xml` and is disabled until its environment-backed credentials are configured:

```dotenv
NABAVA_NET_FEED_ENABLED=true
NABAVA_NET_FEED_USERNAME=replace-with-feed-username
NABAVA_NET_FEED_PASSWORD=replace-with-feed-password
NABAVA_NET_FEED_LOCALE=hr
NABAVA_NET_STOREFRONT_URL=https://www.example.hr
```

Nabava.net can access it using `?username=...&password=...`. Keep the credentials in the server environment and never commit them to the repository.

## Storefront cache

The B2B public catalogue can use a short server-side HTML cache:

```dotenv
STOREFRONT_CACHE_ENABLED=true
STOREFRONT_CACHE_STORE=file
STOREFRONT_CACHE_TTL=120
```

Only anonymous, unpersonalized catalogue pages are eligible. Signed-in customers,
cart/wishlist sessions, product detail pages and form requests bypass the HTML
cache. Each response retains its own session and CSRF token. Responses stay
private in browser/proxy caches. Catalogue, content and settings writes invalidate
the server cache after the database transaction commits. Each cache layer has a
bounded lifetime, including source snapshots used by the HTML cache. The public
menu shares the same revision. Restart long-running workers
after deploying changes, and rebuild the Laravel configuration cache when changing
these environment values.

`public/.user.ini` enables dynamic response compression on CGI/FastCGI hosts;
Apache compression and long-lived asset caching are configured in `.htaccess`.

## Useful Commands

```bash
php artisan optimize:clear
php artisan test
php artisan route:list
```

## cPanel staging deployment

The registered checkout is `/home/herrera/repositories/herrera-new`, on branch
`codex/herrera-b2b-foundation`. It deploys only to `https://herrera.herrera.hr`.
After pushing changes to GitHub, open **Git Version Control → Manage → Pull or
Deploy**, click **Update from Remote**, then **Deploy HEAD Commit**.

When frontend sources change, prepare and commit the production bundle locally:

```bash
npm run build
bash scripts/deploy-staging.sh --stamp-build
git add public/build
```

Commit these files together with their source changes before pushing. The server
validates the bundle fingerprint and manifest; it does not require Node.js.

`.cpanel.yml` runs `scripts/deploy-staging.sh` with PHP 8.4. Deployment first checks
the staging URL, `APP_ENV=staging`, database `herrera_redesign`, safe mode and cache
settings. It backs up changed files and `.env` privately, preserves uploads,
storage, symlinks, old hashed assets and the cPanel PHP handler, then refreshes
autoload/configuration/routes/views. Changed Composer locks install their exact
dependencies without development packages. Errors trigger a rollback attempt;
backups are retained in `/home/herrera/.herrera-staging-backups`.

Deployment does not run database migrations, import data or modify production.
See the [cPanel deployment guide](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/)
for the two-button pull workflow.
