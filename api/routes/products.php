<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/price-scraper.php';

function handleProductRoutes(string $method, string $path, array $authUser): void
{
    $db = getDb();
    $userId = (int) $authUser['user_id'];

    // GET /products — list user's products
    if ($method === 'GET' && $path === '/products') {
        $stmt = $db->prepare(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM alerts a WHERE a.product_id = p.id AND a.is_active = 1) AS active_alerts
             FROM products p
             WHERE p.user_id = :uid
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        $products = $stmt->fetchAll();

        // Cast numeric fields
        foreach ($products as &$p) {
            castProductFields($p);
        }

        sendJson(['products' => $products]);
        return;
    }

    // POST /products/preview — preview price extraction without creating product
    if ($method === 'POST' && $path === '/products/preview') {
        $body = getJsonBody();
        $url = trim($body['url'] ?? '');
        $cssSelector = isset($body['css_selector']) ? trim($body['css_selector']) : null;

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            sendJson(['error' => 'A valid URL is required'], 400);
            return;
        }

        $result = extractPreview($url, $cssSelector ?: null);
        sendJson(['preview' => $result]);
        return;
    }

    // POST /products — create a new product
    if ($method === 'POST' && $path === '/products') {
        $body = getJsonBody();
        $name = trim($body['name'] ?? '');
        $url = trim($body['url'] ?? '');
        $cssSelector = isset($body['css_selector']) ? trim($body['css_selector']) : null;

        if ($name === '') {
            sendJson(['error' => 'Name is required'], 400);
            return;
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            sendJson(['error' => 'A valid URL is required'], 400);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO products (user_id, name, url, css_selector)
             VALUES (:uid, :name, :url, :css)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':name' => $name,
            ':url'  => $url,
            ':css'  => $cssSelector ?: null,
        ]);

        $productId = (int) $db->lastInsertId();

        // Auto-check price on creation
        $result = extractPrice($url, $cssSelector ?: null);
        if ($result['price'] !== null) {
            $updateStmt = $db->prepare(
                'UPDATE products
                 SET current_price = :price, currency = :currency,
                     image_url = :image_url, availability = :avail,
                     last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':price'    => $result['price'],
                ':currency' => $result['currency'] ?? 'SEK',
                ':image_url' => $result['image_url'],
                ':avail'    => $result['availability'],
                ':status'   => 'success',
                ':id'       => $productId,
            ]);

            // Record first price history entry
            recordPriceHistory($db, $productId, $result['price'], $result['currency'] ?? 'SEK');
        } else {
            $updateStmt = $db->prepare(
                'UPDATE products
                 SET last_checked_at = NOW(), last_check_status = :status, last_check_error = :error
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':status' => 'error',
                ':error'  => $result['error'],
                ':id'     => $productId,
            ]);
        }

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch();
        castProductFields($product);

        sendJson(['product' => $product], 201);
        return;
    }

    // Match /products/:id routes
    if (preg_match('#^/products/(\d+)$#', $path, $m)) {
        $productId = (int) $m[1];

        // Fetch and verify ownership
        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        $product = $stmt->fetch();

        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        // GET /products/:id — single product with alerts
        if ($method === 'GET') {
            castProductFields($product);

            $alertStmt = $db->prepare(
                'SELECT * FROM alerts WHERE product_id = :pid ORDER BY created_at DESC'
            );
            $alertStmt->execute([':pid' => $productId]);
            $alerts = $alertStmt->fetchAll();

            foreach ($alerts as &$a) {
                castAlertFieldsInline($a);
            }

            sendJson(['product' => $product, 'alerts' => $alerts]);
            return;
        }

        // PUT /products/:id — update product
        if ($method === 'PUT') {
            $body = getJsonBody();
            $fields = [];
            $params = [':id' => $productId];

            if (isset($body['name'])) {
                $name = trim($body['name']);
                if ($name === '') {
                    sendJson(['error' => 'Name cannot be empty'], 400);
                    return;
                }
                $fields[] = 'name = :name';
                $params[':name'] = $name;
            }
            if (isset($body['url'])) {
                $url = trim($body['url']);
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    sendJson(['error' => 'A valid URL is required'], 400);
                    return;
                }
                $fields[] = 'url = :url';
                $params[':url'] = $url;
            }
            if (array_key_exists('css_selector', $body)) {
                $fields[] = 'css_selector = :css';
                $params[':css'] = $body['css_selector'] !== null ? trim($body['css_selector']) : null;
            }

            if (empty($fields)) {
                sendJson(['error' => 'No fields to update'], 400);
                return;
            }

            $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
            $stmt->execute([':id' => $productId]);
            $updated = $stmt->fetch();
            castProductFields($updated);

            sendJson(['product' => $updated]);
            return;
        }

        // DELETE /products/:id — delete product (alerts cascade)
        if ($method === 'DELETE') {
            $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
            $stmt->execute([':id' => $productId]);
            sendJson(['success' => true]);
            return;
        }
    }

    // POST /products/:id/check — trigger manual price check
    if ($method === 'POST' && preg_match('#^/products/(\d+)/check$#', $path, $m)) {
        $productId = (int) $m[1];

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        $product = $stmt->fetch();

        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        $result = extractPrice($product['url'], $product['css_selector']);

        if ($result['price'] !== null) {
            $stmt = $db->prepare(
                'UPDATE products
                 SET current_price = :price, currency = :currency,
                     image_url = :image_url, availability = :avail,
                     last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
                 WHERE id = :id'
            );
            $stmt->execute([
                ':price'     => $result['price'],
                ':currency'  => $result['currency'] ?? $product['currency'],
                ':image_url' => $result['image_url'],
                ':avail'     => $result['availability'],
                ':status'    => 'success',
                ':id'        => $productId,
            ]);

            // Record price history (one per day via INSERT IGNORE)
            recordPriceHistory($db, $productId, $result['price'], $result['currency'] ?? $product['currency'] ?? 'SEK');
        } else {
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE products
                 SET last_checked_at = NOW(), last_check_status = :status, last_check_error = :error
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => 'error',
                ':error'  => $result['error'],
                ':id'     => $productId,
            ]);
        }

        // Return the extraction result + updated product
        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $updated = $stmt->fetch();
        castProductFields($updated);

        sendJson([
            'product'    => $updated,
            'extraction' => $result,
        ]);
        return;
    }

    // GET /products/:id/history — price history for charts
    if ($method === 'GET' && preg_match('#^/products/(\d+)/history$#', $path, $m)) {
        $productId = (int) $m[1];

        $stmt = $db->prepare('SELECT id FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        if (!$stmt->fetch()) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        $period = $_GET['period'] ?? 'month';
        $days = match ($period) {
            'week'       => 7,
            'month'      => 30,
            'three_months' => 90,
            'year'       => 365,
            'all'        => 99999,
            default      => 30,
        };

        $stmt = $db->prepare(
            'SELECT price, currency, recorded_at
             FROM price_history
             WHERE product_id = :pid AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY recorded_at ASC'
        );
        $stmt->execute([':pid' => $productId, ':days' => $days]);
        $history = $stmt->fetchAll();

        foreach ($history as &$h) {
            $h['price'] = (float) $h['price'];
        }

        sendJson(['history' => $history]);
        return;
    }

    // POST /products/:id/alerts — create alert (delegate to alerts handler)
    if ($method === 'POST' && preg_match('#^/products/(\d+)/alerts$#', $path, $m)) {
        require_once __DIR__ . '/alerts.php';
        handleCreateAlert((int) $m[1], $userId, $db);
        return;
    }

    sendJson(['error' => 'Not found'], 404);
}

function castProductFields(array &$p): void
{
    $p['id'] = (int) $p['id'];
    $p['user_id'] = (int) $p['user_id'];
    $p['current_price'] = $p['current_price'] !== null ? (float) $p['current_price'] : null;
    if (isset($p['active_alerts'])) {
        $p['active_alerts'] = (int) $p['active_alerts'];
    }
}

function castAlertFieldsInline(array &$a): void
{
    $a['id'] = (int) $a['id'];
    $a['product_id'] = (int) $a['product_id'];
    $a['user_id'] = (int) $a['user_id'];
    $a['target_price'] = (float) $a['target_price'];
    $a['is_active'] = (bool) $a['is_active'];
    $a['last_notified_price'] = $a['last_notified_price'] !== null ? (float) $a['last_notified_price'] : null;
    $a['notify_back_in_stock'] = (bool) ($a['notify_back_in_stock'] ?? false);
}

function recordPriceHistory(PDO $db, int $productId, float $price, string $currency): void
{
    $stmt = $db->prepare(
        'INSERT IGNORE INTO price_history (product_id, price, currency, recorded_at)
         VALUES (:pid, :price, :currency, CURDATE())'
    );
    $stmt->execute([
        ':pid'      => $productId,
        ':price'    => $price,
        ':currency' => $currency,
    ]);
}
