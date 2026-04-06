<?php

declare(strict_types=1);

require_once __DIR__ . '/price-scraper-utils.php';
require_once __DIR__ . '/price-scraper-strategies.php';
require_once __DIR__ . '/price-scraper-interpretation.php';

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
        'platform'              => 'unknown',
        'platform_confidence'   => 'low',
        'frontend_framework'    => 'unknown',
        'platform_signals'      => [],
        'platform_reasons'      => [],
    ];

    $fetch = fetchPageResponse($url);
    if (!$fetch['ok']) {
        $result['error'] = $fetch['blocked_reason'] ?? $fetch['error'] ?? 'Failed to fetch page';
        return $result;
    }
    $html = $fetch['body'];

    $doc = new DOMDocument();
    loadHtmlUtf8($doc, $html);

    // Extract product image and availability (independent of price method)
    $result['image_url'] = extractProductImage($doc, $url);
    $result['availability'] = extractAvailability($doc);

    // Use multi-strategy extraction
    $extracted = extractPriceMultiStrategy($doc, $html, $url, $extractionStrategy, $cssSelector);

    // Always propagate platform context regardless of whether price was found
    $result['platform']            = $extracted['platform'] ?? 'unknown';
    $result['platform_confidence'] = $extracted['platform_confidence'] ?? 'low';
    $result['frontend_framework']  = $extracted['frontend_framework'] ?? 'unknown';
    $result['platform_signals']    = $extracted['platform_signals'] ?? [];
    $result['platform_reasons']    = $extracted['platform_reasons'] ?? [];

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

    $fetch = fetchPageResponse($url);
    if (!$fetch['ok']) {
        $result['error'] = $fetch['blocked_reason'] ?? $fetch['error'] ?? 'Failed to fetch page';
        return $result;
    }
    $html = $fetch['body'];

    $doc = new DOMDocument();
    loadHtmlUtf8($doc, $html);

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
        'platform'              => 'unknown',
        'platform_confidence'   => 'low',
        'frontend_framework'    => 'unknown',
        'platform_signals'      => [],
        'platform_reasons'      => [],
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

    // Detect platform context once (used for candidate generation + debug output)
    $platformContext = detectPlatformContext($doc, $rawHtml);
    $result['platform']            = $platformContext['commercePlatform'];
    $result['platform_confidence'] = $platformContext['confidence'];
    $result['frontend_framework']  = $platformContext['frontendFramework'];
    $result['platform_signals']    = $platformContext['signals'];
    $result['platform_reasons']    = $platformContext['reasons'];

    $xpath = new DOMXPath($doc);
    $platformCandidates = extractPlatformCandidates($doc, $xpath, $rawHtml, $platformContext);

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

    // 2.5 Platform-native structured candidates (after JSON-LD, before generic script patterns)
    // These are attribute-based / structured JS data candidates — high confidence when platform is identified.
    foreach ($platformCandidates['structured'] as $pc) {
        $allCandidates[] = $pc;
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

    // 5.5 Platform DOM / theme candidates (after generic script patterns, before generic DOM heuristic)
    // These are weaker theme-dependent selectors — medium confidence only.
    foreach ($platformCandidates['dom'] as $pc) {
        $allCandidates[] = $pc;
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
