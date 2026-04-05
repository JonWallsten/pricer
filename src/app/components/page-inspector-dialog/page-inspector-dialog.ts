import {
    ChangeDetectionStrategy,
    Component,
    inject,
    signal,
    computed,
    OnInit,
    OnDestroy,
    ElementRef,
    viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogRef, MatDialogModule } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTooltipModule } from '@angular/material/tooltip';
import { ApiService } from '../../api.service';
import { I18nService } from '../../i18n.service';
import {
    PageInspectorData,
    PageSourceResponse,
    SelectorCandidate,
    PriceCandidate,
    CampaignInfo,
    MainProductContext,
} from '../../models';

@Component({
    selector: 'app-page-inspector-dialog',
    templateUrl: './page-inspector-dialog.html',
    styleUrl: './page-inspector-dialog.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        FormsModule,
        MatDialogModule,
        MatButtonModule,
        MatIconModule,
        MatFormFieldModule,
        MatInputModule,
        MatProgressSpinnerModule,
        MatTooltipModule,
    ],
})
export class PageInspectorDialog implements OnInit, OnDestroy {
    private readonly api = inject(ApiService);
    private readonly dialogRef = inject(MatDialogRef<PageInspectorDialog>);
    readonly data = inject<PageInspectorData>(MAT_DIALOG_DATA);
    protected readonly i18n = inject(I18nService);

    private readonly previewFrame = viewChild<ElementRef<HTMLIFrameElement>>('previewFrame');

    protected readonly loading = signal(true);
    protected readonly error = signal<string | null>(null);
    protected readonly pageData = signal<PageSourceResponse | null>(null);
    protected readonly selectorInput = signal(this.data.cssSelector ?? '');
    protected readonly candidates = signal<SelectorCandidate[]>([]);
    protected readonly selectedSelector = signal<string | null>(null);
    protected readonly jsWarningExpanded = signal(false);
    protected readonly findPriceInput = signal('');
    protected readonly findPriceLoading = signal(false);
    protected readonly priceMatches = signal<PriceCandidate[]>([]);

    private messageHandler: ((e: MessageEvent) => void) | null = null;

    protected readonly isPick = computed(() => this.data.interactionMode === 'pick');
    protected readonly isDebug = computed(() => this.data.interactionMode === 'debug');

    protected readonly modeBadge = computed(() =>
        this.isPick() ? this.i18n.strings().pickSelector : this.i18n.strings().debugExtraction,
    );

    protected readonly domain = computed(() => {
        try {
            return new URL(this.data.url).hostname;
        } catch {
            return this.data.url;
        }
    });

    protected readonly matchStatusText = computed(() => {
        const pd = this.pageData();
        if (!pd) return '';
        if (!pd.selector_valid && pd.selector_error) {
            return `${this.i18n.strings().invalidSelector}: ${pd.selector_error}`;
        }
        if (pd.selector_match_count === 0) {
            return this.i18n.strings().noSelectorMatch;
        }
        return this.i18n
            .strings()
            .selectorMatches.replace('{count}', String(pd.selector_match_count));
    });

    protected readonly hasSelector = computed(() => this.selectorInput().trim() !== '');

    protected readonly priceCandidates = computed(() => this.pageData()?.price_candidates ?? []);
    protected readonly productContext = computed(() => this.pageData()?.product_context ?? null);
    protected readonly campaignInfo = computed(() => this.pageData()?.campaign ?? null);

    protected readonly hasFindPriceInput = computed(() => {
        const val = this.findPriceInput().trim();
        return val !== '' && !isNaN(parseFloat(val));
    });

    ngOnInit() {
        this.setupMessageListener();
        this.loadPage();
    }

    ngOnDestroy() {
        if (this.messageHandler) {
            window.removeEventListener('message', this.messageHandler);
        }
    }

    protected async loadPage() {
        this.loading.set(true);
        this.error.set(null);

        try {
            const selector = this.selectorInput().trim() || undefined;
            const data = await this.api.fetchPageSource(this.data.url, selector);
            this.pageData.set(data);
            // Allow a tick for the iframe to render
            setTimeout(() => this.injectInspectorScript(), 50);
        } catch (e) {
            this.error.set(e instanceof Error ? e.message : 'Failed to load page');
        } finally {
            this.loading.set(false);
        }
    }

    protected async testSelector() {
        const selector = this.selectorInput().trim();
        if (!selector) return;

        this.loading.set(true);
        try {
            const data = await this.api.fetchPageSource(this.data.url, selector);
            this.pageData.set(data);
            setTimeout(() => this.injectInspectorScript(), 50);
        } catch (e) {
            this.error.set(e instanceof Error ? e.message : 'Failed to test selector');
        } finally {
            this.loading.set(false);
        }
    }

    protected selectCandidate(candidate: SelectorCandidate) {
        this.selectorInput.set(candidate.selector);
        this.selectedSelector.set(candidate.selector);
        // Highlight in iframe
        this.sendToIframe({ type: 'highlight-selector', selector: candidate.selector });
    }

    protected useSelector() {
        const sel = this.selectedSelector() ?? this.selectorInput().trim();
        if (sel) {
            this.dialogRef.close(sel);
        }
    }

    protected copySelector() {
        const sel = this.selectedSelector() ?? this.selectorInput().trim();
        if (sel) {
            navigator.clipboard.writeText(sel);
        }
    }

    protected close() {
        this.dialogRef.close(null);
    }

    protected toggleJsWarning() {
        this.jsWarningExpanded.update((v) => !v);
    }

    protected async searchByPrice() {
        const val = parseFloat(this.findPriceInput().trim());
        if (isNaN(val) || val <= 0) return;

        this.findPriceLoading.set(true);
        this.priceMatches.set([]);

        try {
            const selector = this.selectorInput().trim() || undefined;
            const data = await this.api.fetchPageSource(this.data.url, selector, val);
            this.pageData.set(data);
            this.priceMatches.set(data.price_matches ?? []);
            setTimeout(() => this.injectInspectorScript(), 50);
        } catch {
            // Ignore errors — main loadPage handles that
        } finally {
            this.findPriceLoading.set(false);
        }
    }

    protected getSourceLabel(sourceType: string): string {
        const s = this.i18n.strings();
        switch (sourceType) {
            case 'jsonld':
                return s.sourceJsonLd;
            case 'script_pattern':
                return s.sourceScript;
            case 'dom':
                return s.sourceDom;
            case 'meta':
                return s.sourceMeta;
            case 'microdata':
                return s.sourceMicrodata;
            case 'css_selector':
                return s.sourceCssSelector;
            default:
                return sourceType;
        }
    }

    protected getConfidenceLabel(confidence: string): string {
        const s = this.i18n.strings();
        switch (confidence) {
            case 'high':
                return s.confidenceHigh;
            case 'medium':
                return s.confidenceMedium;
            case 'low':
                return s.confidenceLow;
            default:
                return confidence;
        }
    }

    protected getConfidenceIcon(confidence: string): string {
        switch (confidence) {
            case 'high':
                return 'verified';
            case 'medium':
                return 'check_circle';
            case 'low':
                return 'help';
            default:
                return 'help';
        }
    }

    protected getPriceRoleLabel(role: string | undefined): string {
        if (!role) return '';
        const s = this.i18n.strings();
        switch (role) {
            case 'current':
                return s.priceRoleCurrent;
            case 'regular':
                return s.priceRoleRegular;
            case 'campaign':
                return s.priceRoleCampaign;
            case 'previous_lowest':
                return s.priceRolePreviousLowest;
            case 'unit':
                return s.priceRoleUnit;
            case 'from':
                return s.priceRoleFrom;
            case 'member':
                return s.priceRoleMember;
            default:
                return s.priceRoleUnknown;
        }
    }

    protected getStabilityIcon(label: string): string {
        switch (label) {
            case 'recommended':
                return 'verified';
            case 'fallback':
                return 'swap_horiz';
            case 'fragile':
                return 'warning';
            default:
                return 'help';
        }
    }

    protected getStabilityLabel(label: string): string {
        switch (label) {
            case 'recommended':
                return this.i18n.strings().selectorRecommended;
            case 'fallback':
                return this.i18n.strings().selectorFallback;
            case 'fragile':
                return this.i18n.strings().selectorFragile;
            default:
                return label;
        }
    }

    private setupMessageListener() {
        this.messageHandler = (e: MessageEvent) => {
            // Only accept messages from our iframe (srcdoc has null origin)
            if (e.data?.source !== 'page-inspector') return;

            if (e.data.type === 'element-picked' && this.isPick()) {
                const pickedCandidates: SelectorCandidate[] = (e.data.candidates ?? []).map(
                    (c: {
                        selector: string;
                        matchCount: number;
                        stabilityLabel: string;
                        stabilityScore: number;
                        stabilityReasons: string[];
                    }) => ({
                        selector: c.selector,
                        matchCount: c.matchCount,
                        stabilityLabel: c.stabilityLabel as SelectorCandidate['stabilityLabel'],
                        stabilityScore: c.stabilityScore,
                        stabilityReasons: c.stabilityReasons,
                    }),
                );
                this.candidates.set(pickedCandidates);

                // Auto-select the first recommended candidate
                const best =
                    pickedCandidates.find((c) => c.stabilityLabel === 'recommended') ??
                    pickedCandidates[0];
                if (best) {
                    this.selectCandidate(best);
                }
            }
        };
        window.addEventListener('message', this.messageHandler);
    }

    private injectInspectorScript() {
        const iframe = this.previewFrame()?.nativeElement;
        if (!iframe) return;

        const iframeDoc = iframe.contentDocument ?? iframe.contentWindow?.document;
        if (!iframeDoc) return;

        const nonce = this.generateNonce();
        const mode = this.data.interactionMode;
        const selector = this.selectorInput().trim();

        // Inject CSP meta tag
        const csp = iframeDoc.createElement('meta');
        csp.setAttribute('http-equiv', 'Content-Security-Policy');
        csp.setAttribute(
            'content',
            `default-src 'none'; style-src 'unsafe-inline' * ; img-src * data:; font-src *; script-src 'nonce-${nonce}';`,
        );
        iframeDoc.head?.prepend(csp);

        // Inject inspector script
        const script = iframeDoc.createElement('script');
        script.setAttribute('nonce', nonce);
        script.textContent = this.buildInspectorScript(mode, selector);
        iframeDoc.body?.appendChild(script);
    }

    private buildInspectorScript(mode: string, selector: string): string {
        return `(function() {
    'use strict';
    var HIGHLIGHT_CLASS = '__pi-highlight';
    var HOVER_CLASS = '__pi-hover';

    // Inject highlight styles
    var style = document.createElement('style');
    style.textContent = [
        '.' + HIGHLIGHT_CLASS + '{outline:2px solid #1976d2 !important;outline-offset:1px;background:rgba(25,118,210,0.08) !important;}',
        '.' + HOVER_CLASS + '{outline:2px dashed #ff9800 !important;outline-offset:1px;background:rgba(255,152,0,0.1) !important;cursor:crosshair !important;}',
    ].join('\\n');
    document.head.appendChild(style);

    // Block all navigation
    document.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); }, true);
    document.addEventListener('submit', function(e) { e.preventDefault(); e.stopPropagation(); }, true);

    var lastHovered = null;

    ${
        mode === 'pick'
            ? `
    // Pick mode: hover highlight + click-to-inspect
    document.addEventListener('mouseover', function(e) {
        if (lastHovered) lastHovered.classList.remove(HOVER_CLASS);
        var el = e.target;
        if (el && el !== document.body && el !== document.documentElement) {
            el.classList.add(HOVER_CLASS);
            lastHovered = el;
        }
    }, true);

    document.addEventListener('mouseout', function(e) {
        if (e.target && e.target.classList) e.target.classList.remove(HOVER_CLASS);
    }, true);

    document.addEventListener('click', function(e) {
        var el = e.target;
        if (!el || el === document.body || el === document.documentElement) return;
        if (lastHovered) lastHovered.classList.remove(HOVER_CLASS);
        var candidates = generateCandidates(el);
        window.parent.postMessage({
            source: 'page-inspector',
            type: 'element-picked',
            candidates: candidates,
        }, '*');
    }, false);
    `
            : ''
    }

    ${
        mode === 'debug' && selector
            ? `
    // Debug mode: highlight matching elements
    highlightSelector(${JSON.stringify(selector)});
    `
            : ''
    }

    // Listen for selector changes from parent
    window.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'highlight-selector') {
            clearHighlights();
            if (e.data.selector) highlightSelector(e.data.selector);
        }
    });

    function highlightSelector(sel) {
        try {
            var nodes = document.querySelectorAll(sel);
            for (var i = 0; i < nodes.length; i++) {
                nodes[i].classList.add(HIGHLIGHT_CLASS);
            }
        } catch(ex) { /* invalid selector */ }
    }

    function clearHighlights() {
        var highlighted = document.querySelectorAll('.' + HIGHLIGHT_CLASS);
        for (var i = 0; i < highlighted.length; i++) {
            highlighted[i].classList.remove(HIGHLIGHT_CLASS);
        }
    }

    function generateCandidates(el) {
        var candidates = [];
        var seen = {};

        // 1. Semantic attributes (itemprop, data-price*, data-amount, etc.)
        var semanticAttrs = ['itemprop', 'data-price', 'data-price-type', 'data-amount', 'data-product-price'];
        for (var i = 0; i < semanticAttrs.length; i++) {
            var a = semanticAttrs[i];
            if (el.hasAttribute(a)) {
                var val = el.getAttribute(a);
                var sel = el.tagName.toLowerCase() + '[' + a + '="' + val + '"]';
                addCandidate(candidates, seen, sel, 'recommended', 90, ['Semantic product attribute']);
            }
        }

        // Also check parent for itemprop (e.g. <span> inside <div itemprop="price">)
        if (el.parentElement && el.parentElement.hasAttribute('itemprop')) {
            var pVal = el.parentElement.getAttribute('itemprop');
            var pSel = el.parentElement.tagName.toLowerCase() + '[itemprop="' + pVal + '"] > ' + el.tagName.toLowerCase();
            addCandidate(candidates, seen, pSel, 'recommended', 85, ['Semantic parent attribute']);
        }

        // 2. ID-based
        if (el.id && !looksGenerated(el.id)) {
            addCandidate(candidates, seen, '#' + CSS.escape(el.id), 'recommended', 88, ['Unique ID']);
        }

        // 3. Semantic/meaningful class selectors
        var classes = getStableClasses(el);
        if (classes.length > 0) {
            var clsSel = el.tagName.toLowerCase() + '.' + classes.slice(0, 2).map(function(c){return CSS.escape(c);}).join('.');
            var count = document.querySelectorAll(clsSel).length;
            if (count === 1) {
                addCandidate(candidates, seen, clsSel, 'recommended', 75, ['Unique class combination']);
            } else if (count <= 5) {
                addCandidate(candidates, seen, clsSel, 'fallback', 55, ['Class selector, ' + count + ' matches']);
            }

            // Class-only
            var clsOnly = '.' + classes.slice(0, 2).map(function(c){return CSS.escape(c);}).join('.');
            var countOnly = document.querySelectorAll(clsOnly).length;
            if (countOnly === 1 && !seen[clsOnly]) {
                addCandidate(candidates, seen, clsOnly, 'recommended', 72, ['Unique class']);
            }
        }

        // 4. Short ancestor chain (max 3 levels)
        var chain = buildAncestorChain(el, 3);
        if (chain && !seen[chain]) {
            var chainCount = 0;
            try { chainCount = document.querySelectorAll(chain).length; } catch(ex) {}
            if (chainCount === 1) {
                addCandidate(candidates, seen, chain, 'fallback', 50, ['Ancestor chain']);
            } else if (chainCount <= 3) {
                addCandidate(candidates, seen, chain, 'fragile', 30, ['Ancestor chain, ' + chainCount + ' matches']);
            }
        }

        // 5. nth-child fallback
        var nthSel = buildNthChildSelector(el);
        if (nthSel && !seen[nthSel]) {
            addCandidate(candidates, seen, nthSel, 'fragile', 15, ['Positional selector']);
        }

        // Sort by stability score descending
        candidates.sort(function(a, b) { return b.stabilityScore - a.stabilityScore; });

        return candidates.slice(0, 5);
    }

    function addCandidate(list, seen, selector, label, score, reasons) {
        if (seen[selector]) return;
        seen[selector] = true;
        var count = 0;
        try { count = document.querySelectorAll(selector).length; } catch(ex) { return; }
        list.push({
            selector: selector,
            matchCount: count,
            stabilityLabel: label,
            stabilityScore: score,
            stabilityReasons: reasons,
        });
    }

    function getStableClasses(el) {
        if (!el.className || typeof el.className !== 'string') return [];
        return el.className.split(/\\s+/).filter(function(c) {
            return c && !looksGenerated(c) && c.length > 1 && c.length < 40;
        });
    }

    function looksGenerated(name) {
        // Hashed/framework-generated class patterns
        if (/^[a-z]{1,3}[A-Z][a-zA-Z0-9]{4,}$/.test(name)) return true;  // CSS modules
        if (/^_[a-zA-Z0-9]{6,}$/.test(name)) return true;
        if (/^css-[a-z0-9]+$/i.test(name)) return true;  // emotion/styled
        if (/^sc-[a-zA-Z]/.test(name)) return true;  // styled-components
        if (/^[a-z0-9]{20,}$/.test(name)) return true;  // long hashes
        if (/^jsx-[0-9]+$/.test(name)) return true;
        return false;
    }

    function buildAncestorChain(el, maxDepth) {
        var parts = [];
        var current = el;
        for (var d = 0; d < maxDepth && current && current !== document.body; d++) {
            var part = current.tagName.toLowerCase();
            var stableClasses = getStableClasses(current);
            if (stableClasses.length > 0) {
                part += '.' + CSS.escape(stableClasses[0]);
            }
            parts.unshift(part);
            current = current.parentElement;
        }
        return parts.length > 1 ? parts.join(' > ') : null;
    }

    function buildNthChildSelector(el) {
        if (!el.parentElement) return null;
        var children = el.parentElement.children;
        for (var i = 0; i < children.length; i++) {
            if (children[i] === el) {
                var parentPart = '';
                var pClasses = getStableClasses(el.parentElement);
                if (pClasses.length > 0) {
                    parentPart = el.parentElement.tagName.toLowerCase() + '.' + CSS.escape(pClasses[0]);
                } else {
                    parentPart = el.parentElement.tagName.toLowerCase();
                }
                return parentPart + ' > ' + el.tagName.toLowerCase() + ':nth-child(' + (i + 1) + ')';
            }
        }
        return null;
    }
})();`;
    }

    private sendToIframe(message: Record<string, unknown>) {
        const iframe = this.previewFrame()?.nativeElement;
        iframe?.contentWindow?.postMessage(message, '*');
    }

    private generateNonce(): string {
        const arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        return Array.from(arr, (b) => b.toString(16).padStart(2, '0')).join('');
    }
}
