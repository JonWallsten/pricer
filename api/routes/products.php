<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/price-scraper.php';
require_once __DIR__ . '/../lib/product-match-discovery.php';

function handleProductRoutes(string $method, string $path, array $authUser): void
{
    $db = getDb();
    $userId = (int) $authUser['user_id'];

    // GET /products — list user's products
    if ($method === 'GET' && $path === '/products') {
        $stmt = $db->prepare(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM alerts a WHERE a.product_id = p.id AND a.is_active = 1) AS active_alerts,
                    (SELECT COUNT(*) FROM product_urls pu WHERE pu.product_id = p.id) AS urls_count
             FROM products p
             WHERE p.user_id = :uid
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        $products = $stmt->fetchAll();

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
        if (!isAllowedFetchUrl($url)) {
            sendJson(['error' => 'URL must use http/https and resolve to a public host'], 400);
            return;
        }

        $extractionStrategy = isset($body['extraction_strategy']) ? trim($body['extraction_strategy']) : 'auto';
        if (!in_array($extractionStrategy, ['auto', 'selector'], true)) {
            $extractionStrategy = 'auto';
        }
        $result = extractPreview($url, $cssSelector ?: null, $extractionStrategy);
        sendJson(['preview' => $result]);
        return;
    }

    // POST /products/page-source — fetch page for inspector (picker/debug)
    if ($method === 'POST' && $path === '/products/page-source') {
        $body = getJsonBody();
        $url = trim($body['url'] ?? '');
        $cssSelector = isset($body['css_selector']) ? trim($body['css_selector']) : null;
        $findPrice = isset($body['find_price']) ? (float) $body['find_price'] : null;

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            sendJson(['error' => 'A valid URL is required'], 400);
            return;
        }
        if (!isAllowedFetchUrl($url)) {
            sendJson(['error' => 'URL must use http/https and resolve to a public host'], 400);
            return;
        }

        $html = fetchPage($url);
        if ($html === null) {
            sendJson(['error' => 'Failed to fetch page'], 502);
            return;
        }

        $preview = preparePageForPreview($html, $url);

        // Analyze selector if provided (on original unsanitized HTML)
        $selectorResult = [
            'selector_valid'       => true,
            'selector_error'       => null,
            'selector_match_count' => 0,
            'selector_matches'     => [],
        ];
        if ($cssSelector !== null && $cssSelector !== '') {
            $origDoc = new DOMDocument();
            $ie = libxml_use_internal_errors(true);
            $origDoc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_use_internal_errors($ie);
            $selectorResult = analyzeSelectorInDoc($origDoc, $cssSelector);
        }

        // Discover price candidates from all sources (on original HTML)
        $origDoc2 = new DOMDocument();
        $ie2 = libxml_use_internal_errors(true);
        $origDoc2->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_use_internal_errors($ie2);

        $priceCandidates = discoverPriceCandidates($origDoc2, $html, $cssSelector, $url);

        // "Find by current price" helper
        $priceMatches = [];
        if ($findPrice !== null && $findPrice > 0) {
            $priceMatches = findPriceInSources($origDoc2, $html, $findPrice, $cssSelector);
        }

        // Build product context and detect campaigns for debug
        $productContext = buildMainProductContext($origDoc2, $html, $url);
        $campaign = !empty($priceCandidates) ? detectCampaign($priceCandidates, $productContext) : null;

        sendJson(array_merge($preview, $selectorResult, [
            'price_candidates' => $priceCandidates,
            'price_matches'    => $priceMatches,
            'product_context'  => $productContext,
            'campaign'         => $campaign,
        ]));
        return;
    }

    // POST /products — create a new product (supports multi-URL)
    if ($method === 'POST' && $path === '/products') {
        $body = getJsonBody();
        $name = trim($body['name'] ?? '');

        if ($name === '') {
            sendJson(['error' => 'Name is required'], 400);
            return;
        }

        // Accept either urls array (new) or single url (backward compat)
        $urls = [];
        if (isset($body['urls']) && is_array($body['urls'])) {
            foreach ($body['urls'] as $u) {
                $urlStr = trim($u['url'] ?? '');
                $css = isset($u['css_selector']) ? trim($u['css_selector']) : null;
                if ($urlStr === '' || !filter_var($urlStr, FILTER_VALIDATE_URL)) {
                    sendJson(['error' => 'All URLs must be valid'], 400);
                    return;
                }
                if (!isAllowedFetchUrl($urlStr)) {
                    sendJson(['error' => "URL must use http/https and resolve to a public host: $urlStr"], 400);
                    return;
                }
                $es = isset($u['extraction_strategy']) ? trim($u['extraction_strategy']) : 'auto';
                if (!in_array($es, ['auto', 'selector'], true)) $es = 'auto';
                $urls[] = ['url' => $urlStr, 'css_selector' => $css ?: null, 'extraction_strategy' => $es];
            }
        } else {
            // Backward compat: single url field
            $url = trim($body['url'] ?? '');
            $cssSelector = isset($body['css_selector']) ? trim($body['css_selector']) : null;
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                sendJson(['error' => 'A valid URL is required'], 400);
                return;
            }
            if (!isAllowedFetchUrl($url)) {
                sendJson(['error' => 'URL must use http/https and resolve to a public host'], 400);
                return;
            }
            $es = isset($body['extraction_strategy']) ? trim($body['extraction_strategy']) : 'auto';
            if (!in_array($es, ['auto', 'selector'], true)) $es = 'auto';
            $urls[] = ['url' => $url, 'css_selector' => $cssSelector ?: null, 'extraction_strategy' => $es];
        }

        if (empty($urls)) {
            sendJson(['error' => 'At least one URL is required'], 400);
            return;
        }

        // Create product with first URL as primary
        $stmt = $db->prepare(
            'INSERT INTO products (user_id, name, url, css_selector)
             VALUES (:uid, :name, :url, :css)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':name' => $name,
            ':url'  => $urls[0]['url'],
            ':css'  => $urls[0]['css_selector'],
        ]);
        $productId = (int) $db->lastInsertId();

        // Insert all URLs into product_urls and auto-check each
        $insertUrl = $db->prepare(
            'INSERT INTO product_urls (product_id, url, css_selector, extraction_strategy)
             VALUES (:pid, :url, :css, :es)'
        );
        foreach ($urls as $u) {
            $insertUrl->execute([
                ':pid' => $productId,
                ':url' => $u['url'],
                ':css' => $u['css_selector'],
                ':es'  => $u['extraction_strategy'] ?? 'auto',
            ]);
            $urlId = (int) $db->lastInsertId();

            $result = extractPrice($u['url'], $u['css_selector'], $u['extraction_strategy'] ?? 'auto');
            updateProductUrlFromResult($db, $urlId, $result);
        }

        // Sync product's best price from product_urls
        syncProductBestPrice($db, $productId);

        // Record price history
        $prod = fetchProduct($db, $productId);
        if ($prod['current_price'] !== null) {
            recordPriceHistory($db, $productId, (float) $prod['current_price'], $prod['currency'] ?? 'SEK');
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

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        $product = $stmt->fetch();

        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        // GET /products/:id — single product with alerts and urls
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

            $urlStmt = $db->prepare(
                'SELECT * FROM product_urls WHERE product_id = :pid ORDER BY current_price ASC, created_at ASC'
            );
            $urlStmt->execute([':pid' => $productId]);
            $urls = $urlStmt->fetchAll();
            foreach ($urls as &$u) {
                castProductUrlFields($u);
            }

            sendJson(['product' => $product, 'alerts' => $alerts, 'urls' => $urls]);
            return;
        }

        // PUT /products/:id — update product (supports multi-URL)
        if ($method === 'PUT') {
            $body = getJsonBody();

            // Update name if provided
            if (isset($body['name'])) {
                $name = trim($body['name']);
                if ($name === '') {
                    sendJson(['error' => 'Name cannot be empty'], 400);
                    return;
                }
                $stmt = $db->prepare('UPDATE products SET name = :name WHERE id = :id');
                $stmt->execute([':name' => $name, ':id' => $productId]);
            }

            // Handle urls array if provided
            if (isset($body['urls']) && is_array($body['urls'])) {
                $newUrls = $body['urls'];

                // Validate all URLs first
                foreach ($newUrls as $u) {
                    $urlStr = trim($u['url'] ?? '');
                    if ($urlStr === '' || !filter_var($urlStr, FILTER_VALIDATE_URL)) {
                        sendJson(['error' => 'All URLs must be valid'], 400);
                        return;
                    }
                    if (!isAllowedFetchUrl($urlStr)) {
                        sendJson(['error' => "URL must use http/https and resolve to a public host: $urlStr"], 400);
                        return;
                    }
                }

                // Get existing URLs
                $existingStmt = $db->prepare('SELECT id FROM product_urls WHERE product_id = :pid');
                $existingStmt->execute([':pid' => $productId]);
                $existingIds = array_column($existingStmt->fetchAll(), 'id');

                $incomingIds = [];
                foreach ($newUrls as $u) {
                    $urlStr = trim($u['url']);
                    $css = isset($u['css_selector']) ? trim($u['css_selector']) : null;

                    $es = isset($u['extraction_strategy']) ? trim($u['extraction_strategy']) : 'auto';
                    if (!in_array($es, ['auto', 'selector'], true)) $es = 'auto';

                    if (isset($u['id']) && $u['id'] !== null) {
                        // Update existing
                        $uid = (int) $u['id'];
                        $incomingIds[] = $uid;
                        $updateStmt = $db->prepare(
                            'UPDATE product_urls SET url = :url, css_selector = :css, extraction_strategy = :es WHERE id = :id AND product_id = :pid'
                        );
                        $updateStmt->execute([
                            ':url' => $urlStr,
                            ':css' => $css ?: null,
                            ':es'  => $es,
                            ':id'  => $uid,
                            ':pid' => $productId,
                        ]);
                    } else {
                        // Insert new
                        $insertStmt = $db->prepare(
                            'INSERT INTO product_urls (product_id, url, css_selector, extraction_strategy) VALUES (:pid, :url, :css, :es)'
                        );
                        $insertStmt->execute([
                            ':pid' => $productId,
                            ':url' => $urlStr,
                            ':css' => $css ?: null,
                            ':es'  => $es,
                        ]);
                        $incomingIds[] = (int) $db->lastInsertId();
                    }
                }

                // Delete URLs that were removed
                $toDelete = array_diff($existingIds, $incomingIds);
                if (!empty($toDelete)) {
                    $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                    $delStmt = $db->prepare(
                        "DELETE FROM product_urls WHERE id IN ($placeholders) AND product_id = ?"
                    );
                    $params = array_values($toDelete);
                    $params[] = $productId;
                    $delStmt->execute($params);
                }

                // Sync product's primary url to first remaining
                $firstUrl = $db->prepare('SELECT url, css_selector FROM product_urls WHERE product_id = :pid ORDER BY id ASC LIMIT 1');
                $firstUrl->execute([':pid' => $productId]);
                $first = $firstUrl->fetch();
                if ($first) {
                    $db->prepare('UPDATE products SET url = :url, css_selector = :css WHERE id = :id')
                        ->execute([':url' => $first['url'], ':css' => $first['css_selector'], ':id' => $productId]);
                }
            } elseif (isset($body['url']) || array_key_exists('css_selector', $body)) {
                // Backward compat: single url/css_selector update
                $fields = [];
                $params = [':id' => $productId];
                if (isset($body['url'])) {
                    $url = trim($body['url']);
                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        sendJson(['error' => 'A valid URL is required'], 400);
                        return;
                    }
                    if (!isAllowedFetchUrl($url)) {
                        sendJson(['error' => 'URL must use http/https and resolve to a public host'], 400);
                        return;
                    }
                    $fields[] = 'url = :url';
                    $params[':url'] = $url;
                }
                if (array_key_exists('css_selector', $body)) {
                    $fields[] = 'css_selector = :css';
                    $params[':css'] = $body['css_selector'] !== null ? trim($body['css_selector']) : null;
                }
                if (!empty($fields)) {
                    $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id';
                    $db->prepare($sql)->execute($params);
                }
            }

            $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
            $stmt->execute([':id' => $productId]);
            $updated = $stmt->fetch();
            castProductFields($updated);

            sendJson(['product' => $updated]);
            return;
        }

        // DELETE /products/:id — delete product (alerts + product_urls cascade)
        if ($method === 'DELETE') {
            $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
            $stmt->execute([':id' => $productId]);
            sendJson(['success' => true]);
            return;
        }
    }

    // POST /products/:id/check — trigger manual price check (all URLs)
    if ($method === 'POST' && preg_match('#^/products/(\d+)/check$#', $path, $m)) {
        $productId = (int) $m[1];

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        $product = $stmt->fetch();

        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        // Check all URLs for this product
        $urlStmt = $db->prepare('SELECT * FROM product_urls WHERE product_id = :pid');
        $urlStmt->execute([':pid' => $productId]);
        $productUrls = $urlStmt->fetchAll();

        $results = [];
        foreach ($productUrls as $pu) {
            $result = extractPrice($pu['url'], $pu['css_selector'], $pu['extraction_strategy'] ?? 'auto');
            updateProductUrlFromResult($db, (int) $pu['id'], $result);
            $results[] = [
                'url_id' => (int) $pu['id'],
                'url' => $pu['url'],
                'extraction' => $result,
            ];
        }

        // Sync product best price
        syncProductBestPrice($db, $productId);

        // Record price history
        $prod = fetchProduct($db, $productId);
        if ($prod['current_price'] !== null) {
            recordPriceHistory($db, $productId, (float) $prod['current_price'], $prod['currency'] ?? 'SEK');
        }

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $updated = $stmt->fetch();
        castProductFields($updated);

        // Return first extraction result for backward compat, plus all results
        $extraction = !empty($results) ? $results[0]['extraction'] : null;

        sendJson([
            'product'    => $updated,
            'extraction' => $extraction,
            'url_results' => $results,
        ]);
        return;
    }

    // POST /products/:id/check-url — check a single URL
    if ($method === 'POST' && preg_match('#^/products/(\d+)/check-url$#', $path, $m)) {
        $productId = (int) $m[1];

        $stmt = $db->prepare('SELECT id FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        if (!$stmt->fetch()) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        $body = getJsonBody();
        $urlId = (int) ($body['url_id'] ?? 0);
        if ($urlId <= 0) {
            sendJson(['error' => 'url_id is required'], 400);
            return;
        }

        $puStmt = $db->prepare('SELECT * FROM product_urls WHERE id = :id AND product_id = :pid');
        $puStmt->execute([':id' => $urlId, ':pid' => $productId]);
        $pu = $puStmt->fetch();
        if (!$pu) {
            sendJson(['error' => 'URL not found'], 404);
            return;
        }

        $result = extractPrice($pu['url'], $pu['css_selector'], $pu['extraction_strategy'] ?? 'auto');
        updateProductUrlFromResult($db, $urlId, $result);

        // Re-sync product best price
        syncProductBestPrice($db, $productId);

        // Record price history
        $prod = fetchProduct($db, $productId);
        if ($prod['current_price'] !== null) {
            recordPriceHistory($db, $productId, (float) $prod['current_price'], $prod['currency'] ?? 'SEK');
        }

        // Return updated URL row
        $puStmt->execute([':id' => $urlId, ':pid' => $productId]);
        $updatedUrl = $puStmt->fetch();
        castProductUrlFields($updatedUrl);

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $updatedProduct = $stmt->fetch();
        castProductFields($updatedProduct);

        sendJson([
            'product' => $updatedProduct,
            'url' => $updatedUrl,
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

    // GET /products/:id/matches — persisted cross-store matches
    if ($method === 'GET' && preg_match('#^/products/(\d+)/matches$#', $path, $m)) {
        $productId = (int) $m[1];

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        $product = $stmt->fetch();
        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        $matches = listProductMatches($db, $productId, true);
        sendJson(['matches' => $matches]);
        return;
    }

    // POST /products/:id/discover-matches — manual discovery run
    if ($method === 'POST' && preg_match('#^/products/(\d+)/discover-matches$#', $path, $m)) {
        $productId = (int) $m[1];

        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $productId, ':uid' => $userId]);
        $product = $stmt->fetch();
        if (!$product) {
            sendJson(['error' => 'Product not found'], 404);
            return;
        }

        $body = getJsonBody();
        $force = !empty($body['force']);

        try {
            $result = discoverProductMatches($db, $product, $force);
            sendJson($result);
        } catch (RuntimeException $e) {
            sendJson(['error' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            error_log('Product match discovery failed: ' . $e->getMessage());
            sendJson(['error' => 'Failed to discover product matches'], 500);
        }
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

// ─── Helper functions ────────────────────────────────────────

function castProductFields(array &$p): void
{
    $p['id'] = (int) $p['id'];
    $p['user_id'] = (int) $p['user_id'];
    $p['current_price'] = $p['current_price'] !== null ? (float) $p['current_price'] : null;
    if (isset($p['active_alerts'])) {
        $p['active_alerts'] = (int) $p['active_alerts'];
    }
    if (isset($p['urls_count'])) {
        $p['urls_count'] = (int) $p['urls_count'];
    }
}

function castProductUrlFields(array &$u): void
{
    $u['id'] = (int) $u['id'];
    $u['product_id'] = (int) $u['product_id'];
    $u['current_price'] = $u['current_price'] !== null ? (float) $u['current_price'] : null;
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

function fetchProduct(PDO $db, int $productId): array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $productId]);
    return $stmt->fetch();
}

function updateProductUrlFromResult(PDO $db, int $urlId, array $result): void
{
    if ($result['price'] !== null) {
        $stmt = $db->prepare(
            'UPDATE product_urls
             SET current_price = :price, currency = :currency,
                 image_url = :image_url, availability = :avail,
                 regular_price = :regular_price,
                 previous_lowest_price = :previous_lowest_price,
                 is_campaign = :is_campaign,
                 campaign_type = :campaign_type,
                 campaign_label = :campaign_label,
                 campaign_json = :campaign_json,
                 last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':price'                 => $result['price'],
            ':currency'              => $result['currency'] ?? 'SEK',
            ':image_url'             => $result['image_url'],
            ':avail'                 => $result['availability'],
            ':regular_price'         => $result['regular_price'] ?? null,
            ':previous_lowest_price' => $result['previous_lowest_price'] ?? null,
            ':is_campaign'           => ($result['is_campaign'] ?? false) ? 1 : 0,
            ':campaign_type'         => $result['campaign_type'] ?? null,
            ':campaign_label'        => $result['campaign_label'] ?? null,
            ':campaign_json'         => $result['campaign_json'] ?? null,
            ':status'                => 'success',
            ':id'                    => $urlId,
        ]);
    } else {
        $stmt = $db->prepare(
            'UPDATE product_urls
             SET last_checked_at = NOW(), last_check_status = :status, last_check_error = :error
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'error',
            ':error'  => $result['error'],
            ':id'     => $urlId,
        ]);
    }
}

/**
 * Sync the product's current_price, url, image_url, availability
 * from the cheapest successful product_url.
 */
function syncProductBestPrice(PDO $db, int $productId): void
{
    // Find cheapest successful URL
    $stmt = $db->prepare(
        'SELECT * FROM product_urls
         WHERE product_id = :pid AND current_price IS NOT NULL AND last_check_status = :status
         ORDER BY current_price ASC
         LIMIT 1'
    );
    $stmt->execute([':pid' => $productId, ':status' => 'success']);
    $best = $stmt->fetch();

    if ($best) {
        $update = $db->prepare(
            'UPDATE products
             SET current_price = :price, currency = :currency,
                 url = :url, css_selector = :css,
                 image_url = :image_url, availability = :avail,
                 regular_price = :regular_price,
                 previous_lowest_price = :previous_lowest_price,
                 is_campaign = :is_campaign,
                 campaign_type = :campaign_type,
                 campaign_label = :campaign_label,
                 campaign_json = :campaign_json,
                 last_checked_at = NOW(), last_check_status = :status, last_check_error = NULL
             WHERE id = :id'
        );
        $update->execute([
            ':price'                 => $best['current_price'],
            ':currency'              => $best['currency'],
            ':url'                   => $best['url'],
            ':css'                   => $best['css_selector'],
            ':image_url'             => $best['image_url'],
            ':avail'                 => bestAvailability($db, $productId),
            ':regular_price'         => $best['regular_price'] ?? null,
            ':previous_lowest_price' => $best['previous_lowest_price'] ?? null,
            ':is_campaign'           => ($best['is_campaign'] ?? 0) ? 1 : 0,
            ':campaign_type'         => $best['campaign_type'] ?? null,
            ':campaign_label'        => $best['campaign_label'] ?? null,
            ':campaign_json'         => $best['campaign_json'] ?? null,
            ':status'                => 'success',
            ':id'                    => $productId,
        ]);
    } else {
        // No successful URL — check if any URLs exist with errors
        $errStmt = $db->prepare(
            'SELECT last_check_error FROM product_urls
             WHERE product_id = :pid AND last_check_status = :status
             LIMIT 1'
        );
        $errStmt->execute([':pid' => $productId, ':status' => 'error']);
        $err = $errStmt->fetch();

        $update = $db->prepare(
            'UPDATE products
             SET last_checked_at = NOW(), last_check_status = :status, last_check_error = :error
             WHERE id = :id'
        );
        $update->execute([
            ':status' => $err ? 'error' : 'pending',
            ':error'  => $err ? $err['last_check_error'] : null,
            ':id'     => $productId,
        ]);
    }
}

/**
 * Return the best availability across all URLs for a product.
 * Priority: in_stock > preorder > out_of_stock > unknown
 */
function bestAvailability(PDO $db, int $productId): string
{
    $stmt = $db->prepare('SELECT availability FROM product_urls WHERE product_id = :pid');
    $stmt->execute([':pid' => $productId]);
    $rows = $stmt->fetchAll();

    $priority = ['in_stock' => 4, 'preorder' => 3, 'out_of_stock' => 2, 'unknown' => 1];
    $best = 'unknown';
    $bestPri = 0;

    foreach ($rows as $r) {
        $avail = $r['availability'] ?? 'unknown';
        $pri = $priority[$avail] ?? 0;
        if ($pri > $bestPri) {
            $bestPri = $pri;
            $best = $avail;
        }
    }

    return $best;
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
