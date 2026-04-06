<?php

declare(strict_types=1);


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
    loadHtmlUtf8($doc, $html);

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
 * Detect charset from HTTP headers and HTML meta tags, convert to UTF-8 if needed.
 */
function ensureUtf8(string $body, string $headers): string
{
    $charset = null;

    // 1. Check Content-Type header: text/html; charset=iso-8859-1
    if (preg_match('/Content-Type:\s*[^;\r\n]+;\s*charset=([^\s;\r\n]+)/i', $headers, $m)) {
        $charset = trim($m[1], '"\'');
    }

    // 2. Check HTML meta tags: <meta charset="..."> or <meta http-equiv="Content-Type" content="...; charset=...">
    if ($charset === null) {
        if (preg_match('/<meta\s[^>]*charset=["\']?([^"\'\s;>]+)/i', $body, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta\s[^>]*content=["\'][^"\']*charset=([^"\'\s;>]+)/i', $body, $m)) {
            $charset = $m[1];
        }
    }

    if ($charset === null) {
        // Default: if it's already valid UTF-8, return as-is
        if (mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }
        // Otherwise assume ISO-8859-1 (most common non-UTF-8 encoding for Swedish sites)
        $charset = 'ISO-8859-1';
    }

    $charset = strtoupper(trim($charset));

    // Already UTF-8
    if ($charset === 'UTF-8' || $charset === 'UTF8') {
        return $body;
    }

    $converted = mb_convert_encoding($body, 'UTF-8', $charset);
    return $converted !== false ? $converted : $body;
}

/**
 * Load HTML into a DOMDocument with correct UTF-8 handling.
 * DOMDocument::loadHTML() defaults to Latin-1 unless the markup declares a charset.
 * We prepend a UTF-8 meta tag to ensure correct interpretation.
 */
function loadHtmlUtf8(DOMDocument $doc, string $html): void
{
    // libxml's HTML4 parser only recognises <meta http-equiv="Content-Type" …; charset=…>.
    // The HTML5 shorthand <meta charset="utf-8"> is ignored, causing Latin-1 default.
    // Replace any HTML5 charset meta with the HTML4 equivalent, or prepend one if missing.
    if (preg_match('/<meta\s+charset=["\']?[^"\'>\s]+["\']?\s*\/?>/i', $html)) {
        $html = preg_replace(
            '/<meta\s+charset=["\']?[^"\'>\s]+["\']?\s*\/?>/i',
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">',
            $html,
            1
        );
    } elseif (!preg_match('/<meta\s[^>]*http-equiv=["\']?Content-Type["\']?[^>]*charset/i', $html)) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }
    $internalErrors = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($internalErrors);
}

/**
 * Fetch a page over HTTP using cURL.
 */
function fetchPageResponse(string $url): array
{
    if (!isAllowedFetchUrl($url)) {
        return [
            'ok' => false,
            'body' => null,
            'headers' => '',
            'http_code' => 0,
            'error' => 'URL must use http/https and resolve to a public host',
            'blocked_reason' => null,
        ];
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
        CURLOPT_HEADER         => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || !is_string($response)) {
        return [
            'ok' => false,
            'body' => null,
            'headers' => '',
            'http_code' => $httpCode,
            'error' => $error !== '' ? $error : 'Failed to fetch page',
            'blocked_reason' => null,
        ];
    }

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if ($httpCode >= 400) {
        $blockedReason = null;
        if (stripos($headers, 'cf-mitigated: challenge') !== false || stripos($body, 'cf-challenge') !== false) {
            $blockedReason = 'Blocked by Cloudflare anti-bot challenge';
        } elseif ($httpCode === 403) {
            $blockedReason = 'Blocked by the site (HTTP 403)';
        } elseif ($httpCode === 429) {
            $blockedReason = 'Rate limited by the site (HTTP 429)';
        }

        return [
            'ok' => false,
            'body' => null,
            'headers' => $headers,
            'http_code' => $httpCode,
            'error' => "HTTP $httpCode while fetching page",
            'blocked_reason' => $blockedReason,
        ];
    }

    return [
        'ok' => true,
        'body' => ensureUtf8($body, $headers),
        'headers' => $headers,
        'http_code' => $httpCode,
        'error' => null,
        'blocked_reason' => null,
    ];
}

/**
 * Fetch a page over HTTP using cURL and return only the body on success.
 */
function fetchPage(string $url): ?string
{
    $response = fetchPageResponse($url);
    return $response['ok'] ? $response['body'] : null;
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
