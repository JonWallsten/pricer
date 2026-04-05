<?php

declare(strict_types=1);

/**
 * Domain pattern learning.
 *
 * Tracks which extraction methods and CSS selectors succeed per domain,
 * and suggests known-working patterns when new URLs from the same domain are added.
 */

function normalizeDomainForPattern(string $url): ?string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return null;
    }
    return preg_replace('/^www\./i', '', strtolower($host));
}

/**
 * Record a successful extraction pattern for a domain.
 * Upserts: increments hit_count if the pattern already exists.
 */
function recordSuccessfulPattern(PDO $db, string $url, array $result): void
{
    $domain = normalizeDomainForPattern($url);
    if ($domain === null) {
        return;
    }

    $method = $result['method'] ?? null;
    if ($method === null || $method === 'not_found') {
        return;
    }

    $cssSelector = $result['debug_source'] === 'css_selector' ? ($result['debug_path'] ?? null) : null;
    $patternType = null;

    // For script_patterns, store the sub-type
    if ($method === 'script_patterns') {
        $debugPath = $result['debug_path'] ?? '';
        // Pattern type is typically encoded in debug_source or can be inferred
        if (str_contains($debugPath, 'WebComponents')) {
            $patternType = 'webcomponents_push';
        } elseif (str_contains($debugPath, '__NEXT_DATA__')) {
            $patternType = 'next_data';
        } elseif (str_contains($debugPath, '__NUXT__') || str_contains($debugPath, '__NUXT_DATA__')) {
            $patternType = 'nuxt_data';
        } elseif (str_contains($debugPath, '__INITIAL_STATE__') || str_contains($debugPath, '__APP_DATA__')) {
            $patternType = 'initial_state';
        } else {
            $patternType = 'generic_script';
        }
    }

    $debugPath = $result['debug_path'] ?? null;

    $stmt = $db->prepare(
        'INSERT INTO domain_patterns (domain, extraction_method, pattern_type, css_selector, debug_path, hit_count, last_success_at)
         VALUES (:domain, :method, :pattern_type, :css, :debug_path, 1, NOW())
         ON DUPLICATE KEY UPDATE
             hit_count = hit_count + 1,
             last_success_at = NOW(),
             pattern_type = COALESCE(VALUES(pattern_type), pattern_type),
             debug_path = COALESCE(VALUES(debug_path), debug_path)'
    );
    $stmt->execute([
        ':domain'       => $domain,
        ':method'       => $method,
        ':pattern_type' => $patternType,
        ':css'          => $cssSelector,
        ':debug_path'   => $debugPath,
    ]);
}

/**
 * Record a failed extraction attempt for a domain.
 * Only increments fail_count on an existing row — does not create new patterns from failures.
 */
function recordFailedPattern(PDO $db, string $url, ?string $method, ?string $cssSelector): void
{
    $domain = normalizeDomainForPattern($url);
    if ($domain === null || $method === null) {
        return;
    }

    $stmt = $db->prepare(
        'UPDATE domain_patterns
         SET fail_count = fail_count + 1, last_fail_at = NOW()
         WHERE domain = :domain AND extraction_method = :method
           AND (css_selector = :css OR (css_selector IS NULL AND :css2 IS NULL))'
    );
    $stmt->execute([
        ':domain' => $domain,
        ':method' => $method,
        ':css'    => $cssSelector,
        ':css2'   => $cssSelector,
    ]);
}

/**
 * Get all known patterns for a domain, ranked by effectiveness.
 *
 * @return array[] Patterns ordered by (hit_count - fail_count) DESC, last_success_at DESC
 */
function getDomainPatterns(PDO $db, string $url): array
{
    $domain = normalizeDomainForPattern($url);
    if ($domain === null) {
        return [];
    }

    $stmt = $db->prepare(
        'SELECT extraction_method, pattern_type, css_selector, debug_path,
                hit_count, fail_count, last_success_at,
                ROUND(hit_count / GREATEST(hit_count + fail_count, 1) * 100) AS success_rate
         FROM domain_patterns
         WHERE domain = :domain
         ORDER BY (hit_count - fail_count) DESC, last_success_at DESC'
    );
    $stmt->execute([':domain' => $domain]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get the best known CSS selector for a domain that has a high success rate.
 *
 * @return array{css_selector: string, hit_count: int, success_rate: int}|null
 */
function getBestSelectorForDomain(PDO $db, string $url): ?array
{
    $domain = normalizeDomainForPattern($url);
    if ($domain === null) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT css_selector, hit_count, fail_count,
                ROUND(hit_count / GREATEST(hit_count + fail_count, 1) * 100) AS success_rate
         FROM domain_patterns
         WHERE domain = :domain
           AND css_selector IS NOT NULL
           AND hit_count >= 2
         ORDER BY (hit_count - fail_count) DESC, last_success_at DESC
         LIMIT 1'
    );
    $stmt->execute([':domain' => $domain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int) $row['success_rate'] < 50) {
        return null;
    }

    return [
        'css_selector' => $row['css_selector'],
        'hit_count'    => (int) $row['hit_count'],
        'success_rate' => (int) $row['success_rate'],
    ];
}
