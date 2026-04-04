<?php

declare(strict_types=1);

require_once __DIR__ . '/price-scraper.php';

function listProductMatches(PDO $db, int $productId, bool $includeWeak = false): array
{
    $sql = 'SELECT * FROM product_match_candidates WHERE source_product_id = :pid';
    if (!$includeWeak) {
        $sql .= " AND excluded = 0 AND confidence_score >= 50";
    }
    $sql .= ' ORDER BY confidence_score DESC, last_searched_at DESC, id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute([':pid' => $productId]);
    $rows = $stmt->fetchAll();

    return array_map('castProductMatchCandidate', $rows);
}

function discoverProductMatches(PDO $db, array $product, bool $force = false): array
{
    if (SERPAPI_API_KEY === '') {
        throw new RuntimeException('SERPAPI_API_KEY is not configured.');
    }

    $source = buildSourceNormalizedProduct($db, $product, $force);
    $queries = buildSearchQueries($source);

    $matches = [];
    $searchesRun = 0;

    foreach ($queries as $query) {
        $results = searchSerpApiCached($db, $query, $force);
        $searchesRun++;

        $candidates = filterCandidateSearchResults($results, $source['sourceDomain'] ?? null);
        foreach (array_slice($candidates, 0, 5) as $candidateResult) {
            $candidate = fetchAndExtractCandidateProductCached($db, $candidateResult['url'], $force);
            if ($candidate === null || ($candidate['title'] ?? '') === '') {
                continue;
            }

            $score = scoreCandidateAgainstSource($source, $candidate);
            $persisted = persistProductMatchCandidate(
                $db,
                (int) $product['id'],
                $candidate,
                $score,
                $query,
                $candidateResult['position'] ?? null,
            );
            $matches[$persisted['candidate_url']] = $persisted;
        }

        if (!empty($matches)) {
            $strongMatches = array_filter($matches, static fn(array $m) => $m['confidence_score'] >= 70);
            if (!empty($strongMatches)) {
                break;
            }
        }
    }

    return [
        'source' => $source,
        'queries' => $queries,
        'searches_run' => $searchesRun,
        'matches' => listProductMatches($db, (int) $product['id'], true),
    ];
}

function buildSourceNormalizedProduct(PDO $db, array $product, bool $force = false): array
{
    $sourceUrl = $product['url'] ?? null;
    $sourceCandidate = null;

    if (is_string($sourceUrl) && $sourceUrl !== '') {
        $sourceCandidate = fetchAndExtractCandidateProductCached($db, $sourceUrl, $force);
    }

    $title = trim((string) ($sourceCandidate['title'] ?? $product['name'] ?? ''));
    $domain = is_string($sourceUrl) ? normalizeDomain($sourceUrl) : null;

    return normalizeProductData([
        'sourceUrl' => $sourceUrl,
        'sourceDomain' => $domain,
        'title' => $title,
        'brand' => $sourceCandidate['brand'] ?? null,
        'model' => $sourceCandidate['model'] ?? null,
        'mpn' => $sourceCandidate['mpn'] ?? null,
        'gtin' => $sourceCandidate['gtin'] ?? null,
        'sku' => $sourceCandidate['sku'] ?? null,
        'color' => $sourceCandidate['color'] ?? null,
        'size' => $sourceCandidate['size'] ?? null,
        'dimensions' => $sourceCandidate['dimensions'] ?? [],
        'volume' => $sourceCandidate['volume'] ?? null,
        'price' => $product['current_price'] !== null ? (float) $product['current_price'] : null,
        'currency' => $product['currency'] ?? null,
    ]);
}

function buildSearchQueries(array $source): array
{
    $strongParts = [];
    foreach (['gtin', 'mpn', 'model', 'brand'] as $key) {
        if (!empty($source[$key])) {
            $strongParts[] = $source[$key];
        }
    }

    foreach (array_slice($source['tokens'] ?? [], 0, 4) as $token) {
        if (!in_array($token, $strongParts, true)) {
            $strongParts[] = $token;
        }
    }

    foreach (['size', 'color'] as $key) {
        if (!empty($source[$key])) {
            $strongParts[] = $source[$key];
        }
    }

    $primary = trim(implode(' ', array_slice($strongParts, 0, 6)));
    $fallback = trim('"' . ($source['titleNormalized'] ?? '') . '"');
    if (!empty($source['sourceDomain'])) {
        $fallback .= ' -site:' . $source['sourceDomain'];
    }

    $queries = [];
    if ($primary !== '') {
        $queries[] = $primary;
    }
    if ($fallback !== '' && !in_array($fallback, $queries, true)) {
        $queries[] = $fallback;
    }

    return array_slice($queries, 0, 2);
}

function searchSerpApiCached(PDO $db, string $query, bool $force = false): array
{
    $cacheKey = hash('sha256', implode('|', ['serpapi', $query, SERPAPI_SEARCH_COUNTRY, SERPAPI_SEARCH_LOCALE]));

    if (!$force) {
        $stmt = $db->prepare(
            'SELECT raw_response_json FROM product_match_search_cache
             WHERE key_hash = :key_hash AND expires_at > NOW()'
        );
        $stmt->execute([':key_hash' => $cacheKey]);
        $row = $stmt->fetch();
        if ($row) {
            $decoded = json_decode((string) $row['raw_response_json'], true);
            return is_array($decoded) ? parseSerpApiResults($decoded) : [];
        }
    }

    $url = 'https://serpapi.com/search.json?' . http_build_query([
        'engine' => 'google',
        'q' => $query,
        'api_key' => SERPAPI_API_KEY,
        'google_domain' => 'google.se',
        'gl' => SERPAPI_SEARCH_COUNTRY,
        'hl' => substr(SERPAPI_SEARCH_LOCALE, 0, 2),
        'num' => 10,
    ]);

    $response = fetchJsonUrl($url);
    if (!is_array($response)) {
        throw new RuntimeException('SerpApi search failed.');
    }
    if (isset($response['error'])) {
        throw new RuntimeException('SerpApi error: ' . $response['error']);
    }

    $parsed = parseSerpApiResults($response);
    $resultUrls = array_map(static fn(array $r) => $r['url'], $parsed);

    $stmt = $db->prepare(
        'INSERT INTO product_match_search_cache
         (key_hash, query, provider, locale, country, result_urls_json, raw_response_json, created_at, expires_at)
         VALUES (:key_hash, :query, :provider, :locale, :country, :result_urls_json, :raw_response_json, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
         ON DUPLICATE KEY UPDATE
           result_urls_json = VALUES(result_urls_json),
           raw_response_json = VALUES(raw_response_json),
           created_at = NOW(),
           expires_at = VALUES(expires_at)'
    );
    $stmt->execute([
        ':key_hash' => $cacheKey,
        ':query' => $query,
        ':provider' => 'serpapi',
        ':locale' => SERPAPI_SEARCH_LOCALE,
        ':country' => SERPAPI_SEARCH_COUNTRY,
        ':result_urls_json' => json_encode($resultUrls, JSON_UNESCAPED_UNICODE),
        ':raw_response_json' => json_encode($response, JSON_UNESCAPED_UNICODE),
    ]);

    return $parsed;
}

function parseSerpApiResults(array $payload): array
{
    $results = [];
    foreach (($payload['organic_results'] ?? []) as $row) {
        $url = $row['link'] ?? '';
        if (!is_string($url) || $url === '') {
            continue;
        }
        $results[] = [
            'title' => (string) ($row['title'] ?? ''),
            'url' => $url,
            'snippet' => isset($row['snippet']) ? (string) $row['snippet'] : null,
            'position' => isset($row['position']) ? (int) $row['position'] : null,
        ];
    }
    return $results;
}

function filterCandidateSearchResults(array $results, ?string $sourceDomain): array
{
    $filtered = [];
    foreach ($results as $row) {
        $url = $row['url'] ?? '';
        if (!is_string($url) || $url === '') {
            continue;
        }

        $domain = normalizeDomain($url);
        if ($domain === null) {
            continue;
        }
        if ($sourceDomain !== null && $domain === $sourceDomain) {
            continue;
        }
        if (preg_match('/\.(pdf|jpg|jpeg|png|webp)$/i', $url)) {
            continue;
        }
        if (preg_match('#/(search|sok|category|kategori|forum|community|blog)(/|$)#i', $url)) {
            continue;
        }
        if (preg_match('/(facebook|instagram|youtube|reddit|x\.com|twitter|pinterest)\./i', $domain)) {
            continue;
        }
        if (preg_match('/(amazon\.|ebay\.|tradera\.|prisjakt\.|pricerunner\.)/i', $domain)) {
            continue;
        }

        $filtered[] = $row;
    }

    $deduped = [];
    $seen = [];
    foreach ($filtered as $row) {
        $normalized = normalizeComparableUrl($row['url']);
        if (isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $deduped[] = $row;
    }

    return array_slice($deduped, 0, 10);
}

function fetchAndExtractCandidateProductCached(PDO $db, string $url, bool $force = false): ?array
{
    $urlHash = hash('sha256', normalizeComparableUrl($url));

    if (!$force) {
        $stmt = $db->prepare(
            'SELECT extracted_json, status FROM product_match_fetch_cache
             WHERE url_hash = :url_hash AND expires_at > NOW()'
        );
        $stmt->execute([':url_hash' => $urlHash]);
        $row = $stmt->fetch();
        if ($row && $row['status'] === 'success') {
            $decoded = json_decode((string) $row['extracted_json'], true);
            return is_array($decoded) ? $decoded : null;
        }
        if ($row && $row['status'] === 'error') {
            return null;
        }
    }

    $fetched = fetchHtmlWithMetadata($url, 0);
    if ($fetched === null || ($fetched['html'] ?? '') === '') {
        persistFetchCacheError($db, $urlHash, $url, 'Failed to fetch candidate page');
        return null;
    }

    $candidate = extractCandidateProductFromHtml(
        (string) $fetched['final_url'],
        (string) $fetched['html'],
    );
    if ($candidate === null || ($candidate['title'] ?? '') === '') {
        persistFetchCacheError($db, $urlHash, $url, 'No structured product data found');
        return null;
    }

    $stmt = $db->prepare(
        'INSERT INTO product_match_fetch_cache
         (url_hash, original_url, final_url, final_url_hash, domain, status, error_message, extracted_json, fetched_at, expires_at)
         VALUES (:url_hash, :original_url, :final_url, :final_url_hash, :domain, :status, NULL, :extracted_json, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))
         ON DUPLICATE KEY UPDATE
           final_url = VALUES(final_url),
           final_url_hash = VALUES(final_url_hash),
           domain = VALUES(domain),
           status = VALUES(status),
           error_message = NULL,
           extracted_json = VALUES(extracted_json),
           fetched_at = NOW(),
           expires_at = VALUES(expires_at)'
    );
    $stmt->execute([
        ':url_hash' => $urlHash,
        ':original_url' => $url,
        ':final_url' => $candidate['finalUrl'],
        ':final_url_hash' => hash('sha256', normalizeComparableUrl($candidate['finalUrl'])),
        ':domain' => $candidate['domain'],
        ':status' => 'success',
        ':extracted_json' => json_encode($candidate, JSON_UNESCAPED_UNICODE),
    ]);

    return $candidate;
}

function persistFetchCacheError(PDO $db, string $urlHash, string $url, string $error): void
{
    $stmt = $db->prepare(
        'INSERT INTO product_match_fetch_cache
         (url_hash, original_url, status, error_message, fetched_at, expires_at)
         VALUES (:url_hash, :original_url, :status, :error_message, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))
         ON DUPLICATE KEY UPDATE
           status = VALUES(status),
           error_message = VALUES(error_message),
           fetched_at = NOW(),
           expires_at = VALUES(expires_at)'
    );
    $stmt->execute([
        ':url_hash' => $urlHash,
        ':original_url' => $url,
        ':status' => 'error',
        ':error_message' => $error,
    ]);
}

function fetchHtmlWithMetadata(string $url, int $redirectDepth = 0): ?array
{
    if ($redirectDepth > 3 || !isAllowedFetchUrl($url)) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: sv-SE,sv;q=0.9,en;q=0.8',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (!is_string($response) || $response === '') {
        return null;
    }

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if ($httpCode >= 300 && $httpCode < 400 && preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $redirectUrl = resolveUrl(trim($m[1]), $url);
        return fetchHtmlWithMetadata($redirectUrl, $redirectDepth + 1);
    }

    if ($httpCode >= 400 || $body === '') {
        return null;
    }

    return [
        'final_url' => $finalUrl !== '' ? $finalUrl : $url,
        'html' => $body,
    ];
}

function extractCandidateProductFromHtml(string $finalUrl, string $html): ?array
{
    $doc = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($internalErrors);
    $xpath = new DOMXPath($doc);

    $title = extractPageTitleFromDoc($doc);
    $image = extractProductImage($doc, $finalUrl);
    $availability = extractAvailability($doc);

    $jsonLd = extractStructuredProductSignalsFromJsonLd($xpath);
    $meta = extractStructuredProductSignalsFromMeta($xpath);
    $fallback = extractStructuredProductSignalsFromDom($xpath);

    $raw = array_filter([
        'title' => $jsonLd['title'] ?? $meta['title'] ?? $fallback['title'] ?? $title,
        'brand' => $jsonLd['brand'] ?? $meta['brand'] ?? $fallback['brand'] ?? null,
        'model' => $jsonLd['model'] ?? $meta['model'] ?? $fallback['model'] ?? null,
        'mpn' => $jsonLd['mpn'] ?? $meta['mpn'] ?? $fallback['mpn'] ?? null,
        'gtin' => $jsonLd['gtin'] ?? $meta['gtin'] ?? $fallback['gtin'] ?? null,
        'sku' => $jsonLd['sku'] ?? $meta['sku'] ?? $fallback['sku'] ?? null,
        'price' => $jsonLd['price'] ?? $meta['price'] ?? $fallback['price'] ?? null,
        'currency' => $jsonLd['currency'] ?? $meta['currency'] ?? $fallback['currency'] ?? null,
        'color' => $jsonLd['color'] ?? $meta['color'] ?? $fallback['color'] ?? null,
        'size' => $jsonLd['size'] ?? $meta['size'] ?? $fallback['size'] ?? null,
        'dimensions' => $jsonLd['dimensions'] ?? $meta['dimensions'] ?? $fallback['dimensions'] ?? [],
        'volume' => $jsonLd['volume'] ?? $meta['volume'] ?? $fallback['volume'] ?? null,
    ], static fn(mixed $v) => $v !== null && $v !== '' && $v !== []);

    if (empty($raw['title'])) {
        return null;
    }

    return [
        'finalUrl' => $finalUrl,
        'domain' => normalizeDomain($finalUrl) ?? '',
        'title' => $raw['title'] ?? null,
        'brand' => $raw['brand'] ?? null,
        'model' => $raw['model'] ?? null,
        'mpn' => $raw['mpn'] ?? null,
        'gtin' => $raw['gtin'] ?? null,
        'sku' => $raw['sku'] ?? null,
        'price' => isset($raw['price']) ? (float) $raw['price'] : null,
        'currency' => $raw['currency'] ?? null,
        'availability' => $availability,
        'color' => $raw['color'] ?? null,
        'size' => $raw['size'] ?? null,
        'dimensions' => array_values($raw['dimensions'] ?? []),
        'volume' => $raw['volume'] ?? null,
        'image' => $image,
        'rawSignals' => $raw,
        'normalized' => normalizeProductData([
            'sourceUrl' => $finalUrl,
            'sourceDomain' => normalizeDomain($finalUrl),
            'title' => $raw['title'] ?? '',
            'brand' => $raw['brand'] ?? null,
            'model' => $raw['model'] ?? null,
            'mpn' => $raw['mpn'] ?? null,
            'gtin' => $raw['gtin'] ?? null,
            'sku' => $raw['sku'] ?? null,
            'color' => $raw['color'] ?? null,
            'size' => $raw['size'] ?? null,
            'dimensions' => $raw['dimensions'] ?? [],
            'volume' => $raw['volume'] ?? null,
            'price' => isset($raw['price']) ? (float) $raw['price'] : null,
            'currency' => $raw['currency'] ?? null,
        ]),
    ];
}

function extractStructuredProductSignalsFromJsonLd(DOMXPath $xpath): array
{
    $scripts = $xpath->query('//script[@type="application/ld+json"]');
    if ($scripts === false) {
        return [];
    }

    foreach ($scripts as $script) {
        $json = json_decode(trim($script->textContent), true);
        if (!is_array($json)) {
            continue;
        }

        $items = [];
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            $items = $json['@graph'];
        } elseif (isset($json[0])) {
            $items = $json;
        } else {
            $items = [$json];
        }

        foreach ($items as $item) {
            $signals = extractSignalsFromJsonLdItem($item);
            if (!empty($signals)) {
                return $signals;
            }
        }
    }

    return [];
}

function extractSignalsFromJsonLdItem(mixed $item): array
{
    if (!is_array($item)) {
        return [];
    }

    $type = $item['@type'] ?? '';
    if (is_array($type)) {
        $type = implode(',', $type);
    }
    if (!str_contains((string) $type, 'Product')) {
        return [];
    }

    $brand = $item['brand'] ?? null;
    if (is_array($brand)) {
        $brand = $brand['name'] ?? $brand['@id'] ?? null;
    }

    $offers = $item['offers'] ?? null;
    $offerPrice = null;
    $offerCurrency = null;
    if (is_array($offers)) {
        $offerPrice = $offers['price'] ?? $offers['lowPrice'] ?? null;
        $offerCurrency = $offers['priceCurrency'] ?? null;
    }

    $gtin = $item['gtin'] ?? $item['gtin13'] ?? $item['gtin14'] ?? $item['gtin12'] ?? $item['gtin8'] ?? null;

    return array_filter([
        'title' => $item['name'] ?? null,
        'brand' => is_string($brand) ? $brand : null,
        'model' => $item['model'] ?? null,
        'mpn' => $item['mpn'] ?? null,
        'gtin' => $gtin,
        'sku' => $item['sku'] ?? null,
        'price' => $offerPrice !== null ? parsePrice((string) $offerPrice) : null,
        'currency' => $offerCurrency,
        'color' => $item['color'] ?? null,
        'size' => $item['size'] ?? null,
        'dimensions' => extractDimensionsFromTextArray([
            $item['description'] ?? '',
            is_string($item['size'] ?? null) ? $item['size'] : '',
        ]),
        'volume' => extractVolumeFromText((string) ($item['description'] ?? '')),
    ], static fn(mixed $v) => $v !== null && $v !== '' && $v !== []);
}

function extractStructuredProductSignalsFromMeta(DOMXPath $xpath): array
{
    $titleNode = $xpath->query("//meta[@property='og:title']/@content")->item(0);
    $title = $titleNode?->textContent;

    $priceNode = $xpath->query("//meta[@property='product:price:amount']/@content")->item(0)
        ?? $xpath->query("//meta[@property='og:price:amount']/@content")->item(0);
    $currencyNode = $xpath->query("//meta[@property='product:price:currency']/@content")->item(0)
        ?? $xpath->query("//meta[@property='og:price:currency']/@content")->item(0);

    return array_filter([
        'title' => is_string($title) ? trim($title) : null,
        'price' => $priceNode !== null ? parsePrice($priceNode->textContent) : null,
        'currency' => $currencyNode !== null ? strtoupper(trim($currencyNode->textContent)) : null,
    ], static fn(mixed $v) => $v !== null && $v !== '');
}

function extractStructuredProductSignalsFromDom(DOMXPath $xpath): array
{
    $h1 = $xpath->query('//h1')->item(0);
    $title = $h1 !== null ? trim($h1->textContent) : null;

    $text = trim(preg_replace('/\s+/', ' ', $xpath->evaluate('string(//body)')));
    if ($text === '') {
        return array_filter(['title' => $title], static fn(mixed $v) => $v !== null && $v !== '');
    }

    preg_match('/\b(?:ean|gtin)\b[:\s#-]*([0-9]{8,14})/iu', $text, $gtinMatch);
    preg_match('/\bmpn\b[:\s#-]*([A-Z0-9\-_]+)/iu', $text, $mpnMatch);
    preg_match('/\bsku\b[:\s#-]*([A-Z0-9\-_]+)/iu', $text, $skuMatch);
    preg_match('/\b(?:färg|color)\b[:\s-]*([A-Za-zÅÄÖåäö \-]+)/u', $text, $colorMatch);
    preg_match('/\b(?:storlek|size)\b[:\s-]*([A-Za-z0-9ÅÄÖåäö .,\-]+)/u', $text, $sizeMatch);

    return array_filter([
        'title' => $title,
        'gtin' => $gtinMatch[1] ?? null,
        'mpn' => $mpnMatch[1] ?? null,
        'sku' => $skuMatch[1] ?? null,
        'color' => isset($colorMatch[1]) ? trim($colorMatch[1]) : null,
        'size' => isset($sizeMatch[1]) ? trim($sizeMatch[1]) : null,
        'dimensions' => extractDimensionsFromTextArray([$text]),
        'volume' => extractVolumeFromText($text),
    ], static fn(mixed $v) => $v !== null && $v !== '' && $v !== []);
}

function normalizeProductData(array $data): array
{
    $titleRaw = trim((string) ($data['title'] ?? ''));
    $titleNormalized = normalizeTitle($titleRaw);

    $dimensions = array_values(array_unique(array_filter(array_map(
        static fn(string $v) => normalizeMeasurement($v),
        is_array($data['dimensions'] ?? null) ? $data['dimensions'] : []
    ))));

    return [
        'sourceUrl' => $data['sourceUrl'] ?? null,
        'sourceDomain' => $data['sourceDomain'] ?? null,
        'titleRaw' => $titleRaw,
        'titleNormalized' => $titleNormalized,
        'brand' => normalizeIdentifierValue($data['brand'] ?? null),
        'model' => normalizeIdentifierValue($data['model'] ?? null),
        'mpn' => normalizeIdentifierValue($data['mpn'] ?? null),
        'gtin' => normalizeDigitsOnly($data['gtin'] ?? null),
        'sku' => normalizeIdentifierValue($data['sku'] ?? null),
        'color' => normalizeAttributeValue($data['color'] ?? null),
        'size' => normalizeMeasurement((string) ($data['size'] ?? '')),
        'dimensions' => $dimensions,
        'volume' => extractVolumeFromText((string) ($data['volume'] ?? '')),
        'price' => $data['price'] ?? null,
        'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
        'categoryHints' => extractCategoryHints($titleNormalized),
        'tokens' => tokenizeNormalizedTitle($titleNormalized),
    ];
}

function normalizeTitle(string $title): string
{
    $title = mb_strtolower($title);
    $title = preg_replace('/[\(\)\[\],;:!"\'`]/u', ' ', $title);
    $title = preg_replace('/\b(kampanj|rea|online only|fri frakt|outlet|nyhet|sale|deal)\b/u', ' ', $title);
    $title = preg_replace('/\s+/u', ' ', $title);
    return trim($title);
}

function tokenizeNormalizedTitle(string $title): array
{
    $tokens = preg_split('/\s+/u', $title) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $token) => mb_strlen($token) >= 2));
    return array_values(array_unique($tokens));
}

function extractCategoryHints(string $titleNormalized): array
{
    $known = ['krukfat', 'kruka', 'fat', 'terracotta', 'planter', 'saucer', 'pot', 'lamp', 'chair', 'table'];
    return array_values(array_filter($known, static fn(string $hint) => str_contains($titleNormalized, $hint)));
}

function normalizeIdentifierValue(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    return trim(mb_strtolower($value));
}

function normalizeDigitsOnly(mixed $value): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', (string) $value);
    return $digits !== '' ? $digits : null;
}

function normalizeAttributeValue(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    return trim(normalizeTitle($value));
}

function normalizeMeasurement(string $value): ?string
{
    $value = normalizeTitle($value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/\bcentimeter\b/u', 'cm', $value);
    $value = preg_replace('/\bmillimeter\b/u', 'mm', $value);
    $value = preg_replace('/\bliter\b/u', 'l', $value);
    $value = preg_replace('/\bmilliliter\b/u', 'ml', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function extractDimensionsFromTextArray(array $texts): array
{
    $dimensions = [];
    foreach ($texts as $text) {
        if (!is_string($text) || trim($text) === '') {
            continue;
        }
        preg_match_all('/\b\d+(?:[.,]\d+)?\s?(?:cm|mm|m)\b/u', mb_strtolower($text), $matches);
        foreach ($matches[0] ?? [] as $match) {
            $normalized = normalizeMeasurement($match);
            if ($normalized !== null) {
                $dimensions[] = $normalized;
            }
        }
    }
    return array_values(array_unique($dimensions));
}

function extractVolumeFromText(string $text): ?string
{
    if (trim($text) === '') {
        return null;
    }
    if (preg_match('/\b\d+(?:[.,]\d+)?\s?(?:ml|l)\b/u', mb_strtolower($text), $m)) {
        return normalizeMeasurement($m[0]);
    }
    return null;
}

function scoreCandidateAgainstSource(array $source, array $candidate): array
{
    $candidateNormalized = $candidate['normalized'] ?? normalizeProductData([
        'title' => $candidate['title'] ?? '',
        'brand' => $candidate['brand'] ?? null,
        'model' => $candidate['model'] ?? null,
        'mpn' => $candidate['mpn'] ?? null,
        'gtin' => $candidate['gtin'] ?? null,
        'sku' => $candidate['sku'] ?? null,
        'color' => $candidate['color'] ?? null,
        'size' => $candidate['size'] ?? null,
        'dimensions' => $candidate['dimensions'] ?? [],
        'volume' => $candidate['volume'] ?? null,
        'price' => $candidate['price'] ?? null,
        'currency' => $candidate['currency'] ?? null,
    ]);

    $score = 0;
    $reasons = [];
    $penalties = [];
    $breakdown = [
        'total' => 0,
        'reasons' => &$reasons,
    ];

    if (($source['gtin'] ?? null) && $source['gtin'] === ($candidateNormalized['gtin'] ?? null)) {
        $score += 60;
        $breakdown['gtinMatch'] = 60;
        $reasons[] = 'GTIN exact match';
    }
    if (($source['mpn'] ?? null) && $source['mpn'] === ($candidateNormalized['mpn'] ?? null)) {
        $score += 35;
        $breakdown['mpnMatch'] = 35;
        $reasons[] = 'MPN exact match';
    }
    if (($source['sku'] ?? null) && $source['sku'] === ($candidateNormalized['sku'] ?? null)) {
        $score += 20;
        $breakdown['skuMatch'] = 20;
        $reasons[] = 'SKU exact match';
    }
    if (($source['model'] ?? null) && $source['model'] === ($candidateNormalized['model'] ?? null)) {
        $score += 25;
        $breakdown['modelMatch'] = 25;
        $reasons[] = 'Model matched';
    }

    if (($source['brand'] ?? null) && ($candidateNormalized['brand'] ?? null)) {
        if ($source['brand'] === $candidateNormalized['brand']) {
            $score += 12;
            $breakdown['brandMatch'] = 12;
            $reasons[] = 'Brand matched: ' . $candidateNormalized['brand'];
        } else {
            $score -= 50;
            $penalties[] = -50;
            $reasons[] = 'Brand conflict';
        }
    }

    similar_text($source['titleNormalized'] ?? '', $candidateNormalized['titleNormalized'] ?? '', $titlePercent);
    $titleContribution = (int) round(min(20, max(0, $titlePercent / 100 * 20)));
    if ($titleContribution > 0) {
        $score += $titleContribution;
        $breakdown['titleSimilarity'] = $titleContribution;
        $reasons[] = 'Title similarity: ' . number_format($titlePercent / 100, 2);
    }

    $sourceDims = $source['dimensions'] ?? [];
    $candidateDims = $candidateNormalized['dimensions'] ?? [];
    if (!empty($sourceDims) && !empty($candidateDims)) {
        $intersection = array_values(array_intersect($sourceDims, $candidateDims));
        if (!empty($intersection)) {
            $score += 15;
            $breakdown['dimensionsMatch'] = 15;
            $reasons[] = 'Dimension matched: ' . implode(', ', $intersection);
        } else {
            $score -= 30;
            $penalties[] = -30;
            $reasons[] = 'Dimension conflict';
        }
    }

    if (($source['color'] ?? null) && ($candidateNormalized['color'] ?? null)) {
        if ($source['color'] === $candidateNormalized['color']) {
            $score += 8;
            $breakdown['colorMatch'] = 8;
            $reasons[] = 'Color matched: ' . $candidateNormalized['color'];
        } else {
            $score -= 10;
            $penalties[] = -10;
            $reasons[] = 'Color conflict';
        }
    }

    $categoryOverlap = array_values(array_intersect($source['categoryHints'] ?? [], $candidateNormalized['categoryHints'] ?? []));
    if (!empty($categoryOverlap)) {
        $score += 10;
        $breakdown['categoryMatch'] = 10;
        $reasons[] = 'Category hint matched: ' . implode(', ', $categoryOverlap);
    }

    if (($source['price'] ?? null) !== null && ($candidateNormalized['price'] ?? null) !== null && ($source['currency'] ?? null) === ($candidateNormalized['currency'] ?? null)) {
        $sourcePrice = (float) $source['price'];
        $candidatePrice = (float) $candidateNormalized['price'];
        if ($sourcePrice > 0) {
            $diffPct = abs($candidatePrice - $sourcePrice) / $sourcePrice * 100;
            if ($diffPct <= 5) {
                $score += 6;
                $breakdown['priceProximity'] = 6;
                $reasons[] = 'Price difference: ' . number_format($diffPct, 1) . '%';
            } elseif ($diffPct <= 10) {
                $score += 4;
                $breakdown['priceProximity'] = 4;
                $reasons[] = 'Price difference: ' . number_format($diffPct, 1) . '%';
            } elseif ($diffPct > 40 && $titlePercent < 60) {
                $score -= 10;
                $penalties[] = -10;
                $reasons[] = 'Large price difference with weak title match';
            }
        }
    }

    $score = max(0, min(100, $score));
    $breakdown['total'] = $score;
    if (!empty($penalties)) {
        $breakdown['penalties'] = $penalties;
    }

    return [
        'score' => $score,
        'label' => confidenceLabelFromScore($score),
        'reasons' => array_values(array_unique($reasons)),
        'breakdown' => $breakdown,
    ];
}

function confidenceLabelFromScore(int $score): string
{
    return match (true) {
        $score >= 90 => 'very_likely',
        $score >= 70 => 'likely',
        $score >= 50 => 'possible',
        default => 'weak',
    };
}

function persistProductMatchCandidate(
    PDO $db,
    int $productId,
    array $candidate,
    array $score,
    string $queryUsed,
    ?int $serpPosition
): array {
    $candidateUrl = (string) $candidate['finalUrl'];
    $urlHash = hash('sha256', normalizeComparableUrl($candidateUrl));
    $stmt = $db->prepare(
        'INSERT INTO product_match_candidates
         (source_product_id, candidate_url, candidate_url_hash, candidate_domain, candidate_title, candidate_price, candidate_currency,
          confidence_score, confidence_label, reasons_json, breakdown_json, extracted_brand, extracted_model, extracted_mpn,
          extracted_gtin, extracted_sku, extracted_color, extracted_size, extracted_dimensions_json, image_url, availability,
          query_used, serp_position, last_searched_at, last_fetched_at, excluded)
         VALUES
         (:source_product_id, :candidate_url, :candidate_url_hash, :candidate_domain, :candidate_title, :candidate_price, :candidate_currency,
          :confidence_score, :confidence_label, :reasons_json, :breakdown_json, :extracted_brand, :extracted_model, :extracted_mpn,
          :extracted_gtin, :extracted_sku, :extracted_color, :extracted_size, :extracted_dimensions_json, :image_url, :availability,
          :query_used, :serp_position, NOW(), NOW(), :excluded)
         ON DUPLICATE KEY UPDATE
           candidate_domain = VALUES(candidate_domain),
           candidate_title = VALUES(candidate_title),
           candidate_price = VALUES(candidate_price),
           candidate_currency = VALUES(candidate_currency),
           confidence_score = VALUES(confidence_score),
           confidence_label = VALUES(confidence_label),
           reasons_json = VALUES(reasons_json),
           breakdown_json = VALUES(breakdown_json),
           extracted_brand = VALUES(extracted_brand),
           extracted_model = VALUES(extracted_model),
           extracted_mpn = VALUES(extracted_mpn),
           extracted_gtin = VALUES(extracted_gtin),
           extracted_sku = VALUES(extracted_sku),
           extracted_color = VALUES(extracted_color),
           extracted_size = VALUES(extracted_size),
           extracted_dimensions_json = VALUES(extracted_dimensions_json),
           image_url = VALUES(image_url),
           availability = VALUES(availability),
           query_used = VALUES(query_used),
           serp_position = VALUES(serp_position),
           last_searched_at = NOW(),
           last_fetched_at = NOW(),
           excluded = VALUES(excluded)'
    );
    $stmt->execute([
        ':source_product_id' => $productId,
        ':candidate_url' => $candidateUrl,
        ':candidate_url_hash' => $urlHash,
        ':candidate_domain' => (string) ($candidate['domain'] ?? ''),
        ':candidate_title' => $candidate['title'] ?? null,
        ':candidate_price' => $candidate['price'] ?? null,
        ':candidate_currency' => $candidate['currency'] ?? null,
        ':confidence_score' => $score['score'],
        ':confidence_label' => $score['label'],
        ':reasons_json' => json_encode($score['reasons'], JSON_UNESCAPED_UNICODE),
        ':breakdown_json' => json_encode($score['breakdown'], JSON_UNESCAPED_UNICODE),
        ':extracted_brand' => $candidate['brand'] ?? null,
        ':extracted_model' => $candidate['model'] ?? null,
        ':extracted_mpn' => $candidate['mpn'] ?? null,
        ':extracted_gtin' => $candidate['gtin'] ?? null,
        ':extracted_sku' => $candidate['sku'] ?? null,
        ':extracted_color' => $candidate['color'] ?? null,
        ':extracted_size' => $candidate['size'] ?? null,
        ':extracted_dimensions_json' => json_encode($candidate['dimensions'] ?? [], JSON_UNESCAPED_UNICODE),
        ':image_url' => $candidate['image'] ?? null,
        ':availability' => $candidate['availability'] ?? 'unknown',
        ':query_used' => $queryUsed,
        ':serp_position' => $serpPosition,
        ':excluded' => $score['score'] < 50 ? 1 : 0,
    ]);

    $select = $db->prepare('SELECT * FROM product_match_candidates WHERE source_product_id = :pid AND candidate_url_hash = :hash');
    $select->execute([':pid' => $productId, ':hash' => $urlHash]);
    return castProductMatchCandidate($select->fetch());
}

function castProductMatchCandidate(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['source_product_id'] = (int) $row['source_product_id'];
    $row['confidence_score'] = (int) $row['confidence_score'];
    $row['candidate_price'] = $row['candidate_price'] !== null ? (float) $row['candidate_price'] : null;
    $row['excluded'] = (bool) $row['excluded'];
    $row['reasons'] = json_decode((string) ($row['reasons_json'] ?? '[]'), true) ?: [];
    $row['breakdown'] = json_decode((string) ($row['breakdown_json'] ?? '{}'), true) ?: ['total' => $row['confidence_score']];
    $row['extracted_dimensions'] = json_decode((string) ($row['extracted_dimensions_json'] ?? '[]'), true) ?: [];
    unset($row['reasons_json'], $row['breakdown_json'], $row['extracted_dimensions_json']);
    return $row;
}

function normalizeComparableUrl(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = rtrim((string) ($parts['path'] ?? '/'), '/');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . ($path !== '' ? $path : '/') . $query;
}

function normalizeDomain(string $url): ?string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return null;
    }
    return preg_replace('/^www\./i', '', strtolower($host));
}

function fetchJsonUrl(string $url): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($response) || $response === '' || $httpCode >= 400) {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}
