import { ChangeDetectionStrategy, Component, inject, signal, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatChipsModule } from '@angular/material/chips';
import { ApiService } from '../../api.service';
import { I18nService } from '../../i18n.service';
import { Product } from '../../models';
import { TimeAgoPipe } from '../../pipes/time-ago.pipe';

@Component({
    selector: 'app-dashboard',
    templateUrl: './dashboard.html',
    styleUrl: './dashboard.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [
        MatCardModule,
        MatButtonModule,
        MatIconModule,
        MatProgressSpinnerModule,
        MatChipsModule,
        TimeAgoPipe,
    ],
})
export class Dashboard implements OnInit {
    private readonly api = inject(ApiService);
    private readonly router = inject(Router);
    protected readonly i18n = inject(I18nService);

    protected readonly products = signal<Product[]>([]);
    protected readonly loading = signal(true);

    async ngOnInit() {
        await this.loadProducts();
    }

    async loadProducts() {
        this.loading.set(true);
        try {
            const products = await this.api.getProducts();
            this.products.set(products);
        } catch {
            // Error handling
        } finally {
            this.loading.set(false);
        }
    }

    goToProduct(id: number) {
        this.router.navigate(['/products', id]);
    }

    addProduct() {
        this.router.navigate(['/products', 'new']);
    }

    formatPrice(price: number | null, currency: string): string {
        if (price === null) return '—';
        return new Intl.NumberFormat(this.i18n.locale(), {
            style: 'currency',
            currency: currency || 'SEK',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(price);
    }
}
