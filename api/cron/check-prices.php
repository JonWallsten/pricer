<?php

/**
 * Cron job: Check prices for all tracked products (multi-URL aware).
 *
 * Run hourly via Oderland cron:
 *   0 * * * * php /path/to/api/cron/check-prices.php >> ~/logs/pricer-cron.log 2>&1
 *
 * This script:
 * 1. Fetches all product_urls not checked in the last 55 minutes
 * 2. Extracts current prices using structured data / CSS selectors
 * 3. Updates product_url records with results
 * 4. Syncs each product's best price from its URLs
 * 5. Sends email notifications for alerts that match
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
require_once __DIR__ . '/../lib/domain-patterns.php';

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Price check starting...\n";

$db = getDb();

// Fetch product_urls not checked in the last 55 minutes, joined with product + user info
$stmt = $db->prepare(
    'SELECT pu.*, p.name AS product_name, p.availability AS old_availability,
            p.currency AS product_currency, u.email AS user_email
     FROM product_urls pu
     JOIN products p ON p.id = pu.product_id
     JOIN users u ON u.id = p.user_id
     WHERE pu.last_checked_at IS NULL
        OR pu.last_checked_at < DATE_SUB(NOW(), INTERVAL 55 MINUTE)
     ORDER BY pu.last_checked_at ASC'
);
$stmt->execute();
$urlRows = $stmt->fetchAll();

$total = count($urlRows);
$checked = 0;
$errors = 0;
$notified = 0;

// Track which products we need to sync after checking their URLs
$productsToSync = [];

echo "[$timestamp] Found $total URLs to check.\n";

foreach ($urlRows as $row) {
    $urlId = (int) $row['id'];
    $productId = (int) $row['product_id'];
    $productName = $row['product_name'];

    echo "  Checking: $productName ({$row['url']})... ";

    $result = extractPrice($row['url'], $row['css_selector'], $row['extraction_strategy'] ?? 'auto');

    if ($result['price'] !== null) {
        echo "OK → {$result['price']} {$result['currency']} (via {$result['method']})\n";

        $updateStmt = $db->prepare(
            'UPDATE product_urls
             SET current_price = :price, currency = :currency,
                 image_url = :image_url, availability = :avail,
                 last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':price'    => $result['price'],
            ':currency' => $result['currency'] ?? $row['product_currency'] ?? 'SEK',
            ':image_url' => $result['image_url'],
            ':avail'    => $result['availability'],
            ':status'   => 'success',
            ':id'       => $urlId,
        ]);
    } else {
        echo "ERROR: {$result['error']}\n";

        $updateStmt = $db->prepare(
            'UPDATE product_urls
             SET last_checked_at = NOW(), last_check_status = :status, last_check_error = :error
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'error',
            ':error'  => $result['error'],
            ':id'     => $urlId,
        ]);

        $errors++;
    }

    // Record domain pattern for learning
    if ($result['price'] !== null) {
        recordSuccessfulPattern($db, $row['url'], $result);
    } else {
        recordFailedPattern($db, $row['url'], $result['method'] ?? null, $row['css_selector']);
    }

    // Mark this product for syncing
    $productsToSync[$productId] = [
        'user_email' => $row['user_email'],
        'product_name' => $productName,
        'old_availability' => $row['old_availability'] ?? 'unknown',
    ];

    $checked++;

    // Rate limit: 2 seconds between requests
    if ($checked < $total) {
        sleep(2);
    }
}

// Now sync each affected product's best price and check alerts
echo "[$timestamp] Syncing " . count($productsToSync) . " products...\n";

foreach ($productsToSync as $productId => $info) {
    // Find cheapest successful URL
    $bestStmt = $db->prepare(
        'SELECT * FROM product_urls
         WHERE product_id = :pid AND current_price IS NOT NULL AND last_check_status = :status
         ORDER BY current_price ASC LIMIT 1'
    );
    $bestStmt->execute([':pid' => $productId, ':status' => 'success']);
    $best = $bestStmt->fetch();

    // Compute best availability across all URLs
    $availStmt = $db->prepare('SELECT availability FROM product_urls WHERE product_id = :pid');
    $availStmt->execute([':pid' => $productId]);
    $availRows = $availStmt->fetchAll();
    $priority = ['in_stock' => 4, 'preorder' => 3, 'out_of_stock' => 2, 'unknown' => 1];
    $bestAvail = 'unknown';
    $bestPri = 0;
    foreach ($availRows as $ar) {
        $avail = $ar['availability'] ?? 'unknown';
        $pri = $priority[$avail] ?? 0;
        if ($pri > $bestPri) {
            $bestPri = $pri;
            $bestAvail = $avail;
        }
    }

    if ($best) {
        $updateProd = $db->prepare(
            'UPDATE products
             SET current_price = :price, currency = :currency,
                 url = :url, css_selector = :css,
                 image_url = :image_url, availability = :avail,
                 last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
             WHERE id = :id'
        );
        $updateProd->execute([
            ':price'    => $best['current_price'],
            ':currency' => $best['currency'],
            ':url'      => $best['url'],
            ':css'      => $best['css_selector'],
            ':image_url' => $best['image_url'],
            ':avail'    => $bestAvail,
            ':status'   => 'success',
            ':id'       => $productId,
        ]);

        $currentPrice = (float) $best['current_price'];
        $currency = $best['currency'] ?? 'SEK';

        // Record price history (one entry per day via INSERT IGNORE)
        $histStmt = $db->prepare(
            'INSERT IGNORE INTO price_history (product_id, price, currency, recorded_at)
             VALUES (:pid, :price, :currency, CURDATE())'
        );
        $histStmt->execute([
            ':pid'      => $productId,
            ':price'    => $currentPrice,
            ':currency' => $currency,
        ]);

        $oldAvailability = $info['old_availability'];

        // Check for back-in-stock transition: out_of_stock → in_stock
        // Only notify if alert also has target_price met
        if ($oldAvailability === 'out_of_stock' && $bestAvail === 'in_stock') {
            $bisStmt = $db->prepare(
                'SELECT a.id FROM alerts a
                 WHERE a.product_id = :pid AND a.is_active = 1 AND a.notify_back_in_stock = 1
                   AND a.target_price >= :price'
            );
            $bisStmt->execute([':pid' => $productId, ':price' => $currentPrice]);
            $bisAlerts = $bisStmt->fetchAll();

            foreach ($bisAlerts as $bisAlert) {
                echo "    → Back in stock! Notifying {$info['user_email']}... ";
                $sent = sendBackInStockNotification(
                    $info['user_email'],
                    $info['product_name'],
                    $best['url'],
                    $currentPrice,
                    $currency,
                    $productId
                );
                echo $sent ? "sent.\n" : "FAILED.\n";
                if ($sent) $notified++;
            }
        }

        // Check for triggered alerts
        $alertStmt = $db->prepare(
            'SELECT * FROM alerts
             WHERE product_id = :pid AND is_active = 1 AND target_price >= :price
               AND (last_notified_price IS NULL OR :price2 < last_notified_price)'
        );
        $alertStmt->execute([
            ':pid'    => $productId,
            ':price'  => $currentPrice,
            ':price2' => $currentPrice,
        ]);
        $alerts = $alertStmt->fetchAll();

        foreach ($alerts as $alert) {
            $targetPrice = (float) $alert['target_price'];

            // If alert requires in-stock and product is explicitly out of stock, defer notification
            // (unknown availability is allowed through — only defer when we know it's out of stock)
            if (!empty($alert['notify_back_in_stock']) && $bestAvail === 'out_of_stock') {
                echo "    → Alert target met but out of stock, deferring (target: $targetPrice).\n";
                continue;
            }

            echo "    → Alert triggered! Notifying {$info['user_email']} (target: $targetPrice)... ";

            $sent = sendNotification(
                $info['user_email'],
                $info['product_name'],
                $currentPrice,
                $targetPrice,
                $best['url'],
                $currency,
                $productId
            );

            if ($sent) {
                echo "sent.\n";
                $notifyStmt = $db->prepare(
                    'UPDATE alerts SET last_notified_price = :price, last_notified_at = NOW() WHERE id = :id'
                );
                $notifyStmt->execute([':price' => $currentPrice, ':id' => (int) $alert['id']]);
                $notified++;
            } else {
                echo "FAILED.\n";
            }
        }
    } else {
        // No successful URL for this product
        $errStmt = $db->prepare(
            'SELECT last_check_error FROM product_urls WHERE product_id = :pid AND last_check_status = :status LIMIT 1'
        );
        $errStmt->execute([':pid' => $productId, ':status' => 'error']);
        $err = $errStmt->fetch();

        $updateProd = $db->prepare(
            'UPDATE products SET last_checked_at = NOW(), last_check_status = :status, last_check_error = :error WHERE id = :id'
        );
        $updateProd->execute([
            ':status' => $err ? 'error' : 'pending',
            ':error'  => $err ? $err['last_check_error'] : null,
            ':id'     => $productId,
        ]);
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "[$timestamp] Done. URLs checked: $checked, Errors: $errors, Notifications: $notified, Time: {$elapsed}s\n";
