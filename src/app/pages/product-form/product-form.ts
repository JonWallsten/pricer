import {
    ChangeDetectionStrategy,
    Component,
    inject,
    signal,
    input,
    computed,
    OnInit,
    OnDestroy,
    effect,
    untracked,
} from '@angular/core';
import { Router } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatChipsModule } from '@angular/material/chips';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { ApiService } from '../../api.service';
import { I18nService } from '../../i18n.service';
import { PreviewResult } from '../../models';

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
    ],
})
export class ProductForm implements OnInit, OnDestroy {
    private readonly api = inject(ApiService);
    private readonly router = inject(Router);
    private readonly fb = inject(FormBuilder);
    protected readonly i18n = inject(I18nService);

    /** Route param — set via withComponentInputBinding */
    readonly id = input<string>();

    protected readonly saving = signal(false);
    protected readonly fetching = signal(false);
    protected readonly preview = signal<PreviewResult | null>(null);
    protected readonly errorMsg = signal('');
    protected readonly isEdit = signal(false);

    /** Discount chip percentages */
    protected readonly discountOptions = [5, 10, 25, 50] as const;
    protected readonly selectedDiscount = signal<number | null>(10);
    protected readonly notifyBackInStock = signal(false);

    protected readonly form = this.fb.nonNullable.group({
        name: ['', Validators.required],
        url: ['', [Validators.required, Validators.pattern(/^https?:\/\/.+/)]],
        css_selector: [''],
    });

    /** Target price computed from preview price and selected discount */
    protected readonly targetPrice = computed(() => {
        const p = this.preview();
        const d = this.selectedDiscount();
        if (!p?.price || d === null) return null;
        return Math.round(p.price * (1 - d / 100));
    });

    private urlDebounceTimer: ReturnType<typeof setTimeout> | null = null;
    private urlFetchEffect = effect(() => {
        // Re-read the signal that triggers this effect
        const currentPreview = this.preview();
        // We'll use manual URL watching instead (see ngOnInit)
        void currentPreview;
    });

    async ngOnInit() {
        const productId = this.id();
        if (productId && productId !== 'new') {
            this.isEdit.set(true);
            try {
                const { product } = await this.api.getProduct(Number(productId));
                this.form.patchValue({
                    name: product.name,
                    url: product.url,
                    css_selector: product.css_selector ?? '',
                });
            } catch {
                this.router.navigate(['/']);
            }
        }

        // Watch URL field for auto-preview (new products only)
        if (!this.isEdit()) {
            this.form.controls.url.valueChanges.subscribe((url) => {
                if (this.urlDebounceTimer) {
                    clearTimeout(this.urlDebounceTimer);
                }
                if (url && /^https?:\/\/.+/.test(url)) {
                    this.urlDebounceTimer = setTimeout(() => this.fetchPreview(), 800);
                } else {
                    this.preview.set(null);
                }
            });
        }
    }

    ngOnDestroy() {
        if (this.urlDebounceTimer) {
            clearTimeout(this.urlDebounceTimer);
        }
    }

    private async fetchPreview() {
        const url = this.form.controls.url.value;
        const cssSelector = this.form.controls.css_selector.value || null;
        if (!url) return;

        this.fetching.set(true);
        this.preview.set(null);

        try {
            const result = await this.api.previewUrl(url, cssSelector);
            this.preview.set(result);

            // Auto-fill name from page title if name is empty
            if (!this.form.controls.name.value && result.page_title) {
                this.form.controls.name.setValue(result.page_title);
            }
        } catch {
            this.preview.set(null);
        } finally {
            this.fetching.set(false);
        }
    }

    selectDiscount(pct: number) {
        this.selectedDiscount.set(this.selectedDiscount() === pct ? null : pct);
    }

    formatSelectorOnBlur() {
        const val = this.form.controls.css_selector.value.trim();
        if (!val) return;

        let formatted = val;
        // Bare attribute like data-price → [data-price]
        if (
            /^[a-z][a-z0-9-]*$/i.test(formatted) &&
            !formatted.startsWith('.') &&
            !formatted.startsWith('#')
        ) {
            formatted = `[${formatted}]`;
        }
        // attribute=value like data-price='test' → [data-price='test']
        else if (/^[a-z][a-z0-9-]*=.+$/i.test(formatted) && !formatted.startsWith('[')) {
            formatted = `[${formatted}]`;
        }

        if (formatted !== val) {
            this.form.controls.css_selector.setValue(formatted);
        }
    }

    async save() {
        if (this.form.invalid) return;
        this.saving.set(true);
        this.errorMsg.set('');

        const val = this.form.getRawValue();
        const body = {
            name: val.name,
            url: val.url,
            css_selector: val.css_selector || null,
        };

        try {
            if (this.isEdit()) {
                await this.api.updateProduct(Number(this.id()), body);
                this.router.navigate(['/products', this.id()]);
            } else {
                const product = await this.api.createProduct(body);

                // Auto-create alert if a discount is selected and we have a target price
                const target = this.targetPrice();
                if (target !== null && target > 0) {
                    await this.api.createAlert(product.id, target, this.notifyBackInStock());
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
        return new Intl.NumberFormat('sv-SE', {
            style: 'currency',
            currency: currency || 'SEK',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(price);
    }

    goBack() {
        if (this.isEdit()) {
            this.router.navigate(['/products', this.id()]);
        } else {
            this.router.navigate(['/']);
        }
    }
}
