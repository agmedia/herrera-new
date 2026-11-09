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

## Automatic EPREL lookup

When EPREL is enabled and its API key is configured, creating an active product
or publishing an inactive draft schedules an exact energy-data lookup after the
product transaction commits. This includes products imported from supplier XML.
Ineligible products, missing identifiers, manual declarations and fully documented
products are skipped. Existing energy fields and document links are preserved;
missing data is filled only after an exact official match. Conflicting matches
remain available for manual review through the existing EPREL tools.

The dedicated database queue requires a supervised worker on each installation:

```bash
php artisan queue:work eprel --queue=eprel --timeout=600 --sleep=3 --tries=3
```

The general/default queue worker does not process this queue. Automatic lookups
share the catalogue request quota, defer when throttled and expire after six
hours. Transient errors have at most three failed attempts; authentication
rejections fail immediately. Normal outcomes and conflicts are logged with only
the product ID and status; terminal failures use the standard failed-job tooling.
Run the existing manual catalogue lookup to retry an unresolved product. Restart
long-running workers after deploying code changes.

The registered cPanel staging deployment manages one every-minute cron entry
marked `HERRERA-STAGING-EPREL-WORKER`. It invokes the tracked
`scripts/run-staging-eprel-worker.sh`, which uses a nonblocking single-worker
lock and a shared deployment lock. Each invocation stops when the queue is empty
or after 50 seconds between articles; an in-flight article may take up to 600
seconds. Fresh invocations load the deployed release, so this bounded worker
does not need a separate restart. Unrelated cron entries are preserved and a
private crontab snapshot is restored if deployment rolls back.

The worker validates the staging URL/database and deployed Git revision, skips
maintenance or an undeployed checkout, and does nothing while EPREL is disabled
or unconfigured. Deployment never enables EPREL. Run the tracked worker with
`--check` to verify its guards and configuration without processing jobs or
contacting EPREL. With zero queued jobs a normal invocation also makes no EPREL
requests. Its private log is
`/home/herrera/.herrera-staging-backups/eprel-worker.log`.

## Herrera client workflows (November 2026)

The November backlog is prepared locally and pushed through Git. Deploy it only
through the registered staging deployment; no direct cPanel file edits or uploads.
Translations into additional languages are excluded from this release.

- **Retail pricing:** Catalog / Actions supports the B2C-only audience, brand
  discounts and fixed net selling prices for selected products. Negotiated B2B
  pricing remains separate. Orders retain their original B2B/B2C classification;
  retail orders have the internal cost centre `Web prodaja`. Statistics default
  to B2B, with B2C/all filters. No external ERP cost-centre ID is invented.
- **Ordering quantities:** the old OpenCart minimum remains the source for the
  safe pack-step repair. `herrera:backfill-order-quantities` previews the repair;
  `--apply` changes only still-unmodified imports. Unit prices remain per piece.
  Storefront cards, detail, floating controls and cart use the same minimum/step.
- **Supplier catalogues:** Vipelectro uses the existing `master` integration.
  `herrera:sync-supplier-catalog --import-drafts` refreshes configured sources
  and queues valid new items as inactive drafts. Existing product data is not
  overwritten. Select category mappings/defaults in Integrations / Supplier
  articles before importing. The initial markup is 250% (cost multiplied by 3.5).
  Unpriced or unmapped rows remain pending, never public zero-priced products.
- **Feed currency:** the supplied Esperanza catalogue currently declares EUR.
  EUR prices require no conversion. PLN sources can use the official ECB daily
  reference (latest published business day), with its date retained. Failure
  without a valid dated reference stops importing. Current Master/Vipelectro
  and Vayox feeds lack procurement prices; obtain those separately. A private
  procurement CSV can be previewed with
  `herrera:supplier-prices <supplier> <private.csv>` and applied with `--apply`.
- **Missing supplier rows:** disappearance only queues manual review. Explicitly
  reviewed retirement sells remaining own stock and deactivates at zero; stale
  supplier stock cannot be sold. Historical orders remain readable.
- **Non-XML suppliers:** fixed 100000 is supplier availability, never warehouse
  stock. Select suppliers in Integrations / Stock. Reservations, cancellations
  and automated XML updates leave it unchanged. A periodic real quantity CSV
  (`sku,quantity`) is handled by `herrera:supplier-availability` (preview first;
  see `artisan help` for arguments). Keep private connection files outside Git.
- **Customer XML:** the customer profile has an explicit assortment approval
  panel. Only approved B2B accounts with a valid contract and enabled grant can download
  `/feeds/customers/{token}.xml`. Tokens are hashed, revocable and shown only at
  generation; require HTTPS outside local/testing. Export uses full wholesale
  BASE prices, without personal/group/promotional discounts or exact stock.
  Purchased B2B products are included only when separately approved. A blank
  whitelist never exposes the complete catalogue. Redact bearer URLs from
  hosting access logs when providing this service to customers.
- **Delivery estimates:** initial schedules are the client PDF supplied on
  9 November. Edit them under Integrations / Delivery times. Calculation uses
  next supplier order + supplier lead + two calendar days, including weekends.
  Same-day ordering is inclusive, without a daily cutoff. Orders placed from
  20 December through 8 January add five supplier days. Expired manual dates
  show an inquiry. Partial deliveries and per-item estimates are retained on
  the order. The PDF is a snapshot, not a connected cloud spreadsheet.
- **Pickup:** the Herrera method is free, at Gospodarska ulica 10, 48260
  Križevci, by telephone arrangement. No opening hours are invented. The order
  keeps the address/instructions and omits the two customer-transport days.
- **Clearance:** disabled until a fresh, confirmed all-company e-Računi outgoing
  report is provided and a clearance category is chosen. The CSV schema is
  `product_code,last_outgoing_at,coverage_from,as_of`, dates `YYYY-MM-DD`; blank
  last-outgoing means none in the confirmed coverage. Current-day evidence and
  at least 100 calendar days of coverage are required. `herrera:clearance`
  previews candidates; `--apply` changes eligible products. Clearance sells only
  real own stock at 60% off the full wholesale base, without stacking coupons or
  personal discounts. At zero it stays inactive until explicit manual activation.
  The supplied ERP APIs do not establish an authoritative all-company movement
  endpoint; do not infer this from webshop orders or inventory balances.
- **Returns/claims:** select original order items and quantities; Klara approves
  (with superadmin support). Only cumulative physical receipt reduces net sold
  quantities. Inspected, sellable restocking is a separate explicit quantity;
  defective claims are not automatically restocked. Receipt history prevents
  duplicate processing and cancellation cannot restore these quantities again.
  Customer notifications plus Klara, warehouse and Judita use editable settings.
  No financial refund/ERP credit is automatically issued.

The staging deploy applies the reviewed additive migrations and preserves
unrelated crons. In addition to EPREL, it manages
`HERRERA-STAGING-INTEGRATIONS-WORKER` and
`HERRERA-STAGING-INTEGRATIONS-SCHEDULER` through
`scripts/run-staging-integrations-worker.sh`. Queue and scheduler use separate
nonblocking locks plus the shared deployment lock, check the deployed revision,
and skip maintenance. The database queue must have `retry_after > 9000` to cover
existing long integration jobs. `--check` validates without running jobs;
`--schedule` invokes Laravel's scheduler. Catalogue drafts run at 03:45, clearance
at 04:30 Europe/Zagreb, and Esperanza stock follows its registry schedule only
when configured and enabled. The worker consumes only the `integrations` queue.

Private feed settings are imported with the tracked
`herrera:configure-stock --connection-file <private.json> --replace` command.
Never commit credentials, source XMLs or customer bearer URLs. Master/Vayox
procurement data, authoritative ERP movements and any external ERP cost-centre
mapping remain data/configuration prerequisites, not fabricated defaults.

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

Deployment applies only the reviewed additive customer workflow/CRM, optional
product-options, historical CRM payload, supplier catalogues, reference projects,
customer offers and their scoped abilities migrations from 14 October 2026, while staging is in maintenance
mode. It does not run other database migrations, re-import catalog/orders or modify
production. A file rollback keeps these additive tables and columns to preserve
any CRM history already recorded. `supplier-catalogs:seed --publish` adds the five
verified official catalogue links only when their codes do not exist; it never
downloads the large supplier PDFs or overwrites administrator changes.
`supplier-catalogs:covers --apply` fills missing covers only for unchanged
official source links; uploaded or custom covers remain untouched.
It applies the agreed October B2B settings once through `herrera:apply-quick-fixes`:
the Herrera social profiles, blog navigation, structured specification filters and
hiding unavailable products from catalog lists and search. Active product detail
URLs remain accessible regardless of stock. A saved marker preserves later administrator edits.
The command is restricted to the isolated local preview and staging databases.
It also collapses non-category filters by default, refreshes the three existing
Croatian blog articles from `resources/content/herrera-blog` and adds the CMS blog
carousel at `home.bottom`, before the newsletter. Each article keeps its original
slug/status/publication date and a recoverable copy of its former text and payload;
per-article markers preserve later admin edits. Versioned illustrative covers and
their ImageGen prompts are stored in `public/assets/blog/herrera`.
See the [cPanel deployment guide](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/)
for the two-button pull workflow.

## Offers, supplier catalogues and reference projects

- `/admin/customer-offers`: select an approved buyer, add products/variants and
  quantities, then save a draft. Prices use the buyer's existing B2B rules and
  promotions. Preview and PDF download do not send mail. Sending is an explicit
  action; local safe mode and disabled mail transports block delivery. Sent
  offers are immutable, and an uncertain delivery is locked for review. Sales
  representatives only access their assigned buyers. Buyers see their sent
  offers at `/account/customer-offers`.
- `/admin/content/supplier-catalogs`: upload a PDF/cover or store an external
  HTTPS catalogue link. The form respects the effective application/PHP upload
  size limit. Uploaded files are private and served only for published entries.
  `/katalozi` lists the published catalogues with brand filters.
- `/admin/content/references`: bilingual project details, draft/scheduled
  publication and private photo galleries with cover, captions and ordering.
  `/reference` and `/reference/{slug}` show only published projects with photos.
  Demo references require the explicitly guarded `herrera:demo-reference`
  preview command, never an automatic deployment hook. Its `--staging` option
  accepts only the safe-mode `https://herrera.herrera.hr` installation backed by
  `herrera_redesign`; the default accepts only the isolated local preview.

The new permissions are registered additively without resetting existing roles
or explicit denials. New migrations create their own tables and keep existing
products, customers, prices and orders intact.
