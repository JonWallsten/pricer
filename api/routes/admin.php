<?php

declare(strict_types=1);

function handleAdminRoutes(string $method, string $path, array $authUser): void
{
    // Guard: only ADMIN_EMAIL can access admin routes
    if (ADMIN_EMAIL === '' || $authUser['email'] !== ADMIN_EMAIL) {
        sendJson(['error' => 'Forbidden'], 403);
        return;
    }

    $db = getDb();

    // GET /admin/users — list all users
    if ($method === 'GET' && $path === '/admin/users') {
        $stmt = $db->query(
            'SELECT id, email, name, picture_url, is_approved, created_at, last_login_at
             FROM users ORDER BY created_at DESC'
        );
        $users = $stmt->fetchAll();

        $result = array_map(static fn(array $u) => [
            'id'            => (int) $u['id'],
            'email'         => $u['email'],
            'name'          => $u['name'],
            'picture_url'   => $u['picture_url'],
            'is_approved'   => (bool) (int) $u['is_approved'],
            'is_admin'      => $u['email'] === ADMIN_EMAIL,
            'created_at'    => $u['created_at'],
            'last_login_at' => $u['last_login_at'],
        ], $users);

        sendJson(['users' => $result]);
        return;
    }

    // PUT /admin/users/:id/approve
    if ($method === 'PUT' && preg_match('#^/admin/users/(\d+)/approve$#', $path, $m)) {
        $userId = (int) $m[1];
        $stmt = $db->prepare('UPDATE users SET is_approved = 1 WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        if ($stmt->rowCount() === 0) {
            sendJson(['error' => 'User not found'], 404);
            return;
        }

        sendJson(['success' => true]);
        return;
    }

    // PUT /admin/users/:id/reject
    if ($method === 'PUT' && preg_match('#^/admin/users/(\d+)/reject$#', $path, $m)) {
        $userId = (int) $m[1];
        $stmt = $db->prepare('UPDATE users SET is_approved = 0 WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        if ($stmt->rowCount() === 0) {
            sendJson(['error' => 'User not found'], 404);
            return;
        }

        sendJson(['success' => true]);
        return;
    }

    sendJson(['error' => 'Not found'], 404);
}
