<?php

/**
 * Cron job: Check prices for all tracked products.
 *
 * Run hourly via Oderland cron:
 *   0 * * * * php /path/to/api/cron/check-prices.php >> ~/logs/pricer-cron.log 2>&1
 *
 * This script:
 * 1. Fetches all products not checked in the last 55 minutes
 * 2. Extracts current prices using structured data / CSS selectors
 * 3. Updates product records with results
 * 4. Sends email notifications for alerts that match
 */

declare(strict_types=1);

// Block web access — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

// Bootstrap
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/price-scraper.php';
require_once __DIR__ . '/../lib/mailer.php';

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Price check starting...\n";

$db = getDb();

// Fetch products not checked in the last 55 minutes (buffer for hourly cron)
$stmt = $db->prepare(
    'SELECT p.*, u.email AS user_email
     FROM products p
     JOIN users u ON u.id = p.user_id
     WHERE p.last_checked_at IS NULL
        OR p.last_checked_at < DATE_SUB(NOW(), INTERVAL 55 MINUTE)
     ORDER BY p.last_checked_at ASC'
);
$stmt->execute();
$products = $stmt->fetchAll();

$total = count($products);
$checked = 0;
$errors = 0;
$notified = 0;

echo "[$timestamp] Found $total products to check.\n";

foreach ($products as $product) {
    $productId = (int) $product['id'];
    $productName = $product['name'];

    echo "  Checking: $productName ({$product['url']})... ";

    $result = extractPrice($product['url'], $product['css_selector']);

    if ($result['price'] !== null) {
        echo "OK → {$result['price']} {$result['currency']} (via {$result['method']})\n";

        $oldAvailability = $product['availability'] ?? 'unknown';
        $newAvailability = $result['availability'];

        $updateStmt = $db->prepare(
            'UPDATE products
             SET current_price = :price,
                 currency = :currency,
                 image_url = :image_url,
                 availability = :avail,
                 last_checked_at = NOW(),
                 last_check_status = :status,
                 last_check_error = NULL
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':price'     => $result['price'],
            ':currency'  => $result['currency'] ?? $product['currency'],
            ':image_url' => $result['image_url'],
            ':avail'     => $newAvailability,
            ':status'    => 'success',
            ':id'        => $productId,
        ]);

        // Record price history (one entry per day via INSERT IGNORE)
        $histStmt = $db->prepare(
            'INSERT IGNORE INTO price_history (product_id, price, currency, recorded_at)
             VALUES (:pid, :price, :currency, CURDATE())'
        );
        $histStmt->execute([
            ':pid'      => $productId,
            ':price'    => $result['price'],
            ':currency' => $result['currency'] ?? $product['currency'] ?? 'SEK',
        ]);

        // Check for back-in-stock transition: out_of_stock → in_stock
        if ($oldAvailability === 'out_of_stock' && $newAvailability === 'in_stock') {
            $bisStmt = $db->prepare(
                'SELECT a.id FROM alerts a
                 WHERE a.product_id = :pid
                   AND a.is_active = 1
                   AND a.notify_back_in_stock = 1'
            );
            $bisStmt->execute([':pid' => $productId]);
            $bisAlerts = $bisStmt->fetchAll();

            foreach ($bisAlerts as $bisAlert) {
                $userEmail = $product['user_email'];
                echo "    → Back in stock! Notifying $userEmail... ";

                $sent = sendBackInStockNotification(
                    $userEmail,
                    $productName,
                    $product['url'],
                    $result['price'],
                    $result['currency'] ?? $product['currency'] ?? 'SEK'
                );

                if ($sent) {
                    echo "sent.\n";
                    $notified++;
                } else {
                    echo "FAILED.\n";
                }
            }
        }

        // Check for triggered alerts
        $alertStmt = $db->prepare(
            'SELECT * FROM alerts
             WHERE product_id = :pid
               AND is_active = 1
               AND target_price >= :price
               AND (last_notified_price IS NULL OR :price2 < last_notified_price)'
        );
        $alertStmt->execute([
            ':pid'    => $productId,
            ':price'  => $result['price'],
            ':price2' => $result['price'],
        ]);
        $alerts = $alertStmt->fetchAll();

        foreach ($alerts as $alert) {
            $userEmail = $product['user_email'];
            $targetPrice = (float) $alert['target_price'];

            echo "    → Alert triggered! Notifying $userEmail (target: $targetPrice)... ";

            $sent = sendNotification(
                $userEmail,
                $productName,
                $result['price'],
                $targetPrice,
                $product['url'],
                $result['currency'] ?? $product['currency'] ?? 'SEK'
            );

            if ($sent) {
                echo "sent.\n";
                $notifyStmt = $db->prepare(
                    'UPDATE alerts
                     SET last_notified_price = :price, last_notified_at = NOW()
                     WHERE id = :id'
                );
                $notifyStmt->execute([
                    ':price' => $result['price'],
                    ':id'    => (int) $alert['id'],
                ]);
                $notified++;
            } else {
                echo "FAILED.\n";
            }
        }
    } else {
        echo "ERROR: {$result['error']}\n";

        $updateStmt = $db->prepare(
            'UPDATE products
             SET last_checked_at = NOW(),
                 last_check_status = :status,
                 last_check_error = :error
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'error',
            ':error'  => $result['error'],
            ':id'     => $productId,
        ]);

        $errors++;
    }

    $checked++;

    // Rate limit: 2 seconds between requests
    if ($checked < $total) {
        sleep(2);
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "[$timestamp] Done. Checked: $checked, Errors: $errors, Notifications: $notified, Time: {$elapsed}s\n";
