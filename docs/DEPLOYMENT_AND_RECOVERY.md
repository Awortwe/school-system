# Deployment, workers and recovery

## Before deployment

Complete the school acceptance checks in `PDF_REQUIREMENTS.md`. Verify branding, actual academic policies, fees, staff permissions, salary classifications/statutory profiles, vehicle/driver documents, stock openings and approved signatures. Do not deploy development/demo accounts or sample policies as real school configuration. No production deployment has been performed in this task.

## Runtime and web server

Use the PHP version/extensions required by the locked Composer packages (`composer check-platform-reqs`), Composer, Node/npm for building assets and a supported MySQL/MariaDB installation. Serve only the repository's `public` directory over HTTPS. Never expose the repository root, `.env`, `storage/app/private`, backups or `vendor` directly.

On XAMPP, use an Apache virtual host with `DocumentRoot` pointing at `C:/xampp/htdocs/theSanctuary/public` and allow Laravel's rewrite rules. Keep MySQL running. `php artisan serve --host=127.0.0.1 --port=8000` is a local development server, not a production hosting arrangement.

Install locked dependencies; set `.env` with a dedicated database account and strong application key. Generate a key only for a new installation. Keep the existing key when moving an installation because encrypted salary details depend on it. Configure `APP_ENV=production`, `APP_DEBUG=false`, the correct HTTPS URL, `APP_TIMEZONE=Africa/Accra`, database/mail/session/queue settings, secure cookies and restricted filesystem permissions. Supply secrets outside version control.

Build with `npm ci`, `npm run typecheck`, `npm run build`. Run `composer install --no-dev --optimize-autoloader` for the deployed runtime after development tests have passed. Take a backup, inspect migration status, apply additive migrations, then rebuild configuration/routes/views caches. Restart queue workers after a deployment. Never run `migrate:fresh` or demo seeding on school data.

For a new database, seed roles only, then create the first administrator with the existing secret-prompt command:

```powershell
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan tss:create-admin "School Owner" "owner@example.com"
```

Do not put passwords in command arguments/history. Role/default seeders are for initial setup; review any re-run carefully because role configuration affects access.

## Worker and scheduler

Use the database queue connection for imports and large class PDFs. Keep a monitored worker running:

```powershell
php artisan queue:work --queue=default --tries=1 --timeout=900 --sleep=3
```

Set `DB_QUEUE_RETRY_AFTER=1000` (or a value greater than the worker timeout) so a running report/import job is not released for another worker before its timeout. Restart workers after changing queue configuration.

Install it as a managed service/process using the host's service manager (Windows service wrapper, Supervisor or systemd), with automatic restart and restricted permissions. Do not run a production worker from an unattended interactive terminal. Failed jobs must be reviewed; correct the input/configuration before deliberately retrying. Import rows that succeeded are not rolled back by failures in other rows, so do not blindly retry a completed import.

Configure the host scheduler to run `php artisan schedule:run` every minute from the repository directory. On Windows, use Task Scheduler with the PHP executable, `artisan schedule:run` arguments and this project as Start in. The application does not install OS tasks automatically. Enable `SCHOOL_SCHEDULED_BACKUPS=true` only after a manual backup and recovery drill succeeds.

## Backups

`php artisan tss:backup` invokes the configured MySQL dump tool with a transaction-consistent dump and packages it with public/private uploads in `storage/app/private/backups`. Backups are not recursively included. The command prints the archive path and SHA-256, never credentials. Configure `MYSQLDUMP_PATH` for the actual host.

Set a strong `BACKUP_PASSWORD` in the deployment environment to encrypt archive entries with AES-256. Without it, archives are protected by private filesystem access only. Keep the password and application key in separate secure recovery storage. Copy backups off the application host using a school-approved encrypted backup process. Define retention and monitoring with the school; automatic deletion/retention policy is not assumed.

Private backups were successfully created before the additive local migrations during this task. They are recovery material, not downloadable public artifacts.

## Restore drill and incident recovery

Restore first into a **new, isolated database and separate checkout**, never directly over the live school database. Create the target database/account explicitly. Verify the archive hash, unlock it with the separately stored password and extract into a private recovery directory. Import `database.sql` using a trusted MySQL client targeting that new database. Restore `uploads/private` and `uploads/public` into the separate checkout's corresponding storage directories. Copy the correct original application key through the secure recovery process; do not generate a replacement key.

Point the recovery checkout's environment at the isolated database, disable scheduled jobs/outgoing mail, verify migrations, then test login, counts, sampled students/balances, payment/reversal traces, reports, encrypted salary-detail decryption and private file access. Compare restored data with the archive's manifest and an independent school record. Record the results, recovery time and any gaps.

Only after this drill and a reviewed incident plan should an administrator plan a live cutover. Replacing live data is destructive and requires explicit approval, a fresh live backup, a maintenance window and rollback plan. This task did not overwrite or restore over the live school database, and a production restore drill has not been claimed completed.

## Acceptance checklist

- Run the complete automated suite, TypeScript check and production build.
- Verify each staff role using real permission assignments and direct URL/file access attempts.
- Reconcile sampled invoice/payment/credit/refund/salary/purchase balances against independent calculations and banking evidence.
- Test simultaneous submissions on staging MySQL; SQLite tests do not simulate MySQL row-lock contention/load.
- Inspect approved individual/class PDFs and payslips with school-provided photographs/signatures and representative long names/subject lists.
- Test phones/tablets/keyboards and actual 58/80 mm printer models; record driver/margin settings.
- Test mail delivery/reset links, managed worker restart/failure behavior, scheduled backups, encrypted off-site copies and isolated restoration.
- Obtain school acceptance before calling the system production-ready.
