# Application architecture and audit

Audit date: 8 October 2026. Branch inspected: `PMS-2`. The repository, not the assumed PDF layout, is the implementation reference. All three pages of `PMM2.pdf` were extracted and visually inspected. Its deployment instructions are reference material, not authorization to connect to the server.

## Original architecture

| Area | Observed implementation |
| --- | --- |
| Backend | Laravel 12.66.0 from `composer.lock`, PHP `^8.2`, root-level `artisan`, `app`, `routes`, `database`, `public` |
| Dependencies | Sanctum 4.3.3, PHPWord 1.1.0, ZipArchive; PHPUnit 11, Faker, Pint, Sail, Pail and IDE Helper are development dependencies |
| Frontend | Independent React 19 SPA in `frontend`, JavaScript/JSX, Vite 8.2.1 installed, React Router 7, Ant Design 6, Tailwind 3 |
| Build | `frontend/npm run build` produces `frontend/dist`; Node requirements are now explicit in its package manifest |
| Laravel views | A scaffold welcome Blade view only; no Vue or Inertia application |
| Legacy root build | Root Vite inputs reference absent `resources/css/app.css` and `resources/js/app.jsx`; this is not the working frontend |
| Communication | Axios JSON requests plus fetch for authenticated document files; originally `VITE_API_URL`, bearer tokens stored in browser localStorage |
| Authentication | Email/password `/api/login`; Sanctum personal access token; `/api/me`, `/api/logout`; public registration route absent |
| Authorization | Custom roles/permissions, active and expiring user-role pivots, direct grant/deny overrides, `manage.users`, `can.mutate`, controller/FormRequest ownership checks |
| Domain | Registration, initiation documentation, planner assignment, plan review, SDMM/IDMM/DICT recommendation and approval, execution, matrix progress, closure request, closure sign-off |
| Storage | Private `storage/app/private/documents`, 10 MiB PDF/DOCX/XLSX uploads, versioned Document rows, authenticated file endpoint, ZIP archives and DOCX reports |
| Data | Users, roles, permissions and pivots; projects/members, activities, requirements, documents, reviews, progress history, notifications, account activity and audit logs |
| Scheduling | `deadlines:check` at 06:00 in the application timezone; activity scanning uses chunked `each`; reminders are deduplicated per day |
| Queues | No application jobs were found dispatched; synchronous request operations and database notifications |
| Tests | Initially 103 PHP tests using in-memory SQLite; workflow, dashboards, ownership, user management, archives, read-only mutation and auditing coverage |
| Previous deployment | Render-oriented Dockerfile, `conf/nginx/nginx-site.conf` on container port 80, startup script automatically migrating and seeding |

Inspected all 35 migration files, all seeders and the user factory, controller/validation/middleware/model/service/command paths, frontend routes and API helpers, authentication and upload flows, build manifests/locks, configuration, existing test suites and deployment files. Generated vendor code, node_modules, logs and IDE helper output are dependencies or generated artifacts, not application source to restructure.

The initial working tree already modified the September 9 index migration and ProjectSeeder, and already deleted `repomix-output.xml` and `test-demo.ps1`. Those deletions were left as found. The local `.env` was not overwritten. No commit, push, remote connection or deployment was performed.

## Final target

Keep the existing separation. No Laravel files are moved and no pages are rewritten.

```text
Repository                         Site 2 server
frontend/src -> frontend/dist  ->  /var/www/nssf-frontend-2       Nginx :8080
Laravel at repository root     ->  /var/www/nssf-project-mgmt-2  Nginx :8001
                                  public/index.php -> isolated PHP-FPM socket
                                  PostgreSQL localhost :5432, site2_db / site2_user
```

The Composer security update keeps Laravel within major version 12. The shared PostgreSQL service remains the intended database runtime. The isolated cluster on local port 25432 is for tests only and must never be deployed.

## PDF reconciliation

| PDF assumption | Decision |
| --- | --- |
| Backend is under `/backend` | Laravel is at the root; use `/var/www/nssf-project-mgmt-2`, without an extra backend directory |
| Build a Vite `dist` | Correct for `frontend/`; do not run the unrelated root Vite build |
| Unix inline environment assignment on a Windows laptop | Use CMD `set "VITE_API_BASE_URL=..."` or `frontend/.env.production` |
| `php artisan serve` on 8001 | Propose Nginx on 8001 forwarding to an isolated FPM socket; administrator must review and approve this runtime change |
| Database grants alone | Create the database with the Site 2 role as owner and grant schema usage/create explicitly |
| Directory-wide mode 775 | Static files are readable; only Laravel storage/cache are writable by the PHP pool |
| Public HTTP endpoints | Preserve the requested allocation in templates. Require administrator-approved HTTPS or a protected transport before real credentials/business data are used |
| Reload shared Nginx | Administrator validates the entire configuration and authorizes a graceful reload; do not edit Site 1 |

## Authentication and authorization changes

The SPA retains bearer authentication. Sanctum's session guard fallback is disabled so a cookie session cannot override a revoked/invalid bearer token. No stateful Sanctum middleware or cross-origin cookie login is enabled; CSRF cookies are not part of this bearer flow. Cookies use Site 2-specific names for any web-session use. Existing database tokens keep their 720-minute expiration configuration.

Login uses the real email column and case-insensitive exact matching instead of querying a nonexistent username column. Reading `/me` does not reactivate disabled roles, and login does not infer privileges from special email addresses. Existing legacy accounts needing a role must be assigned it explicitly through User Management. Disabled accounts cannot use previously issued tokens. Logout deletes the current persisted token and safely handles the web guard.

Dashboard role selection is checked against the caller's active roles. Matrix content/progress edits require project ownership (or administrator role), and matrix reviews/returns require `projects.review`. Document edits follow existing editable roles: an assigned planner/administrator, reviewer or ICT Support; coordinator, approver and view-only writes are not allowed by these checks. Cross-project activity/requirement links in uploads are rejected. Closed projects cannot be reassigned. Normal workflow checks and the UI remain intact.

API reads still reflect the existing portfolio-wide visibility model for authenticated active accounts. This is not tenant isolation. If project visibility must be restricted beyond the existing roles and planner list filtering, define that policy before exposing additional data or adding tenants.

## Seeding and production initialization

Default `DatabaseSeeder` now calls reference data only: roles and permissions, without accounts or projects. Existing obsolete roles, assigned users and permission overrides are not deleted by reference seeding. RoleSeeder still synchronizes the defined permission sets for the current roles, so review custom role-permission additions before running it on an existing deployment.

`DemoSeeder` is explicit, runs only in `local`/`testing`, and wraps the entire pack in a transaction. It preserves 48 sample projects, creates the two existing development support accounts, five designated workflow accounts and 43 bulk accounts (50 total). ProjectSeeder resolves active eligible role holders; missing or ambiguous roles produce errors before project inserts. Partial prior demo project/account sets are reported without erasing data. Demo document rows intentionally describe sample files; no real document payload is generated, so those particular sample downloads return 404. Upload tests use actual fake file payloads instead.

Production starts with `users:bootstrap-admin`, which asks for a password privately and creates a new account only. User Management then creates ICT Support and workflow users. No fixed demo password is used for production initialization.

## Remaining gates

Read [AUDIT_RESULTS.md](AUDIT_RESULTS.md) for executed checks and remaining limitations, [POSTGRESQL_MIGRATION.md](POSTGRESQL_MIGRATION.md) for database behavior, [PERFORMANCE_OPTIMIZATION.md](PERFORMANCE_OPTIMIZATION.md) for resource choices, and [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) for the manual sequence. The configuration proposals have not been enabled on Linux and are not evidence of production readiness.
