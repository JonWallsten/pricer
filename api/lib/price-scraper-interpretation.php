<?php

declare(strict_types=1);


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
    loadHtmlUtf8($doc, $html);

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
