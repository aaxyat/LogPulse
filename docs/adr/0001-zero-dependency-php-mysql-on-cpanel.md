# 0001: Zero-Dependency PHP 8.1 and MySQL on Shared cPanel

## Context
The system must deploy seamlessly to shared cPanel hosting where shell permissions are restricted, daemon processes are aggressively terminated, Node.js and Python runtimes are absent, and Composer is not available in the global path.

## Decision
We implement a zero-dependency PHP 8.1 backend using native PDO for MySQL/MariaDB, native JWT authentication via HMAC-SHA256, and a single front controller (`public/index.php`) with `.htaccess` URL rewriting. The frontend dashboard uses Alpine.js, Tailwind CSS, and Chart.js served without a server-side build step, updating live logs via configurable short-polling.

## Consequences
- **Portability**: Deploys via plain file upload / Git pull without running `composer install` or `npm build` on the server.
- **Reliability**: Eliminates dropped WebSocket connections and daemon crashes common on shared Apache/Passenger tiers.
- **Constraint**: Background tasks (retention log pruning, alert rule evaluations, uptime pings) rely on standard cPanel cron jobs rather than long-running background worker processes.
