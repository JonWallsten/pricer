<?php

declare(strict_types=1);

// ─── JWT helpers (HMAC-SHA256) ────────────────────────────

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
}

function createJwt(array $payload, int $expiresInSeconds = 2592000): string
{
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload['iat'] = time();
    $payload['exp'] = time() + $expiresInSeconds;
    $body = base64UrlEncode(json_encode($payload));

    $signature = base64UrlEncode(
        hash_hmac('sha256', "$header.$body", JWT_SECRET, true)
    );

    return "$header.$body.$signature";
}

function verifyJwt(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $signature] = $parts;

    $expectedSig = base64UrlEncode(
        hash_hmac('sha256', "$header.$body", JWT_SECRET, true)
    );

    if (!hash_equals($expectedSig, $signature)) {
        return null;
    }

    $payload = json_decode(base64UrlDecode($body), true);
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function isGoogleEmailVerified(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function isConfiguredAdminGoogleId(string $googleId): bool
{
    return ADMIN_GOOGLE_ID !== '' && hash_equals(ADMIN_GOOGLE_ID, $googleId);
}

// ─── Cookie helpers ───────────────────────────────────────

function getAuthCookiePath(): string
{
    // In production the app lives at /pricer/api/; in dev it's /
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($scriptName, '/pricer/')) {
        return '/pricer/api/';
    }
    return '/';
}

function setAuthCookie(string $jwt, int $expiresInSeconds = 2592000): void
{
    $isSecure = ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie('auth_token', $jwt, [
        'expires'  => time() + $expiresInSeconds,
        'path'     => getAuthCookiePath(),
        'secure'   => $isSecure,
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);
}

function clearAuthCookie(): void
{
    $isSecure = ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie('auth_token', '', [
        'expires'  => time() - 3600,
        'path'     => getAuthCookiePath(),
        'secure'   => $isSecure,
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE['auth_token']);
}
