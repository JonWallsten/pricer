import {
    ChangeDetectionStrategy,
    Component,
    inject,
    signal,
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
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatSelectModule } from '@angular/material/select';
import { ApiService } from '../../api.service';
import { I18nService } from '../../i18n.service';
import {
    Product,
    ProductUrl,
    Alert,
    ExtractionResult,
    PriceHistoryEntry,
    ProductMatchCandidate,
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
        TimeAgoPipe,
    ],
})
export class ProductDetail implements OnInit, OnDestroy {
    private readonly api = inject(ApiService);
    private readonly router = inject(Router);
    private readonly fb = inject(FormBuilder);
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

        const style = getComputedStyle(document.documentElement);
        const primary = style.getPropertyValue('--mat-sys-primary').trim() || '#1976d2';
        const text = style.getPropertyValue('--mat-sys-on-surface-variant').trim() || '#6b7280';
        const grid = style.getPropertyValue('--mat-sys-outline-variant').trim() || '#d1d5db';
        const primaryFill = this.withAlpha(primary, 0.16);
        const pointRadius = data.length === 1 ? 6 : data.length > 30 ? 0 : 4;

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
                        tension: 0.3,
                        borderWidth: 3,
                        pointRadius,
                        pointHoverRadius: Math.max(pointRadius + 2, 6),
                        pointBackgroundColor: primary,
                        pointBorderColor: primary,
                        pointBorderWidth: 0,
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
                                const currency = data[ctx.dataIndex]?.currency || 'SEK';
                                return `${val} ${currency}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: {
                            maxTicksLimit: 8,
                            color: text,
                        },
                        grid: { display: false },
                        border: { display: false },
                    },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            color: text,
                            callback: (val) => `${val}`,
                        },
                        grid: {
                            color: this.withAlpha(grid, 0.75),
                        },
                        border: { display: false },
                    },
                },
            },
        });
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

    async discoverMatches(force = false) {
        this.discoveringMatches.set(true);
        this.matchesError.set(null);
        try {
            const result = await this.api.discoverProductMatches(Number(this.id()), force);
            this.matches.set(result.matches);
        } catch (error) {
            this.matchesError.set(
                error instanceof Error ? error.message : this.i18n.strings().error,
            );
        } finally {
            this.discoveringMatches.set(false);
        }
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

    formatPrice(price: number | null, currency?: string): string {
        if (price === null) return '—';
        return new Intl.NumberFormat('sv-SE', {
            style: 'currency',
            currency: currency || this.product()?.currency || 'SEK',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(price);
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

    goBack() {
        this.router.navigate(['/']);
    }
}
