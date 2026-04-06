<?php

declare(strict_types=1);

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

        $priceSpecification = $item['priceSpecification'] ?? null;
        if (is_array($priceSpecification)) {
            $extracted = extractPriceFromJsonLdPriceSpecification($priceSpecification);
            if ($extracted !== null) {
                return $extracted;
            }
        }
    }

    // Product with offers
    if (str_contains($type, 'Product')) {
        $priceSpecification = $item['priceSpecification'] ?? null;
        if (is_array($priceSpecification)) {
            $extracted = extractPriceFromJsonLdPriceSpecification($priceSpecification);
            if ($extracted !== null) {
                return $extracted;
            }
        }

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
 * Extract a price from Schema.org priceSpecification blocks.
 */
function extractPriceFromJsonLdPriceSpecification(mixed $priceSpecification): ?array
{
    if (!is_array($priceSpecification)) {
        return null;
    }

    $specList = isset($priceSpecification[0]) ? $priceSpecification : [$priceSpecification];

    foreach ($specList as $spec) {
        if (!is_array($spec)) {
            continue;
        }

        $price = $spec['price'] ?? $spec['minPrice'] ?? $spec['maxPrice'] ?? null;
        if ($price === null) {
            continue;
        }

        $parsed = parsePrice((string) $price);
        if ($parsed !== null) {
            return [
                'price'    => $parsed,
                'currency' => $spec['priceCurrency'] ?? null,
            ];
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
    $markers = [
        '__INITIAL_STATE__',
        '__APP_DATA__',
        '__PRELOADED_STATE__',
        '__ARTICLE_DETAIL_APOLLO_STATE__',
    ];

    foreach ($markers as $marker) {
        if (!str_contains($scriptContent, $marker)) {
            continue;
        }

        $state = parseAssignedStateData($scriptContent, $marker);
        if (is_array($state)) {
            $found = findPriceFieldsInData($state, $marker, 'initial_state');
            $candidates = array_merge($candidates, $found);
        }
    }

    return $candidates;
}

/**
 * Parse common JS state assignments into arrays.
 *
 * Supports:
 *   - window.__STATE__ = { ... }
 *   - window.__STATE__ = "{...}"  (JSON string encoded inside JS)
 */
function parseAssignedStateData(string $scriptContent, string $marker): ?array
{
    $escapedMarker = preg_quote($marker, '/');

    // Object/array literal assignment
    if (preg_match('/' . $escapedMarker . '\s*=\s*({.+?}|\[.+?\])\s*(?:;|<\/script>)/s', $scriptContent, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    // Stringified JSON assignment, usually with double quotes and \uXXXX escapes
    if (preg_match(
        '/' . $escapedMarker . '\s*=\s*("(?:\\\\.|[^"\\\\])*")\s*(?:;|<\/script>)/s',
        $scriptContent,
        $m
    )) {
        $decodedString = json_decode($m[1], true);
        if (is_string($decodedString)) {
            $decoded = json_decode($decodedString, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    return null;
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
    $priceKeys = implode('|', [
        'price',
        'currentPrice',
        'salePrice',
        'discountPrice',
        'productPrice',
        'itemPrice',
        'unitPrice',
        'finalPrice',
        'adjustedPrice',
        'regularPrice',
        'specialPrice',
        'discountedPrice',
        'lowestPrice',
        'previousLowestPrice',
        'comparisonPrice',
        'omnibusPrice',
        'minimumDefaultPrice',
        'numericalPrice',
        'netPrice',
    ]);
    $pattern = '/["\'](' . $priceKeys . ')["\']\s*:\s*(["\']?\d[\d\s,.]*(?:\s*(?:kr|SEK|USD|EUR|€|\$|£))?["\']?)/i';

    if (preg_match_all($pattern, $scriptContent, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = $match[1];
            $raw = trim($match[2], '"\'');
            // Strip trailing commas/dots that are JSON delimiters, not decimals
            $raw = rtrim($raw, ',.');
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
        'minimumDefaultPrice',
        'numericalPrice',
        'netPrice',
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

        $priceSpecifications = $item['priceSpecification'] ?? null;
        $specList = is_array($priceSpecifications)
            ? (isset($priceSpecifications[0]) ? $priceSpecifications : [$priceSpecifications])
            : [];
        foreach ($specList as $idx => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            foreach (['price', 'minPrice', 'maxPrice'] as $field) {
                if (!isset($spec[$field])) {
                    continue;
                }
                $parsed = parsePrice((string) $spec[$field]);
                if ($parsed !== null && $parsed > 0) {
                    $specType = is_string($spec['@type'] ?? null) ? $spec['@type'] : 'PriceSpecification';
                    $candidates[] = [
                        'sourceType'     => 'jsonld',
                        'patternType'    => null,
                        'label'          => "JSON-LD $type → $specType → $field",
                        'valueRaw'       => $spec[$field],
                        'valueFormatted' => (string) $spec[$field],
                        'numericValue'   => $parsed,
                        'currency'       => $spec['priceCurrency'] ?? ($item['priceCurrency'] ?? null),
                        'path'           => "$pathPrefix.$type.priceSpecification[$idx].$field",
                        'confidence'     => 'high',
                        'reasons'        => array_filter([
                            "Schema.org $type with priceSpecification.$field",
                            isset($spec['priceCurrency']) ? "Currency: {$spec['priceCurrency']}" : null,
                        ]),
                    ];
                }
            }
        }
    }

    if ($isProduct) {
        $priceSpecifications = $item['priceSpecification'] ?? null;
        $specList = is_array($priceSpecifications)
            ? (isset($priceSpecifications[0]) ? $priceSpecifications : [$priceSpecifications])
            : [];
        foreach ($specList as $idx => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            foreach (['price', 'minPrice', 'maxPrice'] as $field) {
                if (!isset($spec[$field])) {
                    continue;
                }
                $parsed = parsePrice((string) $spec[$field]);
                if ($parsed !== null && $parsed > 0) {
                    $specType = is_string($spec['@type'] ?? null) ? $spec['@type'] : 'PriceSpecification';
                    $candidates[] = [
                        'sourceType'     => 'jsonld',
                        'patternType'    => null,
                        'label'          => "JSON-LD Product → $specType → $field",
                        'valueRaw'       => $spec[$field],
                        'valueFormatted' => (string) $spec[$field],
                        'numericValue'   => $parsed,
                        'currency'       => $spec['priceCurrency'] ?? null,
                        'path'           => "$pathPrefix.Product.priceSpecification[$idx].$field",
                        'confidence'     => 'high',
                        'reasons'        => array_filter([
                            "Schema.org Product with priceSpecification.$field",
                            isset($spec['priceCurrency']) ? "Currency: {$spec['priceCurrency']}" : null,
                        ]),
                    ];
                }
            }
        }

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

// ─── Page Inspector: preview, diagnostics, selector analysis ─

/**
 * Detect the e-commerce platform and frontend framework from fetched HTML.
 *
 * Returns a context object with:
 *   - commercePlatform: detected backend commerce platform
 *   - frontendFramework: detected JS frontend framework (separate concern)
 *   - confidence: 'high' | 'medium' | 'low'
 *   - signals: raw signal keys detected
 *   - reasons: human-readable explanation per signal
 *
 * This is context only — it does not decide the final extracted price.
 *
 * @return array{commercePlatform: string, frontendFramework: string, confidence: string, signals: string[], reasons: string[]}
 */
function detectPlatformContext(DOMDocument $doc, string $html): array
{
    $xpath    = new DOMXPath($doc);
    $signals  = [];
    $reasons  = [];

    // ── Commerce platform signals ─────────────────────────────────

    // Shopify
    if (str_contains($html, 'window.Shopify')) {
        $signals[] = 'shopify';
        $reasons[] = 'window.Shopify object present in scripts';
    }
    if (str_contains($html, 'cdn.shopify.com')) {
        $signals[] = 'shopify';
        $reasons[] = 'cdn.shopify.com script src detected';
    }
    if (str_contains($html, 'ShopifyAnalytics')) {
        $signals[] = 'shopify';
        $reasons[] = 'ShopifyAnalytics object present';
    }
    $shopifyMeta = $xpath->query("//meta[@name='generator' and contains(@content,'Shopify')]")->item(0);
    if ($shopifyMeta !== null) {
        $signals[] = 'shopify';
        $reasons[] = 'meta generator=Shopify found';
    }

    // WooCommerce
    if (preg_match('/\bwoocommerce[-_]page\b/i', $html)) {
        $signals[] = 'woocommerce';
        $reasons[] = 'woocommerce-page CSS class in HTML';
    }
    if (str_contains($html, 'wc_add_to_cart_params') || str_contains($html, 'wc_cart_fragments_params')) {
        $signals[] = 'woocommerce';
        $reasons[] = 'WooCommerce JS params object present';
    }
    if (str_contains($html, '/wp-content/plugins/woocommerce/')) {
        $signals[] = 'woocommerce';
        $reasons[] = 'WooCommerce plugin asset path detected';
    }
    $wpMeta = $xpath->query("//meta[@name='generator' and contains(@content,'WordPress')]")->item(0);
    if ($wpMeta !== null && (str_contains($html, 'woocommerce') || str_contains($html, 'WooCommerce'))) {
        $signals[] = 'woocommerce';
        $reasons[] = 'WordPress generator meta + WooCommerce reference';
    }

    // Magento / Adobe Commerce
    if (str_contains($html, 'Mage.')) {
        $signals[] = 'magento';
        $reasons[] = 'Mage. JS namespace found';
    }
    $magentoScript = $xpath->query("//script[@type='text/x-magento-init']")->item(0);
    if ($magentoScript !== null) {
        $signals[] = 'magento';
        $reasons[] = '<script type="text/x-magento-init"> found';
    }
    if (str_contains($html, 'data-mage-init') || str_contains($html, '"Magento_')) {
        $signals[] = 'magento';
        $reasons[] = 'Magento data-mage-init or module reference found';
    }
    // Magento pricing widget
    if (str_contains($html, 'price-box') && str_contains($html, 'data-price-type')) {
        $signals[] = 'magento';
        $reasons[] = 'Magento price-box + data-price-type attributes found';
    }

    // PrestaShop
    if (str_contains($html, 'window.prestashop')) {
        $signals[] = 'prestashop';
        $reasons[] = 'window.prestashop object present';
    }
    if (str_contains($html, 'prestashop') && str_contains($html, 'id_product')) {
        $signals[] = 'prestashop';
        $reasons[] = 'prestashop reference + id_product detected';
    }
    $prestaMeta = $xpath->query("//meta[@name='generator' and contains(@content,'PrestaShop')]")->item(0);
    if ($prestaMeta !== null) {
        $signals[] = 'prestashop';
        $reasons[] = 'meta generator=PrestaShop found';
    }

    // Centra
    if (str_contains($html, 'window.Centra')) {
        $signals[] = 'centra';
        $reasons[] = 'window.Centra object present';
    }
    if (str_contains($html, '"centra"') || str_contains($html, 'centra-checkout')) {
        $signals[] = 'centra';
        $reasons[] = 'Centra checkout reference detected';
    }

    // Shopware
    if (str_contains($html, 'shopware') || str_contains($html, 'sw-plugin')) {
        $signals[] = 'shopware';
        $reasons[] = 'Shopware reference detected';
    }
    if (str_contains($html, '/bundles/storefront/') && str_contains($html, 'shopware')) {
        $signals[] = 'shopware';
        $reasons[] = 'Shopware storefront bundle asset found';
    }

    // BigCommerce
    if (str_contains($html, 'bigcommerce') || str_contains($html, 'BigCommerce')) {
        $signals[] = 'bigcommerce';
        $reasons[] = 'BigCommerce reference detected';
    }
    if (str_contains($html, 'BCData') || str_contains($html, 'window.BCData')) {
        $signals[] = 'bigcommerce';
        $reasons[] = 'BCData storefront object found';
    }

    // Salesforce Commerce Cloud / Demandware
    if (str_contains($html, 'demandware') || str_contains($html, 'sfcc') || str_contains($html, 'salesforce-commerce')) {
        $signals[] = 'sfcc';
        $reasons[] = 'Demandware/SFCC reference detected';
    }
    if (str_contains($html, 'dwre') || str_contains($html, '/on/demandware.store/')) {
        $signals[] = 'sfcc';
        $reasons[] = 'Demandware store URL pattern found';
    }

    // ── Frontend framework signals (separate from commerce platform) ─

    $frontendFramework = 'unknown';
    $frontendReasons   = [];

    if (str_contains($html, '__NEXT_DATA__')) {
        $frontendFramework = 'next_js';
        $frontendReasons[] = '__NEXT_DATA__ hydration marker found';
        $signals[] = 'next_js';
        $reasons[] = 'Next.js frontend detected via __NEXT_DATA__';
    } elseif (str_contains($html, '__NUXT__') || str_contains($html, '__NUXT_DATA__')) {
        $frontendFramework = 'nuxt';
        $frontendReasons[] = '__NUXT__ / __NUXT_DATA__ hydration marker found';
        $signals[] = 'nuxt';
        $reasons[] = 'Nuxt.js frontend detected via __NUXT__ hydration marker';
    }

    // ── Derive commerce platform from signal counts ────────────────

    $platformCounts = [];
    foreach ($signals as $s) {
        if (!in_array($s, ['next_js', 'nuxt'], true)) {
            $platformCounts[$s] = ($platformCounts[$s] ?? 0) + 1;
        }
    }

    arsort($platformCounts);
    $commercePlatform = 'unknown';
    $topCount         = 0;
    $secondCount      = 0;
    $platformList     = array_keys($platformCounts);

    if (!empty($platformList)) {
        $commercePlatform = $platformList[0];
        $topCount         = $platformCounts[$platformList[0]];
        $secondCount      = isset($platformList[1]) ? $platformCounts[$platformList[1]] : 0;
    }

    // Confidence: based on signal strength and whether signals conflict
    $confidence = 'low';
    if ($topCount >= 3) {
        $confidence = 'high';
    } elseif ($topCount >= 2) {
        $confidence = $secondCount <= 1 ? 'medium' : 'low'; // conflicting signals → low
    } elseif ($topCount === 1) {
        $confidence = 'low';
    }

    return [
        'commercePlatform'  => $commercePlatform,
        'frontendFramework' => $frontendFramework,
        'confidence'        => $confidence,
        'signals'           => array_unique($signals),
        'reasons'           => $reasons,
    ];
}

/**
 * Generate price candidates using platform-specific extraction rules.
 *
 * Returns two groups:
 *   - 'structured': high-confidence attribute-based / script-structured candidates
 *   - 'dom':        medium-confidence DOM/theme-dependent candidates
 *
 * Both groups follow the same candidate model as the rest of the pipeline
 * and must go through main product detection, association scoring, price role
 * classification, and final selection — they do not bypass the pipeline.
 *
 * @param array $platformContext Result of detectPlatformContext()
 * @return array{structured: array[], dom: array[]}
 */
function extractPlatformCandidates(DOMDocument $doc, DOMXPath $xpath, string $html, array $platformContext): array
{
    $platform   = $platformContext['commercePlatform'];
    $structured = [];
    $dom        = [];

    switch ($platform) {

        case 'shopify':
            // ── Shopify structured: ShopifyAnalytics / product JSON ────
            // Try to parse ShopifyAnalytics.meta.product for the active variant price
            if (preg_match('/ShopifyAnalytics\.meta\s*=\s*(\{.*?\});/s', $html, $m)) {
                $meta = @json_decode($m[1], true);
                if (is_array($meta)) {
                    // Prefer selectedVariant if available; fall back to variants[0]
                    $variant   = $meta['selectedVariant'] ?? ($meta['product']['variants'][0] ?? null);
                    $rawPrice  = $variant['price'] ?? null;
                    if ($rawPrice !== null) {
                        $numericPrice = is_int($rawPrice) ? $rawPrice / 100 : (float) $rawPrice;
                        if ($numericPrice > 0) {
                            $structured[] = [
                                'sourceType'     => 'platform_structured',
                                'patternType'    => 'shopify_analytics',
                                'label'          => 'Shopify ShopifyAnalytics.meta variant price',
                                'valueRaw'       => $rawPrice,
                                'valueFormatted' => (string) $numericPrice,
                                'numericValue'   => $numericPrice,
                                'currency'       => $meta['currency'] ?? null,
                                'path'           => 'ShopifyAnalytics.meta.product.variant.price',
                                'confidence'     => 'high',
                                'priceRole'      => 'current',
                                'reasons'        => ['Shopify ShopifyAnalytics active variant price (cents/100)'],
                            ];
                        }
                    }
                    $compareAt = $variant['compareAtPrice'] ?? null;
                    if ($compareAt !== null) {
                        $numericCompare = is_int($compareAt) ? $compareAt / 100 : (float) $compareAt;
                        if ($numericCompare > 0) {
                            $structured[] = [
                                'sourceType'     => 'platform_structured',
                                'patternType'    => 'shopify_analytics',
                                'label'          => 'Shopify compareAtPrice (regular)',
                                'valueRaw'       => $compareAt,
                                'valueFormatted' => (string) $numericCompare,
                                'numericValue'   => $numericCompare,
                                'currency'       => $meta['currency'] ?? null,
                                'path'           => 'ShopifyAnalytics.meta.product.variant.compareAtPrice',
                                'confidence'     => 'high',
                                'priceRole'      => 'regular',
                                'reasons'        => ['Shopify compareAtPrice (original before discount)'],
                            ];
                        }
                    }
                }
            }

            // ── Shopify DOM / theme candidates ─────────────────────────
            $shopifyDomSelectors = [
                ['selector' => '[data-product-price]',   'attr' => 'content', 'label' => 'Shopify [data-product-price]', 'role' => 'current'],
                ['selector' => '.price__current',         'attr' => null,      'label' => 'Shopify .price__current',      'role' => 'current'],
                ['selector' => '.price-item--sale',       'attr' => null,      'label' => 'Shopify .price-item--sale',    'role' => 'current'],
                ['selector' => '.price-item--regular',    'attr' => null,      'label' => 'Shopify .price-item--regular', 'role' => 'regular'],
            ];
            foreach ($shopifyDomSelectors as $sel) {
                $nodes = @$xpath->query(cssToXPath($sel['selector']));
                $node  = $nodes !== false ? $nodes->item(0) : null;
                if ($node === null) {
                    continue;
                }
                $rawText = $sel['attr'] ? $node->getAttribute($sel['attr']) : trim($node->textContent);
                $price   = parsePrice(trim($rawText));
                if ($price !== null && $price > 0) {
                    $dom[] = [
                        'sourceType'     => 'platform_dom',
                        'patternType'    => 'shopify_dom',
                        'label'          => $sel['label'],
                        'valueRaw'       => $rawText,
                        'valueFormatted' => $rawText,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => $sel['selector'],
                        'confidence'     => 'medium',
                        'priceRole'      => $sel['role'],
                        'reasons'        => ['Shopify theme DOM selector - confidence medium due to theme variance'],
                    ];
                }
            }
            break;

        case 'woocommerce':
            // ── WooCommerce: prefer <ins> (sale) over <del> (original) ────
            // Sale price: inside <ins>
            $insNodes  = @$xpath->query("//ins//span[contains(@class,'woocommerce-Price-amount')]//bdi");
            $insNode   = $insNodes !== false ? $insNodes->item(0) : null;
            if ($insNode !== null) {
                $raw   = trim($insNode->textContent);
                $price = parsePrice($raw);
                if ($price !== null && $price > 0) {
                    $structured[] = [
                        'sourceType'     => 'platform_structured',
                        'patternType'    => 'woocommerce_price',
                        'label'          => 'WooCommerce <ins> sale price',
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => 'ins .woocommerce-Price-amount bdi',
                        'confidence'     => 'high',
                        'priceRole'      => 'current',
                        'reasons'        => ['WooCommerce sale price inside <ins> element'],
                    ];
                }
            }

            // Regular/original price: inside <del>
            $delNodes = @$xpath->query("//del//span[contains(@class,'woocommerce-Price-amount')]//bdi");
            $delNode  = $delNodes !== false ? $delNodes->item(0) : null;
            if ($delNode !== null) {
                $raw   = trim($delNode->textContent);
                $price = parsePrice($raw);
                if ($price !== null && $price > 0) {
                    $structured[] = [
                        'sourceType'     => 'platform_structured',
                        'patternType'    => 'woocommerce_price',
                        'label'          => 'WooCommerce <del> regular price',
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => 'del .woocommerce-Price-amount bdi',
                        'confidence'     => 'high',
                        'priceRole'      => 'regular',
                        'reasons'        => ['WooCommerce original price inside <del> element'],
                    ];
                }
            }

            // Fallback: generic .price > .amount (lower confidence, may include shipping etc.)
            if ($insNode === null && $delNode === null) {
                $amountNodes = @$xpath->query(
                    "//*[contains(@class,'price')]/*[contains(@class,'amount') or contains(@class,'woocommerce-Price-amount')]"
                );
                if ($amountNodes !== false && $amountNodes->length > 0) {
                    $raw   = trim($amountNodes->item(0)->textContent);
                    $price = parsePrice($raw);
                    if ($price !== null && $price > 0) {
                        $dom[] = [
                            'sourceType'     => 'platform_dom',
                            'patternType'    => 'woocommerce_price',
                            'label'          => 'WooCommerce .price > .amount fallback',
                            'valueRaw'       => $raw,
                            'valueFormatted' => $raw,
                            'numericValue'   => $price,
                            'currency'       => null,
                            'path'           => '.price > .amount',
                            'confidence'     => 'medium',
                            'priceRole'      => 'current',
                            'reasons'        => ['WooCommerce generic price/amount — may include non-product prices'],
                        ];
                    }
                }
            }
            break;

        case 'magento':
            // ── Magento: data-price-amount attribute is highly reliable ────
            $priceTypes = [
                'finalPrice' => ['priceRole' => 'current',  'confidence' => 'high'],
                'oldPrice'   => ['priceRole' => 'regular',  'confidence' => 'high'],
                'minPrice'   => ['priceRole' => 'current',  'confidence' => 'medium'],
                'maxPrice'   => ['priceRole' => 'from',     'confidence' => 'medium'],
            ];
            foreach ($priceTypes as $priceType => $meta) {
                $nodes = @$xpath->query("//*[@data-price-type='" . $priceType . "'][@data-price-amount]");
                if ($nodes === false || $nodes->length === 0) {
                    continue;
                }
                $raw   = trim($nodes->item(0)->getAttribute('data-price-amount'));
                $price = (float) $raw;
                if ($price > 0) {
                    $structured[] = [
                        'sourceType'     => 'platform_structured',
                        'patternType'    => 'magento_price_box',
                        'label'          => "Magento [$priceType] data-price-amount",
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => "[data-price-type=\"$priceType\"][data-price-amount]",
                        'confidence'     => $meta['confidence'],
                        'priceRole'      => $meta['priceRole'],
                        'reasons'        => ["Magento price box: data-price-type=$priceType attribute (high reliability)"],
                    ];
                }
            }

            // Fallback DOM: .price-wrapper .price (text-based, weaker)
            $fallbackNodes = @$xpath->query("//*[contains(@class,'price-wrapper')]//*[contains(@class,'price')]");
            if ($fallbackNodes !== false && $fallbackNodes->length > 0) {
                $raw   = trim($fallbackNodes->item(0)->textContent);
                $price = parsePrice($raw);
                if ($price !== null && $price > 0) {
                    $dom[] = [
                        'sourceType'     => 'platform_dom',
                        'patternType'    => 'magento_price_box',
                        'label'          => 'Magento .price-wrapper .price fallback',
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => '.price-wrapper .price',
                        'confidence'     => 'medium',
                        'priceRole'      => 'current',
                        'reasons'        => ['Magento price text fallback — data-price-amount not available'],
                    ];
                }
            }
            break;

        case 'prestashop':
            // ── PrestaShop: .current-price .price, #our_price_display ────
            $prestaSelectors = [
                ['sel' => ".current-price .price",   'role' => 'current',  'conf' => 'high'],
                ['sel' => "#our_price_display",       'role' => 'current',  'conf' => 'high'],
                ['sel' => ".product-price .price",    'role' => 'current',  'conf' => 'medium'],
                ['sel' => ".original-price",          'role' => 'regular',  'conf' => 'medium'],
                ['sel' => ".unit-price",              'role' => 'unit',     'conf' => 'medium'],
            ];
            foreach ($prestaSelectors as $s) {
                $nodes = @$xpath->query(cssToXPath($s['sel']));
                $node  = $nodes !== false ? $nodes->item(0) : null;
                if ($node === null) {
                    continue;
                }
                $raw   = trim($node->textContent);
                $price = parsePrice($raw);
                if ($price !== null && $price > 0) {
                    $group = $s['conf'] === 'high' ? $structured : $dom;
                    $entry = [
                        'sourceType'     => $s['conf'] === 'high' ? 'platform_structured' : 'platform_dom',
                        'patternType'    => 'prestashop_price',
                        'label'          => "PrestaShop {$s['sel']}",
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => $s['sel'],
                        'confidence'     => $s['conf'],
                        'priceRole'      => $s['role'],
                        'reasons'        => ["PrestaShop price selector: {$s['sel']}"],
                    ];
                    if ($s['conf'] === 'high') {
                        $structured[] = $entry;
                    } else {
                        $dom[] = $entry;
                    }
                    break; // Only take first match per priority list
                }
            }
            break;

        case 'shopware':
            // ── Shopware: common Shopware 6 price structures ────────────
            $shopwareSelectors = [
                ['sel' => ".product-detail-price",         'role' => 'current', 'conf' => 'high'],
                ['sel' => ".product-price .price",         'role' => 'current', 'conf' => 'medium'],
                ['sel' => "[itemprop='price']",            'role' => 'current', 'conf' => 'medium'],
            ];
            foreach ($shopwareSelectors as $s) {
                $nodes = @$xpath->query(cssToXPath($s['sel']));
                $node  = $nodes !== false ? $nodes->item(0) : null;
                if ($node === null) {
                    continue;
                }
                $raw   = trim($node->getAttribute('content') ?: $node->textContent);
                $price = parsePrice($raw);
                if ($price !== null && $price > 0) {
                    $entry = [
                        'sourceType'     => $s['conf'] === 'high' ? 'platform_structured' : 'platform_dom',
                        'patternType'    => 'shopware_price',
                        'label'          => "Shopware {$s['sel']}",
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => $s['sel'],
                        'confidence'     => $s['conf'],
                        'priceRole'      => $s['role'],
                        'reasons'        => ["Shopware price selector: {$s['sel']}"],
                    ];
                    if ($s['conf'] === 'high') {
                        $structured[] = $entry;
                    } else {
                        $dom[] = $entry;
                    }
                    break;
                }
            }
            break;

        case 'bigcommerce':
            // ── BigCommerce: structured product price data ───────────────
            if (preg_match('/BCData\s*=\s*(\{.*?\});/s', $html, $m)) {
                $bc = @json_decode($m[1], true);
                if (is_array($bc)) {
                    $rawPrice = $bc['product_info']['price'] ?? null;
                    if ($rawPrice !== null) {
                        $price = parsePrice((string) $rawPrice);
                        if ($price !== null && $price > 0) {
                            $structured[] = [
                                'sourceType'     => 'platform_structured',
                                'patternType'    => 'bigcommerce_bcdata',
                                'label'          => 'BigCommerce BCData.product_info.price',
                                'valueRaw'       => $rawPrice,
                                'valueFormatted' => (string) $rawPrice,
                                'numericValue'   => $price,
                                'currency'       => null,
                                'path'           => 'BCData.product_info.price',
                                'confidence'     => 'high',
                                'priceRole'      => 'current',
                                'reasons'        => ['BigCommerce BCData product price object'],
                            ];
                        }
                    }
                }
            }
            // DOM fallback
            $bcSelectors = [
                ['sel' => "[data-product-price-without-tax]", 'attr' => 'data-product-price-without-tax', 'role' => 'current'],
                ['sel' => ".productView-price .price--main",  'attr' => null,                            'role' => 'current'],
            ];
            foreach ($bcSelectors as $s) {
                $nodes = @$xpath->query(cssToXPath($s['sel']));
                $node  = $nodes !== false ? $nodes->item(0) : null;
                if ($node === null) {
                    continue;
                }
                $raw   = $s['attr'] ? $node->getAttribute($s['attr']) : trim($node->textContent);
                $price = parsePrice(trim($raw));
                if ($price !== null && $price > 0) {
                    $dom[] = [
                        'sourceType'     => 'platform_dom',
                        'patternType'    => 'bigcommerce_dom',
                        'label'          => "BigCommerce {$s['sel']}",
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => $s['sel'],
                        'confidence'     => 'medium',
                        'priceRole'      => $s['role'],
                        'reasons'        => ["BigCommerce DOM price selector: {$s['sel']}"],
                    ];
                    break;
                }
            }
            break;

        case 'sfcc':
            // Salesforce Commerce Cloud — conservative: only stable, well-known attribute
            $sfccNodes = @$xpath->query("//*[@itemprop='price'][@content]");
            if ($sfccNodes !== false && $sfccNodes->length > 0) {
                $raw   = trim($sfccNodes->item(0)->getAttribute('content'));
                $price = parsePrice($raw);
                if ($price !== null && $price > 0) {
                    $dom[] = [
                        'sourceType'     => 'platform_dom',
                        'patternType'    => 'sfcc_price',
                        'label'          => 'SFCC itemprop=price[content]',
                        'valueRaw'       => $raw,
                        'valueFormatted' => $raw,
                        'numericValue'   => $price,
                        'currency'       => null,
                        'path'           => '[itemprop="price"][content]',
                        'confidence'     => 'medium',
                        'priceRole'      => 'current',
                        'reasons'        => ['SFCC microdata price attribute (conservative)'],
                    ];
                }
            }
            break;

        // centra / next_js / nuxt / unknown: no additional candidates
        // — already well-covered by existing extractors
        default:
            break;
    }

    return ['structured' => $structured, 'dom' => $dom];
}
