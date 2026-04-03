<?php

declare(strict_types=1);

function handleAuthRoutes(string $method, string $path): void
{
    // GET /auth/config — public Google client ID for frontend
    if ($method === 'GET' && $path === '/auth/config') {
        sendJson(['google_client_id' => GOOGLE_CLIENT_ID]);
        return;
    }

    // POST /auth/logout — clear auth cookie
    if ($method === 'POST' && $path === '/auth/logout') {
        clearAuthCookie();
        sendJson(['success' => true]);
        return;
    }

    // POST /auth/google — exchange Google ID token for JWT
    if ($method === 'POST' && $path === '/auth/google') {
        $body = getJsonBody();
        $idToken = $body['token'] ?? '';

        if ($idToken === '') {
            sendJson(['error' => 'Missing token'], 400);
            return;
        }

        // Verify the Google ID token via Google's tokeninfo endpoint
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $response = @file_get_contents($url);

        if ($response === false) {
            sendJson(['error' => 'Failed to verify token'], 401);
            return;
        }

        $payload = json_decode($response, true);

        if (!is_array($payload) || ($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
            sendJson(['error' => 'Invalid token'], 401);
            return;
        }

        $googleId = $payload['sub'] ?? '';
        $email    = $payload['email'] ?? '';
        $name     = $payload['name'] ?? '';
        $picture  = $payload['picture'] ?? null;
        $emailVerified = isGoogleEmailVerified($payload['email_verified'] ?? false);

        if ($googleId === '' || $email === '') {
            sendJson(['error' => 'Incomplete token data'], 400);
            return;
        }
        if (!$emailVerified) {
            sendJson(['error' => 'Google email is not verified'], 401);
            return;
        }

        // Upsert user
        $db = getDb();
        $stmt = $db->prepare(
            'INSERT INTO users (google_id, email, name, picture_url, last_login_at)
             VALUES (:gid, :email, :name, :pic, NOW())
             ON DUPLICATE KEY UPDATE
               email = VALUES(email),
               name = VALUES(name),
               picture_url = VALUES(picture_url),
               last_login_at = NOW()'
        );
        $stmt->execute([
            ':gid'   => $googleId,
            ':email' => $email,
            ':name'  => $name,
            ':pic'   => $picture,
        ]);

        // Fetch the user row (need the auto-increment id)
        $stmt = $db->prepare('SELECT id, email, name, picture_url, is_approved FROM users WHERE google_id = :gid');
        $stmt->execute([':gid' => $googleId]);
        $user = $stmt->fetch();

        if (!$user) {
            sendJson(['error' => 'User not found after upsert'], 500);
            return;
        }

        // Auto-approve admin
        $isAdmin = isConfiguredAdminGoogleId($googleId);
        if ($isAdmin && !(int) $user['is_approved']) {
            $db->prepare('UPDATE users SET is_approved = 1 WHERE id = :id')
                ->execute([':id' => $user['id']]);
            $user['is_approved'] = 1;
        }

        $jwt = createJwt([
            'user_id' => (int) $user['id'],
            'email'   => $user['email'],
        ]);

        setAuthCookie($jwt);

        sendJson([
            'user'  => [
                'id'          => (int) $user['id'],
                'email'       => $user['email'],
                'name'        => $user['name'],
                'picture_url' => $user['picture_url'],
                'is_approved' => (bool) (int) $user['is_approved'],
                'is_admin'    => $isAdmin,
            ],
        ]);
        return;
    }

    // GET /auth/me — return current user from JWT
    if ($method === 'GET' && $path === '/auth/me') {
        $authUser = getAuthUser();

        if ($authUser === null) {
            sendJson(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = getDb();
        $stmt = $db->prepare('SELECT id, email, name, picture_url, is_approved, google_id FROM users WHERE id = :id');
        $stmt->execute([':id' => $authUser['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            sendJson(['error' => 'User not found'], 404);
            return;
        }

        sendJson([
            'user' => [
                'id'          => (int) $user['id'],
                'email'       => $user['email'],
                'name'        => $user['name'],
                'picture_url' => $user['picture_url'],
                'is_approved' => (bool) (int) $user['is_approved'],
                'is_admin'    => isConfiguredAdminGoogleId((string) $user['google_id']),
            ],
        ]);
        return;
    }

    sendJson(['error' => 'Not Found'], 404);
}
