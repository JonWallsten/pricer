<?php

declare(strict_types=1);

/**
 * Price extraction engine.
 *
 * Uses the multi-strategy pipeline to extract prices from product pages.
 * Attempts multiple methods in priority order and returns the first good match.
 *
 * @param string      $url              The product page URL
 * @param string|null $cssSelector      Optional CSS selector for manual price extraction
 * @param string      $extractionStrategy  'auto' | 'selector'
 * @return array{price: float|null, currency: string|null, method: string|null, error: string|null,
 *               image_url: string|null, availability: string, confidence: string|null,
 *               debug_source: string|null, debug_path: string|null, warnings: string[]}
 */
function extractPrice(string $url, ?string $cssSelector = null, string $extractionStrategy = 'auto'): array
{
    $result = [
        'price'                 => null,
        'currency'              => null,
        'method'                => null,
        'error'                 => null,
        'image_url'             => null,
        'availability'          => 'unknown',
        'confidence'            => null,
        'debug_source'          => null,
        'debug_path'            => null,
        'warnings'              => [],
        'regular_price'         => null,
        'previous_lowest_price' => null,
        'is_campaign'           => false,
        'campaign_type'         => null,
        'campaign_label'        => null,
        'campaign_json'         => null,
    ];

    $html = fetchPage($url);
    if ($html === null) {
        $result['error'] = 'Failed to fetch page';
        return $result;
    }

    // Suppress HTML parsing warnings
    $doc = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($internalErrors);

    // Extract product image and availability (independent of price method)
    $result['image_url'] = extractProductImage($doc, $url);
    $result['availability'] = extractAvailability($doc);

    // Use multi-strategy extraction
    $extracted = extractPriceMultiStrategy($doc, $html, $url, $extractionStrategy, $cssSelector);

    if ($extracted['price'] !== null) {
        $result['price']                 = $extracted['price'];
        $result['currency']              = $extracted['currency'];
        $result['method']                = $extracted['method'];
        $result['confidence']            = $extracted['confidence'];
        $result['debug_source']          = $extracted['debug_source'];
        $result['debug_path']            = $extracted['debug_path'];
        $result['warnings']              = $extracted['warnings'] ?? [];
        $result['regular_price']         = $extracted['regular_price'] ?? null;
        $result['previous_lowest_price'] = $extracted['previous_lowest_price'] ?? null;
        $result['is_campaign']           = $extracted['is_campaign'] ?? false;
        $result['campaign_type']         = $extracted['campaign_type'] ?? null;
        $result['campaign_label']        = $extracted['campaign_label'] ?? null;
        $result['campaign_json']         = $extracted['campaign_json'] ?? null;
        return $result;
    }

    // Build informative error message from warnings
    $warnings = $extracted['warnings'] ?? [];
    if ($cssSelector !== null && $cssSelector !== '' && in_array('CSS selector matched no price', $warnings, true)) {
        $otherWarnings = array_filter($warnings, fn($w) => $w !== 'CSS selector matched no price');
        if (!empty($otherWarnings)) {
            $result['error'] = 'CSS selector matched no price. ' . implode('. ', $otherWarnings);
        } else {
            $result['error'] = 'CSS selector matched no price';
        }
    } else {
        $result['error'] = 'No price found via any extraction method';
    }
    $result['warnings'] = $warnings;

    return $result;
}

/**
 * Extract the page title from a URL.
 * Used by the preview endpoint to auto-fill product name.
 */
function extractPageTitle(string $url): ?string
{
    $html = fetchPage($url);
    if ($html === null) {
        return null;
    }
    $doc = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($internalErrors);

    return extractPageTitleFromDoc($doc);
}

/**
 * Extract the page title from a DOMDocument.
 */
function extractPageTitleFromDoc(DOMDocument $doc): ?string
{
    $xpath = new DOMXPath($doc);

    // Prefer og:title — usually a cleaner product name
    $ogTitle = $xpath->query("//meta[@property='og:title']/@content")->item(0);
    if ($ogTitle !== null && trim($ogTitle->textContent) !== '') {
        return trim($ogTitle->textContent);
    }

    // Fallback to <title> tag
    $titleNode = $xpath->query('//title')->item(0);
    if ($titleNode !== null && trim($titleNode->textContent) !== '') {
        $title = trim($titleNode->textContent);
        // Strip common suffixes: "Product Name - Store Name", "Product Name | Store"
        $title = preg_replace('/\s*[\-–—|]\s*[^|\-–—]*$/', '', $title);
        return trim($title);
    }

    return null;
}

/**
 * Validate that a URL is safe for outbound fetching.
 *
 * Only public HTTP(S) targets are allowed. This blocks localhost, private or
 * reserved IP ranges, and hostnames that resolve to those ranges.
 */
function isAllowedFetchUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = $parts['host'] ?? '';
    if ($host === '') {
        return false;
    }

    if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.localhost')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return isPublicIpAddress($host);
    }

    $records = dns_get_record($host, DNS_A + DNS_AAAA);
    if ($records === false || $records === []) {
        return false;
    }

    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!is_string($ip) || !isPublicIpAddress($ip)) {
            return false;
        }
    }

    return true;
}

/**
 * Reject private, loopback, link-local, multicast, and other reserved IPs.
 */
function isPublicIpAddress(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Fetch a page over HTTP using cURL.
 */
function fetchPage(string $url): ?string
{
    if (!isAllowedFetchUrl($url)) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        // Redirects are disabled so a public URL cannot bounce into localhost/private ranges.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',  // Accept any encoding (gzip, deflate)
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: sv-SE,sv;q=0.9,en;q=0.8',
        ],
        // SSL verification
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        return null;
    }

    return is_string($response) ? $response : null;
}

// ─── Extraction methods ───────────────────────────────────

/**
 * Extract price from JSON-LD structured data (Schema.org Product / Offer).
 */
function extractFromJsonLd(DOMDocument $doc): array
{
    $result = ['price' => null, 'currency' => null, 'method' => null, 'error' => null];

    $xpath = new DOMXPath($doc);
    $scripts = $xpath->query('//script[@type="application/ld+json"]');

    if ($scripts === false) {
        return $result;
    }

    foreach ($scripts as $script) {
        $json = json_decode(trim($script->textContent), true);
        if (!is_array($json)) {
            continue;
        }

        // Handle @graph arrays (common in many sites)
        $items = [];
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            $items = $json['@graph'];
        } else {
            $items = [$json];
        }

        // Also handle top-level arrays
        if (isset($json[0])) {
            $items = $json;
        }

        foreach ($items as $item) {
            $extracted = extractPriceFromJsonLdItem($item);
            if ($extracted !== null) {
                $result['price'] = $extracted['price'];
                $result['currency'] = $extracted['currency'];
                $result['method'] = 'json-ld';
                return $result;
            }
        }
    }

    return $result;
}

/**
 * Recursively search a JSON-LD item for Product/Offer price data.
 */
function extractPriceFromJsonLdItem(mixed $item): ?array
{
    if (!is_array($item)) {
        return null;
    }

    $type = $item['@type'] ?? '';
    // Normalize to string if array (e.g., ["Product", "IndividualProduct"])
    if (is_array($type)) {
        $type = implode(',', $type);
    }

    // Direct offer with price
    if (str_contains($type, 'Offer') || str_contains($type, 'AggregateOffer')) {
        $price = $item['price'] ?? $item['lowPrice'] ?? $item['highPrice'] ?? null;
        if ($price !== null) {
            $parsed = parsePrice((string) $price);
            if ($parsed !== null) {
                return [
                    'price'    => $parsed,
                    'currency' => $item['priceCurrency'] ?? null,
                ];
            }
        }
    }

    // Product with offers
    if (str_contains($type, 'Product')) {
        $offers = $item['offers'] ?? null;
        if (is_array($offers)) {
            // Single offer object
            if (isset($offers['price']) || isset($offers['lowPrice']) || isset($offers['@type'])) {
                $extracted = extractPriceFromJsonLdItem($offers);
                if ($extracted !== null) {
                    return $extracted;
                }
            }
            // Array of offers — take the first valid one
            foreach ($offers as $offer) {
                if (is_array($offer)) {
                    $extracted = extractPriceFromJsonLdItem($offer);
                    if ($extracted !== null) {
                        return $extracted;
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Extract price from Open Graph and product meta tags.
 */
function extractFromMetaTags(DOMDocument $doc): array
{
    $result = ['price' => null, 'currency' => null, 'method' => null, 'error' => null];

    $xpath = new DOMXPath($doc);

    // Price meta tags in priority order
    $priceSelectors = [
        'product:price:amount',
        'og:price:amount',
        'twitter:data1',  // Some sites use this for price
    ];

    $currencySelectors = [
        'product:price:currency',
        'og:price:currency',
    ];

    $price = null;
    foreach ($priceSelectors as $prop) {
        $node = $xpath->query("//meta[@property='$prop']/@content")->item(0)
            ?? $xpath->query("//meta[@name='$prop']/@content")->item(0);
        if ($node !== null) {
            $price = parsePrice($node->textContent);
            if ($price !== null) {
                break;
            }
        }
    }

    if ($price === null) {
        return $result;
    }

    $currency = null;
    foreach ($currencySelectors as $prop) {
        $node = $xpath->query("//meta[@property='$prop']/@content")->item(0)
            ?? $xpath->query("//meta[@name='$prop']/@content")->item(0);
        if ($node !== null) {
            $currency = strtoupper(trim($node->textContent));
            break;
        }
    }

    $result['price'] = $price;
    $result['currency'] = $currency;
    $result['method'] = 'meta-tags';
    return $result;
}

/**
 * Extract price from Microdata (itemprop="price").
 */
function extractFromMicrodata(DOMDocument $doc): array
{
    $result = ['price' => null, 'currency' => null, 'method' => null, 'error' => null];

    $xpath = new DOMXPath($doc);

    // Look for itemprop="price" — prefer content attribute, fallback to text
    $nodes = $xpath->query('//*[@itemprop="price"]');
    if ($nodes === false || $nodes->length === 0) {
        return $result;
    }

    foreach ($nodes as $node) {
        /** @var DOMElement $node */
        $raw = $node->getAttribute('content') ?: $node->textContent;
        $price = parsePrice($raw);
        if ($price !== null) {
            $result['price'] = $price;
            $result['method'] = 'microdata';

            // Try to find currency nearby
            $currencyNode = $xpath->query('//*[@itemprop="priceCurrency"]')->item(0);
            if ($currencyNode instanceof DOMElement) {
                $result['currency'] = strtoupper(trim(
                    $currencyNode->getAttribute('content') ?: $currencyNode->textContent
                ));
            }

            return $result;
        }
    }

    return $result;
}

/**
 * Extract price using a CSS selector (converted to XPath).
 */
function extractFromCssSelector(DOMDocument $doc, string $selector): array
{
    $result = ['price' => null, 'currency' => null, 'method' => null, 'error' => null];

    $xpath = new DOMXPath($doc);
    $xpathExpr = cssToXPath($selector);

    if ($xpathExpr === null) {
        $result['error'] = 'Invalid CSS selector';
        return $result;
    }

    $nodes = @$xpath->query($xpathExpr);
    if ($nodes === false || $nodes->length === 0) {
        return $result;
    }

    foreach ($nodes as $node) {
        /** @var DOMElement $node */
        $raw = $node->getAttribute('content') ?: $node->textContent;
        $price = parsePrice($raw);
        if ($price !== null) {
            $result['price'] = $price;
            $result['method'] = 'css-selector';
            return $result;
        }
    }

    return $result;
}

// ─── Availability extraction ──────────────────────────────

/**
 * Extract product availability from structured data and meta tags.
 *
 * Priority: JSON-LD Offer.availability → meta product:availability → microdata
 * Maps Schema.org values to internal enum: in_stock, out_of_stock, preorder, unknown
 */
function extractAvailability(DOMDocument $doc): string
{
    $xpath = new DOMXPath($doc);

    // 1. JSON-LD — look for Offer/AggregateOffer availability
    $scripts = $xpath->query('//script[@type="application/ld+json"]');
    if ($scripts !== false) {
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
                $avail = extractAvailabilityFromJsonLdItem($item);
                if ($avail !== 'unknown') {
                    return $avail;
                }
            }
        }
    }

    // 2. Meta tags: product:availability, og:availability
    foreach (['product:availability', 'og:availability'] as $prop) {
        $node = $xpath->query("//meta[@property='$prop']/@content")->item(0)
            ?? $xpath->query("//meta[@name='$prop']/@content")->item(0);
        if ($node !== null) {
            $mapped = mapAvailability($node->textContent);
            if ($mapped !== 'unknown') {
                return $mapped;
            }
        }
    }

    // 3. Microdata itemprop="availability"
    $microNode = $xpath->query('//*[@itemprop="availability"]')->item(0);
    if ($microNode instanceof DOMElement) {
        $val = $microNode->getAttribute('content') ?: $microNode->getAttribute('href') ?: $microNode->textContent;
        $mapped = mapAvailability($val);
        if ($mapped !== 'unknown') {
            return $mapped;
        }
    }

    return 'unknown';
}

/**
 * Recursively search a JSON-LD item for Offer availability.
 */
function extractAvailabilityFromJsonLdItem(mixed $item): string
{
    if (!is_array($item)) {
        return 'unknown';
    }

    $type = $item['@type'] ?? '';
    if (is_array($type)) {
        $type = implode(',', $type);
    }

    // Direct offer with availability
    if (str_contains($type, 'Offer') || str_contains($type, 'AggregateOffer')) {
        if (isset($item['availability'])) {
            return mapAvailability((string) $item['availability']);
        }
    }

    // Product with offers
    if (str_contains($type, 'Product')) {
        $offers = $item['offers'] ?? null;
        if (is_array($offers)) {
            if (isset($offers['availability'])) {
                return mapAvailability((string) $offers['availability']);
            }
            foreach ($offers as $offer) {
                if (is_array($offer) && isset($offer['availability'])) {
                    return mapAvailability((string) $offer['availability']);
                }
            }
        }
    }

    return 'unknown';
}

/**
 * Map Schema.org availability URIs and common strings to internal enum values.
 */
function mapAvailability(string $raw): string
{
    $raw = strtolower(trim($raw));

    // Schema.org URIs: https://schema.org/InStock, http://schema.org/InStock, InStock
    $patterns = [
        'instock' => 'in_stock',
        'in_stock' => 'in_stock',
        'in stock' => 'in_stock',
        'outofstock' => 'out_of_stock',
        'out_of_stock' => 'out_of_stock',
        'out of stock' => 'out_of_stock',
        'soldout' => 'out_of_stock',
        'discontinued' => 'out_of_stock',
        'preorder' => 'preorder',
        'pre-order' => 'preorder',
        'presale' => 'preorder',
        'backorder' => 'preorder',
    ];

    // Strip schema.org URI prefix
    $raw = preg_replace('#^https?://schema\.org/#i', '', $raw);

    foreach ($patterns as $pattern => $value) {
        if (str_contains($raw, $pattern)) {
            return $value;
        }
    }

    return 'unknown';
}

/**
 * Full extraction for the preview endpoint.
 * Fetches the page once and extracts price, image, availability, and page title.
 * Uses the multi-strategy pipeline.
 *
 * @return array{price: float|null, currency: string|null, method: string|null, error: string|null,
 *               image_url: string|null, availability: string, page_title: string|null,
 *               confidence: string|null, debug_source: string|null, debug_path: string|null, warnings: string[]}
 */
function extractPreview(string $url, ?string $cssSelector = null, string $extractionStrategy = 'auto'): array
{
    $result = [
        'price'                 => null,
        'currency'              => null,
        'method'                => null,
        'error'                 => null,
        'image_url'             => null,
        'availability'          => 'unknown',
        'page_title'            => null,
        'confidence'            => null,
        'debug_source'          => null,
        'debug_path'            => null,
        'warnings'              => [],
        'regular_price'         => null,
        'previous_lowest_price' => null,
        'is_campaign'           => false,
        'campaign_type'         => null,
        'campaign_label'        => null,
    ];

    $html = fetchPage($url);
    if ($html === null) {
        $result['error'] = 'Failed to fetch page';
        return $result;
    }

    $doc = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($internalErrors);

    // Extract all metadata from the single fetch
    $result['image_url'] = extractProductImage($doc, $url);
    $result['availability'] = extractAvailability($doc);
    $result['page_title'] = extractPageTitleFromDoc($doc);

    // Use multi-strategy extraction
    $extracted = extractPriceMultiStrategy($doc, $html, $url, $extractionStrategy, $cssSelector);

    $result['price']                 = $extracted['price'];
    $result['currency']              = $extracted['currency'];
    $result['method']                = $extracted['method'];
    $result['confidence']            = $extracted['confidence'];
    $result['debug_source']          = $extracted['debug_source'];
    $result['debug_path']            = $extracted['debug_path'];
    $result['warnings']              = $extracted['warnings'] ?? [];
    $result['regular_price']         = $extracted['regular_price'] ?? null;
    $result['previous_lowest_price'] = $extracted['previous_lowest_price'] ?? null;
    $result['is_campaign']           = $extracted['is_campaign'] ?? false;
    $result['campaign_type']         = $extracted['campaign_type'] ?? null;
    $result['campaign_label']        = $extracted['campaign_label'] ?? null;

    if ($extracted['price'] === null) {
        $result['error'] = !empty($extracted['warnings'])
            ? implode('. ', $extracted['warnings'])
            : 'No price found via any extraction method';
    }

    return $result;
}

// ─── Product image extraction ─────────────────────────────

/**
 * Extract product image URL from structured data and meta tags.
 *
 * Priority: JSON-LD Product image → og:image → microdata itemprop="image"
 *
 * @return string|null Absolute image URL or null
 */
function extractProductImage(DOMDocument $doc, string $pageUrl): ?string
{
    $xpath = new DOMXPath($doc);

    // 1. JSON-LD: look for Product.image
    $scripts = $xpath->query('//script[@type="application/ld+json"]');
    if ($scripts !== false) {
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
                $img = extractImageFromJsonLdItem($item);
                if ($img !== null) {
                    return resolveUrl($img, $pageUrl);
                }
            }
        }
    }

    // 2. og:image meta tag
    $ogNode = $xpath->query("//meta[@property='og:image']/@content")->item(0);
    if ($ogNode !== null && trim($ogNode->textContent) !== '') {
        return resolveUrl(trim($ogNode->textContent), $pageUrl);
    }

    // 3. Microdata itemprop="image"
    $microNode = $xpath->query('//*[@itemprop="image"]')->item(0);
    if ($microNode instanceof DOMElement) {
        $src = $microNode->getAttribute('content')
            ?: $microNode->getAttribute('src')
            ?: $microNode->getAttribute('href');
        if ($src !== '') {
            return resolveUrl($src, $pageUrl);
        }
    }

    return null;
}

/**
 * Recursively search a JSON-LD item for a Product image.
 */
function extractImageFromJsonLdItem(mixed $item): ?string
{
    if (!is_array($item)) {
        return null;
    }

    $type = $item['@type'] ?? '';
    if (is_array($type)) {
        $type = implode(',', $type);
    }

    if (str_contains($type, 'Product')) {
        $image = $item['image'] ?? null;
        if (is_string($image) && $image !== '') {
            return $image;
        }
        if (is_array($image)) {
            // Could be an array of URLs or an ImageObject
            if (isset($image['url'])) {
                return $image['url'];
            }
            if (isset($image['@id'])) {
                return $image['@id'];
            }
            // Array of strings — take first
            foreach ($image as $img) {
                if (is_string($img) && $img !== '') {
                    return $img;
                }
                if (is_array($img) && isset($img['url'])) {
                    return $img['url'];
                }
            }
        }
    }

    return null;
}

/**
 * Resolve a potentially relative URL against a base page URL.
 */
function resolveUrl(string $url, string $base): string
{
    // Already absolute
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    // Protocol-relative
    if (str_starts_with($url, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }
    // Relative — resolve against base
    $parts = parse_url($base);
    $root = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (str_starts_with($url, '/')) {
        return $root . $url;
    }
    $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
    return $root . $dir . '/' . $url;
}

// ─── Price parsing ────────────────────────────────────────

/**
 * Parse a price string into a float. Handles Swedish formats:
 * - "1 234,50 kr"  → 1234.50
 * - "1234:-"       → 1234.00
 * - "1 234:50"     → 1234.50
 * - "1,234.50"     → 1234.50  (English format)
 * - "SEK 1234.50"  → 1234.50
 */
function parsePrice(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    // Remove currency symbols/words and common suffixes
    $raw = preg_replace('/(?:SEK|kr|:-|USD|EUR|€|\$|£)/i', '', $raw);
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    // Remove non-breaking spaces and regular spaces used as thousands separators
    $raw = preg_replace('/[\x{00A0}\x{202F}\s]+/u', '', $raw);

    // Determine decimal separator:
    // If both comma and period exist, the last one is the decimal separator
    $lastComma = strrpos($raw, ',');
    $lastPeriod = strrpos($raw, '.');
    $lastColon = strrpos($raw, ':');

    if ($lastComma !== false && $lastPeriod !== false) {
        // Both exist — last one is decimal
        if ($lastComma > $lastPeriod) {
            // 1.234,50 → Swedish/European
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            // 1,234.50 → English
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($lastComma !== false) {
        // Only comma — treat as decimal separator
        $raw = str_replace(',', '.', $raw);
    } elseif ($lastColon !== false) {
        // Swedish colon format: 1234:50
        $raw = str_replace(':', '.', $raw);
    }
    // else: only period or no separator — already in correct format

    // Extract the numeric value
    if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
        $val = (float) $m[1];
        return $val > 0 ? $val : null;
    }

    return null;
}

// ─── CSS to XPath conversion (basic) ─────────────────────

/**
 * Convert a simple CSS selector to XPath.
 * Supports: tag, .class, #id, tag.class, tag#id, [attr], [attr=val],
 * descendant combinators (space), and basic chaining.
 */
function cssToXPath(string $css): ?string
{
    $css = trim($css);
    if ($css === '') {
        return null;
    }

    // Split by spaces for descendant combinator
    $parts = preg_split('/\s+/', $css);
    $xpathParts = [];

    foreach ($parts as $part) {
        $xpath = '';
        $tag = '*';

        // Extract tag name (if present at start)
        if (preg_match('/^([a-zA-Z][\w-]*)/', $part, $m)) {
            $tag = $m[1];
            $part = substr($part, strlen($m[1]));
        }

        $xpath = $tag;
        $conditions = [];

        // Process remaining selectors (#id, .class, [attr])
        while ($part !== '' && $part !== false) {
            if (str_starts_with($part, '#')) {
                // ID selector
                preg_match('/^#([\w-]+)/', $part, $m);
                if (!$m) break;
                $conditions[] = "@id='" . htmlspecialchars($m[1], ENT_QUOTES) . "'";
                $part = substr($part, strlen($m[0]));
            } elseif (str_starts_with($part, '.')) {
                // Class selector
                preg_match('/^\.([\w-]+)/', $part, $m);
                if (!$m) break;
                $cls = htmlspecialchars($m[1], ENT_QUOTES);
                $conditions[] = "contains(concat(' ',normalize-space(@class),' '),' $cls ')";
                $part = substr($part, strlen($m[0]));
            } elseif (str_starts_with($part, '[')) {
                // Attribute selector
                preg_match('/^\[([^\]]+)\]/', $part, $m);
                if (!$m) break;
                $attr = $m[1];
                if (str_contains($attr, '=')) {
                    [$aName, $aVal] = explode('=', $attr, 2);
                    $aVal = trim($aVal, '\'"');
                    $conditions[] = "@" . trim($aName) . "='" . htmlspecialchars($aVal, ENT_QUOTES) . "'";
                } else {
                    $conditions[] = "@" . trim($attr);
                }
                $part = substr($part, strlen($m[0]));
            } else {
                break;
            }
        }

        if ($conditions) {
            $xpath .= '[' . implode(' and ', $conditions) . ']';
        }

        $xpathParts[] = $xpath;
    }

    return '//' . implode('//', $xpathParts);
}

// ─── Product pricing interpretation ─────────────────────

/**
 * Build a transient identity context for the main product on the page.
 *
 * Aggregates signals from JSON-LD, Open Graph, microdata, DOM, and script data
 * so that downstream functions can decide which discovered prices belong to the
 * main product and which belong to recommendations / accessories.
 *
 * @return array{title: ?string, sku: ?string, gtin: ?string, brand: ?string,
 *               image: ?string, url: string, identifiers: string[],
 *               confidence: int, reasons: string[]}
 */
function buildMainProductContext(DOMDocument $doc, string $rawHtml, string $url): array
{
    $ctx = [
        'title'       => null,
        'sku'         => null,
        'gtin'        => null,
        'brand'       => null,
        'image'       => null,
        'url'         => $url,
        'identifiers' => [],
        'confidence'  => 0,
        'reasons'     => [],
    ];

    $xpath = new DOMXPath($doc);

    // ── 1. JSON-LD Product (highest signal) ──────────────
    $productCount = 0;
    $scripts = $xpath->query('//script[@type="application/ld+json"]');
    if ($scripts !== false) {
        foreach ($scripts as $script) {
            $json = json_decode(trim($script->textContent), true);
            if (!is_array($json)) {
                continue;
            }
            $items = isset($json['@graph']) && is_array($json['@graph']) ? $json['@graph'] : (isset($json[0]) ? $json : [$json]);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = $item['@type'] ?? '';
                if (is_array($type)) {
                    $type = implode(',', $type);
                }
                if (!str_contains($type, 'Product')) {
                    continue;
                }
                $productCount++;
                if ($productCount === 1) {
                    $ctx['title'] = is_string($item['name'] ?? null) ? $item['name'] : $ctx['title'];
                    $ctx['sku']   = is_string($item['sku'] ?? null) ? $item['sku'] : $ctx['sku'];
                    $ctx['brand'] = is_string($item['brand']['name'] ?? null)
                        ? $item['brand']['name']
                        : (is_string($item['brand'] ?? null) ? $item['brand'] : $ctx['brand']);
                    $ctx['image'] = is_string($item['image'] ?? null)
                        ? $item['image']
                        : (is_array($item['image'] ?? null) && is_string($item['image'][0] ?? null) ? $item['image'][0] : $ctx['image']);
                    foreach (['gtin', 'gtin13', 'gtin14', 'gtin8', 'ean'] as $gf) {
                        if (is_string($item[$gf] ?? null) && $item[$gf] !== '') {
                            $ctx['gtin'] = $item[$gf];
                            break;
                        }
                    }
                    foreach (['sku', 'mpn', 'productID', 'identifier'] as $idf) {
                        if (is_string($item[$idf] ?? null) && $item[$idf] !== '') {
                            $ctx['identifiers'][] = $item[$idf];
                        }
                    }
                    if ($ctx['gtin'] !== null) {
                        $ctx['identifiers'][] = $ctx['gtin'];
                    }
                    $ctx['confidence'] += 40;
                    $ctx['reasons'][] = 'JSON-LD Product found';
                }
            }
        }
    }
    if ($productCount > 1) {
        $ctx['confidence'] -= 20;
        $ctx['reasons'][] = "Multiple JSON-LD Products ($productCount) — ambiguity penalty";
    }

    // ── 2. Open Graph / product meta ─────────────────────
    $ogTitle = $xpath->query('//meta[@property="og:title"]/@content')->item(0);
    if ($ogTitle !== null) {
        $ogTitleVal = trim($ogTitle->textContent);
        if ($ogTitleVal !== '') {
            $ctx['title'] ??= $ogTitleVal;
            $ctx['confidence'] += 10;
            $ctx['reasons'][] = 'og:title present';
        }
    }
    foreach (['product:brand', 'og:brand'] as $bp) {
        $bn = $xpath->query("//meta[@property='$bp']/@content")->item(0);
        if ($bn !== null && trim($bn->textContent) !== '') {
            $ctx['brand'] ??= trim($bn->textContent);
            break;
        }
    }
    $ogImage = $xpath->query('//meta[@property="og:image"]/@content')->item(0);
    if ($ogImage !== null && trim($ogImage->textContent) !== '') {
        $ctx['image'] ??= trim($ogImage->textContent);
    }

    // ── 3. Microdata ─────────────────────────────────────
    $mdProduct = $xpath->query('//*[@itemtype and contains(@itemtype,"Product")]')->item(0);
    if ($mdProduct instanceof DOMElement) {
        $mdName = $xpath->query('.//*[@itemprop="name"]', $mdProduct)->item(0);
        if ($mdName instanceof DOMElement) {
            $val = trim($mdName->getAttribute('content') ?: $mdName->textContent);
            if ($val !== '') {
                $ctx['title'] ??= $val;
            }
        }
        foreach (['sku', 'mpn', 'gtin13', 'gtin'] as $mp) {
            $node = $xpath->query(".//*[@itemprop='$mp']", $mdProduct)->item(0);
            if ($node instanceof DOMElement) {
                $val = trim($node->getAttribute('content') ?: $node->textContent);
                if ($val !== '') {
                    if ($mp === 'gtin13' || $mp === 'gtin') {
                        $ctx['gtin'] ??= $val;
                    }
                    if ($mp === 'sku') {
                        $ctx['sku'] ??= $val;
                    }
                    $ctx['identifiers'][] = $val;
                }
            }
        }
        $mdBrand = $xpath->query('.//*[@itemprop="brand"]', $mdProduct)->item(0);
        if ($mdBrand instanceof DOMElement) {
            // brand can be nested: itemprop="brand" > itemprop="name"
            $inner = $xpath->query('.//*[@itemprop="name"]', $mdBrand)->item(0);
            $val = $inner instanceof DOMElement
                ? trim($inner->getAttribute('content') ?: $inner->textContent)
                : trim($mdBrand->getAttribute('content') ?: $mdBrand->textContent);
            if ($val !== '') {
                $ctx['brand'] ??= $val;
            }
        }
    }

    // ── 4. DOM signals ───────────────────────────────────
    $h1 = $xpath->query('//h1')->item(0);
    if ($h1 instanceof DOMElement) {
        $h1Text = trim($h1->textContent);
        if ($h1Text !== '' && $ctx['title'] !== null && str_contains(mb_strtolower($ctx['title']), mb_strtolower(mb_substr($h1Text, 0, 30)))) {
            $ctx['confidence'] += 10;
            $ctx['reasons'][] = 'H1 matches product title';
        }
        $ctx['title'] ??= $h1Text;
    }

    // data-product-* attributes
    $dpNodes = $xpath->query('//*[@data-product-id or @data-product-sku or @data-product-name]');
    if ($dpNodes !== false && $dpNodes->length > 0) {
        $dp = $dpNodes->item(0);
        if ($dp instanceof DOMElement) {
            $dpSku = $dp->getAttribute('data-product-sku');
            $dpName = $dp->getAttribute('data-product-name');
            if ($dpSku !== '') {
                $ctx['sku'] ??= $dpSku;
                $ctx['identifiers'][] = $dpSku;
            }
            if ($dpName !== '') {
                $ctx['title'] ??= $dpName;
            }
        }
    }

    // ── 5. Identifier scoring ────────────────────────────
    $ctx['identifiers'] = array_values(array_unique($ctx['identifiers']));
    if ($ctx['sku'] !== null || $ctx['gtin'] !== null) {
        $idPoints = min(30, (($ctx['sku'] !== null ? 15 : 0) + ($ctx['gtin'] !== null ? 15 : 0)));
        $ctx['confidence'] += $idPoints;
        $ctx['reasons'][] = 'Product identifiers found (sku/gtin)';
    }
    if ($ctx['brand'] !== null) {
        $ctx['confidence'] += 10;
        $ctx['reasons'][] = 'Brand found';
    }

    $ctx['confidence'] = max(0, min(100, $ctx['confidence']));

    return $ctx;
}

/**
 * Score how strongly a price candidate belongs to the main product.
 *
 * Returns the candidate enriched with productAssociationScore (0-100)
 * and productAssociationReasons.
 *
 * @param array $candidate  A PriceCandidate-like array
 * @param array $context    From buildMainProductContext()
 * @return array Enriched candidate
 */
function scoreProductAssociation(array $candidate, array $context): array
{
    $score = 50; // neutral baseline
    $reasons = [];

    $sourceType  = $candidate['sourceType'] ?? '';
    $path        = mb_strtolower($candidate['path'] ?? '');
    $label       = mb_strtolower($candidate['label'] ?? '');

    // ── Positive signals ─────────────────────────────────

    // JSON-LD source is page-level structured data → strong signal
    if ($sourceType === 'jsonld') {
        $score += 20;
        $reasons[] = 'JSON-LD structured data (page-level)';
    }

    // Meta / microdata are page-level
    if ($sourceType === 'meta' || $sourceType === 'microdata') {
        $score += 20;
        $reasons[] = ucfirst($sourceType) . ' source (page-level)';
    }

    // CSS selector (user explicitly pointed to it)
    if ($sourceType === 'css_selector') {
        $score += 30;
        $reasons[] = 'User-provided CSS selector';
    }

    // Path or label contains main product identifiers
    if ($context['sku'] !== null && $context['sku'] !== '') {
        $skuLower = mb_strtolower($context['sku']);
        if (str_contains($path, $skuLower) || str_contains($label, $skuLower)) {
            $score += 20;
            $reasons[] = 'Path/label contains product SKU';
        }
    }
    if ($context['gtin'] !== null && $context['gtin'] !== '') {
        $gtinLower = mb_strtolower($context['gtin']);
        if (str_contains($path, $gtinLower) || str_contains($label, $gtinLower)) {
            $score += 20;
            $reasons[] = 'Path/label contains product GTIN';
        }
    }

    // WebComponents initialPrices[0] is typically the primary product
    if (preg_match('/initialprices\[0\]/', $path)) {
        $score += 15;
        $reasons[] = 'Primary slot in initialPrices[0]';
    }

    // ── Negative signals ─────────────────────────────────

    // Recommendation / related product context
    if (preg_match('/\b(recommend|related|similar|also.?bought|upsell|accessor|carousel|slider|cross.?sell)\b/i', $path . ' ' . $label)) {
        $score -= 40;
        $reasons[] = 'Path suggests recommendation/related product';
    }

    // WebComponents component name suggests recommendations
    $patternType = $candidate['patternType'] ?? '';
    if (preg_match('/\b(recommend|related|similar|accessor|upsell|cross.?sell)\b/i', $patternType)) {
        $score -= 50;
        $reasons[] = 'WebComponent name suggests recommendation';
    }

    $score = max(0, min(100, $score));

    $candidate['productAssociationScore']   = $score;
    $candidate['productAssociationReasons'] = $reasons;

    return $candidate;
}

/**
 * Classify a price candidate into a role.
 *
 * Roles: current, regular, campaign, previous_lowest, unit, from, member, unknown
 *
 * @param array   $candidate      A PriceCandidate-like array
 * @param array[] $allCandidates  All candidates for cross-referencing
 * @return string The classified role
 */
function classifyPriceRole(array $candidate, array $allCandidates): string
{
    $path  = mb_strtolower($candidate['path'] ?? '');
    $label = mb_strtolower($candidate['label'] ?? '');
    $field = $path . ' ' . $label;

    // ── previous_lowest (Omnibus 30-day lowest) ──────────
    // Must check before regular, because "lowestPrice" could match careless patterns
    $omnibusPatterns = '/\b(lowestprice|previouslowest|lowest.?30|lowest.?price.?in|comparisonprice|omnibusprice|jamforpris|lagsta.?pris|previousprice30)\b/';
    if (preg_match($omnibusPatterns, $field)) {
        return 'previous_lowest';
    }

    // Dom context clues for Omnibus
    $domContext = mb_strtolower($candidate['domContext'] ?? '');
    if ($domContext !== '' && preg_match('/\b(lagsta|lowest.?30|omnibus|jamforpris|comparison.?price|30.?dag)\b/', $domContext)) {
        return 'previous_lowest';
    }

    // ── regular / list price ─────────────────────────────
    if (preg_match('/\b(regularprice|ordinarie|listprice|originalprice|normalprice|beforeprice|msrp|rrp)\b/', $field)) {
        return 'regular';
    }
    // DOM signals for old/struck price
    $sourceType = $candidate['sourceType'] ?? '';
    if ($sourceType === 'dom' && preg_match('/\b(old|was|original|before|ordinarie|previous|regular|strike|compare|list)\b/', $domContext)) {
        return 'regular';
    }
    if (($candidate['domTag'] ?? '') === 'del' || ($candidate['domTag'] ?? '') === 's') {
        return 'regular';
    }

    // ── unit price ───────────────────────────────────────
    if (preg_match('/\b(unitprice|perunit|styckpris|perprice|priceperunit)\b/', $field)) {
        return 'unit';
    }

    // ── from price ───────────────────────────────────────
    if (preg_match('/\b(fromprice|franpris|startingprice|froeprice|starting.?at)\b/', $field)) {
        return 'from';
    }

    // ── member price ─────────────────────────────────────
    if (preg_match('/\b(memberprice|medlemspris|clubprice|klubbpris|loyaltyprice)\b/', $field)) {
        return 'member';
    }

    // ── campaign / sale price ────────────────────────────
    if (preg_match('/\b(saleprice|kampanjpris|discountprice|specialprice|dealprice|offerprice)\b/', $field)) {
        return 'campaign';
    }
    if ($sourceType === 'dom' && preg_match('/\b(sale|kampanj|rea|discount|special|erbjudande)\b/', $domContext)) {
        return 'campaign';
    }

    // ── current (the standard/active price) ──────────────
    // adjustedPrice / currentPrice / finalPrice are "what you pay now"
    if (preg_match('/\b(adjustedprice|currentprice|finalprice|actualprice|yourprice)\b/', $field)) {
        return 'current';
    }
    // JSON-LD Offer price is the canonical current price
    if ($sourceType === 'jsonld' && preg_match('/\boffer/', $path)) {
        return 'current';
    }
    // Generic "price" field in structured sources → current
    if (in_array($sourceType, ['jsonld', 'meta', 'microdata'], true)) {
        return 'current';
    }

    return 'unknown';
}

/**
 * Detect whether a campaign/promotion is active, using classified candidates.
 *
 * @param array[] $candidates  Enriched candidates with priceRole set
 * @param array   $context     From buildMainProductContext()
 * @return array|null  Campaign info or null if no campaign detected
 */
function detectCampaign(array $candidates, array $context): ?array
{
    // Collect prices by role (only high-association candidates)
    $byRole = [];
    foreach ($candidates as $c) {
        $role  = $c['priceRole'] ?? 'unknown';
        $score = $c['productAssociationScore'] ?? 0;
        if ($score >= 20 && ($c['numericValue'] ?? 0) > 0) {
            $byRole[$role][] = $c;
        }
    }

    $currentPrice  = null;
    $regularPrice  = null;
    $previousLowest = null;
    $campaignPrice = null;

    // Best price per role (highest association score, then highest confidence)
    $pickBest = function (array $list): ?array {
        usort($list, function ($a, $b) {
            $aDiff = ($b['productAssociationScore'] ?? 0) - ($a['productAssociationScore'] ?? 0);
            if ($aDiff !== 0) {
                return $aDiff;
            }
            $confOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($confOrder[$a['confidence'] ?? 'low'] ?? 3) <=> ($confOrder[$b['confidence'] ?? 'low'] ?? 3);
        });
        return $list[0] ?? null;
    };

    if (!empty($byRole['current'])) {
        $best = $pickBest($byRole['current']);
        $currentPrice = $best['numericValue'] ?? null;
    }
    if (!empty($byRole['regular'])) {
        $best = $pickBest($byRole['regular']);
        $regularPrice = $best['numericValue'] ?? null;
    }
    if (!empty($byRole['previous_lowest'])) {
        $best = $pickBest($byRole['previous_lowest']);
        $previousLowest = $best['numericValue'] ?? null;
    }
    if (!empty($byRole['campaign'])) {
        $best = $pickBest($byRole['campaign']);
        $campaignPrice = $best['numericValue'] ?? null;
    }

    // The "active" (what you pay) price is campaign > current > first available
    $activePrice = $campaignPrice ?? $currentPrice;
    if ($activePrice === null) {
        return null;
    }

    // Determine if there IS a campaign
    $isCampaign = false;
    $type = null;
    $label = null;

    // 1. Explicit campaign price < regular price
    if ($regularPrice !== null && $activePrice < $regularPrice) {
        $isCampaign = true;
        $type = 'discount_price';
        $label = null; // will try to detect below
    }

    // 2. Campaign role price exists and is lower than current
    if ($campaignPrice !== null && $currentPrice !== null && $campaignPrice < $currentPrice) {
        $isCampaign = true;
        $type = 'discount_price';
        $activePrice = $campaignPrice;
    }

    if (!$isCampaign) {
        return null;
    }

    // Reference price for savings: prefer Omnibus 30-day lowest per EU directive
    $referencePrice = $previousLowest ?? $regularPrice;
    $savings = $referencePrice !== null ? round($referencePrice - $activePrice, 2) : null;
    $savingsPct = ($referencePrice !== null && $referencePrice > 0)
        ? round(($referencePrice - $activePrice) / $referencePrice * 100, 1)
        : null;

    return [
        'type'                 => $type ?? 'discount_price',
        'label'                => $label,
        'regular_price'        => $regularPrice,
        'previous_lowest_price' => $previousLowest,
        'campaign_price'       => $activePrice,
        'savings'              => $savings,
        'savings_pct'          => $savingsPct,
    ];
}

/**
 * Select the single best price candidate for the main product.
 *
 * @param array[] $candidates  Enriched candidates with priceRole and association score
 * @param array   $context     From buildMainProductContext()
 * @param array|null $campaign From detectCampaign()
 * @return array|null  Winning candidate or null
 */
function selectPrimaryPrice(array $candidates, array $context, ?array $campaign): ?array
{
    // Filter to candidates with sufficient product association
    $viable = array_filter(
        $candidates,
        fn($c) => ($c['productAssociationScore'] ?? 0) >= 20
            && ($c['numericValue'] ?? 0) > 0
    );

    if (empty($viable)) {
        return null;
    }

    // Preferred roles: what the customer actually pays
    $preferredRoles = ['current', 'campaign'];

    // If campaign detected, the campaign price is what's paid
    if ($campaign !== null) {
        $campaignVal = $campaign['campaign_price'] ?? null;
        if ($campaignVal !== null) {
            // Find best candidate matching the campaign price
            foreach ($viable as $c) {
                $role = $c['priceRole'] ?? 'unknown';
                if (
                    in_array($role, ['campaign', 'current'], true)
                    && abs(($c['numericValue'] ?? 0) - $campaignVal) < 0.01
                ) {
                    return $c;
                }
            }
        }
    }

    // Sort: preferred role first, then association score desc, then confidence
    $confOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($viable, function ($a, $b) use ($preferredRoles, $confOrder) {
        $aPreferred = in_array($a['priceRole'] ?? '', $preferredRoles, true) ? 0 : 1;
        $bPreferred = in_array($b['priceRole'] ?? '', $preferredRoles, true) ? 0 : 1;
        if ($aPreferred !== $bPreferred) {
            return $aPreferred <=> $bPreferred;
        }

        $aScore = $a['productAssociationScore'] ?? 0;
        $bScore = $b['productAssociationScore'] ?? 0;
        if ($aScore !== $bScore) {
            return $bScore <=> $aScore;
        }

        return ($confOrder[$a['confidence'] ?? 'low'] ?? 3) <=> ($confOrder[$b['confidence'] ?? 'low'] ?? 3);
    });

    return $viable[0] ?? null;
}

// ─── Multi-strategy extraction pipeline ──────────────────

/**
 * Multi-strategy price extraction.
 *
 * Attempts extraction using multiple methods and returns a structured result
 * with the method used, confidence level, and debug info.
 *
 * Strategy priority:
 *   auto     → selector (if set) → JSON-LD → script-patterns → meta → microdata → DOM heuristic
 *   selector → CSS selector first, then fallbacks
 *
 * @param DOMDocument $doc            Parsed DOM
 * @param string      $rawHtml        Original HTML source
 * @param string      $url            Page URL (for context)
 * @param string      $strategy       'auto' | 'selector'
 * @param string|null $cssSelector    User-provided CSS selector
 * @return array Structured extraction result
 */
function extractPriceMultiStrategy(
    DOMDocument $doc,
    string $rawHtml,
    string $url,
    string $strategy = 'auto',
    ?string $cssSelector = null,
): array {
    $result = [
        'price'                 => null,
        'currency'              => null,
        'method'                => 'not_found',
        'confidence'            => 'low',
        'debug_source'          => null,
        'debug_path'            => null,
        'formatted_value'       => null,
        'warnings'              => [],
        'regular_price'         => null,
        'previous_lowest_price' => null,
        'is_campaign'           => false,
        'campaign_type'         => null,
        'campaign_label'        => null,
        'campaign_json'         => null,
        'product_context'       => null,
    ];

    $hasCssSelector = $cssSelector !== null && $cssSelector !== '';

    // ── Selector-only mode: keep early-return (no interpretation) ──
    if ($strategy === 'selector') {
        if ($hasCssSelector) {
            $extracted = extractFromCssSelector($doc, $cssSelector);
            if ($extracted['price'] !== null) {
                $result['price'] = $extracted['price'];
                $result['currency'] = $extracted['currency'];
                $result['method'] = 'selector';
                $result['confidence'] = 'high';
                $result['debug_source'] = 'css_selector';
                $result['debug_path'] = $cssSelector;
                return $result;
            }
            $result['warnings'][] = 'CSS selector matched no price';
        }
        $result['warnings'][] = 'No price found via any extraction method';
        return $result;
    }

    // ── Auto mode: collect ALL candidates, then interpret ─────────
    $allCandidates = [];
    $selectorFailed = false;

    // 1. CSS selector (if provided)
    if ($hasCssSelector) {
        $extracted = extractFromCssSelector($doc, $cssSelector);
        if ($extracted['price'] !== null) {
            $allCandidates[] = [
                'sourceType'     => 'css_selector',
                'patternType'    => 'css_selector',
                'label'          => "CSS selector: $cssSelector",
                'valueRaw'       => $extracted['price'],
                'valueFormatted' => (string) $extracted['price'],
                'numericValue'   => $extracted['price'],
                'currency'       => $extracted['currency'],
                'path'           => $cssSelector,
                'confidence'     => 'high',
                'reasons'        => ['Matched user-provided CSS selector'],
            ];
        } else {
            $selectorFailed = true;
            $result['warnings'][] = 'CSS selector matched no price';
        }
    }

    // 2. JSON-LD
    $jsonLdResult = extractFromJsonLd($doc);
    if ($jsonLdResult['price'] !== null) {
        $allCandidates[] = [
            'sourceType'     => 'jsonld',
            'patternType'    => 'jsonld',
            'label'          => 'JSON-LD Product/Offer',
            'valueRaw'       => $jsonLdResult['price'],
            'valueFormatted' => (string) $jsonLdResult['price'],
            'numericValue'   => $jsonLdResult['price'],
            'currency'       => $jsonLdResult['currency'],
            'path'           => 'jsonld.offers.price',
            'confidence'     => 'high',
            'reasons'        => ['JSON-LD structured data'],
        ];
    }

    // 3. Script patterns (returns multiple candidates)
    $scriptResult = extractFromScriptPatterns($doc, $rawHtml);
    if ($scriptResult['price'] !== null) {
        // The function returns the best, but we need ALL script candidates.
        // Re-discover them to get the full set.
        $scriptCandidates = collectScriptPatternCandidates($doc, $rawHtml);
        foreach ($scriptCandidates as $sc) {
            $allCandidates[] = $sc;
        }
    }

    // 4. Meta tags
    $metaResult = extractFromMetaTags($doc);
    if ($metaResult['price'] !== null) {
        $allCandidates[] = [
            'sourceType'     => 'meta',
            'patternType'    => 'meta_tags',
            'label'          => 'Meta tag (product:price:amount)',
            'valueRaw'       => $metaResult['price'],
            'valueFormatted' => (string) $metaResult['price'],
            'numericValue'   => $metaResult['price'],
            'currency'       => $metaResult['currency'],
            'path'           => 'meta[product:price:amount]',
            'confidence'     => 'high',
            'reasons'        => ['Standard product meta tag'],
        ];
    }

    // 5. Microdata
    $microResult = extractFromMicrodata($doc);
    if ($microResult['price'] !== null) {
        $allCandidates[] = [
            'sourceType'     => 'microdata',
            'patternType'    => 'microdata',
            'label'          => 'Microdata itemprop="price"',
            'valueRaw'       => $microResult['price'],
            'valueFormatted' => (string) $microResult['price'],
            'numericValue'   => $microResult['price'],
            'currency'       => $microResult['currency'],
            'path'           => '[itemprop="price"]',
            'confidence'     => 'high',
            'reasons'        => ['Schema.org microdata'],
        ];
    }

    // 6. DOM heuristic
    $domResult = extractPriceHeuristic($doc, $rawHtml);
    if ($domResult['price'] !== null) {
        $allCandidates[] = [
            'sourceType'     => 'dom',
            'patternType'    => 'dom_heuristic',
            'label'          => 'DOM heuristic',
            'valueRaw'       => $domResult['price'],
            'valueFormatted' => $domResult['formatted_value'] ?? (string) $domResult['price'],
            'numericValue'   => $domResult['price'],
            'currency'       => $domResult['currency'],
            'path'           => $domResult['debug_path'] ?? 'dom_heuristic',
            'confidence'     => $domResult['confidence'] ?? 'low',
            'reasons'        => ['DOM heuristic price detection'],
        ];
    }

    if (empty($allCandidates)) {
        // Nothing found — generate helpful warnings
        if ($selectorFailed) {
            $jsInfo = detectJsRendering($doc, $rawHtml);
            if ($jsInfo['likely']) {
                $result['warnings'][] = 'Page appears to use JavaScript rendering — selector may target runtime-only elements';
            }
        }
        $result['warnings'][] = 'No price found via any extraction method';
        return $result;
    }

    // ── Interpretation layer ─────────────────────────────
    $context = buildMainProductContext($doc, $rawHtml, $url);
    $result['product_context'] = $context;

    // Enrich each candidate with association score and price role
    foreach ($allCandidates as $i => $c) {
        $allCandidates[$i] = scoreProductAssociation($c, $context);
        $allCandidates[$i]['priceRole'] = classifyPriceRole($allCandidates[$i], $allCandidates);
    }

    // Detect campaign
    $campaign = detectCampaign($allCandidates, $context);

    // Select the primary price
    $winner = selectPrimaryPrice($allCandidates, $context, $campaign);

    if ($winner === null) {
        // Fallback: pick the first high-confidence candidate (legacy behavior)
        $confOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort(
            $allCandidates,
            fn($a, $b) => ($confOrder[$a['confidence'] ?? 'low'] ?? 3) <=> ($confOrder[$b['confidence'] ?? 'low'] ?? 3)
        );
        $winner = $allCandidates[0];
    }

    $result['price']        = $winner['numericValue'];
    $result['currency']     = $winner['currency'] ?? null;
    $result['method']       = $winner['sourceType'] ?? 'unknown';
    $result['confidence']   = $winner['confidence'] ?? 'low';
    $result['debug_source'] = $winner['sourceType'] ?? null;
    $result['debug_path']   = $winner['path'] ?? null;
    $result['formatted_value'] = $winner['valueFormatted'] ?? null;

    // Campaign fields
    if ($campaign !== null) {
        $result['regular_price']         = $campaign['regular_price'];
        $result['previous_lowest_price'] = $campaign['previous_lowest_price'];
        $result['is_campaign']           = true;
        $result['campaign_type']         = $campaign['type'];
        $result['campaign_label']        = $campaign['label'];
        $result['campaign_json']         = json_encode($campaign, JSON_UNESCAPED_UNICODE);
    }

    return $result;
}

/**
 * Collect all script-pattern candidates (used by the interpretation pipeline).
 *
 * This is the candidate-collection half of extractFromScriptPatterns(),
 * returning the full array instead of picking the best one.
 *
 * @return array[] Price candidate arrays
 */
function collectScriptPatternCandidates(DOMDocument $doc, string $rawHtml): array
{
    $candidates = [];
    $scripts = $doc->getElementsByTagName('script');

    for ($i = 0; $i < $scripts->length; $i++) {
        $script = $scripts->item($i);
        $type = $script->getAttribute('type');
        if ($type === 'application/ld+json' || ($type !== '' && $type !== 'text/javascript')) {
            continue;
        }
        $content = trim($script->textContent);
        if ($content === '' || strlen($content) < 20) {
            continue;
        }

        foreach (extractFromWebComponentsPush($content) as $c) {
            $candidates[] = $c;
        }
        if (str_contains($content, '__NEXT_DATA__')) {
            foreach (extractFromNextData($content) as $c) {
                $candidates[] = $c;
            }
        }
        if (str_contains($content, '__NUXT__') || str_contains($content, '__NUXT_DATA__')) {
            foreach (extractFromNuxtData($content) as $c) {
                $candidates[] = $c;
            }
        }
        if (str_contains($content, '__INITIAL_STATE__') || str_contains($content, '__APP_DATA__')) {
            foreach (extractFromInitialState($content) as $c) {
                $candidates[] = $c;
            }
        }
        foreach (extractGenericScriptPrices($content) as $c) {
            $candidates[] = $c;
        }
    }

    // Also check __NEXT_DATA__ tag
    $xpath = new DOMXPath($doc);
    $nextDataScript = $xpath->query('//script[@id="__NEXT_DATA__"]')->item(0);
    if ($nextDataScript !== null) {
        $json = json_decode(trim($nextDataScript->textContent), true);
        if (is_array($json)) {
            foreach (findPriceFieldsInData($json, '__NEXT_DATA__', 'next_data') as $c) {
                $candidates[] = $c;
            }
        }
    }

    return $candidates;
}

// ─── Script-pattern extraction ───────────────────────────

/**
 * Extract price from common embedded script/config patterns.
 *
 * Supports:
 *   - window.WebComponents.push([...])
 *   - window.__NEXT_DATA__
 *   - window.__NUXT__  /  window.__NUXT_DATA__
 *   - window.__INITIAL_STATE__  /  window.__APP_DATA__
 *   - Generic JSON-like product/price blobs in script tags
 *
 * @return array{price: float|null, currency: string|null, method: string|null, error: string|null,
 *               confidence: string|null, debug_source: string|null, debug_path: string|null, formatted_value: string|null}
 */
function extractFromScriptPatterns(DOMDocument $doc, string $rawHtml): array
{
    $result = [
        'price'           => null,
        'currency'        => null,
        'method'          => null,
        'error'           => null,
        'confidence'      => null,
        'debug_source'    => null,
        'debug_path'      => null,
        'formatted_value' => null,
    ];

    $scripts = $doc->getElementsByTagName('script');
    $candidates = [];

    for ($i = 0; $i < $scripts->length; $i++) {
        $script = $scripts->item($i);
        $type = $script->getAttribute('type');
        // Skip JSON-LD (already handled) and non-JS scripts
        if ($type === 'application/ld+json' || ($type !== '' && $type !== 'text/javascript')) {
            continue;
        }
        $content = trim($script->textContent);
        if ($content === '' || strlen($content) < 20) {
            continue;
        }

        // 1. window.WebComponents.push(...)
        $webCompCandidates = extractFromWebComponentsPush($content);
        foreach ($webCompCandidates as $c) {
            $candidates[] = $c;
        }

        // 2. window.__NEXT_DATA__
        if (str_contains($content, '__NEXT_DATA__')) {
            $nextCandidates = extractFromNextData($content);
            foreach ($nextCandidates as $c) {
                $candidates[] = $c;
            }
        }

        // 3. window.__NUXT__ / __NUXT_DATA__
        if (str_contains($content, '__NUXT__') || str_contains($content, '__NUXT_DATA__')) {
            $nuxtCandidates = extractFromNuxtData($content);
            foreach ($nuxtCandidates as $c) {
                $candidates[] = $c;
            }
        }

        // 4. window.__INITIAL_STATE__ / __APP_DATA__
        if (str_contains($content, '__INITIAL_STATE__') || str_contains($content, '__APP_DATA__')) {
            $stateCandidates = extractFromInitialState($content);
            foreach ($stateCandidates as $c) {
                $candidates[] = $c;
            }
        }

        // 5. Generic product/price patterns in inline scripts
        $genericCandidates = extractGenericScriptPrices($content);
        foreach ($genericCandidates as $c) {
            $candidates[] = $c;
        }
    }

    // Also check __NEXT_DATA__ in a separate script tag (common pattern)
    $xpath = new DOMXPath($doc);
    $nextDataScript = $xpath->query('//script[@id="__NEXT_DATA__"]')->item(0);
    if ($nextDataScript !== null) {
        $json = json_decode(trim($nextDataScript->textContent), true);
        if (is_array($json)) {
            $nextCandidates = findPriceFieldsInData($json, '__NEXT_DATA__', 'next_data');
            foreach ($nextCandidates as $c) {
                $candidates[] = $c;
            }
        }
    }

    if (empty($candidates)) {
        return $result;
    }

    // Sort by confidence: high > medium > low
    $confidenceOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($candidates, function ($a, $b) use ($confidenceOrder) {
        $aOrder = $confidenceOrder[$a['confidence']] ?? 3;
        $bOrder = $confidenceOrder[$b['confidence']] ?? 3;
        return $aOrder <=> $bOrder;
    });

    $best = $candidates[0];
    $result['price'] = $best['numericValue'];
    $result['currency'] = $best['currency'] ?? null;
    $result['method'] = 'script_pattern';
    $result['confidence'] = $best['confidence'];
    $result['debug_source'] = $best['sourceType'] . ':' . ($best['patternType'] ?? 'generic');
    $result['debug_path'] = $best['path'] ?? null;
    $result['formatted_value'] = $best['valueFormatted'] ?? null;

    return $result;
}

/**
 * Extract prices from window.WebComponents.push([...]) patterns.
 *
 * Looks for component payloads such as ProductSchema with price arrays.
 *
 * @return array[] List of PriceCandidate-like arrays
 */
function extractFromWebComponentsPush(string $scriptContent): array
{
    $candidates = [];

    // Match window.WebComponents.push(...) calls
    // Pattern: window.WebComponents.push([...])
    if (!preg_match_all('/window\.WebComponents\s*\.\s*push\s*\(\s*(\[.+?\])\s*\)/s', $scriptContent, $matches)) {
        return $candidates;
    }

    foreach ($matches[1] as $arrayStr) {
        $data = json_decode($arrayStr, true);
        if (!is_array($data)) {
            // Try extracting inner JSON objects if the array parse failed
            continue;
        }

        // WebComponents.push format: [id, 'ComponentName', { ...data }]
        // Walk the array to find component payloads
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Look for [id, name, payload] triplets
            $componentName = null;
            $payload = null;
            for ($j = 0; $j < count($item); $j++) {
                if (is_string($item[$j]) && $j > 0) {
                    $componentName = $item[$j];
                }
                if (is_array($item[$j]) && $componentName !== null) {
                    $payload = $item[$j];
                    break;
                }
            }

            if ($payload === null) {
                // Maybe the array itself IS [id, name, data]
                if (count($item) >= 3 && is_string($item[1] ?? null) && is_array($item[2] ?? null)) {
                    $componentName = $item[1];
                    $payload = $item[2];
                }
            }

            if ($componentName === null || $payload === null) {
                continue;
            }

            // ProductSchema or similar product components
            $isProductComponent = preg_match('/product|price|offer/i', $componentName);
            $isRecommendation   = preg_match('/\b(recommend|related|similar|accessor|upsell|cross.?sell)\b/i', $componentName);

            if ($isProductComponent || $isRecommendation) {
                $found = extractPricesFromWebComponentPayload($payload, $componentName);
                if ($isRecommendation) {
                    // Mark recommendation candidates with low confidence
                    foreach ($found as &$c) {
                        $c['confidence'] = 'low';
                        $c['reasons'][]  = 'From recommendation component: ' . $componentName;
                    }
                    unset($c);
                }
                foreach ($found as $c) {
                    $candidates[] = $c;
                }
            }
        }
    }

    return $candidates;
}

/**
 * Extract price candidates from a WebComponent payload object.
 *
 * Handles patterns like { initialPrices: [{ adjustedPrice, price, ... }], currencyCode: '...' }
 */
function extractPricesFromWebComponentPayload(array $payload, string $componentName): array
{
    $candidates = [];
    $currency = $payload['currencyCode'] ?? $payload['currency'] ?? null;

    // Look for initialPrices array
    $pricePaths = [
        'initialPrices' => ['adjustedPrice', 'price', 'salePrice', 'currentPrice', 'regularPrice', 'lowestPrice', 'previousLowestPrice', 'comparisonPrice', 'omnibusPrice'],
        'prices'        => ['adjustedPrice', 'price', 'salePrice', 'currentPrice', 'regularPrice', 'lowestPrice', 'previousLowestPrice', 'comparisonPrice', 'omnibusPrice'],
        'priceData'     => ['price', 'salePrice', 'currentPrice', 'lowestPrice', 'comparisonPrice'],
    ];

    foreach ($pricePaths as $arrayKey => $priceFields) {
        if (!isset($payload[$arrayKey]) || !is_array($payload[$arrayKey])) {
            continue;
        }

        $priceArray = $payload[$arrayKey];
        // Handle both direct array and nested
        $items = isset($priceArray[0]) ? $priceArray : [$priceArray];

        foreach ($items as $idx => $priceItem) {
            if (!is_array($priceItem)) {
                continue;
            }

            // Extract currency from price item if not at top level
            $itemCurrency = $priceItem['currencyCode']
                ?? $priceItem['currency']
                ?? $currency;

            foreach ($priceFields as $field) {
                if (!isset($priceItem[$field])) {
                    continue;
                }

                $rawVal = $priceItem[$field];
                $numericVal = null;
                $formatted = null;

                if (is_numeric($rawVal)) {
                    $numericVal = (float) $rawVal;
                } elseif (is_string($rawVal)) {
                    $numericVal = parsePrice($rawVal);
                    $formatted = $rawVal;
                }

                if ($numericVal !== null && $numericVal > 0) {
                    $path = "$componentName.$arrayKey[$idx].$field";

                    // adjustedPrice/salePrice is highest confidence
                    $isPreferred = in_array($field, ['adjustedPrice', 'salePrice', 'currentPrice'], true);

                    $candidates[] = [
                        'sourceType'     => 'script_pattern',
                        'patternType'    => 'WebComponents',
                        'label'          => "$componentName → $arrayKey → $field",
                        'valueRaw'       => $rawVal,
                        'valueFormatted' => $formatted ?? (string) $numericVal,
                        'numericValue'   => $numericVal,
                        'currency'       => is_string($itemCurrency) ? strtoupper($itemCurrency) : null,
                        'path'           => $path,
                        'confidence'     => $isPreferred ? 'high' : 'medium',
                        'reasons'        => array_filter([
                            "Found numeric price in $path",
                            $itemCurrency ? 'Currency found in ' . ($priceItem['currencyCode'] ? 'currencyCode' : 'currency') : null,
                            'Price from embedded product state',
                        ]),
                    ];
                }
            }

            // Also check for a top-level formatted price string
            foreach (['formattedPrice', 'priceFormatted', 'displayPrice'] as $fmtField) {
                if (isset($priceItem[$fmtField]) && is_string($priceItem[$fmtField])) {
                    $parsed = parsePrice($priceItem[$fmtField]);
                    if ($parsed !== null && $parsed > 0) {
                        // Only add if we haven't already found this exact numeric value
                        $alreadyFound = false;
                        foreach ($candidates as $c) {
                            if (abs($c['numericValue'] - $parsed) < 0.01) {
                                $alreadyFound = true;
                                break;
                            }
                        }
                        if (!$alreadyFound) {
                            $candidates[] = [
                                'sourceType'     => 'script_pattern',
                                'patternType'    => 'WebComponents',
                                'label'          => "$componentName → $arrayKey → $fmtField",
                                'valueRaw'       => $priceItem[$fmtField],
                                'valueFormatted' => $priceItem[$fmtField],
                                'numericValue'   => $parsed,
                                'currency'       => is_string($itemCurrency) ? strtoupper($itemCurrency) : null,
                                'path'           => "$componentName.$arrayKey[$idx].$fmtField",
                                'confidence'     => 'medium',
                                'reasons'        => ["Formatted price string in $fmtField"],
                            ];
                        }
                    }
                }
            }
        }
    }

    // Fallback: look for direct price fields on the payload root
    foreach (['price', 'currentPrice', 'salePrice', 'adjustedPrice'] as $directField) {
        if (isset($payload[$directField])) {
            $rawVal = $payload[$directField];
            $numericVal = is_numeric($rawVal) ? (float) $rawVal : (is_string($rawVal) ? parsePrice($rawVal) : null);
            if ($numericVal !== null && $numericVal > 0) {
                $candidates[] = [
                    'sourceType'     => 'script_pattern',
                    'patternType'    => 'WebComponents',
                    'label'          => "$componentName → $directField",
                    'valueRaw'       => $rawVal,
                    'valueFormatted' => is_string($rawVal) ? $rawVal : (string) $numericVal,
                    'numericValue'   => $numericVal,
                    'currency'       => is_string($currency) ? strtoupper($currency) : null,
                    'path'           => "$componentName.$directField",
                    'confidence'     => 'medium',
                    'reasons'        => ["Direct price field on $componentName"],
                ];
            }
        }
    }

    return $candidates;
}

/**
 * Extract prices from __NEXT_DATA__ script content.
 *
 * @return array[] Price candidate arrays
 */
function extractFromNextData(string $scriptContent): array
{
    // Try to extract the JSON object
    if (!preg_match('/__NEXT_DATA__\s*=\s*({.+?})\s*(?:;|<\/script>)/s', $scriptContent, $m)) {
        return [];
    }

    $json = json_decode($m[1], true);
    if (!is_array($json)) {
        return [];
    }

    // __NEXT_DATA__.props.pageProps is where product data usually lives
    $pageProps = $json['props']['pageProps'] ?? $json;
    return findPriceFieldsInData($pageProps, '__NEXT_DATA__', 'next_data');
}

/**
 * Extract prices from __NUXT__ / __NUXT_DATA__ script content.
 *
 * @return array[] Price candidate arrays
 */
function extractFromNuxtData(string $scriptContent): array
{
    $candidates = [];

    // __NUXT__ = { ... }
    if (preg_match('/__NUXT__\s*=\s*({.+?})\s*(?:;|<\/script>)/s', $scriptContent, $m)) {
        $json = json_decode($m[1], true);
        if (is_array($json)) {
            $candidates = findPriceFieldsInData($json, '__NUXT__', 'nuxt');
        }
    }

    // __NUXT_DATA__ = [...]
    if (preg_match('/__NUXT_DATA__\s*=\s*(\[.+?\])\s*(?:;|<\/script>)/s', $scriptContent, $m)) {
        $json = json_decode($m[1], true);
        if (is_array($json)) {
            $candidates = array_merge($candidates, findPriceFieldsInData($json, '__NUXT_DATA__', 'nuxt'));
        }
    }

    return $candidates;
}

/**
 * Extract prices from __INITIAL_STATE__ / __APP_DATA__ script content.
 *
 * @return array[] Price candidate arrays
 */
function extractFromInitialState(string $scriptContent): array
{
    $candidates = [];
    $markers = ['__INITIAL_STATE__', '__APP_DATA__'];

    foreach ($markers as $marker) {
        if (!str_contains($scriptContent, $marker)) {
            continue;
        }
        $pattern = '/' . preg_quote($marker, '/') . '\s*=\s*({.+?})\s*(?:;|<\/script>)/s';
        if (preg_match($pattern, $scriptContent, $m)) {
            $json = json_decode($m[1], true);
            if (is_array($json)) {
                $found = findPriceFieldsInData($json, $marker, 'initial_state');
                $candidates = array_merge($candidates, $found);
            }
        }
    }

    return $candidates;
}

/**
 * Extract price-like values from generic inline script content.
 *
 * Looks for patterns like: "price":129, "price":"129 kr", var price = 129;
 *
 * @return array[] Price candidate arrays
 */
function extractGenericScriptPrices(string $scriptContent): array
{
    $candidates = [];

    // JSON-like property patterns: "price": 129 or "price": "129"
    $priceKeys = 'price|currentPrice|salePrice|productPrice|itemPrice|unitPrice|finalPrice|adjustedPrice';
    $pattern = '/["\'](' . $priceKeys . ')["\']\s*:\s*(["\']?\d[\d\s,.]*(?:\s*(?:kr|SEK|USD|EUR|€|\$|£))?["\']?)/i';

    if (preg_match_all($pattern, $scriptContent, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = $match[1];
            $raw = trim($match[2], '"\'');
            $numericVal = parsePrice($raw);
            if ($numericVal !== null && $numericVal > 0 && $numericVal < 1000000) {
                // Detect currency from the value string
                $currency = detectCurrencyFromString($raw);

                $candidates[] = [
                    'sourceType'     => 'script_pattern',
                    'patternType'    => 'generic_json',
                    'label'          => "Script inline: $key",
                    'valueRaw'       => $raw,
                    'valueFormatted' => $raw,
                    'numericValue'   => $numericVal,
                    'currency'       => $currency,
                    'path'           => "inline_script.$key",
                    'confidence'     => 'low',
                    'reasons'        => ["Found $key in inline script data"],
                ];
            }
        }
    }

    return $candidates;
}

/**
 * Recursively search a data structure for price-like fields.
 *
 * Used by Next.js, Nuxt, and Initial State extractors to find product prices
 * inside arbitrarily nested JSON data.
 *
 * @param array  $data       The data to search
 * @param string $rootLabel  Root label for the path (e.g. '__NEXT_DATA__')
 * @param string $patternType Pattern type label
 * @param string $currentPath Current path prefix
 * @param int    $depth      Current recursion depth
 * @return array[] Price candidate arrays
 */
function findPriceFieldsInData(array $data, string $rootLabel, string $patternType, string $currentPath = '', int $depth = 0): array
{
    if ($depth > 8) {
        return []; // Prevent runaway recursion
    }

    $candidates = [];
    $priceFieldNames = [
        'price',
        'currentPrice',
        'salePrice',
        'adjustedPrice',
        'finalPrice',
        'productPrice',
        'itemPrice',
        'unitPrice',
        'lowPrice',
        'highPrice',
        'regularPrice',
        'specialPrice',
        'discountedPrice',
        // Omnibus / 30-day lowest price (EU directive, common on Swedish sites)
        'lowestPrice',
        'previousLowestPrice',
        'comparisonPrice',
        'omnibusPrice',
    ];
    $currencyFieldNames = ['currency', 'currencyCode', 'priceCurrency'];

    // Try to find currency at this level
    $localCurrency = null;
    foreach ($currencyFieldNames as $cf) {
        if (isset($data[$cf]) && is_string($data[$cf]) && strlen($data[$cf]) <= 5) {
            $localCurrency = strtoupper($data[$cf]);
            break;
        }
    }

    foreach ($data as $key => $value) {
        $path = $currentPath ? "$currentPath.$key" : $key;

        // Check if this key is a price field
        if (is_string($key)) {
            $keyLower = strtolower($key);
            $isPriceField = false;
            foreach ($priceFieldNames as $pf) {
                if (strcasecmp($key, $pf) === 0) {
                    $isPriceField = true;
                    break;
                }
            }

            if ($isPriceField) {
                $numericVal = null;
                $formatted = null;

                if (is_numeric($value)) {
                    $numericVal = (float) $value;
                } elseif (is_string($value)) {
                    $numericVal = parsePrice($value);
                    $formatted = $value;
                }

                if ($numericVal !== null && $numericVal > 0 && $numericVal < 1000000) {
                    $currency = $localCurrency ?: detectCurrencyFromString($formatted ?? '');

                    // Confidence based on context
                    $isProductContext = false;
                    // Check if we're inside something that looks like a product
                    if (preg_match('/product|offer|item|sku/i', $currentPath)) {
                        $isProductContext = true;
                    }

                    $candidates[] = [
                        'sourceType'     => 'script_pattern',
                        'patternType'    => $patternType,
                        'label'          => "$rootLabel → $path",
                        'valueRaw'       => $value,
                        'valueFormatted' => $formatted ?? (string) $numericVal,
                        'numericValue'   => $numericVal,
                        'currency'       => $currency,
                        'path'           => "$rootLabel.$path",
                        'confidence'     => $isProductContext ? 'high' : 'medium',
                        'reasons'        => array_filter([
                            "Found price field \"$key\" in $rootLabel data",
                            $isProductContext ? 'Inside product context' : null,
                            $currency ? "Currency: $currency" : null,
                        ]),
                    ];
                }
            }
        }

        // Recurse into arrays/objects
        if (is_array($value)) {
            $subCandidates = findPriceFieldsInData($value, $rootLabel, $patternType, (string) $path, $depth + 1);
            foreach ($subCandidates as $c) {
                $candidates[] = $c;
            }
        }
    }

    return $candidates;
}

/**
 * Detect currency from a price string.
 *
 * @return string|null ISO currency code or null
 */
function detectCurrencyFromString(string $value): ?string
{
    $value = strtolower(trim($value));
    if (str_contains($value, 'sek') || str_contains($value, 'kr')) {
        return 'SEK';
    }
    if (str_contains($value, 'usd') || str_contains($value, '$')) {
        return 'USD';
    }
    if (str_contains($value, 'eur') || str_contains($value, '€')) {
        return 'EUR';
    }
    if (str_contains($value, 'gbp') || str_contains($value, '£')) {
        return 'GBP';
    }
    if (str_contains($value, 'nok')) {
        return 'NOK';
    }
    if (str_contains($value, 'dkk')) {
        return 'DKK';
    }
    return null;
}

// ─── DOM heuristic price extraction ─────────────────────

/**
 * Heuristic fallback: scan source DOM for price-like values.
 *
 * Ranks candidates by contextual signals (nearby price keywords, proximity
 * to main content, absence of negative signals like strike-through).
 *
 * @return array{price: float|null, currency: string|null, method: string|null, error: string|null,
 *               confidence: string|null, debug_path: string|null, formatted_value: string|null}
 */
function extractPriceHeuristic(DOMDocument $doc, string $rawHtml): array
{
    $result = [
        'price'           => null,
        'currency'        => null,
        'method'          => null,
        'error'           => null,
        'confidence'      => null,
        'debug_path'      => null,
        'formatted_value' => null,
    ];

    $xpath = new DOMXPath($doc);
    $candidates = [];

    // Strategy: look for elements with price-related attributes or nearby keywords
    // that contain parseable price values

    // 1. Elements with price-related classes or attributes
    $priceQueries = [
        '//*[contains(@class,"price") and not(contains(@class,"old-price")) and not(contains(@class,"was-price")) and not(contains(@class,"original-price"))]',
        '//*[contains(@class,"pris") and not(contains(@class,"ordinarie"))]',
        '//*[contains(@class,"amount") and not(contains(@class,"shipping"))]',
        '//*[contains(@class,"cost") and not(contains(@class,"shipping"))]',
        '//*[contains(@data-price,"")]',
    ];

    foreach ($priceQueries as $q) {
        $nodes = @$xpath->query($q);
        if ($nodes === false) {
            continue;
        }

        for ($i = 0; $i < min($nodes->length, 10); $i++) {
            $node = $nodes->item($i);
            if (!($node instanceof DOMElement)) {
                continue;
            }

            // Skip struck-through or old-price elements
            $tag = strtolower($node->tagName);
            if ($tag === 'del' || $tag === 's' || $tag === 'strike') {
                continue;
            }
            $classAttr = strtolower($node->getAttribute('class'));
            if (preg_match('/\b(old|was|original|before|ordinarie|previous|regular|strike|compare)\b/', $classAttr)) {
                continue;
            }

            // Try data-price attribute first
            $dataPrice = $node->getAttribute('data-price') ?: $node->getAttribute('data-amount');
            if ($dataPrice !== '' && $dataPrice !== null) {
                $parsed = parsePrice($dataPrice);
                if ($parsed !== null && $parsed > 0) {
                    $candidates[] = [
                        'price'      => $parsed,
                        'formatted'  => $dataPrice,
                        'score'      => 80,
                        'source'     => "data-attribute on <$tag>",
                        'currency'   => detectCurrencyFromString($dataPrice),
                    ];
                    continue;
                }
            }

            // Try text content
            $text = trim($node->textContent);
            if ($text !== '' && strlen($text) < 50) {
                $parsed = parsePrice($text);
                if ($parsed !== null && $parsed > 0 && $parsed < 1000000) {
                    $score = 50;

                    // Boost for certain class patterns
                    if (preg_match('/\b(current|sale|final|active|now|special|kampanj)\b/', $classAttr)) {
                        $score += 20;
                    }
                    if (preg_match('/\bprice\b/', $classAttr) || preg_match('/\bpris\b/', $classAttr)) {
                        $score += 10;
                    }

                    // Penalty for elements that look like they're in a list/carousel
                    $parentClass = $node->parentNode instanceof DOMElement ? strtolower($node->parentNode->getAttribute('class')) : '';
                    if (preg_match('/\b(list|carousel|slider|recommend|related|similar)\b/', $parentClass)) {
                        $score -= 30;
                    }

                    // Penalty for shipping/delivery context
                    if (preg_match('/\b(shipping|frakt|delivery|leverans)\b/', $classAttr)) {
                        $score -= 40;
                    }

                    $currency = detectCurrencyFromString($text);

                    $candidates[] = [
                        'price'      => $parsed,
                        'formatted'  => $text,
                        'score'      => $score,
                        'source'     => "text content of <$tag class=\"" . mb_substr($classAttr, 0, 60) . "\">",
                        'currency'   => $currency,
                    ];
                }
            }
        }
    }

    if (empty($candidates)) {
        return $result;
    }

    // Sort by score descending
    usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
    $best = $candidates[0];

    $result['price'] = $best['price'];
    $result['currency'] = $best['currency'];
    $result['method'] = 'dom_heuristic';
    $result['confidence'] = $best['score'] >= 70 ? 'medium' : 'low';
    $result['debug_path'] = $best['source'];
    $result['formatted_value'] = $best['formatted'];

    return $result;
}

// ─── Price candidate discovery (debug) ──────────────────

/**
 * Discover all possible price candidates from all sources.
 *
 * Used by the debug inspector to show the user where prices exist
 * in different parts of the page source.
 *
 * @return array[] Array of PriceCandidate-like arrays, grouped by source type
 */
function discoverPriceCandidates(DOMDocument $doc, string $rawHtml, ?string $cssSelector = null, string $url = ''): array
{
    $allCandidates = [];

    // 1. CSS Selector candidates
    if ($cssSelector !== null && $cssSelector !== '') {
        $selectorResult = extractFromCssSelector($doc, $cssSelector);
        if ($selectorResult['price'] !== null) {
            $allCandidates[] = [
                'sourceType'     => 'css_selector',
                'patternType'    => 'css_selector',
                'label'          => "CSS selector: $cssSelector",
                'valueRaw'       => $selectorResult['price'],
                'valueFormatted' => (string) $selectorResult['price'],
                'numericValue'   => $selectorResult['price'],
                'currency'       => $selectorResult['currency'],
                'path'           => $cssSelector,
                'confidence'     => 'high',
                'reasons'        => ['Matched user-provided CSS selector'],
            ];
        }
    }

    // 2. JSON-LD candidates
    $jsonLdCandidates = discoverJsonLdCandidates($doc);
    foreach ($jsonLdCandidates as $c) {
        $allCandidates[] = $c;
    }

    // 3. Script pattern candidates
    $scriptCandidates = [];
    $scripts = $doc->getElementsByTagName('script');
    for ($i = 0; $i < $scripts->length; $i++) {
        $script = $scripts->item($i);
        $type = $script->getAttribute('type');
        if ($type === 'application/ld+json' || ($type !== '' && $type !== 'text/javascript')) {
            continue;
        }
        $content = trim($script->textContent);
        if ($content === '' || strlen($content) < 20) {
            continue;
        }

        foreach (extractFromWebComponentsPush($content) as $c) {
            $scriptCandidates[] = $c;
        }
        if (str_contains($content, '__NEXT_DATA__')) {
            foreach (extractFromNextData($content) as $c) {
                $scriptCandidates[] = $c;
            }
        }
        if (str_contains($content, '__NUXT__') || str_contains($content, '__NUXT_DATA__')) {
            foreach (extractFromNuxtData($content) as $c) {
                $scriptCandidates[] = $c;
            }
        }
        if (str_contains($content, '__INITIAL_STATE__') || str_contains($content, '__APP_DATA__')) {
            foreach (extractFromInitialState($content) as $c) {
                $scriptCandidates[] = $c;
            }
        }
        foreach (extractGenericScriptPrices($content) as $c) {
            $scriptCandidates[] = $c;
        }
    }

    // Also check __NEXT_DATA__ tag
    $xpath = new DOMXPath($doc);
    $nextDataScript = $xpath->query('//script[@id="__NEXT_DATA__"]')->item(0);
    if ($nextDataScript !== null) {
        $json = json_decode(trim($nextDataScript->textContent), true);
        if (is_array($json)) {
            $pageProps = $json['props']['pageProps'] ?? $json;
            foreach (findPriceFieldsInData($pageProps, '__NEXT_DATA__', 'next_data') as $c) {
                $scriptCandidates[] = $c;
            }
        }
    }

    foreach ($scriptCandidates as $c) {
        $allCandidates[] = $c;
    }

    // 4. Meta tag candidates
    $metaResult = extractFromMetaTags($doc);
    if ($metaResult['price'] !== null) {
        $allCandidates[] = [
            'sourceType'     => 'meta',
            'patternType'    => 'meta_tags',
            'label'          => 'Meta tag price (product:price:amount / og:price)',
            'valueRaw'       => $metaResult['price'],
            'valueFormatted' => (string) $metaResult['price'],
            'numericValue'   => $metaResult['price'],
            'currency'       => $metaResult['currency'],
            'path'           => 'meta[product:price:amount]',
            'confidence'     => 'high',
            'reasons'        => ['Standard product meta tag'],
        ];
    }

    // 5. Microdata candidates
    $microResult = extractFromMicrodata($doc);
    if ($microResult['price'] !== null) {
        $allCandidates[] = [
            'sourceType'     => 'microdata',
            'patternType'    => 'microdata',
            'label'          => 'Microdata itemprop="price"',
            'valueRaw'       => $microResult['price'],
            'valueFormatted' => (string) $microResult['price'],
            'numericValue'   => $microResult['price'],
            'currency'       => $microResult['currency'],
            'path'           => '[itemprop="price"]',
            'confidence'     => 'high',
            'reasons'        => ['Schema.org microdata'],
        ];
    }

    // 6. DOM heuristic candidates
    $domCandidates = discoverDomPriceCandidates($doc);
    foreach ($domCandidates as $c) {
        $allCandidates[] = $c;
    }

    // Deduplicate by numeric value (keep highest confidence)
    $deduped = [];
    $seenValues = [];
    foreach ($allCandidates as $c) {
        $key = round($c['numericValue'] * 100);
        if (!isset($seenValues[$key]) || confidenceRank($c['confidence']) > confidenceRank($seenValues[$key]['confidence'])) {
            $seenValues[$key] = $c;
        }
    }
    $deduped = array_values($seenValues);

    // Sort: high confidence first, then by numeric value
    usort($deduped, function ($a, $b) {
        $confDiff = confidenceRank($b['confidence']) - confidenceRank($a['confidence']);
        if ($confDiff !== 0) {
            return $confDiff;
        }
        return $a['numericValue'] <=> $b['numericValue'];
    });

    // ── Enrich with interpretation data ──────────────────
    if ($url !== '') {
        $context = buildMainProductContext($doc, $rawHtml, $url);
        foreach ($deduped as $i => $c) {
            $deduped[$i] = scoreProductAssociation($c, $context);
            $deduped[$i]['priceRole'] = classifyPriceRole($deduped[$i], $deduped);
        }
    }

    return $deduped;
}

/**
 * Discover all JSON-LD price candidates (not just the first match).
 *
 * @return array[] Price candidate arrays
 */
function discoverJsonLdCandidates(DOMDocument $doc): array
{
    $candidates = [];
    $xpath = new DOMXPath($doc);
    $scripts = $xpath->query('//script[@type="application/ld+json"]');

    if ($scripts === false) {
        return $candidates;
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
            $found = extractAllJsonLdPrices($item, 'json-ld');
            foreach ($found as $c) {
                $candidates[] = $c;
            }
        }
    }

    return $candidates;
}

/**
 * Recursively extract ALL price values from a JSON-LD item (not just first).
 */
function extractAllJsonLdPrices(mixed $item, string $pathPrefix = ''): array
{
    $candidates = [];
    if (!is_array($item)) {
        return $candidates;
    }

    $type = $item['@type'] ?? '';
    if (is_array($type)) {
        $type = implode(',', $type);
    }

    $isOffer = str_contains($type, 'Offer') || str_contains($type, 'AggregateOffer');
    $isProduct = str_contains($type, 'Product');

    if ($isOffer) {
        foreach (['price', 'lowPrice', 'highPrice'] as $field) {
            if (isset($item[$field])) {
                $parsed = parsePrice((string) $item[$field]);
                if ($parsed !== null && $parsed > 0) {
                    $candidates[] = [
                        'sourceType'     => 'jsonld',
                        'patternType'    => null,
                        'label'          => "JSON-LD $type → $field",
                        'valueRaw'       => $item[$field],
                        'valueFormatted' => (string) $item[$field],
                        'numericValue'   => $parsed,
                        'currency'       => $item['priceCurrency'] ?? null,
                        'path'           => "$pathPrefix.$type.$field",
                        'confidence'     => 'high',
                        'reasons'        => ["Schema.org $type with $field", $item['priceCurrency'] ? "Currency: {$item['priceCurrency']}" : null],
                    ];
                }
            }
        }
    }

    if ($isProduct) {
        $offers = $item['offers'] ?? null;
        if (is_array($offers)) {
            $offerList = isset($offers['@type']) ? [$offers] : (isset($offers[0]) ? $offers : [$offers]);
            foreach ($offerList as $offer) {
                if (is_array($offer)) {
                    $subCandidates = extractAllJsonLdPrices($offer, "$pathPrefix.Product.offers");
                    foreach ($subCandidates as $c) {
                        $candidates[] = $c;
                    }
                }
            }
        }
    }

    return $candidates;
}

/**
 * Discover DOM-based price candidates using heuristics.
 *
 * @return array[] Price candidate arrays
 */
function discoverDomPriceCandidates(DOMDocument $doc): array
{
    $candidates = [];
    $xpath = new DOMXPath($doc);

    $priceQueries = [
        '//*[contains(@class,"price") and not(contains(@class,"old-price")) and not(contains(@class,"was-price")) and not(contains(@class,"original-price"))]',
        '//*[contains(@class,"pris") and not(contains(@class,"ordinarie"))]',
    ];

    foreach ($priceQueries as $q) {
        $nodes = @$xpath->query($q);
        if ($nodes === false) {
            continue;
        }

        for ($i = 0; $i < min($nodes->length, 5); $i++) {
            $node = $nodes->item($i);
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['del', 's', 'strike'], true)) {
                continue;
            }
            $classAttr = strtolower($node->getAttribute('class'));
            if (preg_match('/\b(old|was|original|before|ordinarie|previous|strike|compare)\b/', $classAttr)) {
                continue;
            }

            $text = trim($node->textContent);
            if ($text !== '' && strlen($text) < 50) {
                $parsed = parsePrice($text);
                if ($parsed !== null && $parsed > 0 && $parsed < 1000000) {
                    $candidates[] = [
                        'sourceType'     => 'dom',
                        'patternType'    => 'heuristic',
                        'label'          => "DOM: <$tag class=\"" . mb_substr($classAttr, 0, 40) . "\">",
                        'valueRaw'       => $text,
                        'valueFormatted' => $text,
                        'numericValue'   => $parsed,
                        'currency'       => detectCurrencyFromString($text),
                        'path'           => "<$tag class=\"" . mb_substr($classAttr, 0, 40) . "\">",
                        'confidence'     => 'low',
                        'reasons'        => ['Price-like text in DOM element with price-related class'],
                    ];
                }
            }
        }
    }

    return $candidates;
}

/**
 * Numeric rank for confidence levels (higher = more confident).
 */
function confidenceRank(string $confidence): int
{
    return match ($confidence) {
        'high'   => 3,
        'medium' => 2,
        'low'    => 1,
        default  => 0,
    };
}

// ─── "Find by current price" helper ─────────────────────

/**
 * Search all discovered sources for a known price value.
 *
 * Treats equivalent formats as matching:
 *   129 ≈ 129.0 ≈ 129,00 ≈ "129 kr" ≈ "SEK 129"
 *
 * @param DOMDocument $doc           Parsed DOM
 * @param string      $rawHtml       Original HTML
 * @param float       $knownPrice    The price the user sees on the page
 * @param string|null $cssSelector   Optional selector to also check
 * @return array[] Ranked matching candidates
 */
function findPriceInSources(DOMDocument $doc, string $rawHtml, float $knownPrice, ?string $cssSelector = null): array
{
    $allCandidates = discoverPriceCandidates($doc, $rawHtml, $cssSelector);

    // Filter to those matching the known price (within small tolerance)
    $tolerance = max($knownPrice * 0.005, 0.50); // 0.5% or 0.50, whichever is larger

    $matching = [];
    foreach ($allCandidates as $c) {
        if (abs($c['numericValue'] - $knownPrice) <= $tolerance) {
            $c['reasons'][] = "Matches known price " . number_format($knownPrice, 2);
            $matching[] = $c;
        }
    }

    // Sort: high confidence first
    usort($matching, function ($a, $b) {
        return confidenceRank($b['confidence']) - confidenceRank($a['confidence']);
    });

    return $matching;
}

// ─── Page Inspector: preview, diagnostics, selector analysis ─

/**
 * Detect whether the fetched page likely relies on client-side JavaScript
 * rendering for its main content.
 *
 * Must be called on the original DOM *before* script stripping.
 *
 * @return array{likely: bool, confidence: string, hints: string[]}
 */
function detectJsRendering(DOMDocument $doc, string $rawHtml): array
{
    $hints = [];

    // 1. Empty SPA root containers
    foreach (['root', 'app', '__next', '__nuxt'] as $id) {
        $el = $doc->getElementById($id);
        if ($el !== null && mb_strlen(trim($el->textContent)) < 50) {
            $hints[] = "Empty #$id container (likely SPA mount point)";
        }
    }

    // 2. Hydration / framework blobs in script contents
    $markers = ['__NEXT_DATA__', '__NUXT__', 'window.__INITIAL_STATE__', 'window.__APP_DATA__'];
    foreach ($markers as $marker) {
        if (str_contains($rawHtml, $marker)) {
            $hints[] = "Found hydration marker: $marker";
        }
    }

    // 3. Framework attributes
    $xpath = new DOMXPath($doc);
    $frameworkAttrs = [
        'ng-version'           => 'Angular',
        'data-reactroot'       => 'React',
        'data-server-rendered' => 'Vue SSR',
        'data-svelte'          => 'Svelte',
    ];
    foreach ($frameworkAttrs as $attr => $name) {
        $nodes = @$xpath->query("//*[@$attr]");
        if ($nodes !== false && $nodes->length > 0) {
            $hints[] = "Framework attribute detected: $attr ($name)";
        }
    }

    // 4. High script density vs low body text
    $scripts = $doc->getElementsByTagName('script');
    $body = $doc->getElementsByTagName('body')->item(0);
    $bodyText = $body ? trim($body->textContent) : '';
    $bodyTextLen = mb_strlen(preg_replace('/\s+/', ' ', $bodyText));

    if ($scripts->length > 10 && $bodyTextLen < 500) {
        $hints[] = 'High script count (' . $scripts->length . ') with low body text (' . $bodyTextLen . ' chars)';
    }

    // 5. Very low meaningful body text
    if ($bodyTextLen < 200) {
        $hints[] = "Very low body text content ($bodyTextLen chars)";
    }

    $count = count($hints);
    if ($count === 0) {
        return ['likely' => false, 'confidence' => 'low', 'hints' => []];
    }

    return [
        'likely'     => true,
        'confidence' => $count >= 3 ? 'high' : ($count >= 2 ? 'medium' : 'low'),
        'hints'      => $hints,
    ];
}

/**
 * Detect page-quality issues that may prevent reliable extraction.
 *
 * @return array{page_quality_warnings: string[]}
 */
function detectPageQualityIssues(DOMDocument $doc, string $rawHtml): array
{
    $warnings = [];

    $body = $doc->getElementsByTagName('body')->item(0);
    $bodyText = $body ? strtolower(trim($body->textContent)) : '';
    $bodyTextLen = mb_strlen(preg_replace('/\s+/', ' ', $bodyText));

    // Very low content
    if ($bodyTextLen < 100) {
        $warnings[] = 'very_low_text_content';
    }

    // Challenge / anti-bot page signals
    $challengeSignals = [
        'checking your browser',
        'please verify you are a human',
        'captcha',
        'ray id',
        'attention required',
        'enable javascript and cookies',
        'just a moment',
        'ddos protection',
    ];
    foreach ($challengeSignals as $signal) {
        if (str_contains($bodyText, $signal)) {
            $warnings[] = 'challenge_page_detected';
            break;
        }
    }

    // Access denied / 403
    $title = '';
    $titleNode = $doc->getElementsByTagName('title')->item(0);
    if ($titleNode) {
        $title = strtolower(trim($titleNode->textContent));
    }
    $accessDeniedSignals = ['access denied', 'forbidden', '403', 'not authorized'];
    foreach ($accessDeniedSignals as $signal) {
        if (str_contains($title, $signal) || (str_contains($bodyText, $signal) && $bodyTextLen < 500)) {
            $warnings[] = 'possible_bot_block';
            break;
        }
    }

    // Login wall
    $loginSignals = ['you must be logged in', 'please log in', 'sign in to continue', 'logga in för att'];
    foreach ($loginSignals as $signal) {
        if (str_contains($bodyText, $signal)) {
            $warnings[] = 'possible_login_wall';
            break;
        }
    }

    return ['page_quality_warnings' => array_values(array_unique($warnings))];
}

/**
 * Prepare fetched HTML for display in the page inspector iframe.
 *
 * Strips executable content, neutralizes navigation, injects a <base> tag,
 * and runs JS-rendering + page-quality detection.
 *
 * @return array{html: string, base_url: string, js_rendering_likely: bool, js_rendering_confidence: string, js_hints: string[], page_quality_warnings: string[]}
 */
function preparePageForPreview(string $html, string $url): array
{
    $doc = new DOMDocument();
    $internalErrors = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($internalErrors);

    // Run diagnostics on original DOM (before stripping)
    $jsInfo = detectJsRendering($doc, $html);
    $qualityInfo = detectPageQualityIssues($doc, $html);

    $xpath = new DOMXPath($doc);

    // Strip dangerous elements: script, noscript, iframe, object, embed
    foreach (['script', 'noscript', 'iframe', 'object', 'embed'] as $tag) {
        $nodes = $doc->getElementsByTagName($tag);
        $toRemove = [];
        for ($i = 0; $i < $nodes->length; $i++) {
            $toRemove[] = $nodes->item($i);
        }
        foreach ($toRemove as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Strip meta refresh
    $metaNodes = @$xpath->query('//meta[translate(@http-equiv,"REFSH","refsh")="refresh"]');
    if ($metaNodes !== false) {
        foreach ($metaNodes as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Strip on* event handler attributes
    $allElements = @$xpath->query('//*');
    if ($allElements !== false) {
        foreach ($allElements as $el) {
            /** @var DOMElement $el */
            $attrsToRemove = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                // Remove event handlers
                if (str_starts_with($name, 'on')) {
                    $attrsToRemove[] = $attr->name;
                }
            }
            foreach ($attrsToRemove as $attrName) {
                $el->removeAttribute($attrName);
            }

            // Neutralize javascript: URLs in href, src, action
            foreach (['href', 'src', 'action'] as $urlAttr) {
                if ($el->hasAttribute($urlAttr)) {
                    $val = trim($el->getAttribute($urlAttr));
                    if (preg_match('/^\s*javascript\s*:/i', $val)) {
                        $el->setAttribute($urlAttr, '#');
                    }
                }
            }

            // Neutralize links: preserve original in data-original-href, set href to #
            if (strtolower($el->tagName) === 'a' && $el->hasAttribute('href')) {
                $original = $el->getAttribute('href');
                if ($original !== '#') {
                    $el->setAttribute('data-original-href', $original);
                    $el->setAttribute('href', '#');
                }
            }

            // Neutralize forms: preserve original action, clear it
            if (strtolower($el->tagName) === 'form') {
                if ($el->hasAttribute('action')) {
                    $el->setAttribute('data-original-action', $el->getAttribute('action'));
                }
                $el->setAttribute('action', '#');
                $el->setAttribute('onsubmit', '');
                $el->removeAttribute('onsubmit');
            }
        }
    }

    // Inject <base> tag for resource resolution
    $parts = parse_url($url);
    $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $baseUrl .= ':' . $parts['port'];
    }
    $baseUrl .= '/';

    // Remove any existing <base> tags and inject ours
    $existingBases = $doc->getElementsByTagName('base');
    $toRemove = [];
    for ($i = 0; $i < $existingBases->length; $i++) {
        $toRemove[] = $existingBases->item($i);
    }
    foreach ($toRemove as $node) {
        $node->parentNode->removeChild($node);
    }

    $head = $doc->getElementsByTagName('head')->item(0);
    if ($head) {
        $base = $doc->createElement('base');
        $base->setAttribute('href', $baseUrl);
        if ($head->firstChild) {
            $head->insertBefore($base, $head->firstChild);
        } else {
            $head->appendChild($base);
        }
    }

    $outputHtml = $doc->saveHTML();

    // Cap response at ~2 MB
    if (strlen($outputHtml) > 2 * 1024 * 1024) {
        $outputHtml = substr($outputHtml, 0, 2 * 1024 * 1024);
    }

    return [
        'html'                    => $outputHtml,
        'base_url'                => $baseUrl,
        'js_rendering_likely'     => $jsInfo['likely'],
        'js_rendering_confidence' => $jsInfo['confidence'],
        'js_hints'                => $jsInfo['hints'],
        'page_quality_warnings'   => $qualityInfo['page_quality_warnings'],
    ];
}

/**
 * Analyze a CSS selector against a DOMDocument.
 *
 * @return array{selector_valid: bool, selector_error: string|null, selector_match_count: int, selector_matches: array}
 */
function analyzeSelectorInDoc(DOMDocument $doc, string $selector): array
{
    $xpathExpr = cssToXPath($selector);
    if ($xpathExpr === null) {
        return [
            'selector_valid'       => false,
            'selector_error'       => 'Invalid CSS selector syntax',
            'selector_match_count' => 0,
            'selector_matches'     => [],
        ];
    }

    $xpath = new DOMXPath($doc);
    $nodes = @$xpath->query($xpathExpr);

    if ($nodes === false) {
        return [
            'selector_valid'       => false,
            'selector_error'       => 'XPath evaluation failed for this selector',
            'selector_match_count' => 0,
            'selector_matches'     => [],
        ];
    }

    $matches = [];
    $limit = min($nodes->length, 3);
    for ($i = 0; $i < $limit; $i++) {
        /** @var DOMElement $node */
        $node = $nodes->item($i);
        $text = trim($node->textContent);
        $classes = $node->getAttribute('class');

        // Collect notable attributes
        $attrs = [];
        foreach (['itemprop', 'data-price', 'data-amount', 'content', 'id'] as $a) {
            $val = $node->getAttribute($a);
            if ($val !== '') {
                $attrs[] = "$a=\"$val\"";
            }
        }

        $matches[] = [
            'tag'              => strtolower($node->tagName),
            'textSnippet'      => mb_substr($text, 0, 80),
            'classSnippet'     => mb_substr($classes, 0, 100),
            'attributeSnippet' => mb_substr(implode(' ', $attrs), 0, 120),
        ];
    }

    return [
        'selector_valid'       => true,
        'selector_error'       => null,
        'selector_match_count' => $nodes->length,
        'selector_matches'     => $matches,
    ];
}
