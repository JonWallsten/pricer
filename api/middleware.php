<?php

declare(strict_types=1);

/**
 * Extract and verify the authenticated user from the auth cookie.
 * Returns the JWT payload (containing user_id, email) or null.
 */
function getAuthUser(): ?array
{
    $token = $_COOKIE['auth_token'] ?? null;

    if ($token === null) {
        return null;
    }

    return verifyJwt($token);
}

/**
 * Require authentication. Sends 401 and exits if not authenticated.
 * Returns the authenticated user payload.
 */
function requireAuth(): array
{
    $user = getAuthUser();

    if ($user === null) {
        sendJson(['error' => 'Unauthorized'], 401);
        exit;
    }

    return $user;
}

/**
 * Require authentication AND approval. Sends 401 if not authenticated,
 * 403 if not yet approved. Returns the authenticated user payload.
 */
function requireApproved(): array
{
    $authUser = requireAuth();

    $db = getDb();
    $stmt = $db->prepare('SELECT is_approved FROM users WHERE id = :id');
    $stmt->execute([':id' => $authUser['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !(int) $row['is_approved']) {
        sendJson(['error' => 'Account pending approval'], 403);
        exit;
    }

    return $authUser;
}
