# Local setup

This staff-only Laravel/MySQL/React/Inertia application implements the modules in the supplied PDF. Read the [coverage and remaining acceptance checks](PDF_REQUIREMENTS.md) and [staff guide](STAFF_GUIDE.md).

## Existing XAMPP installation

Preserve `.env`, the application key and school database. Start MySQL from XAMPP. In PowerShell, open this project directory and run:

```powershell
Set-Location C:\xampp\htdocs\theSanctuary
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Open [the staff login](http://127.0.0.1:8000/login). Keep that terminal open. Built browser assets are in `public/build`; rebuild after source changes with `npm.cmd run build`. For live frontend development, run `npm.cmd run dev` in a second terminal while Laravel remains running.

Imports and large class report jobs need a separate worker terminal:

```powershell
C:\xampp\php\php.exe artisan queue:work --queue=default --tries=1 --timeout=900 --sleep=3
```

Set `DB_QUEUE_RETRY_AFTER=1000` or higher than the worker timeout. Failed jobs require review before retrying. Production workers need a managed service, as described in the deployment guide.

Alternatively, configure an Apache virtual host with its document root pointing to this project's `public` directory, enable rewrite support and use that host's URL. Never serve the project root: it contains private configuration and storage.

Use an existing authorised staff account. The labelled local demo accounts are `headteacher@demo.thesanctuary.test`, `accountant@demo.thesanctuary.test` and `teacher@demo.thesanctuary.test`, with development-only password `Password@123`, unless subsequently changed. Remove or disable demo accounts before production use. The existing owner account is preserved; no password was reset.

## Verification

```powershell
npm.cmd run typecheck
npm.cmd run build
C:\xampp\php\php.exe artisan test
```

Tests force a disposable SQLite in-memory database. The running application uses configured MySQL. Tests do not reset school data. MySQL concurrency/load acceptance is a separate deployment check.

## New installation or deployment

Follow [deployment and recovery](DEPLOYMENT_AND_RECOVERY.md) for dependencies, `.env`, additive migrations, role setup, secure administrator creation, HTTPS/mail, workers, scheduling, backups and isolated restoration. Never run `migrate:fresh` or demo seeding on existing school records. Keep actual salaries, route prices and stock values empty until the school supplies them.
