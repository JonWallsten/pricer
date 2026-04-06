import {
    ChangeDetectionStrategy,
    Component,
    inject,
    signal,
    computed,
    effect,
    input,
    OnInit,
    OnDestroy,
    ElementRef,
    viewChild,
} from '@angular/core';
import { Router } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSelectModule } from '@angular/material/select';
import { MatMenuModule } from '@angular/material/menu';
import { MatDividerModule } from '@angular/material/divider';
import { firstValueFrom } from 'rxjs';
import { ApiService } from '../../api.service';
import { AuthService } from '../../auth.service';
import { classifyFetchError } from '../../fetch-error.util';
import { I18nService } from '../../i18n.service';
import {
    Product,
    ProductUrl,
    Alert,
    ExtractionResult,
    PriceHistoryEntry,
    ProductMatchCandidate,
    ProductMatchDiscoveryResponse,
    PageInspectorData,
    ExtractionStrategy,
} from '../../models';
import { TimeAgoPipe } from '../../pipes/time-ago.pipe';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

@Component({
    selector: 'app-product-detail',
    templateUrl: './product-detail.html',
    styleUrl: './product-detail.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        ReactiveFormsModule,
        MatButtonModule,
        MatIconModule,
        MatFormFieldModule,
        MatInputModule,
        MatProgressSpinnerModule,
        MatSlideToggleModule,
        MatButtonToggleModule,
        MatCheckboxModule,
        MatSelectModule,
        MatMenuModule,
        MatDividerModule,
        TimeAgoPipe,
    ],
})
export class ProductDetail implements OnInit, OnDestroy {
    private readonly api = inject(ApiService);
    private readonly router = inject(Router);
    private readonly fb = inject(FormBuilder);
    private readonly dialog = inject(MatDialog);
    protected readonly auth = inject(AuthService);
    protected readonly i18n = inject(I18nService);

    readonly id = input.required<string>();

    protected readonly product = signal<Product | null>(null);
    protected readonly alerts = signal<Alert[]>([]);
    protected readonly urls = signal<ProductUrl[]>([]);
    protected readonly loading = signal(true);
    protected readonly checking = signal(false);
    protected readonly checkResult = signal<ExtractionResult | null>(null);
    protected readonly checkingUrlIds = signal<Set<number>>(new Set());
    protected readonly addingAlert = signal(false);
    protected readonly historyPeriod = signal<string>('month');
    protected readonly history = signal<PriceHistoryEntry[]>([]);
    protected readonly historyLoading = signal(false);
    protected readonly matches = signal<ProductMatchCandidate[]>([]);
    protected readonly matchesLoading = signal(false);
    protected readonly discoveringMatches = signal(false);
    protected readonly matchesError = signal<string | null>(null);
    protected readonly addingMatchUrls = signal<Set<string>>(new Set());
    protected readonly lastDiscoveryResult = signal<ProductMatchDiscoveryResponse | null>(null);
    protected readonly trackedUrls = computed(() => new Set(this.urls().map((u) => u.url)));
    protected readonly filteredMatches = computed(() =>
        this.matches().filter((m) => !this.trackedUrls().has(m.candidate_url)),
    );

    readonly chartCanvas = viewChild<ElementRef<HTMLCanvasElement>>('priceChart');
    private chart: Chart | null = null;
    private readonly syncChart = effect(() => {
        const canvas = this.chartCanvas();
        const data = this.history();
        const loading = this.historyLoading();

        if (loading || !canvas || data.length === 0) {
            this.chart?.destroy();
            this.chart = null;
            return;
        }

        queueMicrotask(() => this.renderChart(data));
    });

    protected readonly alertForm = this.fb.nonNullable.group({
        target_price: [0, [Validators.required, Validators.min(0.01)]],
    });

    async ngOnInit() {
        await this.loadProduct();
        await this.loadMatches();
        await this.loadHistory();
    }

    ngOnDestroy() {
        this.chart?.destroy();
    }

    private async loadProduct() {
        this.loading.set(true);
        try {
            const data = await this.api.getProduct(Number(this.id()));
            this.product.set(data.product);
            this.alerts.set(data.alerts);
            this.urls.set(data.urls);
        } catch {
            this.router.navigate(['/']);
        } finally {
            this.loading.set(false);
        }
    }

    async loadMatches() {
        this.matchesLoading.set(true);
        this.matchesError.set(null);
        try {
            const matches = await this.api.getProductMatches(Number(this.id()));
            this.matches.set(matches);
        } catch {
            this.matches.set([]);
        } finally {
            this.matchesLoading.set(false);
        }
    }

    async loadHistory() {
        this.historyLoading.set(true);
        try {
            const data = await this.api.getProductHistory(Number(this.id()), this.historyPeriod());
            this.history.set(data);
        } catch {
            this.history.set([]);
        } finally {
            this.historyLoading.set(false);
        }
    }

    async onPeriodChange(period: string) {
        this.historyPeriod.set(period);
        await this.loadHistory();
    }

    private renderChart(data: PriceHistoryEntry[]) {
        const canvas = this.chartCanvas();
        if (!canvas) return;

        this.chart?.destroy();

        const ctx = canvas.nativeElement.getContext('2d');
        if (!ctx) return;

        const labels = data.map((d) => d.recorded_at);
        const prices = data.map((d) => d.price);

        const primary = this.resolveThemeColor('--mat-sys-primary', '#1976d2');
        const text = this.resolveThemeColor('--mat-sys-on-surface-variant', '#6b7280');
        const textStrong = this.resolveThemeColor('--mat-sys-on-surface', '#111827');
        const grid = this.resolveThemeColor('--mat-sys-outline-variant', '#d1d5db');
        const primaryFill = this.withAlpha(primary, 0.18);
        const pointRadius = data.length === 1 ? 7 : data.length > 30 ? 0 : 4;
        const pointHoverRadius = Math.max(pointRadius + 2, 7);
        const suggestedMin = Math.min(...prices);
        const suggestedMax = Math.max(...prices);
        const span = Math.max(suggestedMax - suggestedMin, suggestedMax * 0.06, 10);

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: this.i18n.strings().currentPrice,
                        data: prices,
                        borderColor: primary,
                        backgroundColor: primaryFill,
                        fill: true,
                        tension: data.length > 1 ? 0.28 : 0,
                        borderWidth: 3,
                        pointRadius,
                        pointHoverRadius,
                        pointBackgroundColor: primary,
                        pointBorderColor: this.withAlpha(textStrong, 0.9),
                        pointBorderWidth: data.length === 1 ? 3 : 2,
                        showLine: data.length > 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => {
                                const val = ctx.parsed.y;
                                if (val === null || val === undefined) return '';
                                const currency = data[ctx.dataIndex]?.currency || 'SEK';
                                return new Intl.NumberFormat(this.i18n.locale(), {
                                    style: 'currency',
                                    currency,
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 2,
                                }).format(val);
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: {
                            maxTicksLimit: 8,
                            color: text,
                            padding: 10,
                        },
                        grid: { display: false },
                        border: { display: false },
                    },
                    y: {
                        beginAtZero: false,
                        suggestedMin: suggestedMin - span * 0.45,
                        suggestedMax: suggestedMax + span * 0.55,
                        ticks: {
                            color: text,
                            padding: 10,
                            callback: (val) => `${val}`,
                        },
                        grid: {
                            color: this.withAlpha(grid, 0.42),
                        },
                        border: { display: false },
                    },
                },
            },
        });
    }

    private resolveThemeColor(variableName: string, fallback: string): string {
        const style = getComputedStyle(document.documentElement);
        const raw = style.getPropertyValue(variableName).trim();

        if (raw === '') {
            return fallback;
        }

        const lightDarkMatch = raw.match(/^light-dark\((.+),(.+)\)$/);
        if (lightDarkMatch) {
            const isDark = this.isDarkThemeActive();
            return (isDark ? lightDarkMatch[2] : lightDarkMatch[1]).trim();
        }

        return raw;
    }

    private isDarkThemeActive(): boolean {
        const theme = document.documentElement.dataset['theme'];
        if (theme === 'dark') return true;
        if (theme === 'light') return false;
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    private withAlpha(color: string, alpha: number): string {
        const value = color.trim();

        if (value.startsWith('#')) {
            let hex = value.slice(1);
            if (hex.length === 3) {
                hex = hex
                    .split('')
                    .map((char) => char + char)
                    .join('');
            }
            if (hex.length === 6) {
                const r = parseInt(hex.slice(0, 2), 16);
                const g = parseInt(hex.slice(2, 4), 16);
                const b = parseInt(hex.slice(4, 6), 16);
                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
            }
        }

        const rgbMatch = value.match(
            /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+[\d.]+)?\s*\)/i,
        );
        if (rgbMatch) {
            return `rgba(${rgbMatch[1]}, ${rgbMatch[2]}, ${rgbMatch[3]}, ${alpha})`;
        }

        return value;
    }

    async checkPrice() {
        this.checking.set(true);
        this.checkResult.set(null);
        try {
            const result = await this.api.checkPrice(Number(this.id()));
            this.product.set(result.product);
            this.checkResult.set(result.extraction);
            // Reload URLs to get updated per-URL prices
            const data = await this.api.getProduct(Number(this.id()));
            this.urls.set(data.urls);
        } catch {
            // Error
        } finally {
            this.checking.set(false);
        }
    }

    async checkSingleUrl(urlId: number) {
        this.checkingUrlIds.update((s) => new Set(s).add(urlId));
        try {
            const result = await this.api.checkUrl(Number(this.id()), urlId);
            this.product.set(result.product);
            this.urls.update((list) => list.map((u) => (u.id === result.url.id ? result.url : u)));
        } catch {
            // Error
        } finally {
            this.checkingUrlIds.update((s) => {
                const next = new Set(s);
                next.delete(urlId);
                return next;
            });
        }
    }

    async removeUrl(siteUrl: ProductUrl) {
        if (this.urls().length <= 1) return;
        try {
            await this.api.deleteProductUrl(Number(this.id()), siteUrl.id);
            this.urls.update((list) => list.filter((u) => u.id !== siteUrl.id));
            const remaining = this.urls();
            if (remaining.length > 0) {
                const best = remaining.reduce((a, b) =>
                    (a.current_price ?? Infinity) <= (b.current_price ?? Infinity) ? a : b,
                );
                this.product.update((p) =>
                    p
                        ? {
                              ...p,
                              best_price: best.current_price,
                              currency: best.currency,
                              url: remaining[0].url,
                          }
                        : p,
                );
            }
        } catch {
            // Error
        }
    }

    async discoverMatches(force = false) {
        this.discoveringMatches.set(true);
        this.matchesError.set(null);
        try {
            const result = await this.api.discoverProductMatches(Number(this.id()), force);
            this.matches.set(result.matches);
            this.lastDiscoveryResult.set(result);
        } catch (error) {
            this.matchesError.set(
                error instanceof Error ? error.message : this.i18n.strings().error,
            );
        } finally {
            this.discoveringMatches.set(false);
        }
    }

    async dismissMatch(match: ProductMatchCandidate) {
        try {
            await this.api.dismissProductMatch(Number(this.id()), match.id);
            this.matches.update((list) => list.filter((m) => m.id !== match.id));
        } catch {
            // Non-critical — silently fail
        }
    }

    async addMatchUrl(match: ProductMatchCandidate) {
        const url = match.candidate_url;
        if (this.trackedUrls().has(url) || this.addingMatchUrls().has(url)) return;

        this.addingMatchUrls.update((s) => new Set(s).add(url));
        try {
            const newUrl = await this.api.addProductUrl(Number(this.id()), url);
            this.urls.update((list) => [...list, newUrl]);

            // Auto-open selector picker only when price extraction failed
            if (newUrl.current_price === null) {
                await this.runSelectorFlow(newUrl);
            }
        } catch {
            // Error — silently fail, button will revert
        } finally {
            this.addingMatchUrls.update((s) => {
                const next = new Set(s);
                next.delete(url);
                return next;
            });
        }
    }

    async pickSelectorForUrl(siteUrl: ProductUrl) {
        await this.runSelectorFlow(siteUrl);
    }

    private async runSelectorFlow(siteUrl: ProductUrl) {
        const selector = await this.openSelectorPicker(siteUrl.url, siteUrl.css_selector);
        if (selector) {
            const strategy = await this.openSelectorConfirm(selector);
            if (strategy) {
                const updatedUrl = await this.api.updateProductUrl(Number(this.id()), siteUrl.id, {
                    css_selector: selector,
                    extraction_strategy: strategy,
                });
                this.urls.update((list) =>
                    list.map((u) => (u.id === updatedUrl.id ? updatedUrl : u)),
                );
                await this.checkSingleUrl(siteUrl.id);
            }
        }
    }

    private async openSelectorPicker(
        url: string,
        cssSelector?: string | null,
    ): Promise<string | null> {
        const { PageInspectorDialog } =
            await import('../../components/page-inspector-dialog/page-inspector-dialog');
        const dialogRef = this.dialog.open(PageInspectorDialog, {
            width: '95vw',
            maxWidth: '1400px',
            height: '85vh',
            panelClass: 'page-inspector-panel',
            data: {
                interactionMode: 'pick',
                url,
                cssSelector: cssSelector ?? undefined,
            } satisfies PageInspectorData,
        });
        return firstValueFrom(dialogRef.afterClosed());
    }

    private async openSelectorConfirm(selector: string): Promise<ExtractionStrategy | null> {
        const { SelectorConfirmDialog } =
            await import('../../components/selector-confirm-dialog/selector-confirm-dialog');
        const dialogRef = this.dialog.open(SelectorConfirmDialog, {
            width: '400px',
            data: { selector },
        });
        return firstValueFrom(dialogRef.afterClosed());
    }

    getDomain(url: string): string {
        try {
            return new URL(url).hostname.replace(/^www\./, '');
        } catch {
            return url;
        }
    }

    editProduct() {
        this.router.navigate(['/products', this.id(), 'edit']);
    }

    async deleteProduct() {
        if (!confirm(this.i18n.strings().deleteProductConfirm)) return;
        try {
            await this.api.deleteProduct(Number(this.id()));
            this.router.navigate(['/']);
        } catch {
            // Error
        }
    }

    async addAlert() {
        if (this.alertForm.invalid) return;
        this.addingAlert.set(true);
        try {
            const alert = await this.api.createAlert(
                Number(this.id()),
                this.alertForm.controls.target_price.value,
            );
            this.alerts.update((list) => [alert, ...list]);
            this.alertForm.reset({ target_price: 0 });
        } catch {
            // Error
        } finally {
            this.addingAlert.set(false);
        }
    }

    async toggleAlert(alert: Alert) {
        try {
            const updated = await this.api.updateAlert(alert.id, { is_active: !alert.is_active });
            this.alerts.update((list) => list.map((a) => (a.id === updated.id ? updated : a)));
        } catch {
            // Error
        }
    }

    async toggleBackInStock(alert: Alert) {
        try {
            const updated = await this.api.updateAlert(alert.id, {
                notify_back_in_stock: !alert.notify_back_in_stock,
            });
            this.alerts.update((list) => list.map((a) => (a.id === updated.id ? updated : a)));
        } catch {
            // Error
        }
    }

    async deleteAlert(alert: Alert) {
        if (!confirm(this.i18n.strings().deleteAlertConfirm)) return;
        try {
            await this.api.deleteAlert(alert.id);
            this.alerts.update((list) => list.filter((a) => a.id !== alert.id));
        } catch {
            // Error
        }
    }

    formatPrice(price: number | null, currency?: string | null): string {
        if (price === null) return '—';
        return new Intl.NumberFormat(this.i18n.locale(), {
            style: 'currency',
            currency: currency || this.product()?.currency || 'SEK',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
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

    getAvailabilityLabel(avail: string): string {
        const s = this.i18n.strings();
        switch (avail) {
            case 'in_stock':
                return s.inStock;
            case 'out_of_stock':
                return s.outOfStock;
            case 'preorder':
                return s.preorder;
            default:
                return s.availabilityUnknown;
        }
    }

    getMatchConfidenceLabel(label: ProductMatchCandidate['confidence_label']): string {
        const s = this.i18n.strings();
        switch (label) {
            case 'very_likely':
                return s.matchVeryLikely;
            case 'likely':
                return s.matchLikely;
            case 'possible':
                return s.matchPossible;
            default:
                return s.matchWeak;
        }
    }

    async debugUrl(url: ProductUrl) {
        const { PageInspectorDialog } =
            await import('../../components/page-inspector-dialog/page-inspector-dialog');
        this.dialog.open(PageInspectorDialog, {
            width: '95vw',
            maxWidth: '1400px',
            height: '85vh',
            panelClass: 'page-inspector-panel',
            data: {
                interactionMode: 'debug',
                url: url.url,
                cssSelector: url.css_selector ?? undefined,
            } satisfies PageInspectorData,
        });
    }

    copyToClipboard(text: string) {
        navigator.clipboard.writeText(text);
    }

    goBack() {
        this.router.navigate(['/']);
    }
}
