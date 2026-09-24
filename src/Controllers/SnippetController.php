<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;

class SnippetController
{
    public static function getSnippets(array $user, string $token): void
    {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $ingestUrl = "{$baseUrl}/api/v1/ingest/{$token}";

        $snippets = [
            'curl' => <<<BASH
curl -X POST "{$ingestUrl}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "level": "INFO",
    "message": "User checkout completed successfully",
    "context": {
      "user_id": 492,
      "order_id": "ord_9941",
      "amount": 49.99
    }
  }'
BASH,

            'curl_header' => <<<BASH
curl -X POST "{$baseUrl}/api/v1/ingest" \\
  -H "Authorization: Bearer {$token}" \\
  -H "Content-Type: application/json" \\
  -d '[
    {"level": "INFO", "message": "Batch event 1"},
    {"level": "WARN", "message": "High memory usage detected", "context": {"mem_pct": 89}}
  ]'
BASH,

            'nodejs' => <<<JS
import fetch from 'node-fetch';

async function sendLog(level, message, context = {}) {
  await fetch('{$ingestUrl}', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      level,
      message,
      context,
      timestamp: new Date().toISOString()
    })
  });
}

// Example usage:
await sendLog('ERROR', 'Database connection timeout', { attempt: 3, host: 'db-primary' });
JS,

            'python' => <<<PY
import requests
import datetime

def send_log(level: str, message: str, context: dict = None):
    payload = {
        "level": level,
        "message": message,
        "context": context or {},
        "timestamp": datetime.datetime.utcnow().isoformat() + "Z"
    }
    requests.post("{$ingestUrl}", json=payload, timeout=5)

# Example usage:
send_log("ERROR", "Unhandled exception in payment worker", {"trace_id": "tr_882a1"})
PY,

            'php' => <<<PHP
function sendLog(string \$level, string \$message, array \$context = []): bool {
    \$url = '{$ingestUrl}';
    \$data = json_encode([
        'level' => \$level,
        'message' => \$message,
        'context' => \$context,
        'timestamp' => date('c'),
    ]);

    \$ch = curl_init(\$url);
    curl_setopt_array(\$ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => \$data,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
    ]);
    \$result = curl_exec(\$ch);
    curl_close(\$ch);
    return \$result !== false;
}

// Example:
sendLog('WARN', 'API Rate limit approaching', ['client' => 'mobile_v3']);
PHP,

            'go' => <<<GO
package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"time"
)

type LogPayload struct {
	Level     string                 `json:"level"`
	Message   string                 `json:"message"`
	Context   map[string]interface{} `json:"context,omitempty"`
	Timestamp string                 `json:"timestamp"`
}

func SendLog(level, message string, context map[string]interface{}) error {
	payload := LogPayload{
		Level:     level,
		Message:   message,
		Context:   context,
		Timestamp: time.Now().UTC().Format(time.RFC3339),
	}
	body, _ := json.Marshal(payload)
	_, err := http.Post("{$ingestUrl}", "application/json", bytes.NewBuffer(body))
	return err
}
GO,
        ];

        echo json_encode([
            'success' => true,
            'ingest_url' => $ingestUrl,
            'token' => $token,
            'snippets' => $snippets
        ]);
    }
}
