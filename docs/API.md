# LogPulse REST API Specification

Base URL: `https://logs.yourdomain.com/api/v1`

---

## 1. Authentication

### User Login
- **Endpoint**: `POST /api/v1/auth/login`
- **Request Body**:
  ```json
  {
    "email": "admin@logpulse.io",
    "password": "AdminSecret2026!"
  }
  ```
- **Response** (200 OK):
  ```json
  {
    "success": true,
    "token": "eyJhbGciOi...",
    "user": {
      "id": 1,
      "name": "Ayush Lead SRE",
      "email": "admin@logpulse.io",
      "role": "admin"
    }
  }
  ```

---

## 2. Ingestion API (Dual Auth)

Supports token authentication via URL path or HTTP header.

### Single or Batch Log Ingestion
- **Method**: `POST`
- **URL Option A**: `/api/v1/ingest/{ingest_token}`
- **URL Option B**: `/api/v1/ingest` with `Authorization: Bearer {ingest_token}` or `X-API-Key: {ingest_token}`
- **Single Log Payload**:
  ```json
  {
    "level": "ERROR",
    "message": "Stripe charge failed",
    "context": {
      "charge_id": "ch_981a",
      "attempt": 2
    },
    "timestamp": "2026-09-24T03:45:00Z"
  }
  ```
- **Batch Log Payload**:
  ```json
  [
    {"level": "INFO", "message": "Batch event 1"},
    {"level": "WARN", "message": "High memory consumption", "context": {"mem_pct": 91}}
  ]
  ```
- **Response** (200 OK):
  ```json
  {
    "success": true,
    "ingested": 2,
    "endpoint": "billing-service-cluster"
  }
  ```

---

## 3. Log Stream & Inspection

### Query Logs
- **Endpoint**: `GET /api/v1/logs`
- **Headers**: `Authorization: Bearer {user_jwt}`
- **Query Parameters**:
  - `endpoint_id` (optional): Filter to specific endpoint
  - `level` (optional): `INFO`, `WARN`, `ERROR`, `DEBUG`
  - `q` (optional): Substring search across message and context
  - `since_id` (optional): Fetch records with `id > since_id` for live delta streaming
  - `limit` (optional): Max records (default 50, max 200)

### Inspect Single Log Record
- **Endpoint**: `GET /api/v1/logs/{id}`
- **Response**: Full JSON containing parsed context, raw payload envelope, client IP, and user-agent.

---

## 4. Endpoints Management
- `GET /api/v1/endpoints`: List all endpoints and retention windows.
- `POST /api/v1/endpoints`: Create endpoint (`{"name": "...", "retention_days": 14}`).
- `POST /api/v1/endpoints/{id}/regenerate-token`: Cycle ingest token.
- `DELETE /api/v1/endpoints/{id}`: Delete endpoint and purge logs.
- `POST /api/v1/endpoints/{id}/prune`: Prune logs older than N days.

---

## 5. Monitoring & Tooling
- `GET /api/v1/metrics/dashboard`: 24h log volume, error rate %, active monitors.
- `GET /api/v1/alerts`: List configured threshold alert rules.
- `POST /api/v1/alerts`: Create alert rule (`channel_type`: `webhook`, `slack`, `discord`, `email`).
- `POST /api/v1/alerts/{id}/test`: Dispatch immediate test notification.
- `GET /api/v1/monitors`: List uptime targets and 7-day uptime %.
- `POST /api/v1/monitors`: Create HTTP health monitor.
- `POST /api/v1/monitors/{id}/ping`: Execute immediate probe.
