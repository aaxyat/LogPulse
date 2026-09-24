# Log Ingest & Monitoring Engine

Multi-tenant log ingestion, real-time viewer, and synthetic monitoring service hosted on shared cPanel PHP 8.1+ environments.

## Language

### Core Domain

**Project**:
A monitored application or service (e.g. Audiobookshelf, Nextcloud, API gateway) grouping its ingestion token, real-time logs, uptime health checks, and alert configurations.
_Avoid_: App, service, workspace, stream

**User**:
An authenticated account holder authorized to manage Projects, view logs, and configure alerts.
_Avoid_: Account, client, customer, member

**Endpoint**:
The HTTP ingestion URL and protocol assigned to a Project for receiving incoming log events.
_Avoid_: Channel, sink, route, collector

**Ingest Token**:
A high-entropy secret string used by log-sending clients to authenticate HTTP log submissions to an Endpoint.
_Avoid_: API secret, endpoint password, auth key

**Log Entry**:
A discrete event record containing timestamp, severity level, message text, and optional arbitrary structured context.
_Avoid_: Event, trace, line, message, record

**Log Batch**:
An array of Log Entries submitted together in a single HTTP request to an Endpoint.
_Avoid_: Bulk log, chunk, log list

### Monitoring & Operations

**Retention Policy**:
The duration in days that an Endpoint retains Log Entries before automated cron purging.
_Avoid_: TTL, expiration period, cleanup window

**Alert Rule**:
A threshold configuration that triggers notifications when matching Log Entries exceed a count within a rolling time window.
_Avoid_: Alarm, trigger, watch condition

**Notification Channel**:
An external destination (such as a Discord webhook, Slack webhook, or email address) receiving Alert Rule dispatches.
_Avoid_: Webhook target, sink, dispatch endpoint

**Uptime Monitor**:
A scheduled HTTP probe configured to periodically verify availability and response latency of an external target URL.
_Avoid_: Synthetic check, health pinger, heartbeat, watcher

**Check Run**:
A single execution result of an Uptime Monitor recording timestamp, HTTP status code, response time in milliseconds, and status.
_Avoid_: Ping result, probe execution, heartbeat sample
