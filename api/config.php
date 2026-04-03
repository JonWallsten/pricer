<?php

declare(strict_types=1);

// ─── Load credentials from .credentials.env (+ local overlay) ─
$baseEnvFile = dirname(__DIR__) . '/.credentials.env';
$localEnvFile = dirname(__DIR__) . '/.credentials.local.env';

if (!file_exists($baseEnvFile)) {
    throw new RuntimeException('.credentials.env not found. Copy .credentials.env.example and fill in your values.');
}

function loadEnvFile(string $path): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $_ENV[$key] = $value;
    }
}

loadEnvFile($baseEnvFile);
if (file_exists($localEnvFile)) {
    loadEnvFile($localEnvFile);
}

// ─── Constants ────────────────────────────────────────────
define('DB_HOST', $_ENV['DB_HOST'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? '');
define('UPLOADS_DIR', __DIR__ . '/uploads');

// ─── Optional SMTP config (falls back to PHP mail() if not set) ─
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? '587');
define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');
define('SMTP_FROM', $_ENV['SMTP_FROM'] ?? '');
define('NOTIFICATION_FROM_EMAIL', $_ENV['NOTIFICATION_FROM_EMAIL'] ?? 'noreply@jonwallsten.com');
define('ADMIN_EMAIL', $_ENV['ADMIN_EMAIL'] ?? '');

// ─── Validate critical secrets ────────────────────────────
if (JWT_SECRET === '') {
    throw new RuntimeException('JWT_SECRET is not set. Check .credentials.env.');
}
