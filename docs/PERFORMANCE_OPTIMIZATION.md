# Resource review for the shared server

No remote hardware measurements were taken. The following choices are proposals for Site 2; they are not claims about measured RAM savings. Exact worker counts must follow the administrator's resource budget and real process RSS. Site 1's files, ports, pools, databases and settings must remain unchanged.

| Area | Original/current behavior | Recommended Site 2 configuration | Expected benefit | Risks / limits | Administrator approval |
| --- | --- | --- | --- | --- | --- |
| PHP runtime | PDF proposes a persistent `artisan serve` terminal | Existing Nginx and PHP-FPM, new Site 2 pool/socket, HTTP listener 8001 | Production process management; no second development server | Requires installed FPM and a graceful shared service reload | Yes, runtime proposal and enabling configs |
| PHP workers | Server configuration unknown | `ondemand`, initial cap 1, idle timeout 10s, recycle 300 requests | Idle worker exits; cap bounds concurrency | Requests queue during long exports; raise only after measuring headroom | Yes; measure worker RSS and Site 1 headroom |
| FPM account | Shared user not inspected | Dedicated `nssf2` OS user; socket accessible to Nginx | Separates Site 2 runtime/file ownership | Pool alone under www-data would not isolate secrets from other apps using that user | Yes, account/pool/permissions |
| PHP memory | Actual limit unknown | Proposed 128M per request, verified against DOCX/ZIP exports | Upper bound on individual request allocations | Large exports may need a measured adjustment; request memory is not process RSS | Yes, pool settings |
| Composer | Development packages installed locally | `composer install --no-dev --prefer-dist --optimize-autoloader` from the committed lock | Smaller deployed dependency set, optimized autoload | Do not copy Windows vendor binaries to Linux or run a broad production update | Installation is manual in Site 2 directory |
| Laravel boot | Cached state unknown | Config, route and view caches after environment/migrations | Reduces repeated boot filesystem work | Cache must be regenerated after configuration changes; never transfer local config cache | No infrastructure change; operator review |
| Session/cache | Config defaults database; PDF unspecified | File sessions/cache within Site 2 storage, distinct cookie/prefix | No Redis service; less PostgreSQL work for non-business state | Single-server proposal; disk growth/permission errors; not shared across replicas | No, application settings only |
| Queues | Database default; no dispatched application jobs found | `QUEUE_CONNECTION=sync` | No persistent queue worker needed for current code paths | Newly introduced asynchronous jobs require a reviewed queue plan | No, preserve current synchronous tasks |
| Deadlines | Daily 06:00 Laravel schedule; chunked activity processing | One cron entry for this application's scheduler | Deadline notifications continue without a scheduler daemon | Server clock/timezone/cron need verification | Yes, adding Site 2 cron |
| Database indexes | Existing performance indexes; hand-written catalog inspection in September 9 migration | Portable schema index inspection; preserve named indexes | Avoid failed migrations and duplicate equivalent definitions | Index creation may lock tables on an existing large database | Schema migration review |
| Dashboard queries | Several bulk/group queries already avoid N+1; role-specific queries remain | Preserve bulk gate aggregation and existing short-lived frontend project cache | Maintains prior query improvements and fresh workflow decisions | Portfolio-wide arrays still grow with all projects; no fabricated benchmark | No |
| `/me` | Repeated auth serialization and permission queries | Serialize auth data once | Fewer repeated queries on refresh | No shared authorization cache, so revocations remain live | No |
| Audit flags | Every page loaded/grouped all failed login history | Scan only relevant users/time range with cursor and 15-minute window | Less PHP object retention and less unrelated history scanned | PostgreSQL cursor/PDO may buffer raw result rows; still test growing portfolios | No |
| List/report pagination | Audit list supports up to 5000 rows; projects/users/portfolio dashboards return full lists | Keep API contracts; measure actual portfolio before jointly adding server/client pagination | Avoids breaking existing selectors, exports and role queues | Large portfolios remain a real memory/payload risk; test realistic sizes | Product review for coordinated pagination |
| Frontend runtime | Standalone Vite SPA | Build on the laptop; deploy `dist` only; no Node on server | Avoids server build RAM and node_modules storage | API URL is embedded; changing it requires rebuilding | No runtime service installation |
| JS loading | Lazy routes; PDF/Excel imports already dynamic; Ant Design chunk | Preserve existing splitting and export features | Large export bundles load on demand | Large library chunks remain; replacing export/UI libraries would alter features | No |
| HTTP assets | PDF has minimal SPA config | Hashed assets cached for one year, index.html revalidated, gzip level 2 | Less repeat download traffic and moderate compression CPU | Missing assets return 404, not index.html; old assets retained through releases | Yes, Site 2 Nginx config |
| Logs | Single/debug config default; security audit rows retained | Daily warning application logs with 7 files, Site 2 Nginx log rotation | Bounds routine application log retention | Rotation does not purge database audit/history; retention requires a business policy | Yes for Nginx rotation; policy approval for data retention |
| PostgreSQL | Shared service, no measurements | Separate Site 2 owner/database, nonpersistent PDO connections, existing autovacuum | Isolation without another database service or connection pooler | Shared memory/connection budget must be inspected; CLI tasks also need connections | Yes for new DB/user; global changes excluded |
| OPcache | Windows CLI has OPcache; production FPM unknown | Inspect existing FPM OPcache before changes | Existing bytecode cache can reduce repeated compilation | OPcache memory is shared across pools; do not resize globally for Site 2 | Yes for any shared setting |

## Measured local build

The production build was actually executed on Windows with Node 26.7.0 and installed Vite 8.2.1. Total `frontend/dist` at this audit: 3,984,011 bytes across 94 files (including static images), without source maps.

| Emitted resource | Uncompressed build report | Gzip build report |
| --- | --- | --- |
| ExcelJS, lazy import | 1,056.38 kB | 246.96 kB |
| html2pdf, lazy import | 935.51 kB | 265.60 kB |
| Main index JS | 122.48 kB | 38.35 kB |
| Main CSS | 36.22 kB | 8.32 kB |

Vite emits warnings for chunks over 500 kB and ExcelJS's direct eval. They are real warnings, not suppressed checks. Route dependencies are now split naturally rather than forced into an Ant Design chunk. Large export bundles mainly affect browser startup/downloads; they do not require a Node server. Existing export libraries remain because replacing them would risk spreadsheet/PDF functionality. The login background is 235,948 bytes; there was no need to regenerate the design or aggressively transcode it.

## Server measurements (Linux SSH session, operator only)

```bash
cd /var/www/nssf-project-mgmt-2
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
psql --version
sudo -u postgres psql -c 'SHOW server_version;'
nginx -v
systemctl list-units '*fpm*'
sudo ss -ltnp
sudo ss -lxnp
du -sh storage public /var/www/nssf-frontend-2
```

Use the discovered FPM executable/version to inspect its configuration. `php --ini` shows CLI configuration, which can differ from FPM. Do not paste complete `nginx -T`, `.env`, database connection strings or process environments into a public report; they can contain secrets.

Within a confirmed Site 2 database, the administrator can inspect connections and storage:

```sql
SELECT current_database(), version();
SELECT datname, count(*) FROM pg_stat_activity GROUP BY datname;
SELECT pg_size_pretty(pg_database_size(current_database()));
SHOW max_connections;
SHOW shared_buffers;
SHOW work_mem;
SHOW maintenance_work_mem;
SELECT relname, n_live_tup, n_dead_tup, last_autovacuum
FROM pg_stat_user_tables ORDER BY n_dead_tup DESC;
```

Do not expose PostgreSQL 5432 publicly. Do not change shared_buffers, work_mem, autovacuum or max_connections merely because this guide mentions them. A small Site 2 FPM cap plus occasional migration/scheduler connections will usually be simpler than installing PgBouncer, Redis or a new supervisor; validate the actual available connection slots first.

Measure warmed Site 2 workers under login/dashboard/upload/export load, allocate a budget after Site 1 and PostgreSQL headroom, then calculate a conservative `pm.max_children` from measured worker RSS. Shared OPcache/RSS accounting can overlap; do not add process RSS values as an exact total. There are no measured server throughput or memory savings in this report.

For index/query changes, use `EXPLAIN` against representative Site 2 queries and restored test data. `EXPLAIN ANALYZE` actually executes a query, so use it only for reviewed read-only queries. Retain autovacuum and normal database backups. Large exports, file processing and growth of audit/history tables remain part of capacity planning.

Reference: [PHP-FPM pool options](https://www.php.net/manual/en/install.fpm.configuration.php), [Laravel deployment](https://laravel.com/docs/12.x/deployment), and [PostgreSQL schema permissions](https://www.postgresql.org/docs/16/ddl-schemas.html). The worker values above are application-specific proposals, not values prescribed by those documents.
