# Audit results and release review

Date: 8 October 2026. Branch: `PMS-2`. Local changes are prepared for owner review; this is **not a declaration of production readiness**.

## Final release-preparation update

**Status: BLOCKED on external deployment gates, not on local code validation.** No production host, database, service or business data was touched.

- Opening a project now requests only `GET /projects/{id}?summary=1`, which returns tab counts and workflow metadata. Documents, activities and requirements are fetched only when their respective tabs are first selected. Visited tabs remain mounted, preserving local form state.
- A bounded, in-memory GET cache deduplicates concurrent requests, expires after 30 seconds, isolates auth-token scopes and invalidates after every successful write, logout or unauthorized response. It never stores API data in browser storage.
- The summary route regression test proves that tab collections are absent from the lightweight response and present on the existing full route. The closure/workflow probe dropped from 25 to 10 SQL queries for the audited project.
- Route components and role dashboards are lazy-loaded. Removing the forced Ant Design vendor chunk lets Vite split route dependencies naturally. The verified production artifact is 3,984,011 bytes across 94 files; the entry HTML is 2,352 bytes. PDF/Excel export chunks remain intentionally on demand, though ExcelJS is still a 1.06 MB minified chunk and reports its upstream direct-`eval` warning.
- Full lint now exits successfully with **0 errors and 39 warnings**. The warnings are existing React Compiler migration diagnostics (`set-state-in-effect`) and Fast Refresh mixed-export notices. React Compiler is not enabled; they are retained as warnings for planned cleanup rather than hidden.

The exact first manual deployment command, after the release is reviewed/committed and the administrator has installed the dedicated public key, is:

```bat
ssh -i "%USERPROFILE%\.ssh\nssf_site2_ed25519" edward@147.182.164.148
```

## Outcome

The original system is root-level Laravel 12 with an already-independent React/Vite frontend in `frontend`. That structure is retained. PostgreSQL becomes the default, the existing 48-project demonstration pack is validated with real PostgreSQL, production reference seeding is separated from demo data, and Site 2 deployment templates preserve the PDF's 8080/8001/5432 allocation.

No remote server connection, deployment, shared service change, commit, push, merge, rebase, reset or destructive operation against business databases was performed. The existing `.env` was preserved. Initial changes to the index migration/ProjectSeeder were reviewed and built upon. Initial deletions of repomix-output.xml and test-demo.ps1 remain untouched.

## Implemented corrections

- The September 9 index migration uses portable Laravel index inspection; the user's PostgreSQL fix is retained in intent, with manual database-specific SQL removed.
- ProjectSeeder's invalid leading blank line and arbitrary first-user role fallback are removed. Required workflow accounts resolve through active roles; missing/ambiguous/partial demo data produces actionable errors without data deletion.
- Default production seeding creates only reference permissions/roles. Reference seeders no longer delete retired role assignments or user overrides. Explicit local/testing demo seeders run transactionally and retain the intended 48 projects. Existing sample passwords/roles are not silently reset.
- A privately prompted `users:bootstrap-admin` command initializes one new real administrator without changing an existing account. Demo commands are restricted to local/testing; `demo:purge --force` no longer implies deleting every project. No purge command was executed.
- PostgreSQL decimal scores retain numeric JSON responses; permission booleans are cast. Audit search remains case-insensitive; invalid/non-email login no longer queries an absent username column.
- Login does not grant privileges from special emails; `/me` does not reactivate disabled roles. Sanctum uses the existing bearer flow exclusively. Disabled accounts cannot continue through old tokens; logout safely revokes the current token.
- Dashboard role parameters must match an assigned active role. Requirement edits require project ownership/administrator status; reviews and returns require review permission. Document edits reject unauthorized roles and cross-project references. Closed projects cannot be reassigned.
- `/me` avoids repeated auth serialization; audit flag calculations restrict history scanning to relevant users/time ranges and iterate with a cursor. Existing bulk dashboard calculations, lazy pages and browser export libraries are preserved.
- API calls and authorized document fetches share `VITE_API_BASE_URL`, with the legacy `VITE_API_URL` alias retained. Builds without an explicit production API URL fail early. CORS defaults are restricted, and the production example lists the exact frontend origin.
- Composer lockfile validation is fixed and only three installed packages were upgraded to resolve four reported advisories: Laravel 12.66.0 -> 12.69.3, CommonMark 2.10.0 -> 2.10.3, Flysystem 3.35.2 -> 3.36.0. No new large runtime service was installed.

## Executed checks

| Check | Result and evidence |
| --- | --- |
| PDF review | All three PMM2.pdf pages extracted and rendered/visually inspected |
| Original PHP suite | 103 tests / 437 assertions; one existing closure test failed because its fixture omitted the required planner closure request |
| Final SQLite suite | `php artisan test`: 124 tests / 807 assertions passed |
| Final PostgreSQL suite | `php vendor/phpunit/phpunit/phpunit -c phpunit.pgsql.xml --no-progress`: 124 tests / 807 assertions passed |
| Fresh PostgreSQL migrations | All 35 migrations executed successfully through PDO against PostgreSQL 16 in a new workspace-owned local cluster on 25432 |
| Existing local migration status | Read-only `migrate:status` showed all 35 migrations already applied, including September 9. No normal local migrations or seeds were run |
| Existing local data/index integrity | Read-only PostgreSQL audit found one of the 48 sample references, with invalid reviewer/planner roles; zero documents have null uploaders. Lifecycle, plan-status and reviewed-at indexes exist. No existing rows were repaired or deleted |
| Seeder integrity | 48 projects / 50 users, required active roles, uploaders/reviewers/activity ownership, repeat-run counts/passwords, incomplete/missing-role cases, production demo refusal tested |
| Production initialization | New administrator creation and refusal to reset/elevate an existing account tested |
| Authentication/authorization | Case-insensitive login, non-email rejection, real bearer logout/revocation, disabled tokens, inactive roles, no email-derived grants, dashboard spoofing, matrix ownership/review gates tested |
| Upload/download | Valid uploader, real fake file storage, authenticated download, unauthenticated rejection, cross-project requirement rejection tested; existing ZIP/archive tests retained |
| CORS | Cross-port Authorization/Content-Type preflight and non-reflection of other origins tested |
| Index repeat safety | Repeating September 9 up against existing indexes adds no duplicates on both test drivers |
| Audit history bounds | Third failed attempt remains flagged when prior attempts fall outside the current paginated response |
| PHP syntax | 135 application/bootstrap/config/database/route/test PHP files checked; zero syntax failures |
| Composer manifest/lock | `composer validate --strict --no-check-publish` passed; `composer check-platform-reqs` passed |
| Composer security audit | After targeted upgrades, `composer audit --locked --format=json` returned no advisories or abandoned packages |
| Frontend security audit | `npm audit --json` returned zero vulnerabilities across the installed lock's dependency inventory |
| Production frontend build | Successful Windows build using the specified Site 2 API URL; 3,984,011 bytes in dist across 94 files. ExcelJS direct-eval and a 1.06 MB on-demand export chunk remain |
| Missing production API URL | Negative build check failed with the intended explicit configuration message before producing new output |
| Changed frontend files | ESLint passed for apiConfig, apiHelpers, axios and vite.config; Node syntax checks also passed |
| Full frontend lint | Passed with 0 errors and 39 warnings. Compiler migration and Fast Refresh diagnostics remain visible as warnings because React Compiler is not enabled |
| Laravel caches/boot | Config, route and view caching succeeded using isolated ignored cache paths with the demo PostgreSQL database; route inventory includes 55 API routes; scheduler lists deadlines:check |
| Browser smoke check | Built SPA preview on 18080 -> disposable PHP API on 18001/PostgreSQL: login, planner dashboard, project table, requirements panel, authenticated /projects refresh, logout, logged-out route redirection verified; no captured console errors in this flow |
| Deployment shell syntax | Git Bash `bash -n scripts/00-laravel-deploy.sh` passed; script was not executed |
| Git whitespace | `git diff --check` passed |

Early attempts exposed sandbox process restrictions on Vite/PostgreSQL/Git Bash and restricted Composer networking. The necessary local process/network checks were rerun with approved escalation. Composer's first Laravel extraction was interrupted by a Windows file lock; the subsequent install completed and all final checks use Laravel 12.69.3. A first isolated cache check used Windows absolute cache paths incorrectly; relative ignored cache paths corrected it, and all three cache commands then succeeded. These initial failures are not hidden successes.

PHP tests use forced isolated connections and reject other database destinations before schema setup. The PostgreSQL test cluster and browser demo database are separate from normal local PostgreSQL and production. Temporary files/builds are under ignored `tmp`; no demonstration accounts were added to the actual local database. Temporary servers are stopped after verification.

## Outstanding production gates and limitations

1. **Existing frontend lint debt:** full lint has no errors but 39 warnings. A coordinated cleanup should address the effect/memoization patterns without changing page workflows.
2. **Real Linux configuration checks:** Nginx/FPM are not installed in this Windows environment. Templates were reviewed but `nginx -t`, the discovered `php-fpm -t`, pool ownership, socket permissions, firewall/transport and shared service reload behavior must be verified by the administrator.
3. **Protected transport:** the PDF's public HTTP exposes credentials/tokens in transit. Templates preserve its ports; enable administrator-approved HTTPS or a protected network transport before real use. No proxy/TLS topology was silently enabled.
4. **Server resources:** no server RAM/disk/CPU measurements or real concurrent-load tests were taken. FPM's one-worker proposal is deliberately conservative; large exports can queue other requests and require a measured limit.
5. **Business data transfer:** no existing MySQL business records were imported or reconciled. If that is needed, rehearse a backup/conversion/count/identity-sequence reconciliation into a new PostgreSQL database before release.
6. **Historical rollback:** duplicate token/date migrations have rollback ownership ambiguities. Fresh upward migration compatibility is tested; destructive schema rollback/restoration is not. Prefer compatible code rollback with additive schema retained, and rehearse restore into a disposable recovery database.
7. **Browser coverage:** the recorded planner smoke flow is not every page or role. API tests cover multiple role workflows, but full browser user management, report/PDF/Excel exports, representative large files, real mail and Linux archive permissions remain manual gates.
8. **Growth/visibility policy:** projects/users/portfolio panels still fetch whole lists under their current contracts; no server/client pagination rewrite was made. API portfolio reads retain existing authenticated visibility. Review any stricter project confidentiality/tenant requirement before extending deployment scope.
9. **Operational health:** the existing dashboard scheduler label is not an actual cron-health check. Verify schedule logs and notification creation. Database audit/history retention and Nginx/scheduler log rotation need an approved policy; no business history was purged.
10. **Existing sample data:** the actual normal local PostgreSQL database contains **one partial sample project with invalid reviewer/planner roles**. The new seeder reports this condition and does not overwrite it. Use a new separate local demo database to load the complete 48-project pack, or review the existing project's intended assignments before authorizing a separate repair. Demo file rows retain their historical sample paths without payloads; these sample-only downloads return 404 until real files are uploaded.

## Manual release and rollback

[DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) includes the exact commands by shell, a complete A-I operator sequence, schema setup, new-admin initialization, runtime validation, browser/resource checks, backups, rollback and future updates. The sequence is: owner review/commit; install/test/build locally; obtain SSH/runtime/transport approval; inspect server and ports; allocate isolated Site 2 database/directories; install/configure Laravel; preserve/create APP_KEY appropriately; back up existing data; migrate/reference seed; initialize admin; transfer dist; validate new FPM/Nginx configs; administrator reload; scheduler/rotation; verify and monitor both sites.

Rollback restores a reviewed previous Site 2 code/frontend release with the preserved key/private files. Database restore requires an explicit decision and a verified backup, preferably restored into a new recovery database before switching only Site 2. No automatic rollback, migration reset or deletion is supplied.

## File manifest

The lists below distinguish this task's changes from pre-existing user deletions. Ignored dist/tmp/vendor/node_modules artifacts are not source files to commit or transfer as backend source.

### Modified source/configuration/documentation files (44)

- `.gitignore`
- `README.md`
- `app/Console/Commands/GenerateDemoProjects.php`
- `app/Console/Commands/PurgeDemoData.php`
- `app/Http/Controllers/Api/AuditLogController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/ProjectController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/DocumentController.php`
- `app/Http/Controllers/RequirementController.php`
- `app/Models/Project.php`
- `app/Models/User.php`
- `app/Models/UserPermission.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `config/app.php`
- `config/cors.php`
- `config/database.php`
- `config/sanctum.php`
- `database/migrations/2026_09_09_170000_add_more_performance_indexes.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/PermissionSeeder.php`
- `database/seeders/ProjectSeeder.php`
- `database/seeders/RoleSeeder.php`
- `database/seeders/UserBulkSeeder.php`
- `database/seeders/UserSeeder.php`
- `frontend/.env.example`
- `frontend/README.md`
- `frontend/package-lock.json`
- `frontend/package.json`
- `frontend/src/lib/apiHelpers.js`
- `frontend/src/lib/axios.js`
- `frontend/vite.config.js`
- `phpunit.xml`
- `routes/api.php`
- `scripts/00-laravel-deploy.sh`
- `tests/Feature/AuditLogTest.php`
- `tests/Feature/InitiationAndReviewTest.php`
- `tests/Feature/Person3PlannerTest.php`
- `tests/Feature/PlanLifecycleTest.php`
- `tests/Feature/RequirementTest.php`
- `tests/Feature/WorkflowTest.php`
- `tests/TestCase.php`

### Newly created source/configuration/documentation files (27)

- `.env.example`
- `app/Console/Commands/BootstrapAdministrator.php`
- `app/Http/Middleware/EnsureActiveAccount.php`
- `database/seeders/DemoDataGuard.php`
- `database/seeders/DemoSeeder.php`
- `database/seeders/DemoUserSeeder.php`
- `database/seeders/DemoUsers.php`
- `database/seeders/ReferenceDataSeeder.php`
- `deployment/.env.production.example`
- `deployment/nginx/nssf-backend-2.conf`
- `deployment/nginx/nssf-frontend-2.conf`
- `deployment/php-fpm/nssf-site2.conf`
- `deployment/postgresql/site2-setup.sql`
- `docs/ARCHITECTURE.md`
- `docs/AUDIT_RESULTS.md`
- `docs/DEPLOYMENT_GUIDE.md`
- `docs/PERFORMANCE_OPTIMIZATION.md`
- `docs/POSTGRESQL_MIGRATION.md`
- `frontend/.env.production.example`
- `frontend/src/lib/apiConfig.js`
- `phpunit.pgsql.xml`
- `tests/Feature/AuthenticationSafetyTest.php`
- `tests/Feature/AuthorizationIntegrityTest.php`
- `tests/Feature/BootstrapAdministratorTest.php`
- `tests/Feature/CorsConfigurationTest.php`
- `tests/Feature/PerformanceIndexMigrationTest.php`
- `tests/Feature/SeederIntegrityTest.php`

### Pre-existing changes preserved

- Deleted `repomix-output.xml` and `test-demo.ps1` were already deleted before this task; neither was restored or deleted by the audit.
- The index migration and ProjectSeeder were already modified; the reviewed corrections build upon those local edits.
- Existing `.env`, Git identity/ownership and unrelated files remain unchanged.
