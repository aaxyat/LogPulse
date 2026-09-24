<?php
declare(strict_types=1);

/**
 * LogPulse Front Controller & API Gateway
 */

// Simple PSR-4 autoloader for LogPulse namespace
spl_autoload_register(function (string $class) {
    $prefix = 'LogPulse\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use LogPulse\Auth\AuthMiddleware;
use LogPulse\Controllers\AlertController;
use LogPulse\Controllers\AuthController;
use LogPulse\Controllers\EndpointController;
use LogPulse\Controllers\LogController;
use LogPulse\Controllers\MetricsController;
use LogPulse\Controllers\ProjectController;
use LogPulse\Controllers\SnippetController;
use LogPulse\Controllers\UptimeController;
use LogPulse\Database\DB;
use LogPulse\Router;

// Load Config
$configPath = dirname(__DIR__) . '/config/config.php';
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.example.php';
}
$config = require $configPath;

// Set Timezone & Error Reporting
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
if (($config['app']['env'] ?? 'production') === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Initialize Database connection
DB::init($config['database']);

// Setup Router
$router = new Router($config['cors'] ?? []);
$jwtSecret = $config['app']['jwt_secret'];

// Middleware closures
$authMw = fn(&$ctx) => ['user' => AuthMiddleware::requireAuth($jwtSecret)];
$adminMw = fn(&$ctx) => ['user' => AuthMiddleware::requireAdmin($jwtSecret)];

// -------------------------------------------------------------
// Documentation Route
// -------------------------------------------------------------
$router->get('/docs', function () {
    header('Content-Type: text/html; charset=UTF-8');
    require dirname(__DIR__) . '/public/assets/docs.html';
});

// -------------------------------------------------------------
// Public Authentication Routes
// -------------------------------------------------------------
$router->post('/api/v1/auth/login', function ($params) use ($config) {
    header('Content-Type: application/json');
    AuthController::login($config);
});

$router->post('/api/v1/auth/logout', function () {
    header('Content-Type: application/json');
    AuthController::logout();
});

// Authenticated User Profile
$router->get('/api/v1/auth/me', function ($params, $ctx) {
    header('Content-Type: application/json');
    AuthController::me($ctx['user']);
}, [$authMw]);

// Admin User Management
$router->get('/api/v1/auth/users', function ($params, $ctx) {
    header('Content-Type: application/json');
    AuthController::listUsers($ctx['user']);
}, [$adminMw]);

$router->post('/api/v1/auth/users', function ($params, $ctx) {
    header('Content-Type: application/json');
    AuthController::createUser($ctx['user']);
}, [$adminMw]);

// -------------------------------------------------------------
// Ingest Routes (Dual Token Auth: Path or Header)
// -------------------------------------------------------------
$router->post('/api/v1/ingest/{token}', function ($params) {
    header('Content-Type: application/json');
    LogController::ingest((string)$params['token']);
});

$router->post('/api/v1/ingest', function () {
    header('Content-Type: application/json');
    LogController::ingest(null);
});

// -------------------------------------------------------------
// Log Viewing & Inspection Routes
// -------------------------------------------------------------
$router->get('/api/v1/logs', function ($params, $ctx) {
    header('Content-Type: application/json');
    LogController::list($ctx['user']);
}, [$authMw]);

$router->get('/api/v1/logs/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    LogController::get($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->post('/api/v1/endpoints/{id}/prune', function ($params, $ctx) {
    header('Content-Type: application/json');
    LogController::prune($ctx['user'], (int)$params['id']);
}, [$authMw]);

// -------------------------------------------------------------
// Endpoints Management Routes
// -------------------------------------------------------------
$router->get('/api/v1/endpoints', function ($params, $ctx) {
    header('Content-Type: application/json');
    EndpointController::list($ctx['user']);
}, [$authMw]);

$router->post('/api/v1/endpoints', function ($params, $ctx) {
    header('Content-Type: application/json');
    EndpointController::create($ctx['user']);
}, [$authMw]);

$router->put('/api/v1/endpoints/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    EndpointController::update($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->post('/api/v1/endpoints/{id}/regenerate-token', function ($params, $ctx) {
    header('Content-Type: application/json');
    EndpointController::regenerateToken($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->delete('/api/v1/endpoints/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    EndpointController::delete($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->get('/api/v1/endpoints/{token}/snippets', function ($params, $ctx) {
    header('Content-Type: application/json');
    SnippetController::getSnippets($ctx['user'], (string)$params['token']);
}, [$authMw]);

// -------------------------------------------------------------
// Projects Management Routes
// -------------------------------------------------------------
$router->get('/api/v1/projects', function ($params, $ctx) {
    header('Content-Type: application/json');
    ProjectController::list($ctx['user']);
}, [$authMw]);

$router->post('/api/v1/projects', function ($params, $ctx) {
    header('Content-Type: application/json');
    ProjectController::create($ctx['user']);
}, [$authMw]);

$router->get('/api/v1/projects/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    ProjectController::get($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->put('/api/v1/projects/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    ProjectController::update($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->post('/api/v1/projects/{id}/ping', function ($params, $ctx) {
    header('Content-Type: application/json');
    ProjectController::ping($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->delete('/api/v1/projects/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    ProjectController::delete($ctx['user'], (int)$params['id']);
}, [$authMw]);

// -------------------------------------------------------------
// Dashboard Metrics Routes
// -------------------------------------------------------------
$router->get('/api/v1/metrics/dashboard', function ($params, $ctx) {
    header('Content-Type: application/json');
    MetricsController::dashboardStats($ctx['user']);
}, [$authMw]);

// -------------------------------------------------------------
// Alert Rules Routes
// -------------------------------------------------------------
$router->get('/api/v1/alerts', function ($params, $ctx) {
    header('Content-Type: application/json');
    AlertController::list($ctx['user']);
}, [$authMw]);

$router->post('/api/v1/alerts', function ($params, $ctx) {
    header('Content-Type: application/json');
    AlertController::create($ctx['user']);
}, [$authMw]);

$router->delete('/api/v1/alerts/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    AlertController::delete($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->post('/api/v1/alerts/{id}/test', function ($params, $ctx) {
    header('Content-Type: application/json');
    AlertController::test($ctx['user'], (int)$params['id']);
}, [$authMw]);

// -------------------------------------------------------------
// Uptime Monitors Routes
// -------------------------------------------------------------
$router->get('/api/v1/monitors', function ($params, $ctx) {
    header('Content-Type: application/json');
    UptimeController::list($ctx['user']);
}, [$authMw]);

$router->post('/api/v1/monitors', function ($params, $ctx) {
    header('Content-Type: application/json');
    UptimeController::create($ctx['user']);
}, [$authMw]);

$router->post('/api/v1/monitors/{id}/ping', function ($params, $ctx) {
    header('Content-Type: application/json');
    UptimeController::pingNow($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->get('/api/v1/monitors/{id}/history', function ($params, $ctx) {
    header('Content-Type: application/json');
    UptimeController::history($ctx['user'], (int)$params['id']);
}, [$authMw]);

$router->delete('/api/v1/monitors/{id}', function ($params, $ctx) {
    header('Content-Type: application/json');
    UptimeController::delete($ctx['user'], (int)$params['id']);
}, [$authMw]);

// Dispatch incoming request
$router->dispatch();
