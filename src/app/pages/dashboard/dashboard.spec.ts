import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideZonelessChangeDetection } from '@angular/core';
import { Dashboard } from './dashboard';
import { ApiService } from '../../api.service';
import { Product } from '../../models';

const MOCK_PRODUCT: Product = {
    id: 1,
    user_id: 1,
    name: 'Test Laptop',
    url: 'https://example.com/laptop',
    css_selector: null,
    image_url: null,
    availability: 'in_stock',
    current_price: 9999,
    regular_price: 12999,
    previous_lowest_price: null,
    is_campaign: true,
    campaign_type: null,
    campaign_label: null,
    currency: 'SEK',
    last_checked_at: '2026-04-06T10:00:00Z',
    last_check_status: 'success',
    last_check_error: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-04-06T10:00:00Z',
    active_alerts: 2,
    urls_count: 1,
};

describe('Dashboard', () => {
    let mockApi: { getProducts: ReturnType<typeof vi.fn> };
    let router: Router;

    beforeEach(async () => {
        mockApi = { getProducts: vi.fn().mockResolvedValue([]) };

        TestBed.configureTestingModule({
            imports: [Dashboard],
            providers: [
                provideZonelessChangeDetection(),
                provideRouter([]),
                { provide: ApiService, useValue: mockApi },
            ],
        });
        await TestBed.compileComponents();

        router = TestBed.inject(Router);
    });

    afterEach(() => {
        TestBed.resetTestingModule();
    });

    it('creates the component', () => {
        const fixture = TestBed.createComponent(Dashboard);
        expect(fixture.componentInstance).toBeTruthy();
    });

    it('shows loading spinner on first render', () => {
        const fixture = TestBed.createComponent(Dashboard);
        fixture.detectChanges();
        // loading starts true before getProducts resolves
        const spinner = fixture.nativeElement.querySelector('mat-spinner');
        expect(spinner).toBeTruthy();
    });

    it('renders product cards after loading', async () => {
        mockApi.getProducts.mockResolvedValue([MOCK_PRODUCT]);
        const fixture = TestBed.createComponent(Dashboard);
        fixture.detectChanges();
        await fixture.whenStable();
        fixture.detectChanges();

        const cards = fixture.nativeElement.querySelectorAll('mat-card');
        expect(cards.length).toBeGreaterThan(0);
    });

    it('displays product name', async () => {
        mockApi.getProducts.mockResolvedValue([MOCK_PRODUCT]);
        const fixture = TestBed.createComponent(Dashboard);
        fixture.detectChanges();
        await fixture.whenStable();
        fixture.detectChanges();

        expect(fixture.nativeElement.textContent).toContain('Test Laptop');
    });

    it('hides spinner after loading completes', async () => {
        const fixture = TestBed.createComponent(Dashboard);
        fixture.detectChanges();
        await fixture.whenStable();
        fixture.detectChanges();

        const spinner = fixture.nativeElement.querySelector('mat-spinner');
        expect(spinner).toBeNull();
    });

    describe('formatPrice()', () => {
        it('returns em dash for null price', () => {
            const fixture = TestBed.createComponent(Dashboard);
            const component = fixture.componentInstance;
            expect(component.formatPrice(null, 'SEK')).toBe('—');
        });

        it('formats a SEK price', () => {
            const fixture = TestBed.createComponent(Dashboard);
            const component = fixture.componentInstance;
            const result = component.formatPrice(9999, 'SEK');
            expect(result).toContain('9');
            expect(result).toContain('999');
        });

        it('formats a price without decimals for round numbers', () => {
            const fixture = TestBed.createComponent(Dashboard);
            const component = fixture.componentInstance;
            const result = component.formatPrice(1000, 'SEK');
            expect(result).not.toContain('.00');
        });
    });

    describe('navigation', () => {
        it('navigates to /products/new when addProduct() is called', () => {
            const fixture = TestBed.createComponent(Dashboard);
            const component = fixture.componentInstance;
            const navSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
            component.addProduct();
            expect(navSpy).toHaveBeenCalledWith(['/products', 'new']);
        });

        it('navigates to /products/:id when goToProduct() is called', () => {
            const fixture = TestBed.createComponent(Dashboard);
            const component = fixture.componentInstance;
            const navSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
            component.goToProduct(42);
            expect(navSpy).toHaveBeenCalledWith(['/products', 42]);
        });
    });
});
