# LogPulse ⚡

> Modern, zero-dependency log ingestion, streaming observability, and synthetic monitoring for shared hosting (cPanel) and cloud servers. Built with pure PHP 8.1+ and MySQL/MariaDB.

![LogPulse Observability](https://img.shields.io/badge/PHP-8.1%2B-blue?style=flat-square&logo=php)
![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20SQLite-emerald?style=flat-square)
![Build](https://img.shields.io/badge/Dependencies-Zero%20Vendor%20Bloat-purple?style=flat-square)
![License](https://img.shields.io/badge/License-MIT-slate?style=flat-square)

---

## 🌟 Highlights

- **Zero Vendor Bloat**: Pure native PHP standard library (`PDO`, `json_encode`, `hash_hmac`, `filter_var`, `curl_*`). No Composer vendor directory or Node.js build pipelines required on the host.
- **Shared cPanel Optimized**: Runs on standard Apache / LiteSpeed with `.htaccess` rewriting. No long-running daemons or resident background memory processes required.
- **Project-Centric Architecture**: Monitor standalone applications and microservices (e.g. `Audiobookshelf`, `Nextcloud`, `Stripe Gateway`) with dedicated cards, health badges, and live log buffers.
- **Dual-Auth Ingestion**: Submit single logs or batches (up to 500 records) via URL path token (`/api/v1/ingest/{token}`) or HTTP headers (`Authorization: Bearer {token}` or `X-API-Key: {token}`).
- **Interactive Live Viewer**: Real-time log streaming with configurable short-polling (1s, 3s, 5s), severity filter pills (`ALL`, `INFO`, `WARN`, `ERROR`, `DEBUG`), full-text search, and slide-over JSON inspector.
- **Synthetic HTTP Health & Latency Probes**: Scheduled external uptime and response latency tracking executed via standard cPanel cron.
- **Alert Dispatch Engine**: Threshold-based alert rules dispatching notifications to Webhooks, Slack, Discord, or Email.
- **Built-in API Docs**: Interactive REST API documentation at `/docs` with Light & Dark themes that automatically follow system color preference.

---

## 🚀 Quick Start (cPanel Deployment)

### 1. Upload Codebase
Place the repository files in your cPanel directory (e.g., `/home/username/logs`):
```
logs/
├── bin/
├── config/
├── docs/
├── public/       <-- Point subdomain DocumentRoot here
└── src/
```

### 2. Configure MySQL Database
1. In cPanel **MySQL Databases**, create a database and user with full privileges.
2. In **phpMyAdmin**, import [`config/schema.sql`](config/schema.sql).
3. Copy `config/config.example.php` to `config/config.php` and fill in credentials:
```php
return [
    'app' => [
        'name' => 'LogPulse',
        'env' => 'production',
        'url' => 'https://logs.yourdomain.com',
        'jwt_secret' => 'YOUR_RANDOM_64_CHAR_HEX_SECRET',
        'jwt_expiry_hours' => 72,
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'cpaneluser_logpulse',
        'username' => 'cpaneluser_loguser',
        'password' => 'DbPassword123!',
        'charset' => 'utf8mb4',
    ],
];
```

### 3. Seed Primary Admin User
Run via cPanel Terminal or SSH:
```bash
php bin/console seed:admin admin@yourdomain.com YourStrongPassword "Lead SRE"
```

### 4. Setup 5-Minute cPanel Cron
In cPanel **Cron Jobs**, set an interval of `*/5 * * * *`:
```bash
/usr/local/bin/php /home/username/logs/bin/console cron:run >> /home/username/logs/cron.log 2>&1
```

---

## 📡 Ingestion Examples

### cURL
```bash
curl -X POST "https://logs.yourdomain.com/api/v1/ingest/lp_your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "level": "ERROR",
    "message": "Payment gateway timeout on checkout",
    "context": { "order_id": "ord_9921", "amount": 49.00 }
  }'
```

### Python
```python
import requests

requests.post(
    "https://logs.yourdomain.com/api/v1/ingest/lp_your_token_here",
    json={
        "level": "WARN",
        "message": "High memory consumption detected",
        "context": {"mem_pct": 89}
    },
    timeout=5
)
```

### Node.js
```javascript
await fetch('https://logs.yourdomain.com/api/v1/ingest/lp_your_token_here', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    level: 'INFO',
    message: 'Worker execution completed',
    context: { worker_id: 'pod-88a' }
  })
});
```

### Docker Logs Pipe
```bash
docker logs -f my_container 2>&1 | while read -r line; do
  curl -s -X POST "https://logs.yourdomain.com/api/v1/ingest/lp_your_token_here" \
    -H "Content-Type: application/json" \
    -d "{\"level\": \"INFO\", \"message\": \"$line\"}" > /dev/null
done
```

---

## 📚 Documentation
- [REST API Reference](docs/API.md) (or browse to `/docs` in your browser)
- [cPanel Deployment Guide](docs/DEPLOYMENT.md)
- [Architecture Decision Record (ADR 0001)](docs/adr/0001-zero-dependency-php-mysql-on-cpanel.md)
- [Domain Concepts](CONTEXT.md)

---

## 🛡️ License
Released under the [MIT License](LICENSE).
