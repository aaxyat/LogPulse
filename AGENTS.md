# Agent Guidelines & Repository Architecture

This document governs agent operations, conventions, and architectural contracts for this codebase.

## 1. Domain Vocabulary
All code identifiers, database tables, and API routes must conform strictly to the glossary in [`CONTEXT.md`](./CONTEXT.md). Use canonical domain terms (`User`, `Endpoint`, `Ingest Token`, `Log Entry`, `Log Batch`, `Retention Policy`, `Alert Rule`, `Notification Channel`, `Uptime Monitor`, `Check Run`).

## 2. Core Architectural Invariants

- **Runtime & Zero Vendor Bloat**: Target PHP 8.1+. Do not introduce external Composer dependencies or Node.js build pipelines. Use native standard library (`PDO`, `json_encode`/`json_decode`, `hash_hmac`, `filter_var`, `curl_*`).
- **Database Access**: MySQL / MariaDB via native `PDO`. All database queries MUST use prepared statements with parameterized inputs. Direct string interpolation into SQL queries is strictly prohibited.
- **Shared cPanel Constraints**:
  - Web requests are synchronous HTTP through Apache/LiteSpeed with `.htaccess` rewriting to `public/index.php`.
  - Never design long-running daemons or resident background memory processes.
  - Background operations (retention pruning, alert evaluation, uptime pings) are executed through standard CLI commands triggered by cPanel Cron (`php bin/console <command>`).
- **Authentication**:
  - Dashboard users authenticate via JWT (HMAC-SHA256) issued on login. Passwords hashed using `password_hash(..., PASSWORD_BCRYPT)`.
  - Ingestion clients authenticate via `Ingest Token` passed either in the URL path (`/api/v1/ingest/{token}`) or via HTTP header (`Authorization: Bearer {token}` or `X-API-Key: {token}`). Verify tokens using `hash_equals()`.
- **Frontend Architecture**:
  - Single-page dashboard served via PHP view templates with zero build step.
  - Uses Alpine.js, Tailwind CSS (via CDN/standalone stylesheet), and Chart.js.
  - Near real-time log streaming implemented via configurable short-polling AJAX requests (`/api/v1/endpoints/{id}/logs?since_id=...`).

## 3. Directory Layout

```
.
├── CONTEXT.md                      # Domain glossary (no implementation specs)
├── AGENTS.md                       # Agent directives and codebase conventions
├── docs/
│   ├── adr/                        # Architectural Decision Records
│   ├── API.md                      # Complete REST API reference
│   └── DEPLOYMENT.md               # cPanel setup, MySQL wizard & cron schedule
├── bin/
│   └── console                     # CLI runner for cron tasks (prune, alert, ping)
├── config/
│   ├── config.example.php          # Database credentials, app secrets, base URLs
│   └── schema.sql                  # Automated MySQL table schema & indexes
├── src/
│   ├── Auth/                       # JWT generation, validation, user password auth
│   ├── Controllers/                # API and view controllers
│   ├── Database/                   # PDO connection manager and query helpers
│   ├── Models/                     # Core domain data mappers
│   ├── Services/                   # Ingest parser, alert evaluator, uptime pinger
│   └── Router.php                  # Lightweight HTTP router with middleware
└── public/
    ├── index.php                   # Single front controller entrypoint
    ├── .htaccess                   # Apache URL rewriting & security headers
    └── assets/                     # Alpine.js, Chart.js, styles, and dashboard logic
```

## 4. Verification Protocol
Before declaring any task complete:
1. Verify PHP syntax on all touched files: `php -l <file.php>`.
2. Validate API routes and ingest payloads using PHP CLI / curl smoke tests.
3. Confirm MySQL schema statements execute without syntax or index errors.
