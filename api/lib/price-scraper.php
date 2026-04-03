<?php

declare(strict_types=1);

/**
 * Price extraction engine.
 *
 * Attempts to extract product price from a URL using (in priority order):
 * 1. JSON-LD structured data (Schema.org Product)
 * 2. Open Graph / product meta tags
 * 3. Microdata (itemprop="price")
 * 4. User-provided CSS selector (fallback)
 *
 * @param string      $url         The product page URL
 * @param string|null $cssSelector Optional CSS selector for manual price extraction
 * @return array{price: float|null, currency: string|null, method: string|null, error: string|null, image_url: string|null, availability: string}
 */
function extractPrice(string $url, ?string $cssSelector = null): array
{
    $result = [
        'price'        => null,
        'currency'     => null,
        'method'       => null,
        'error'        => null,
        'image_url'    => null,
        'availability' => 'unknown',
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

    // 1. JSON-LD
    $extracted = extractFromJsonLd($doc);
    if ($extracted['price'] !== null) {
        $extracted['image_url'] = $result['image_url'];
        $extracted['availability'] = $result['availability'];
        return $extracted;
    }

    // 2. Meta tags (og:price, product:price)
    $extracted = extractFromMetaTags($doc);
    if ($extracted['price'] !== null) {
        $extracted['image_url'] = $result['image_url'];
        $extracted['availability'] = $result['availability'];
        return $extracted;
    }

    // 3. Microdata (itemprop="price")
    $extracted = extractFromMicrodata($doc);
    if ($extracted['price'] !== null) {
        $extracted['image_url'] = $result['image_url'];
        $extracted['availability'] = $result['availability'];
        return $extracted;
    }

    // 4. CSS selector fallback
    if ($cssSelector !== null && $cssSelector !== '') {
        $extracted = extractFromCssSelector($doc, $cssSelector);
        if ($extracted['price'] !== null) {
            $extracted['image_url'] = $result['image_url'];
            $extracted['availability'] = $result['availability'];
            return $extracted;
        }
        $result['error'] = 'CSS selector matched no price';
        return $result;
    }

    $result['error'] = 'No price found via structured data. Try adding a CSS selector.';
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
 * Fetch a page over HTTP using cURL.
 */
function fetchPage(string $url): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
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
 *
 * @return array{price: float|null, currency: string|null, method: string|null, error: string|null, image_url: string|null, availability: string, page_title: string|null}
 */
function extractPreview(string $url, ?string $cssSelector = null): array
{
    $result = [
        'price'        => null,
        'currency'     => null,
        'method'       => null,
        'error'        => null,
        'image_url'    => null,
        'availability' => 'unknown',
        'page_title'   => null,
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

    // Price extraction chain
    foreach (['extractFromJsonLd', 'extractFromMetaTags', 'extractFromMicrodata'] as $fn) {
        $extracted = $fn($doc);
        if ($extracted['price'] !== null) {
            $result['price'] = $extracted['price'];
            $result['currency'] = $extracted['currency'];
            $result['method'] = $extracted['method'];
            return $result;
        }
    }

    if ($cssSelector !== null && $cssSelector !== '') {
        $extracted = extractFromCssSelector($doc, $cssSelector);
        if ($extracted['price'] !== null) {
            $result['price'] = $extracted['price'];
            $result['currency'] = $extracted['currency'];
            $result['method'] = $extracted['method'];
            return $result;
        }
        $result['error'] = 'CSS selector matched no price';
        return $result;
    }

    $result['error'] = 'No price found via structured data. Try adding a CSS selector.';
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
