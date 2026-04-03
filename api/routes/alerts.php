<?php

declare(strict_types=1);

function handleAlertRoutes(string $method, string $path, array $authUser): void
{
    $db = getDb();
    $userId = (int) $authUser['user_id'];

    // PUT /alerts/:id — update alert
    if ($method === 'PUT' && preg_match('#^/alerts/(\d+)$#', $path, $m)) {
        $alertId = (int) $m[1];

        $stmt = $db->prepare('SELECT * FROM alerts WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $alertId, ':uid' => $userId]);
        $alert = $stmt->fetch();

        if (!$alert) {
            sendJson(['error' => 'Alert not found'], 404);
            return;
        }

        $body = getJsonBody();
        $fields = [];
        $params = [':id' => $alertId];

        if (isset($body['target_price'])) {
            $targetPrice = (float) $body['target_price'];
            if ($targetPrice <= 0) {
                sendJson(['error' => 'Target price must be positive'], 400);
                return;
            }
            $fields[] = 'target_price = :target';
            $params[':target'] = $targetPrice;

            // Reset notification state when target changes
            $fields[] = 'last_notified_price = NULL';
            $fields[] = 'last_notified_at = NULL';
        }

        if (isset($body['is_active'])) {
            $fields[] = 'is_active = :active';
            $params[':active'] = $body['is_active'] ? 1 : 0;

            // Reset notification state when re-enabling
            if ($body['is_active']) {
                $fields[] = 'last_notified_price = NULL';
                $fields[] = 'last_notified_at = NULL';
            }
        }

        if (isset($body['notify_back_in_stock'])) {
            $fields[] = 'notify_back_in_stock = :notify_bis';
            $params[':notify_bis'] = $body['notify_back_in_stock'] ? 1 : 0;
        }

        if (empty($fields)) {
            sendJson(['error' => 'No fields to update'], 400);
            return;
        }

        $sql = 'UPDATE alerts SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $stmt = $db->prepare('SELECT * FROM alerts WHERE id = :id');
        $stmt->execute([':id' => $alertId]);
        $updated = $stmt->fetch();
        castAlertFields($updated);

        sendJson(['alert' => $updated]);
        return;
    }

    // DELETE /alerts/:id — delete alert
    if ($method === 'DELETE' && preg_match('#^/alerts/(\d+)$#', $path, $m)) {
        $alertId = (int) $m[1];

        $stmt = $db->prepare('SELECT id FROM alerts WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $alertId, ':uid' => $userId]);

        if (!$stmt->fetch()) {
            sendJson(['error' => 'Alert not found'], 404);
            return;
        }

        $stmt = $db->prepare('DELETE FROM alerts WHERE id = :id');
        $stmt->execute([':id' => $alertId]);

        sendJson(['success' => true]);
        return;
    }

    sendJson(['error' => 'Not found'], 404);
}

/**
 * Create a new alert for a product. Called from products route handler.
 */
function handleCreateAlert(int $productId, int $userId, PDO $db): void
{
    // Verify product ownership
    $stmt = $db->prepare('SELECT id FROM products WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $productId, ':uid' => $userId]);

    if (!$stmt->fetch()) {
        sendJson(['error' => 'Product not found'], 404);
        return;
    }

    $body = getJsonBody();
    $targetPrice = isset($body['target_price']) ? (float) $body['target_price'] : 0;

    if ($targetPrice <= 0) {
        sendJson(['error' => 'Target price must be positive'], 400);
        return;
    }

    $notifyBackInStock = !empty($body['notify_back_in_stock']) ? 1 : 0;

    $stmt = $db->prepare(
        'INSERT INTO alerts (product_id, user_id, target_price, notify_back_in_stock)
         VALUES (:pid, :uid, :target, :notify_bis)'
    );
    $stmt->execute([
        ':pid'        => $productId,
        ':uid'        => $userId,
        ':target'     => $targetPrice,
        ':notify_bis' => $notifyBackInStock,
    ]);

    $alertId = (int) $db->lastInsertId();

    $stmt = $db->prepare('SELECT * FROM alerts WHERE id = :id');
    $stmt->execute([':id' => $alertId]);
    $alert = $stmt->fetch();
    castAlertFields($alert);

    sendJson(['alert' => $alert], 201);
}

function castAlertFields(array &$alert): void
{
    $alert['id'] = (int) $alert['id'];
    $alert['product_id'] = (int) $alert['product_id'];
    $alert['user_id'] = (int) $alert['user_id'];
    $alert['target_price'] = (float) $alert['target_price'];
    $alert['is_active'] = (bool) $alert['is_active'];
    $alert['last_notified_price'] = $alert['last_notified_price'] !== null ? (float) $alert['last_notified_price'] : null;
    $alert['notify_back_in_stock'] = (bool) ($alert['notify_back_in_stock'] ?? false);
}
