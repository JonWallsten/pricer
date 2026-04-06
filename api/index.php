<?php

declare(strict_types=1);

// ─── Bootstrap ────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/middleware.php';

// ─── CORS ─────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:4200',
];
if (APP_URL !== '') {
    $allowedOrigins[] = preg_replace('#/[^/]+$#', '', APP_URL); // strip path, keep scheme+host
    $allowedOrigins[] = rtrim(APP_URL, '/');
    // Also allow just the origin (scheme+host without path)
    $parsedAppUrl = parse_url(APP_URL);
    if ($parsedAppUrl) {
        $appOrigin = ($parsedAppUrl['scheme'] ?? 'https') . '://' . ($parsedAppUrl['host'] ?? '');
        $allowedOrigins[] = $appOrigin;
    }
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Helpers ──────────────────────────────────────────────
function sendJson(mixed $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

// ─── Parse route ──────────────────────────────────────────
$basePath = APP_API_BASE_PATH;
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = substr($requestUri, strlen($basePath)) ?: '/';
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// ─── Route dispatch ───────────────────────────────────────
// Auth routes (public)
if (str_starts_with($path, '/auth')) {
    require_once __DIR__ . '/routes/auth.php';
    handleAuthRoutes($method, $path);
    exit;
}

// Admin routes (require auth, admin check inside handler)
if (str_starts_with($path, '/admin')) {
    $authUser = requireAuth();
    require_once __DIR__ . '/routes/admin.php';
    handleAdminRoutes($method, $path, $authUser);
    exit;
}

// All routes below require authentication + approval
$authUser = requireApproved();

if (str_starts_with($path, '/products')) {
    require_once __DIR__ . '/routes/products.php';
    handleProductRoutes($method, $path, $authUser);
    exit;
}

if (str_starts_with($path, '/alerts')) {
    require_once __DIR__ . '/routes/alerts.php';
    handleAlertRoutes($method, $path, $authUser);
    exit;
}

sendJson(['error' => 'Not found'], 404);
