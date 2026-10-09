# PostgreSQL migration and seeder integrity

## Changes made

`config/database.php` defaults to `pgsql`; `.env.example` and the production example use PostgreSQL. The existing local `.env` is preserved. Explicit Composer PDO/PostgreSQL/ZIP requirements prevent a deployment from silently lacking database or archive support. MySQL/MariaDB connection definitions remain available for compatibility.

The user's September 9 migration already added PostgreSQL branches, avoiding the original MySQL `information_schema.statistics` failure. The implementation now uses Laravel 12 `Schema::hasIndex` for names and exact ordered column lists. This removes hand-written catalog queries, supports PostgreSQL and SQLite through their respective grammars, and avoids mistaking a column anywhere in a composite index for an equivalent index. The same migration still creates the same named indexes and only drops its named indexes in `down`.

This historical edit is appropriate to make fresh deployments and incomplete local attempts portable. **It does not rerun an applied migration.** Existing successful databases do not change when their migration is already recorded. Inspect their actual indexes with `pg_indexes`; any missing index should be repaired with a separately reviewed additive migration, not by deleting migration history. Never undo a recorded migration merely to force it to rerun.

Earlier performance indexes are additive Laravel schema definitions. The SQLite WAL migration and provider PRAGMA are explicitly SQLite-only and do not run for PostgreSQL. Every migration was inspected; no raw MySQL query remains in application logic or migrations. `unsigned*`, `enum`, `after`, JSON columns and foreign keys are expressed through Laravel's schema API rather than raw MySQL DDL. PostgreSQL ignores unsigned/physical column-order semantics and maps enums to validated text; verify any imported numeric values and enum values before transferring existing MySQL data.

Model scores and raw project-list scores are normalized to numbers because PostgreSQL returns NUMERIC values as strings. UserPermission's grant flag has a boolean cast. Audit searches use Laravel `whereLike`, preserving the previous case-insensitive search behavior on PostgreSQL. Email login uses `LOWER(email) = ?`, not a wildcard match. No table name or foreign-key relationship was renamed.

## Seeder failure and resolution

The earlier required accounts were not created by the checked-out UserSeeder: it creates `admin@gmail.com` and `ictsupport@gmail.com` only. The bulk seeder also looked up the wrong ICT email. The old ProjectSeeder expected reviewer/planner/coordinator/approver accounts that could be absent, resulting in `documents.uploaded_by = NULL`. Its local fallback to the first user avoided the constraint while assigning the wrong people to important roles; it has been replaced.

The leading blank line before ProjectSeeder's PHP opening tag was also removed so its namespace is valid. Designated development workflow accounts now use the `.test` domain:

| Account | Required active role |
| --- | --- |
| reviewer@example.test | Project Reviewer |
| planner@example.test | Project Planner |
| coordinator@example.test | Project Coordinator |
| approver@example.test | Project Approver |
| viewonly@example.test | Project ViewOnly |

Role resolution prefers the designated active account; otherwise it accepts exactly one active, unexpired holder of that role. It rejects zero or multiple candidates. No numeric user IDs or arbitrary first users are used. Document uploaders remain non-null foreign keys. The sample pack's projects, activities, requirements, documents and reviews are tested together, including repeat-run counts and passwords. Missing accounts fail before inserts, while a failed demo pack rolls back its transaction.

Normal production commands:

```bash
# Linux SSH session, administrator-approved Site 2 project only
cd /var/www/nssf-project-mgmt-2
php artisan migrate:status
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan users:bootstrap-admin
```

Demo data is opt-in on a disposable local database:

```bat
rem Windows CMD, project root, APP_ENV=local and a confirmed disposable DB
cd /d "C:\Users\user\Desktop\Project-Management-System--main"
php artisan db:seed --class=DemoSeeder
```

The final read-only inspection found exactly one sample reference in the normal local PostgreSQL database, with invalid reviewer/planner roles. The required checked performance indexes exist and no document has a null uploader. The new seeder reports the incomplete set. Use a newly created separate local database to load the complete pack. Any repair of the existing sample database must be reviewed separately; this work did not delete or rewrite its projects. Existing complete sample projects are validated rather than having their assignments/passwords overwritten.

## Test database isolation

`phpunit.xml` forces in-memory SQLite. `phpunit.pgsql.xml` forces a PostgreSQL test database named `pms_audit_test`, user `audit_runner`, host 127.0.0.1 and port 25432. `Tests/TestCase.php` checks those settings before RefreshDatabase is allowed to migrate or clear test tables. Test config/route caches point into ignored `tmp`, so a production cache cannot redirect tests to another database. Both suites use `APP_ENV=testing` and clear `DB_URL`.

The PostgreSQL suite uses real PDO/PostgreSQL, not mocked SQL. It creates schema through all migrations in a new workspace-owned cluster; RefreshDatabase then rolls test data back. This is separate from local PostgreSQL on 5432 and from the remote server. The cluster is stopped after verification and its ignored files can be reviewed or removed manually.

Example Windows CMD commands for repeating the isolated suite, with installed PostgreSQL 16:

```bat
cd /d "C:\Users\user\Desktop\Project-Management-System--main"
rem Use an unused data directory. Do not run initdb on an existing cluster.
"C:\Program Files\PostgreSQL\16\bin\initdb.exe" -D "%CD%\tmp\pgsql-audit-new" -U audit_runner --auth=trust --encoding=UTF8 --locale=C
"C:\Program Files\PostgreSQL\16\bin\pg_ctl.exe" -D "%CD%\tmp\pgsql-audit-new" -l "%CD%\tmp\pgsql-audit-new\server.log" -o "-p 25432 -h 127.0.0.1 -c shared_buffers=16MB -c max_connections=12" -w start
"C:\Program Files\PostgreSQL\16\bin\createdb.exe" -h 127.0.0.1 -p 25432 -U audit_runner pms_audit_test
php vendor\phpunit\phpunit\phpunit -c phpunit.pgsql.xml
"C:\Program Files\PostgreSQL\16\bin\pg_ctl.exe" -D "%CD%\tmp\pgsql-audit-new" -m fast -w stop
```

Trust authentication is limited to this temporary loopback test cluster; never copy it to production. Port 25432 must be free. Only the current local user should access its data directory. Alternatively restart the existing verified `tmp/pgsql-audit` cluster without initdb/createdb and run the suite; that database contains test data only.

## Existing MySQL business data

Schema portability is not a business-data transfer. No MySQL data export/import was performed and no database data was deleted. If the intended production database contains existing MySQL business records, first take and validate backups, inventory table counts/encoding/foreign keys, and rehearse a conversion into a new PostgreSQL database. Preserve primary keys and reset identity sequences after import; reconcile nulls, boolean values, decimal precision, invalid dates, case-only duplicate emails, JSON and enum values. PostgreSQL email uniqueness is case-sensitive at present, so manually reconcile case-only duplicates before deciding on a new normalized-email uniqueness migration.

The existing budget column is FLOAT and remains so to avoid rewriting financial values during an infrastructure task. A separate, reviewed DECIMAL migration may be appropriate for money after inspecting existing values and rounding requirements. Archived data, reviews and progress histories must be reconciled as well as active project counts. Do not feed a MySQL SQL dump directly to PostgreSQL.

The duplicate historical personal_access_tokens migration has a guarded `up` but an unconditional `down`. Some requirement date columns also occur in two guarded migrations. These historical rollback ownership ambiguities were not rewritten; prefer a code rollback with additive schema retained. Rehearse any schema rollback on a restored disposable copy before touching production.

## Administrator verification SQL

```sql
-- In psql connected to site2_db; read-only checks.
SELECT current_database(), current_user, version();
SELECT tablename, indexname, indexdef
FROM pg_indexes WHERE schemaname = 'public' ORDER BY tablename, indexname;
SELECT COUNT(*) FROM documents WHERE uploaded_by IS NULL;
SELECT COUNT(*) FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE u.id IS NULL;
SELECT COUNT(*) FROM projects;
SELECT COUNT(*) FROM migrations;
```

PostgreSQL automatically indexes primary/unique keys, but does not automatically index referencing foreign-key columns; the earlier performance migration supplies common lookup indexes. Do not add redundant indexes or adjust shared server memory without measured plans and administrator review.
