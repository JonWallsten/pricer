import {
    ChangeDetectionStrategy,
    Component,
    inject,
    signal,
    input,
    computed,
    OnInit,
    OnDestroy,
} from '@angular/core';
import { Router } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormArray, FormGroup, Validators } from '@angular/forms';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatChipsModule } from '@angular/material/chips';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSelectModule } from '@angular/material/select';
import { MatDialog } from '@angular/material/dialog';
import { ApiService } from '../../api.service';
import { classifyFetchError } from '../../fetch-error.util';
import { I18nService } from '../../i18n.service';
import {
    PreviewResult,
    PageInspectorData,
    ExtractionStrategy,
    DomainPatternSuggestion,
} from '../../models';

@Component({
    selector: 'app-product-form',
    templateUrl: './product-form.html',
    styleUrl: './product-form.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        ReactiveFormsModule,
        MatCardModule,
        MatFormFieldModule,
        MatInputModule,
        MatButtonModule,
        MatIconModule,
        MatProgressSpinnerModule,
        MatChipsModule,
        MatCheckboxModule,
        MatSelectModule,
    ],
})
export class ProductForm implements OnInit, OnDestroy {
    private readonly api = inject(ApiService);
    private readonly router = inject(Router);
    private readonly fb = inject(FormBuilder);
    private readonly dialog = inject(MatDialog);
    protected readonly i18n = inject(I18nService);

    /** Route param — set via withComponentInputBinding */
    readonly id = input<string>();

    protected readonly saving = signal(false);
    protected readonly errorMsg = signal('');
    protected readonly isEdit = signal(false);
    protected readonly priceWarning = signal(false);

    /** Per-URL preview state: index → preview data */
    protected readonly previews = signal<Map<number, PreviewResult>>(new Map());
    protected readonly fetchingIndexes = signal<Set<number>>(new Set());

    /** Per-URL domain pattern suggestions */
    protected readonly domainSuggestions = signal<Map<number, DomainPatternSuggestion>>(new Map());
    protected readonly dismissedSuggestions = signal<Set<number>>(new Set());

    /** Discount chip percentages */
    protected readonly discountOptions = [5, 10, 25, 50] as const;
    protected readonly selectedDiscount = signal<number | null>(10);
    protected readonly notifyBackInStock = signal(false);
    protected readonly alertTargetPrice = signal<number | null>(null);
    protected readonly renotifyDropAmount = signal<number | null>(null);

    protected readonly form = this.fb.nonNullable.group({
        name: ['', Validators.required],
        urls: this.fb.array<FormGroup>([]),
    });

    get urlsArray(): FormArray<FormGroup> {
        return this.form.controls.urls;
    }

    /** First preview with a valid price — used for discount chips */
    protected readonly firstPreviewPrice = computed(() => {
        const map = this.previews();
        for (const [, p] of map) {
            if (p?.price) return p;
        }
        return null;
    });

    /** Target price computed from first preview price and selected discount */
    protected readonly targetPrice = computed(() => {
        const p = this.firstPreviewPrice();
        const d = this.selectedDiscount();
        if (!p?.price || d === null) return null;
        return Math.round(p.price * (1 - d / 100));
    });

    protected readonly displayedAlertTargetPrice = computed(
        () => this.alertTargetPrice() ?? this.targetPrice(),
    );

    private urlDebounceTimers = new Map<number, ReturnType<typeof setTimeout>>();
    private urlSubscriptions: (() => void)[] = [];

    /** Existing URL IDs when editing — needed for diff on save */
    private existingUrlIds = new Map<number, number>(); // formIndex → url row id

    async ngOnInit() {
        const productId = this.id();
        if (productId && productId !== 'new') {
            this.isEdit.set(true);
            try {
                const { product, urls } = await this.api.getProduct(Number(productId));
                this.form.patchValue({ name: product.name });

                // Populate URL rows from existing product_urls
                for (const u of urls) {
                    const idx = this.urlsArray.length;
                    this.addUrlRow(u.url, u.css_selector ?? '', u.extraction_strategy ?? 'auto');
                    this.existingUrlIds.set(idx, u.id);
                }
            } catch {
                this.router.navigate(['/']);
            }
        } else {
            // Start with one empty URL row for new products
            this.addUrlRow();
        }
    }

    ngOnDestroy() {
        for (const timer of this.urlDebounceTimers.values()) {
            clearTimeout(timer);
        }
        for (const unsub of this.urlSubscriptions) {
            unsub();
        }
    }

    addUrlRow(url = '', cssSelector = '', extractionStrategy: ExtractionStrategy = 'auto') {
        const group = this.fb.nonNullable.group({
            url: [url, [Validators.required, Validators.pattern(/^https?:\/\/.+/)]],
            css_selector: [cssSelector],
            extraction_strategy: [extractionStrategy],
        });

        this.urlsArray.push(group);
        const idx = this.urlsArray.length - 1;

        // Watch URL field for auto-preview on new products
        if (!this.isEdit()) {
            const sub = group.controls.url.valueChanges.subscribe((val) => {
                const timer = this.urlDebounceTimers.get(idx);
                if (timer) clearTimeout(timer);
                if (val && /^https?:\/\/.+/.test(val)) {
                    this.urlDebounceTimers.set(
                        idx,
                        setTimeout(() => this.fetchPreview(idx), 800),
                    );
                } else {
                    this.previews.update((m) => {
                        const next = new Map(m);
                        next.delete(idx);
                        return next;
                    });
                }
            });
            this.urlSubscriptions.push(() => sub.unsubscribe());
        }
    }

    removeUrlRow(index: number) {
        this.urlsArray.removeAt(index);
        // Clean up preview and fetching state
        this.previews.update((m) => {
            const next = new Map(m);
            next.delete(index);
            // Re-key entries above this index
            const reKeyed = new Map<number, PreviewResult>();
            for (const [k, v] of next) {
                reKeyed.set(k > index ? k - 1 : k, v);
            }
            return reKeyed;
        });
        this.fetchingIndexes.update((s) => {
            const next = new Set<number>();
            for (const k of s) {
                if (k === index) continue;
                next.add(k > index ? k - 1 : k);
            }
            return next;
        });
        // Re-key existing URL IDs
        const updated = new Map<number, number>();
        for (const [k, v] of this.existingUrlIds) {
            if (k === index) continue;
            updated.set(k > index ? k - 1 : k, v);
        }
        this.existingUrlIds = updated;
    }

    private async fetchPreview(index: number) {
        const group = this.urlsArray.at(index);
        if (!group) return;
        const url = group.controls['url'].value;
        const cssSelector = group.controls['css_selector'].value || null;
        const extractionStrategy =
            (group.controls['extraction_strategy'].value as string) || 'auto';
        if (!url) return;

        this.fetchingIndexes.update((s) => new Set(s).add(index));
        this.previews.update((m) => {
            const next = new Map(m);
            next.delete(index);
            return next;
        });

        // Fetch domain pattern suggestion in parallel
        this.fetchDomainSuggestion(index, url);

        try {
            const result = await this.api.previewUrl(url, cssSelector, extractionStrategy);
            this.previews.update((m) => new Map(m).set(index, result));
            this.priceWarning.set(false);

            // Auto-fill name from first URL's page title if name is empty
            if (index === 0 && !this.form.controls.name.value && result.page_title) {
                this.form.controls.name.setValue(result.page_title);
            }
        } catch {
            // Ignore preview errors
        } finally {
            this.fetchingIndexes.update((s) => {
                const next = new Set(s);
                next.delete(index);
                return next;
            });
        }
    }

    selectDiscount(pct: number) {
        this.selectedDiscount.set(this.selectedDiscount() === pct ? null : pct);
        this.alertTargetPrice.set(null);
    }

    protected onAlertTargetInput(rawValue: string) {
        const value = this.parseOptionalAmount(rawValue);
        this.alertTargetPrice.set(value);

        if (value === null) {
            this.selectedDiscount.set(null);
            return;
        }

        this.selectedDiscount.set(this.findMatchingDiscount(value));
    }

    protected onRenotifyDropInput(rawValue: string) {
        this.renotifyDropAmount.set(this.parseOptionalAmount(rawValue));
    }

    private parseOptionalAmount(rawValue: string): number | null {
        const value = rawValue.trim();
        if (value === '') return null;

        const parsed = Number(value);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    private findMatchingDiscount(targetPrice: number): number | null {
        const previewPrice = this.firstPreviewPrice()?.price;
        if (!previewPrice) return null;

        return (
            this.discountOptions.find(
                (pct) => Math.round(previewPrice * (1 - pct / 100)) === Math.round(targetPrice),
            ) ?? null
        );
    }

    formatSelectorOnBlur(index: number) {
        const group = this.urlsArray.at(index);
        if (!group) return;
        const val = (group.controls['css_selector'].value as string).trim();
        if (!val) return;

        let formatted = val;
        if (
            /^[a-z][a-z0-9-]*$/i.test(formatted) &&
            !formatted.startsWith('.') &&
            !formatted.startsWith('#')
        ) {
            formatted = `[${formatted}]`;
        } else if (/^[a-z][a-z0-9-]*=.+$/i.test(formatted) && !formatted.startsWith('[')) {
            formatted = `[${formatted}]`;
        }

        if (formatted !== val) {
            group.controls['css_selector'].setValue(formatted);
        }
    }

    private async fetchDomainSuggestion(index: number, url: string) {
        try {
            const suggestion = await this.api.getDomainPattern(url);
            if (!suggestion.suggested_selector && !suggestion.suggested_method) return;

            this.domainSuggestions.update((m) => new Map(m).set(index, suggestion));
        } catch {
            // Domain pattern lookup is best-effort
        }
    }

    protected applySuggestion(index: number) {
        const suggestion = this.domainSuggestions().get(index);
        if (!suggestion?.suggested_selector) return;

        const group = this.urlsArray.at(index);
        if (!group) return;

        group.controls['css_selector'].setValue(suggestion.suggested_selector);
        this.dismissedSuggestions.update((s) => {
            const next = new Set(s);
            next.delete(index);
            return next;
        });
        this.fetchPreview(index);
    }

    protected dismissSuggestion(index: number) {
        this.dismissedSuggestions.update((s) => new Set(s).add(index));
    }

    async save() {
        if (this.form.invalid) return;
        this.errorMsg.set('');

        // Pre-save validation: block if any URL has no extracted price (new products only)
        if (!this.isEdit() && !this.priceWarning()) {
            const previews = this.previews();
            const urlCount = this.urlsArray.length;
            let hasFailure = false;

            for (let i = 0; i < urlCount; i++) {
                const preview = previews.get(i);
                if (!preview || preview.price === null || preview.price === undefined) {
                    hasFailure = true;
                    break;
                }
            }

            if (hasFailure) {
                this.priceWarning.set(true);
                return;
            }
        }

        this.saving.set(true);
        this.priceWarning.set(false);

        const val = this.form.getRawValue();
        const urls = val.urls.map((u, i) => {
            const entry: {
                id?: number;
                url: string;
                css_selector: string | null;
                extraction_strategy: string;
            } = {
                url: u['url'] as string,
                css_selector: (u['css_selector'] as string) || null,
                extraction_strategy: (u['extraction_strategy'] as string) || 'auto',
            };
            const existingId = this.existingUrlIds.get(i);
            if (existingId) entry.id = existingId;
            return entry;
        });

        try {
            if (this.isEdit()) {
                await this.api.updateProduct(Number(this.id()), { name: val.name, urls });
                this.router.navigate(['/products', this.id()]);
            } else {
                const product = await this.api.createProduct({ name: val.name, urls });

                // Auto-create alert if a discount is selected and we have a target price
                const target = this.displayedAlertTargetPrice();
                if (target !== null && target > 0) {
                    await this.api.createAlert(
                        product.id,
                        target,
                        this.notifyBackInStock(),
                        this.renotifyDropAmount(),
                    );
                }

                this.router.navigate(['/products', product.id]);
            }
        } catch (e) {
            this.errorMsg.set(e instanceof Error ? e.message : this.i18n.strings().error);
        } finally {
            this.saving.set(false);
        }
    }

    formatPrice(price: number, currency?: string | null): string {
        return new Intl.NumberFormat(this.i18n.locale(), {
            style: 'currency',
            currency: currency || 'SEK',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(price);
    }

    getFriendlyFetchError(error: string | null | undefined): string {
        const s = this.i18n.strings();
        switch (classifyFetchError(error)) {
            case 'cloudflare':
                return s.siteBlockedByCloudflare;
            case 'blocked':
                return s.siteBlockedByStore;
            case 'rate_limited':
                return s.siteRateLimited;
            case 'fetch_failed':
                return s.siteFetchFailed;
            default:
                return error || s.error;
        }
    }

    goBack() {
        if (this.isEdit()) {
            this.router.navigate(['/products', this.id()]);
        } else {
            this.router.navigate(['/']);
        }
    }

    async openSelectorPicker(index: number) {
        const group = this.urlsArray.at(index);
        if (!group) return;
        const url = group.controls['url'].value;
        if (!url) return;

        const { PageInspectorDialog } =
            await import('../../components/page-inspector-dialog/page-inspector-dialog');

        const data: PageInspectorData = {
            interactionMode: 'pick',
            url,
            cssSelector: group.controls['css_selector'].value || undefined,
        };

        const ref = this.dialog.open(PageInspectorDialog, {
            data,
            width: '95vw',
            maxWidth: '1400px',
            height: '85vh',
            panelClass: 'page-inspector-panel',
        });

        ref.afterClosed().subscribe((selector: string | null) => {
            if (selector) {
                group.controls['css_selector'].setValue(selector);
                this.fetchPreview(index);
            }
        });
    }
}
