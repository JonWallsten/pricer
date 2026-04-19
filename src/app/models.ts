export interface User {
    id: number;
    email: string;
    name: string;
    picture_url: string | null;
    is_approved: boolean;
    is_admin: boolean;
}

export interface AdminUser {
    id: number;
    email: string;
    name: string;
    picture_url: string | null;
    is_approved: boolean;
    is_admin: boolean;
    created_at: string;
    last_login_at: string | null;
}

export type Availability = 'in_stock' | 'out_of_stock' | 'preorder' | 'unknown';

export interface ProductUrl {
    id: number;
    product_id: number;
    url: string;
    css_selector: string | null;
    extraction_strategy: ExtractionStrategy;
    current_price: number | null;
    regular_price: number | null;
    previous_lowest_price: number | null;
    is_campaign: boolean;
    campaign_type: string | null;
    campaign_label: string | null;
    currency: string;
    image_url: string | null;
    availability: Availability;
    last_checked_at: string | null;
    last_check_status: 'pending' | 'success' | 'error' | null;
    last_check_error: string | null;
    created_at: string;
}

export type ExtractionStrategy = 'auto' | 'selector';

export interface Product {
    id: number;
    user_id: number;
    name: string;
    url: string;
    css_selector: string | null;
    image_url: string | null;
    availability: Availability;
    current_price: number | null;
    regular_price: number | null;
    previous_lowest_price: number | null;
    is_campaign: boolean;
    campaign_type: string | null;
    campaign_label: string | null;
    currency: string;
    last_checked_at: string | null;
    last_check_status: 'pending' | 'success' | 'error';
    last_check_error: string | null;
    created_at: string;
    updated_at: string;
    active_alerts?: number;
    urls_count?: number;
    urls?: ProductUrl[];
}

export interface Alert {
    id: number;
    product_id: number;
    user_id: number;
    target_price: number;
    is_active: boolean;
    notify_back_in_stock: boolean;
    renotify_drop_amount: number | null;
    last_notified_price: number | null;
    last_notified_at: string | null;
    created_at: string;
}

export interface ExtractionResult {
    price: number | null;
    currency: string | null;
    method: string | null;
    error: string | null;
    image_url: string | null;
    confidence?: string | null;
    debug_source?: string | null;
    debug_path?: string | null;
    warnings?: string[];
    regular_price?: number | null;
    previous_lowest_price?: number | null;
    is_campaign?: boolean;
    campaign_type?: string | null;
    campaign_label?: string | null;
}

export interface PreviewResult extends ExtractionResult {
    availability: Availability;
    page_title: string | null;
}

export interface PriceHistoryEntry {
    price: number;
    currency: string;
    recorded_at: string;
}

export type MatchConfidenceLabel = 'very_likely' | 'likely' | 'possible' | 'weak';

export interface ProductMatchBreakdown {
    total: number;
    titleSimilarity?: number;
    brandMatch?: number;
    gtinMatch?: number;
    mpnMatch?: number;
    skuMatch?: number;
    modelMatch?: number;
    dimensionsMatch?: number;
    colorMatch?: number;
    categoryMatch?: number;
    priceProximity?: number;
    penalties?: number[];
    reasons: string[];
}

export interface ProductMatchCandidate {
    id: number;
    source_product_id: number;
    candidate_url: string;
    candidate_domain: string;
    candidate_title: string | null;
    candidate_price: number | null;
    candidate_currency: string | null;
    confidence_score: number;
    confidence_label: MatchConfidenceLabel;
    reasons: string[];
    breakdown: ProductMatchBreakdown;
    extracted_brand: string | null;
    extracted_model: string | null;
    extracted_mpn: string | null;
    extracted_gtin: string | null;
    extracted_sku: string | null;
    extracted_color: string | null;
    extracted_size: string | null;
    extracted_dimensions: string[];
    image_url: string | null;
    availability: Availability;
    query_used: string | null;
    serp_position: number | null;
    last_searched_at: string;
    last_fetched_at: string | null;
    excluded: boolean;
}

export interface ProductMatchDiscoveryResponse {
    source?: Record<string, unknown>;
    queries: string[];
    searches_run: number;
    matches: ProductMatchCandidate[];
}

// ─── Page Inspector ──────────────────────────────────────

export interface SelectorMatchPreview {
    tag: string;
    textSnippet: string;
    classSnippet: string;
    attributeSnippet: string;
}

export interface PageSourceResponse {
    html: string;
    base_url: string;
    js_rendering_likely: boolean;
    js_rendering_confidence: 'low' | 'medium' | 'high';
    js_hints: string[];
    page_quality_warnings: string[];
    selector_valid: boolean;
    selector_error: string | null;
    selector_match_count: number;
    selector_matches: SelectorMatchPreview[];
    price_candidates: PriceCandidate[];
    price_matches: PriceCandidate[];
    product_context?: MainProductContext;
    campaign?: CampaignInfo | null;
}

export type PriceCandidateConfidence = 'high' | 'medium' | 'low';

export type PriceRole =
    | 'current'
    | 'regular'
    | 'campaign'
    | 'previous_lowest'
    | 'unit'
    | 'from'
    | 'member'
    | 'unknown';

export interface PriceCandidate {
    sourceType:
        | 'dom'
        | 'jsonld'
        | 'script_pattern'
        | 'meta'
        | 'microdata'
        | 'css_selector'
        | 'platform_structured'
        | 'platform_dom';
    patternType?: string | null;
    label: string;
    valueRaw: unknown;
    valueFormatted?: string;
    numericValue: number;
    currency?: string | null;
    path?: string;
    confidence: PriceCandidateConfidence;
    reasons: string[];
    priceRole?: PriceRole;
    productAssociationScore?: number;
    productAssociationReasons?: string[];
}

export interface CampaignInfo {
    type: string;
    label: string | null;
    regular_price: number | null;
    previous_lowest_price: number | null;
    campaign_price: number;
    savings: number | null;
    savings_pct: number | null;
}

export interface MainProductContext {
    title: string | null;
    sku: string | null;
    gtin: string | null;
    brand: string | null;
    image: string | null;
    url: string;
    identifiers: string[];
    confidence: number;
    reasons: string[];
}

export interface DomainPattern {
    extraction_method: string;
    pattern_type: string | null;
    css_selector: string | null;
    debug_path: string | null;
    hit_count: number;
    fail_count: number;
    success_rate: number;
    last_success_at: string;
}

export interface DomainPatternSuggestion {
    patterns: DomainPattern[];
    suggested_selector: string | null;
    suggested_method: string | null;
    hit_count: number;
    success_rate: number;
}

export interface PageInspectorData {
    interactionMode: 'pick' | 'debug';
    url: string;
    cssSelector?: string;
}

export interface SelectorCandidate {
    selector: string;
    matchCount: number;
    stabilityLabel: 'recommended' | 'fallback' | 'fragile';
    stabilityScore: number;
    stabilityReasons: string[];
}
