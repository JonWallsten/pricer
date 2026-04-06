<?php

/**
 * E2E test: Run price check on a single product and send a test alert email.
 *
 * Usage:
 *   php api/cron/test-alert-email.php --product=<id> --email=<address> [--dry-run]
 *
 * What it does:
 *   1. Checks all URLs for the given product (real HTTP fetch + price extraction)
 *   2. Syncs the product's best price
 *   3. Sends a price-alert email to the given address (regardless of alert config)
 *   4. Optionally sends a back-in-stock email too (if --back-in-stock flag is set)
 *
 * Flags:
 *   --product=<id>     Product ID to test (required)
 *   --email=<address>  Recipient email (required)
 *   --back-in-stock    Also send a back-in-stock email
 *   --dry-run          Run price check but skip sending emails
 *   --skip-check       Skip price check, use existing data to test email only
 */

declare(strict_types=1);

// Block web access — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

// Parse CLI arguments
$opts = getopt('', ['product:', 'email:', 'back-in-stock', 'dry-run', 'skip-check']);

$productId = isset($opts['product']) ? (int) $opts['product'] : 0;
$email = $opts['email'] ?? '';
$sendBackInStock = isset($opts['back-in-stock']);
$dryRun = isset($opts['dry-run']);
$skipCheck = isset($opts['skip-check']);

if ($productId <= 0 || $email === '') {
    echo "Usage: php test-alert-email.php --product=<id> --email=<address> [--dry-run] [--skip-check] [--back-in-stock]\n";
    exit(1);
}

// Bootstrap
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/price-scraper.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/domain-patterns.php';

$db = getDb();
echo "=== Pricer E2E Alert Test ===\n\n";

// 1. Verify product exists
$prodStmt = $db->prepare('SELECT * FROM products WHERE id = :id');
$prodStmt->execute([':id' => $productId]);
$product = $prodStmt->fetch();

if (!$product) {
    echo "ERROR: Product #$productId not found.\n";
    exit(1);
}

echo "Product: {$product['name']} (ID: $productId)\n";
echo "Current price: " . ($product['current_price'] ?? 'N/A') . " " . ($product['currency'] ?? 'SEK') . "\n";
echo "Availability: " . ($product['availability'] ?? 'unknown') . "\n";
echo "Email target: $email\n";
echo "Dry run: " . ($dryRun ? 'yes' : 'no') . "\n\n";

// 2. Fetch product URLs
$urlStmt = $db->prepare('SELECT * FROM product_urls WHERE product_id = :pid');
$urlStmt->execute([':pid' => $productId]);
$urls = $urlStmt->fetchAll();

if (empty($urls)) {
    echo "ERROR: No URLs found for product #$productId.\n";
    exit(1);
}

echo "Found " . count($urls) . " URL(s).\n\n";

// 3. Check prices (unless --skip-check)
if (!$skipCheck) {
    echo "--- Price Check ---\n";
    foreach ($urls as $i => $row) {
        echo "  [{$row['id']}] {$row['url']}\n";
        echo "       Checking... ";

        $result = extractPrice($row['url'], $row['css_selector'], $row['extraction_strategy'] ?? 'auto');

        if ($result['price'] !== null) {
            echo "OK → {$result['price']} {$result['currency']} (via {$result['method']})\n";
            echo "       Availability: {$result['availability']}\n";

            $updateStmt = $db->prepare(
                'UPDATE product_urls
                 SET current_price = :price, currency = :currency,
                     image_url = :image_url, availability = :avail,
                     last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':price'    => $result['price'],
                ':currency' => $result['currency'] ?? $product['currency'] ?? 'SEK',
                ':image_url' => $result['image_url'],
                ':avail'    => $result['availability'],
                ':status'   => 'success',
                ':id'       => (int) $row['id'],
            ]);

            recordSuccessfulPattern($db, $row['url'], $result);
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
                ':id'     => (int) $row['id'],
            ]);

            recordFailedPattern($db, $row['url'], $result['method'] ?? null, $row['css_selector']);
        }

        if ($i < count($urls) - 1) {
            sleep(1);
        }
    }
    echo "\n";
} else {
    echo "--- Skipping price check (using existing data) ---\n\n";
}

// 4. Sync product best price
$bestStmt = $db->prepare(
    'SELECT * FROM product_urls
     WHERE product_id = :pid AND current_price IS NOT NULL AND last_check_status = :status
     ORDER BY current_price ASC LIMIT 1'
);
$bestStmt->execute([':pid' => $productId, ':status' => 'success']);
$best = $bestStmt->fetch();

if (!$best) {
    echo "WARNING: No successful URL with a price found. Cannot send price alert.\n";
    if (!$sendBackInStock) {
        exit(1);
    }
}

if ($best) {
    // Compute best availability
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

    $currentPrice = (float) $best['current_price'];
    $currency = $best['currency'] ?? 'SEK';

    echo "--- Best Price ---\n";
    echo "  Price: $currentPrice $currency\n";
    echo "  URL: {$best['url']}\n";
    echo "  Availability: $bestAvail\n\n";
}

// 5. Send test emails
echo "--- Email Test ---\n";

if ($dryRun) {
    echo "  DRY RUN — skipping email send.\n";
    if ($best) {
        echo "  Would send price alert to: $email\n";
        echo "    Product: {$product['name']}\n";
        echo "    Price: $currentPrice $currency (target: $currentPrice for test)\n";
        echo "    Store URL: {$best['url']}\n";
        echo "    Pricer URL: " . (APP_URL !== '' ? APP_URL . "/#/products/$productId" : '(APP_URL not configured)') . "\n";
    }
    if ($sendBackInStock) {
        echo "  Would send back-in-stock to: $email\n";
    }
} else {
    if ($best) {
        echo "  Sending price alert to $email... ";
        $sent = sendNotification(
            $email,
            $product['name'],
            $currentPrice,
            $currentPrice, // Use current price as target so it always triggers
            $best['url'],
            $currency,
            $productId
        );
        echo $sent ? "SENT ✓\n" : "FAILED ✗\n";
    }

    if ($sendBackInStock) {
        echo "  Sending back-in-stock email to $email... ";
        $sent = sendBackInStockNotification(
            $email,
            $product['name'],
            $best ? $best['url'] : ($urls[0]['url'] ?? ''),
            $best ? $currentPrice : null,
            $best ? $currency : 'SEK',
            $productId
        );
        echo $sent ? "SENT ✓\n" : "FAILED ✗\n";
    }
}

echo "\n=== Done ===\n";
