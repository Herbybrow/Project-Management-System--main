# Manual Site 2 deployment guide

This guide adapts all three pages of PMM2.pdf to the actual repository. Deployment is manual. No remote command in this guide has been executed during the audit. Review the local changes and [AUDIT_RESULTS.md](AUDIT_RESULTS.md) before release. Local release validation currently passes: 124 SQLite tests, 124 isolated PostgreSQL tests, frontend cache tests, a production build and full lint with 0 errors/39 existing migration warnings.

The frontend already lives in `frontend/`. Laravel lives at the repository root, not in `backend/`. Keep Site 1 on ports 80 and 8000 untouched. Site 2 uses frontend 8080, backend 8001 and a separate database on the existing PostgreSQL service, port 5432.

The administrator must approve the proposed Nginx/PHP-FPM backend, resource allocation, new OS account, new virtual hosts and graceful shared service reloads. This replaces the PDF's development-server runtime while keeping its port allocation. No `/api` proxy/topology change is enabled. HTTPS or an approved protected transport is required before sending real passwords/tokens or business documents over the public network; the HTTP examples match the requested arrangement for initial restricted verification only.

## Part A - Local preparation

### Windows Command Prompt (CMD), repository root

```bat
cd /d "C:\Users\user\Desktop\Project-Management-System--main"
git branch --show-current
git status --short
git diff --check
git diff
php -v
php -m
composer validate --strict --no-check-publish
composer install
composer check-platform-reqs
php vendor\phpunit\phpunit\phpunit
```

Use the checked-in lockfiles. `composer install` installs development dependencies on your laptop; production uses `--no-dev`. Do not run the generic `composer setup` script: it can change APP_KEY, run migrations and invoke the unrelated root Vite build. Review Git changes yourself; the audit does not commit or push. Before using `git archive`, manually commit the intended release through your normal review process, otherwise the archive omits uncommitted fixes.

The main test command forces an in-memory SQLite database and rejects your real local database. PostgreSQL tests are described in [POSTGRESQL_MIGRATION.md](POSTGRESQL_MIGRATION.md); they require the separate temporary cluster on 25432. Neither command resets your normal database.

### Local PostgreSQL

For normal development, create a separate local PostgreSQL role/database using your own administrator account, then manually configure your existing `.env` with the PostgreSQL fields in `.env.example`. Never copy an example over an existing `.env`. If this is a completely new checkout, copy `.env.example` only when `.env` is absent, then enter local credentials yourself.

```bat
cd /d "C:\Users\user\Desktop\Project-Management-System--main"
rem Check .env points to your intended LOCAL database before these commands.
php artisan migrate:status
php artisan migrate
rem Optional: ONLY a disposable development database with APP_ENV=local.
php artisan db:seed --class=DemoSeeder
```

Do not run migrate:fresh, db:wipe or demo:purge on business data. A partially seeded database should be inspected, not automatically erased. Default `db:seed` creates roles/permissions only. On an existing app, keep APP_KEY unchanged; a new empty checkout can generate its initial key with `php artisan key:generate`.

### Windows CMD, frontend directory

Use Node satisfying `frontend/package.json` (22.12+ on the 22 line, or 24+). Node 26.7.0 was used for this audit; Node need not exist on the server.

```bat
cd /d "C:\Users\user\Desktop\Project-Management-System--main\frontend"
npm ci
npm run lint
npm test
set "VITE_API_BASE_URL=http://147.182.164.148:8001/api"
npm run build
set "VITE_API_BASE_URL="
dir dist
```

The full lint check currently passes with 0 errors and 39 existing React Compiler/Fast Refresh migration warnings; see the audit results. The production build is a separate check and is tested. Set the intended administrator-approved HTTPS API address instead once protected transport is configured; an HTTPS frontend cannot call an HTTP API without mixed-content blocking.

Alternatively create `frontend/.env.production` from `frontend/.env.production.example`, only if you do not already have it. It contains a public build URL, never secrets. Vite embeds this value at build time, so changing a server `.env` does not update the SPA. `VITE_API_URL` remains accepted for old local environments, while `VITE_API_BASE_URL` takes precedence for both API calls and file downloads. Production builds require an explicit API base URL.

### Windows PowerShell equivalent for the build only

```powershell
Set-Location 'C:\Users\user\Desktop\Project-Management-System--main\frontend'
$env:VITE_API_BASE_URL = 'http://147.182.164.148:8001/api'
npm.cmd run build
Remove-Item Env:VITE_API_BASE_URL
```

Do not use `VITE_API_BASE_URL=... npm run build` in CMD; that line in the PDF is Unix syntax.

## Part B - SSH access

### Windows CMD

Create a dedicated key name so an existing id_ed25519 key is not overwritten. If the chosen file exists, use a different new name. Choose a passphrase at the prompt and use ssh-agent if desired. No key was generated by the audit.

```bat
cd /d "%USERPROFILE%"
if not exist "%USERPROFILE%\.ssh" mkdir "%USERPROFILE%\.ssh"
dir "%USERPROFILE%\.ssh\nssf_site2_ed25519*"
ssh-keygen -t ed25519 -f "%USERPROFILE%\.ssh\nssf_site2_ed25519" -C "nssf-site2"
type "%USERPROFILE%\.ssh\nssf_site2_ed25519.pub"
```

The `.pub` file is the public key: you manually send only that file/content to the administrator, who adds it to edward's authorized_keys with appropriate permissions. The file without `.pub` is the private key: keep it on your laptop, never send it, never commit it and never paste it into chat. The PDF's empty-passphrase command is optional convenience, not a requirement to overwrite an existing key.

After the administrator confirms authorization and provides the expected host-key fingerprint:

```bat
cd /d "%USERPROFILE%"
ssh -i "%USERPROFILE%\.ssh\nssf_site2_ed25519" edward@147.182.164.148
```

Verify the fingerprint before accepting the first connection. Do not bypass a changed-host-key warning. The following sections explicitly label Linux commands; they run inside the SSH session, not CMD.

## Part C - Server inspection

### Linux SSH session, read-only inspection

```bash
cd /var/www
whoami
id
pwd
cat /etc/os-release
free -h
swapon --show
nproc
lscpu
df -h / /var/www /var/lib/postgresql
df -i / /var/www
ps -eo pid,user,comm,rss,%cpu --sort=-rss | head -25
systemctl list-units --type=service --state=running
php -v
php -m
php --ini
composer --version
psql --version
nginx -v
systemctl list-units '*fpm*'
sudo ss -ltnp
sudo ss -lxnp
ls -ld /var/www/nssf-frontend-2 /var/www/nssf-project-mgmt-2
```

If a directory does not exist yet, ls reports that; do not compensate by changing another site's directory. Identify Site 1's existing ports/pools/virtual hosts with the administrator. Confirm 8080 and 8001 are free and will be allowed by the approved firewall. Confirm PostgreSQL remains private/local. No ports 80/8000 are allocated to Site 2.

PHP must support Laravel 12 and the installed lockfile: PHP 8.2+, PDO, pdo_pgsql, mbstring, fileinfo, XML/DOM/XMLWriter, OpenSSL, tokenizer, ZIP and the other platform requirements from `composer check-platform-reqs --no-dev`. Native mbstring is recommended despite Composer polyfills. Check **FPM's** extensions/configuration as well as CLI PHP; they can differ. Discover the actual FPM version/service/pool directory, rather than assuming 8.2.

The administrator may inspect `nginx -T` and FPM configuration locally, but do not paste secret-bearing full output into reports. Record resource measurements and an explicit Site 2 budget using [PERFORMANCE_OPTIMIZATION.md](PERFORMANCE_OPTIMIZATION.md). Do not resize PostgreSQL, OPcache or Site 1's pools globally.

## Part D - Database setup

### Linux SSH session, administrator

```bash
cd /var/www
sudo -u postgres psql
```

### PostgreSQL psql prompt

First inspect version/ownership and whether these names already exist:

```sql
SHOW server_version;
\du
\l
```

For a **new**, empty Site 2 allocation only, enter the statements from `deployment/postgresql/site2-setup.sql`:

```sql
\set ON_ERROR_STOP on
CREATE ROLE site2_user LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
\password site2_user
CREATE DATABASE site2_db OWNER site2_user ENCODING 'UTF8';
\connect site2_db
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO site2_user;
\q
```

`\password` asks privately, avoiding a password literal in SQL or shell history. Do not rerun CREATE commands if the role/database already exists; ask the administrator to verify existing ownership and schema permissions. Database-level grants alone do not guarantee permission to create tables in `public`. No superuser privileges are needed for the application user. These schema commands operate only inside site2_db.

### Linux SSH session, connection verification

```bash
cd /var/www
psql -h 127.0.0.1 -p 5432 -U site2_user -d site2_db -W -c 'SELECT current_user, current_database();'
```

Enter the password at the prompt. Have the administrator confirm the existing local pg_hba authentication rule permits the role; do not replace shared rules or enable trust. Do not expose port 5432 publicly. If converting an existing MySQL business database, stop before migrations/seeders and follow the separately rehearsed import/reconciliation process in POSTGRESQL_MIGRATION.md.

## Part E - Backend deployment

### Linux SSH session, new Site 2 directories only

These account/permission examples assume Debian/Ubuntu; the administrator must adapt them to the discovered OS and approve them. The proposed FPM pool uses a dedicated `nssf2` user/group. If it already exists, verify ownership before proceeding.

```bash
cd /var/www
sudo adduser --system --group --no-create-home nssf2
sudo usermod -a -G nssf2 edward
sudo install -d -o edward -g nssf2 -m 755 /var/www/nssf-project-mgmt-2
sudo install -d -o edward -g www-data -m 755 /var/www/nssf-frontend-2
```

The new group applies to fresh login sessions: reconnect over SSH before depending on it. Only the new Site 2 group is added; existing groups are preserved. The administrator should verify this fits the server's ownership policy.

For a new, empty backend directory, manually clone your reviewed release. Replace the token `REPOSITORY_URL` with your actual repository URL and `RELEASE_COMMIT` with your approved commit hash. Do not put access tokens in command-line URLs.

```bash
cd /var/www/nssf-project-mgmt-2
git clone REPOSITORY_URL .
git switch --detach RELEASE_COMMIT
git status --short
```

Do not clone over an existing deployment. An alternative is a reviewed source archive produced from your own committed release:

```bat
rem Windows CMD, repository root; git archive includes COMMITTED files only.
cd /d "C:\Users\user\Desktop\Project-Management-System--main"
git archive --format=tar.gz -o "%TEMP%\nssf-backend-2.tgz" HEAD
scp -i "%USERPROFILE%\.ssh\nssf_site2_ed25519" "%TEMP%\nssf-backend-2.tgz" edward@147.182.164.148:/var/www/nssf-project-mgmt-2/
```

Extract only in a confirmed new empty Site 2 directory or a prepared new release directory. The committed `.gitattributes` excludes `frontend/` from this backend `git archive`; frontend is packaged separately from `frontend/dist` in Part F. Never transfer your local `.env`, vendor, node_modules, tmp test cluster, cached config/routes, logs or private uploaded files as source code. The server's storage/backups are managed separately.

### Linux SSH session, Laravel project root

```bash
cd /var/www/nssf-project-mgmt-2
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
test -f .env || cp deployment/.env.production.example .env
nano .env
```

`test -f` preserves an existing `.env`. Enter Site 2's database password and real mail settings manually. Required values include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://147.182.164.148:8001
APP_TIMEZONE=UTC
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=site2_db
DB_USERNAME=site2_user
DB_PASSWORD="<SET_MANUALLY>"
CORS_ALLOWED_ORIGINS=http://147.182.164.148:8080
SESSION_DRIVER=file
SESSION_COOKIE=nssf_site2_session
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
SANCTUM_TOKEN_EXPIRATION=720
```

Quotes protect passwords containing spaces or `#`. Do not leave the placeholder as a real password. Check DB_URL is absent or unset: it can override individual DB fields. APP_URL is the backend URL. CORS must list the exact frontend origin, including its port. If HTTPS is approved, update both URLs and CORS and set SESSION_SECURE_COOKIE=true. The bearer flow does not use cross-origin session cookies, credentialed Axios requests or CSRF-cookie calls; avoid enabling stateful Sanctum settings piecemeal.

Generate APP_KEY **only for a new empty deployment with no existing key**:

```bash
cd /var/www/nssf-project-mgmt-2
php artisan key:generate
```

For an update or restoration, preserve the existing key from the protected server `.env`/backup. Rotating it can invalidate encrypted state. Never print it into a deployment report.

Set permissions on Site 2 only, after Composer has finished. Do not run chown/chmod against `/var/www`, `/etc` or Site 1 recursively.

```bash
cd /var/www/nssf-project-mgmt-2
sudo chgrp -R nssf2 /var/www/nssf-project-mgmt-2
sudo find /var/www/nssf-project-mgmt-2 -type d -exec chmod 750 {} +
sudo find /var/www/nssf-project-mgmt-2 -type f -exec chmod 640 {} +
sudo chmod 755 /var/www/nssf-project-mgmt-2 /var/www/nssf-project-mgmt-2/public
sudo find /var/www/nssf-project-mgmt-2/public -type d -exec chmod 755 {} +
sudo find /var/www/nssf-project-mgmt-2/public -type f -exec chmod 644 {} +
sudo chmod 640 /var/www/nssf-project-mgmt-2/.env
sudo chmod -R g+rwX /var/www/nssf-project-mgmt-2/storage /var/www/nssf-project-mgmt-2/bootstrap/cache
sudo find /var/www/nssf-project-mgmt-2/storage /var/www/nssf-project-mgmt-2/bootstrap/cache -type d -exec chmod g+s {} +
```

The deploy user owns code; nssf2 can read code and write storage/cache. Nginx only needs to traverse and read public content; it passes PHP execution to the nssf2 socket. Preserve executable permissions on any intentionally executed shell/binary tools rather than indiscriminately running them after this normalization. When moving to release directories, repeat the approved ownership policy and preserve the shared private storage path.

There is no need to publish private documents via `storage:link`: document downloads go through the authenticated API. If future public assets genuinely require the public disk, link only `storage/app/public`; never expose `storage/app/private`. Keep private backups outside either web root.

Before any migration on an existing Site 2 database, take and verify a backup as described in Part H. For a reviewed new deployment:

```bash
cd /var/www/nssf-project-mgmt-2
php artisan config:clear
php artisan migrate:status
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan users:bootstrap-admin
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Reference seeding synchronizes canonical permissions for current roles; review any existing custom role grants first. It does not create demo accounts or delete retired role assignments. The administrator command privately requests a unique real email/name/password and will not reset an existing account. Use the application to create ICT Support and workflow accounts afterward. Do not run DemoSeeder, UserBulkSeeder, ProjectSeeder or UserSeeder on production.

### PHP-FPM and backend Nginx proposal

The administrator reviews `deployment/php-fpm/nssf-site2.conf`, measures memory and substitutes the discovered paths/version below. One on-demand worker is a conservative starting proposal; increase it only after measuring available headroom and export contention. A new nssf2 pool does not require changing Site 1's pool. The HTTP backend listener remains 8001; it runs Laravel through a Unix FPM socket, not `artisan serve`.

For a confirmed PHP 8.2 Debian/Ubuntu installation **only**, administrator-approved commands are:

```bash
cd /var/www/nssf-project-mgmt-2
sudo test ! -e /etc/php/8.2/fpm/pool.d/nssf-site2.conf
sudo cp deployment/php-fpm/nssf-site2.conf /etc/php/8.2/fpm/pool.d/nssf-site2.conf
sudo php-fpm8.2 -t
sudo test ! -e /etc/nginx/sites-available/nssf-backend-2
sudo cp deployment/nginx/nssf-backend-2.conf /etc/nginx/sites-available/nssf-backend-2
sudo ln -s /etc/nginx/sites-available/nssf-backend-2 /etc/nginx/sites-enabled/nssf-backend-2
sudo nginx -t
```

Run each command separately and stop on any failed guard/validation; do not follow a failed `test ! -e` by copying over an existing file. On another PHP version replace the FPM version/path consistently. Do not change a service definition to php-fpm8.2 merely to match this example. The administrator then authorizes and performs graceful reloads after both frontend/backend configurations validate:

```bash
# Administrator only; service name must match the actual installation.
sudo systemctl reload php8.2-fpm
sudo systemctl reload nginx
sudo ss -lxnp
```

Shared services can affect Site 1 even during a reload, so monitor it and keep previous Site 2 configs. If FPM is unavailable, agree on a supported production runtime with the administrator; do not permanently substitute an unmonitored `php artisan serve` process. Do not install another web stack without reviewing its resource cost.

### Scheduler (administrator-approved cron)

The deadline feature needs a scheduler. Add one Site 2-only entry, preferably to nssf2's crontab so generated files retain pool permissions. Do not replace the existing crontab or Site 1 entries.

```cron
* * * * * cd /var/www/nssf-project-mgmt-2 && /usr/bin/php artisan schedule:run >> /var/www/nssf-project-mgmt-2/storage/logs/scheduler.log 2>&1
```

Verify `/usr/bin/php` is the correct PHP binary. Configure rotation for scheduler and Nginx logs with the administrator. The command is scheduled at 06:00 in APP_TIMEZONE (UTC in the examples); to run it at 06:00 Tanzania time while keeping storage timestamps in UTC, separately approve a timezone on that scheduled event instead of changing stored date semantics. Do not add a persistent queue worker: none is needed by the current synchronous application.

## Part F - Frontend deployment

### Windows CMD, frontend

The build is done in Part A. Package all dist contents, including static images and hashed chunks:

```bat
cd /d "C:\Users\user\Desktop\Project-Management-System--main\frontend"
tar -czf "%TEMP%\nssf-frontend-2.tgz" -C dist .
scp -i "%USERPROFILE%\.ssh\nssf_site2_ed25519" "%TEMP%\nssf-frontend-2.tgz" edward@147.182.164.148:/var/www/nssf-frontend-2/
```

### Linux SSH session, Site 2 static root

On a new root, extract the reviewed archive. On updates, first back up the previous frontend and retain existing hashed assets as described in Part I.

```bash
cd /var/www/nssf-frontend-2
tar -xzf nssf-frontend-2.tgz
find /var/www/nssf-frontend-2 -type d -exec chmod 755 {} +
find /var/www/nssf-frontend-2 -type f -exec chmod 644 {} +
```

The uploaded archive is not needed in the web root afterward; move it to a protected Site 2 release archive directory (confirmed path) or remove it manually after review. Do not put backend source archives, `.env` or backups here.

### Linux SSH session, administrator reviews frontend Nginx

```bash
cd /var/www/nssf-project-mgmt-2
sudo test ! -e /etc/nginx/sites-available/nssf-frontend-2
sudo cp deployment/nginx/nssf-frontend-2.conf /etc/nginx/sites-available/nssf-frontend-2
sudo ln -s /etc/nginx/sites-available/nssf-frontend-2 /etc/nginx/sites-enabled/nssf-frontend-2
sudo nginx -t
```

Run commands individually, stop if the target already exists, and review that Site 2 file instead of overwriting it. The template listens only on 8080, uses the intended static directory, supports SPA refresh/deep links, revalidates index.html, caches hashed assets and returns 404 for missing assets. Its headers do not attempt to add a CSP that could break existing export libraries. After successful validation the administrator performs the coordinated Nginx reload described in Part E.

## Part G - Verification

### Linux SSH session, read-only HTTP probes

```bash
cd /var/www/nssf-project-mgmt-2
curl -I http://127.0.0.1:8080/
curl -I http://127.0.0.1:8080/projects
curl -I http://127.0.0.1:8080/assets/does-not-exist.js
curl -i http://127.0.0.1:8001/up
curl -i -H 'Accept: application/json' http://127.0.0.1:8001/api/me
curl -i -X OPTIONS http://127.0.0.1:8001/api/documents -H 'Origin: http://147.182.164.148:8080' -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: authorization,content-type'
php artisan migrate:status
php artisan schedule:list
free -h
df -h /var/www
ps -eo pid,user,comm,rss,%cpu --sort=-rss | head -25
```

Expect frontend/deep link HTML 200, missing asset 404, `/up` 200, unauthenticated `/api/me` 401, and a permitted CORS preflight. `/up` checks Laravel boot, not database connectivity. Verify the PostgreSQL read-only queries in POSTGRESQL_MIGRATION.md as site2_user. No passwords or bearer tokens belong in shell history or logs.

### Browser checklist, protected Site 2 endpoints

1. Homepage/login loads its logo and building image; no requests point to localhost:8000 or Site 1.
2. Login succeeds with a real newly initialized account; wrong passwords and disabled accounts fail; `/me` returns only explicitly assigned active roles.
3. Logout revokes the current token; refreshing a protected route returns to login. Expired tokens also require login.
4. Open each assigned role dashboard and verify counts. Changing `role` to an unassigned administrator role must return 403.
5. Reviewer registers a project, uploads both initiation documents and advances it to planning; confirm PostgreSQL rows and uploader identity.
6. Assigned planner adds/edits activities and requirements, uploads deliverables and submits a plan. Another planner cannot alter their matrix/project activities.
7. Reviewer approves/returns the plan and matrix; coordinator/approver actions follow the actual category/track gates. ViewOnly mutations fail.
8. Planner requests closure only after readiness gates; reviewer signs off. Closed projects reject further writes, including reassignment.
9. Upload PDF/DOCX/XLSX within 10 MiB, replace/version it, then view/download it while logged in; verify unauthenticated access fails. An activity/requirement from another project must not attach to this upload.
10. Download a closed-project ZIP and DOCX report, and exercise PDF/Excel browser exports with representative large data.
11. Refresh `/projects`, a selected project link, `/reviews`, `/reports`, `/notifications`, `/user-management` and `/audit-log` as the relevant roles; inspect browser console/network errors and CORS.
12. Verify private files and `.env` are unreachable directly, and the backend root is `public/` only.
13. Verify deadline notifications after the configured daily scan; check cron logs rather than relying on the existing dashboard's `scheduler_running=true` label, which is not a real health probe.
14. Monitor RAM/disk and Site 1 after enabling Site 2, then after a realistic login/dashboard/export load. Validate the chosen FPM cap and upload limits under that load.

The local automated suite cannot certify real Nginx/FPM permissions, Linux file paths, firewall rules, protected transport or shared server capacity. Record the completed server checklist before declaring production ready.

## Part H - Rollback

### Before each existing Site 2 release (Linux SSH session)

Use a protected backup directory outside the web roots. Have the administrator allocate its permissions and enough disk. Replace the sample filename with a unique release/timestamp. Backups contain sensitive business records; use your organization's protected backup storage and retention policy.

```bash
cd /var/www/nssf-project-mgmt-2
git rev-parse HEAD
pg_dump -h 127.0.0.1 -p 5432 -U site2_user -d site2_db -W -Fc -f /var/backups/nssf-site2/site2-before-release.dump
pg_restore --list /var/backups/nssf-site2/site2-before-release.dump
sudo tar -czf /var/backups/nssf-site2/storage-before-release.tgz storage/app/private
tar -czf /var/backups/nssf-site2/frontend-before-release.tgz -C /var/www/nssf-frontend-2 .
```

The administrator creates `/var/backups/nssf-site2` for the deploy account with restrictive directory permissions before these commands; stop if permission/disk checks fail. The private-file archive uses sudo because Flysystem may create files readable only by nssf2. Copy the encrypted/protected `.env` and current Site 2 Nginx/pool configuration through your approved backup procedure without printing secrets. Verify database backups by restoring into a **new disposable database** and comparing schema/counts, not merely listing the archive. A logical DB dump and private files must represent a consistent release; use Site 2 maintenance mode or a coordinated write pause for a consistent snapshot.

For an additive schema release, the first rollback choice is the previous reviewed code/frontend with the new schema retained, if compatibility was rehearsed. Keep the current `.env`, APP_KEY and private uploaded files. Reinstall the previous Composer lock, recache config/routes/views, and ask the administrator whether an FPM reload is needed for OPcache. Do not blindly run migrate:rollback; the historical duplicate token/date migrations have ownership ambiguities and can drop columns/tables still needed.

A failed PostgreSQL migration may roll back transactionally, but inspect migration status and schema first; do not edit migration history or reseed data to hide the failure. Resolve through a reviewed corrective migration or a known-good release.

Database restoration is potentially destructive and requires explicit operator/administrator approval. Prefer restoring into a **new** Site 2 recovery database, verifying it, and switching only Site 2's DB_DATABASE during maintenance. Example operator procedure after approving a new recovery allocation:

```bash
# Administrator creates site2_recovery owned by site2_user; never Site 1's DB.
pg_restore -h 127.0.0.1 -p 5432 -U site2_user -d site2_recovery -W --no-owner --no-privileges /var/backups/nssf-site2/site2-before-release.dump
```

Verify the restore before switching `.env`, clear/recache configuration and test in maintenance. Recovery to a backup loses writes after that snapshot unless reconciled; obtain that decision explicitly. Restore matching private files only after preserving newer uploads. No `DROP DATABASE`, `--clean`, automatic reset or overwrite of the live database is part of the normal sequence.

Restore frontend assets from the protected archive into a prepared Site 2 release directory, then switch the Site 2 root/symlink after verification, or perform the reviewed in-place restoration during Site 2 maintenance. Preserve old hashed assets used by already-open tabs until the release is stable. Roll back only Site 2 virtual hosts/pool and validate before any administrator-approved reload. Do not roll back global service configuration or change Site 1 ports.

## Part I - Future updates

1. Review and commit your intended changes locally; build and test that exact release using both lockfiles. Rebuild frontend whenever its source, dependencies or embedded API URL changes.
2. Inspect the server budget/disk and ensure verified Site 2 DB/private-file/config/frontend backups exist. Confirm the previous release remains available.
3. Prepare a new Site 2 release directory or use a short approved maintenance window for in-place updates. `php artisan down` affects this application only; static SPA remains visible but API calls return maintenance responses. Exempt health probes only if approved.
4. For Git-managed releases, fetch your remote and check out the reviewed release commit manually; do not apply unreviewed merges or reset away local changes. Preserve `.env`, APP_KEY and storage. Do not use `git clean` on an active deployment.
5. Install the exact production Composer lock. Clear stale config cache before new environment-sensitive migrations. Run `migrate:status`; apply only reviewed pending migrations with `migrate --force` after backup. No migrations are required if there are no pending schema changes.
6. Seed reference roles only when reviewed reference definitions need updates; never reseed production demo accounts. New production users are managed through the UI.
7. Recache config, routes and views; transfer the corresponding frontend assets. Copy hashed assets first and replace index.html last. Retain old chunks for open browser sessions; clean stale files later under a reviewed Site 2-only retention plan.
8. PHP source updates may require an administrator-approved FPM reload if OPcache timestamps are disabled. Nginx reload is needed for configuration changes, not ordinary static-file changes. Never restart shared services merely to update SPA assets.
9. Run the verification checklist, then `php artisan up` if maintenance was used. Monitor Site 2 and Site 1. If verification fails, keep Site 2 in maintenance and follow Part H.

An atomic release-directory/symlink strategy reduces downtime but requires coordinated Nginx roots, storage symlinks, permissions and OPcache behavior. Agree on it with the administrator instead of changing topology during the first deployment. The included templates use the administrator's fixed directories for a straightforward reviewed first deployment.

## Final operator sequence

The exact shell-specific commands appear in the sections above. In order: review/commit your local release; install/test/build locally; obtain SSH and the runtime/transport/resource approvals; inspect server and available ports; allocate Site 2 role/database and directories; install Laravel/production dependencies; configure secret environment and initial APP_KEY; back up existing data if any; apply safe migrations/reference data; initialize a new administrator; configure the isolated FPM pool and the two Site 2 Nginx listeners; transfer dist; validate both services; have the administrator reload gracefully; add approved scheduler/log rotation; complete browser/database/resource checks; retain a tested rollback release. Stop at any failed check. Do not deploy simply because local tests pass.
