# Club Portal

A web-based Club Management System built with **Laravel**, **Vue.js**, and **Tailwind CSS**. It provides tools to manage church clubs such as Adventurers, Pathfinders, and Master Guides.

## Features

* User authentication and roles (director, staff, parent, treasurer, secretary, adviser, etc.)
* Church and Club registration and management
* Member and Staff management
* Staff-to-member relationship tracking
* Parent registration and child application forms
* Service and event tracking for club members
* Report generation and status tracking
* File and image uploads (e.g., profile pictures, forms)

## Tech Stack

* **Backend**: Laravel (PHP)
* **Frontend**: Vue.js
* **Styling**: Tailwind CSS
* **Build Tools**: Vite
* **Database**: PostgreSQL in development/testing, MySQL in production

## Installation

### Requirements

* PHP >= 8.2
* Composer
* Node.js >= 18
* PostgreSQL for development/testing
* MySQL for production

### Setup

```bash
git clone <repository-url>
cd club-portal
cp .env.example .env
cp .env.testing.example .env.testing
composer install
php artisan key:generate
npm install
npm run dev
php artisan migrate --seed
```

### Running

```bash
php artisan serve
```

## Folder Structure Highlights

* `app/` – Application logic (Models, Controllers)
* `resources/views` – Blade templates
* `routes/web.php` – Web routes
* `database/` – Migrations and seeders
* `public/` – Public assets and entry point

## Roles (selected)

- `parent`:
  - Redirect after login: `/parent/apply` (via `RedirectIfAuthenticated::redirectPath`)
  - Guest self-registration: `GET/POST /register-parent`, helper `GET /churches/{church}/clubs`
  - Authenticated (middleware: `auth`, `verified`, `auth.parent`):
    - `GET /parent/apply` (`parent.apply`)
    - `POST /parent/apply` (`parent.apply.submit`)
    - `GET /parent/children` (`parent-links.index.parent`)

## Utilities

- Seed default pay-to options (fills `pay_to_options` for all clubs, or one via `--club_id`):
  ```bash
  php artisan payto:seed
  php artisan payto:seed --club_id=1
  ```

## Tests

- End-to-end smoke tests:
  - `tests/Feature/SystemSmokeTest.php` covers church creation, director registration, club setup, classes, staff, workplan, payments, and member assignment.
  - Run:
    ```bash
    php artisan test --testsuite=Feature --filter=SystemSmokeTest
    ```

## Superadmin Bootstrap (Postman only)





## Environment Variables

Configure your `.env` file:

```env
APP_NAME="Club Portal"
APP_URL=http://localhost
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

For tests, use `.env.testing` with a dedicated PostgreSQL database. `phpunit.xml` no longer forces SQLite.

## Live Deployment Checklist

These steps cover the parent portal, private payment-proof images, generated payment receipts, and outgoing receipt emails. Replace example users, paths, and service-manager settings with values appropriate for the server.

### 1. Back up persistent data

Before deploying, back up:

- The production database.
- `storage/app`, including existing public uploads and private parent payment proofs.
- The current `.env` file and any web-server or queue-worker configuration.

Do not remove the old release or its storage directory until the private-proof migration and post-deployment checks have passed.

### 2. Configure the production environment

The live `.env` should include production values similar to:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-live-domain.example

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=180

MAIL_MAILER=resend
MAIL_FROM_ADDRESS=payments@your-live-domain.example
MAIL_FROM_NAME="${APP_NAME}"
RESEND_KEY=your-production-resend-key
RESEND_WEBHOOK_SECRET=your-production-webhook-secret
```

Important:

- `APP_URL` must use the public HTTPS domain so links, QR codes, and email tracking URLs do not point to localhost.
- `DB_QUEUE_RETRY_AFTER` must be greater than the 120-second timeout used by receipt-email jobs. `180` seconds prevents a slow job from being processed twice.
- Configure the production database credentials and never commit the production `.env` file.
- Verify the sending domain and `MAIL_FROM_ADDRESS` with Resend before enabling live email.
- Every club that should receive parent payment proofs must have `club_email` configured. Without it, the submission remains available in the platform and is marked `manual_required`, but no club email is sent.

### 3. Install and build the release

From the application directory:

```bash
php artisan down
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

If deployment uses immutable releases, run these commands in the new release and keep `storage` and `.env` linked to persistent locations.

### 4. Run database and storage migrations

Run the schema migrations before moving legacy proofs because the command writes the new `receipt_image_disk` column:

```bash
php artisan migrate --force
php artisan storage:link
php artisan parent-payments:privatize-proofs
```

`parent-payments:privatize-proofs` is idempotent and may safely be run again. A successful production result must show `Failed: 0`. Investigate every `Missing` entry before deleting the previous deployment or storage backup.

The command moves legacy parent payment proofs from the public disk into `storage/app/private/parent-payment-proofs`, records the `local` disk, and removes the public copy. Do not use `--keep-public` for the final live migration because it leaves sensitive proof images publicly accessible.

The web-server user and queue-worker user need read/write access to `storage` and `bootstrap/cache`. For a traditional Linux deployment, use the server's actual deploy and web-server groups rather than granting world-writable permissions:

```bash
chown -R deploy:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

The public storage symlink remains necessary for intentionally public files such as club logos. Parent payment proofs must never be served through `/storage/...`; they are streamed through authenticated routes.

### 5. Cache the production application

```bash
php artisan optimize:clear
php artisan config:cache
php artisan event:cache
php artisan view:cache
```

This project currently contains closure-based routes, so do not run `php artisan route:cache` or the aggregate `php artisan optimize` command until those routes have been converted to controller actions. Run `optimize:clear` again when changing `.env` values, then rebuild the three safe caches above.

### 6. Run a persistent queue worker

Parent proof emails and generated payment-receipt emails use the database queue. They will remain in the `jobs` table until a worker processes them. Do not rely on running `queue:work` manually in an SSH session.

Example Supervisor configuration:

```ini
[program:club-portal-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/club-portal/artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120 --max-time=3600
directory=/var/www/club-portal
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/club-portal/storage/logs/queue-worker.log
stopwaitsecs=180
```

After installing or changing the Supervisor configuration:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart 'club-portal-worker:*'
```

On each new application release, restart long-running workers so they load the new code:

```bash
php artisan queue:restart
```

Use the hosting platform's worker service instead of Supervisor when one is provided. Keep the same queue, retry, and timeout values.

### 7. Run the Laravel scheduler

The project schedules maintenance tasks such as old finance-export cleanup. Add one cron entry for the web-server user:

```cron
* * * * * cd /var/www/club-portal && php artisan schedule:run >> /dev/null 2>&1
```

Use the hosting platform's scheduler when available.

### 8. Bring the application online

```bash
php artisan queue:restart
php artisan up
```

Then confirm that the HTTPS site, worker, scheduler, database, and Resend integration are healthy.

### 9. Post-deployment verification

Run the focused automated checks before or immediately after deployment against a safe test database:

```bash
php artisan test tests/Feature/ParentPaymentProofPrivacyTest.php
npm run build
```

Verify the live workflow with a controlled test parent, child, and club:

1. Confirm the club has a valid `club_email` and an active deposit account.
2. Log in as the parent and open `/parent/payments`.
3. Upload a JPG, PNG, or WEBP payment proof for a test charge.
4. Open **Ver comprobante** and confirm the real image renders with no placeholder or 404 response.
5. Confirm the proof URL uses `/payment-submissions/{id}/proof`, not `/storage/...`.
6. Confirm an unrelated parent cannot open the proof and receives `403`; an unauthenticated browser should be redirected to login.
7. Confirm the authorized club director or treasurer can open the same proof.
8. Confirm the club receives the parent-payment email with the uploaded image as an actual attachment.
9. Approve the transfer, open **Descargar recibo**, and confirm the PDF renders with the correct club logo, QR code, payment information, and amount.
10. Confirm the parent receives the generated receipt email with the PDF attached.
11. Confirm queued jobs drain and inspect failures:

```bash
php artisan queue:monitor default --max=10
php artisan queue:failed
```

Do not retry failed email jobs until their recipient addresses, attachments, and mail-provider configuration have been checked; retrying can send duplicate messages.

### 10. Parent-payment troubleshooting

- **Proof returns 404:** Check that the database path exists on the disk recorded by `receipt_image_disk` and that the release shares the persistent `storage` directory.
- **Proof returns 403:** Confirm the user owns the parent submission or is an authorized, verified director, staff member, treasurer, or active superadmin for the submission's club.
- **Image opens through `/storage/...`:** Treat this as a privacy regression. Run the privatization command and verify the application is serving protected route URLs.
- **Email status is `manual_required`:** Add a valid `club_email` to the club.
- **Email remains `queued`:** Confirm the queue worker is running and reading the `default` database queue.
- **Email status is `failed`:** Inspect `storage/logs/laravel.log`, `php artisan queue:failed`, Resend credentials, verified sender domain, recipient address, and storage permissions.
- **Receipt PDF has a missing logo:** Confirm `public/storage` is linked and the configured club-logo file exists. The receipt generator uses an initials-based fallback when a club has no uploaded logo.
- **Browser displays an old asset:** Redeploy the Vite build, clear Laravel caches, and invalidate any CDN or reverse-proxy cache.

## Contributing

Pull requests are welcome. For major changes, please open an issue first.

## License

[MIT](https://choosealicense.com/licenses/mit/)
