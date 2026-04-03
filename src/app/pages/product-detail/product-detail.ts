import {
    ChangeDetectionStrategy,
    Component,
    inject,
    signal,
    input,
    OnInit,
    OnDestroy,
    ElementRef,
    viewChild,
    AfterViewInit,
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
import { ApiService } from '../../api.service';
import { I18nService } from '../../i18n.service';
import { Product, Alert, ExtractionResult, PriceHistoryEntry } from '../../models';
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
    protected readonly loading = signal(true);
    protected readonly checking = signal(false);
    protected readonly checkResult = signal<ExtractionResult | null>(null);
    protected readonly addingAlert = signal(false);
    protected readonly historyPeriod = signal<string>('month');
    protected readonly history = signal<PriceHistoryEntry[]>([]);
    protected readonly historyLoading = signal(false);

    readonly chartCanvas = viewChild<ElementRef<HTMLCanvasElement>>('priceChart');
    private chart: Chart | null = null;

    protected readonly alertForm = this.fb.nonNullable.group({
        target_price: [0, [Validators.required, Validators.min(0.01)]],
    });

    async ngOnInit() {
        await this.loadProduct();
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
        } catch {
            this.router.navigate(['/']);
        } finally {
            this.loading.set(false);
        }
    }

    async loadHistory() {
        this.historyLoading.set(true);
        try {
            const data = await this.api.getProductHistory(Number(this.id()), this.historyPeriod());
            this.history.set(data);
            // Wait a tick for the canvas to be in the DOM
            setTimeout(() => this.renderChart(data), 0);
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

        // Get CSS variable for primary color
        const style = getComputedStyle(document.documentElement);
        const primary = style.getPropertyValue('--mat-sys-primary').trim() || '#1976d2';

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: this.i18n.strings().currentPrice,
                        data: prices,
                        borderColor: primary,
                        backgroundColor: primary + '22',
                        fill: true,
                        tension: 0.3,
                        pointRadius: data.length > 30 ? 0 : 4,
                        pointHoverRadius: 6,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
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
                        ticks: { maxTicksLimit: 8 },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: (val) => `${val}`,
                        },
                    },
                },
            },
        });
    }

    async checkPrice() {
        this.checking.set(true);
        this.checkResult.set(null);
        try {
            const result = await this.api.checkPrice(Number(this.id()));
            this.product.set(result.product);
            this.checkResult.set(result.extraction);
        } catch {
            // Error
        } finally {
            this.checking.set(false);
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

    goBack() {
        this.router.navigate(['/']);
    }
}
