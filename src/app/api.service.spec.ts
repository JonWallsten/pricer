import { TestBed } from '@angular/core/testing';
import { ApiService } from './api.service';

function okResponse(json: object) {
    return Promise.resolve({
        ok: true,
        json: () => Promise.resolve(json),
    });
}

function errorResponse(status = 400, json: object = { error: 'Bad request' }) {
    return Promise.resolve({
        ok: false,
        status,
        json: () => Promise.resolve(json),
    });
}

describe('ApiService', () => {
    let service: ApiService;
    let fetchSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        TestBed.configureTestingModule({});
        service = TestBed.inject(ApiService);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        TestBed.resetTestingModule();
    });

    // ─── Products ─────────────────────────────────────────

    describe('getProducts()', () => {
        it('returns products array', async () => {
            const products = [{ id: 1, name: 'Laptop' }];
            fetchSpy.mockReturnValue(okResponse({ products }));

            const result = await service.getProducts();
            expect(result).toEqual(products);
            expect(fetchSpy).toHaveBeenCalledWith('api/products', expect.any(Object));
        });

        it('throws on non-ok response', async () => {
            fetchSpy.mockReturnValue(errorResponse(500, {}));
            await expect(service.getProducts()).rejects.toThrow('Failed to fetch products');
        });
    });

    describe('getProduct()', () => {
        it('returns product with alerts and urls', async () => {
            const payload = { product: { id: 1 }, alerts: [], urls: [] };
            fetchSpy.mockReturnValue(okResponse(payload));

            const result = await service.getProduct(1);
            expect(result).toEqual(payload);
            expect(fetchSpy).toHaveBeenCalledWith('api/products/1', expect.any(Object));
        });

        it('throws on non-ok response', async () => {
            fetchSpy.mockReturnValue(errorResponse(404, {}));
            await expect(service.getProduct(99)).rejects.toThrow('Failed to fetch product');
        });
    });

    describe('createProduct()', () => {
        it('returns the created product', async () => {
            const product = { id: 5, name: 'Camera' };
            fetchSpy.mockReturnValue(okResponse({ product }));

            const result = await service.createProduct({
                name: 'Camera',
                urls: [{ url: 'https://example.com/camera' }],
            });
            expect(result).toEqual(product);
        });

        it('sends POST to api/products', async () => {
            fetchSpy.mockReturnValue(okResponse({ product: { id: 1 } }));
            await service.createProduct({ name: 'X', urls: [{ url: 'https://x.com' }] });
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/products',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        it('throws with server error message on failure', async () => {
            fetchSpy.mockReturnValue(errorResponse(422, { error: 'Invalid URL' }));
            await expect(
                service.createProduct({ name: 'X', urls: [{ url: 'bad' }] }),
            ).rejects.toThrow('Invalid URL');
        });
    });

    describe('updateProduct()', () => {
        it('returns the updated product', async () => {
            const product = { id: 1, name: 'Updated' };
            fetchSpy.mockReturnValue(okResponse({ product }));

            const result = await service.updateProduct(1, { name: 'Updated' });
            expect(result).toEqual(product);
        });

        it('sends PUT to api/products/:id', async () => {
            fetchSpy.mockReturnValue(okResponse({ product: { id: 1 } }));
            await service.updateProduct(1, { name: 'X' });
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/products/1',
                expect.objectContaining({ method: 'PUT' }),
            );
        });

        it('throws with server error message on failure', async () => {
            fetchSpy.mockReturnValue(errorResponse(400, { error: 'Name required' }));
            await expect(service.updateProduct(1, {})).rejects.toThrow('Name required');
        });
    });

    describe('deleteProduct()', () => {
        it('resolves on success', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await expect(service.deleteProduct(1)).resolves.toBeUndefined();
        });

        it('sends DELETE to api/products/:id', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await service.deleteProduct(3);
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/products/3',
                expect.objectContaining({ method: 'DELETE' }),
            );
        });

        it('throws on non-ok response', async () => {
            fetchSpy.mockReturnValue(errorResponse(404, {}));
            await expect(service.deleteProduct(99)).rejects.toThrow('Failed to delete product');
        });
    });

    describe('checkPrice()', () => {
        it('returns extraction result', async () => {
            const payload = { product: { id: 1 }, extraction: { price: 299 }, url_results: [] };
            fetchSpy.mockReturnValue(okResponse(payload));

            const result = await service.checkPrice(1);
            expect(result).toEqual(payload);
        });

        it('sends POST to api/products/:id/check', async () => {
            fetchSpy.mockReturnValue(okResponse({ product: {}, extraction: {}, url_results: [] }));
            await service.checkPrice(2);
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/products/2/check',
                expect.objectContaining({ method: 'POST' }),
            );
        });
    });

    describe('previewUrl()', () => {
        it('returns preview data', async () => {
            const preview = { price: 499, currency: 'SEK' };
            fetchSpy.mockReturnValue(okResponse({ preview }));

            const result = await service.previewUrl('https://example.com');
            expect(result).toEqual(preview);
        });

        it('sends css_selector and extraction_strategy in body', async () => {
            fetchSpy.mockReturnValue(okResponse({ preview: {} }));
            await service.previewUrl('https://example.com', '.price', 'selector');
            const call = fetchSpy.mock.calls[0];
            const body = JSON.parse(call[1].body);
            expect(body.css_selector).toBe('.price');
            expect(body.extraction_strategy).toBe('selector');
        });

        it('defaults extraction_strategy to auto when omitted', async () => {
            fetchSpy.mockReturnValue(okResponse({ preview: {} }));
            await service.previewUrl('https://example.com');
            const body = JSON.parse(fetchSpy.mock.calls[0][1].body);
            expect(body.extraction_strategy).toBe('auto');
        });
    });

    describe('getProductHistory()', () => {
        it('returns history array', async () => {
            const history = [{ price: 299, checked_at: '2026-04-01T00:00:00Z' }];
            fetchSpy.mockReturnValue(okResponse({ history }));

            const result = await service.getProductHistory(1, 'month');
            expect(result).toEqual(history);
        });

        it('includes period in query string', async () => {
            fetchSpy.mockReturnValue(okResponse({ history: [] }));
            await service.getProductHistory(1, 'week');
            expect(fetchSpy).toHaveBeenCalledWith(
                expect.stringContaining('period=week'),
                expect.any(Object),
            );
        });
    });

    // ─── Alerts ───────────────────────────────────────────

    describe('createAlert()', () => {
        it('returns the created alert', async () => {
            const alert = { id: 10, target_price: 200 };
            fetchSpy.mockReturnValue(okResponse({ alert }));

            const result = await service.createAlert(1, 200);
            expect(result).toEqual(alert);
        });

        it('sends target_price and notify_back_in_stock in body', async () => {
            fetchSpy.mockReturnValue(okResponse({ alert: { id: 1 } }));
            await service.createAlert(1, 150, true);
            const body = JSON.parse(fetchSpy.mock.calls[0][1].body);
            expect(body.target_price).toBe(150);
            expect(body.notify_back_in_stock).toBe(true);
        });

        it('throws with server error on failure', async () => {
            fetchSpy.mockReturnValue(errorResponse(422, { error: 'Price too low' }));
            await expect(service.createAlert(1, -1)).rejects.toThrow('Price too low');
        });
    });

    describe('updateAlert()', () => {
        it('returns the updated alert', async () => {
            const alert = { id: 10, is_active: false };
            fetchSpy.mockReturnValue(okResponse({ alert }));

            const result = await service.updateAlert(10, { is_active: false });
            expect(result).toEqual(alert);
        });

        it('sends PUT to api/alerts/:id', async () => {
            fetchSpy.mockReturnValue(okResponse({ alert: { id: 10 } }));
            await service.updateAlert(10, { is_active: true });
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/alerts/10',
                expect.objectContaining({ method: 'PUT' }),
            );
        });
    });

    describe('deleteAlert()', () => {
        it('resolves on success', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await expect(service.deleteAlert(5)).resolves.toBeUndefined();
        });

        it('throws on non-ok response', async () => {
            fetchSpy.mockReturnValue(errorResponse(404, {}));
            await expect(service.deleteAlert(99)).rejects.toThrow('Failed to delete alert');
        });
    });

    // ─── Admin ────────────────────────────────────────────

    describe('getUsers()', () => {
        it('returns users array', async () => {
            const users = [{ id: 1, email: 'admin@example.com' }];
            fetchSpy.mockReturnValue(okResponse({ users }));

            const result = await service.getUsers();
            expect(result).toEqual(users);
        });

        it('throws on non-ok response', async () => {
            fetchSpy.mockReturnValue(errorResponse(403, {}));
            await expect(service.getUsers()).rejects.toThrow('Failed to fetch users');
        });
    });

    describe('approveUser()', () => {
        it('resolves on success', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await expect(service.approveUser(1)).resolves.toBeUndefined();
        });

        it('sends PUT to api/admin/users/:id/approve', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await service.approveUser(2);
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/admin/users/2/approve',
                expect.objectContaining({ method: 'PUT' }),
            );
        });
    });

    describe('rejectUser()', () => {
        it('resolves on success', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await expect(service.rejectUser(1)).resolves.toBeUndefined();
        });

        it('throws on non-ok response', async () => {
            fetchSpy.mockReturnValue(errorResponse(400, {}));
            await expect(service.rejectUser(99)).rejects.toThrow('Failed to reject user');
        });
    });

    // ─── URL management ───────────────────────────────────

    describe('addProductUrl()', () => {
        it('returns the new ProductUrl', async () => {
            const url = { id: 7, url: 'https://example.com/2' };
            fetchSpy.mockReturnValue(okResponse({ url }));

            const result = await service.addProductUrl(1, 'https://example.com/2');
            expect(result).toEqual(url);
        });

        it('throws with server error on failure', async () => {
            fetchSpy.mockReturnValue(errorResponse(422, { error: 'Duplicate URL' }));
            await expect(service.addProductUrl(1, 'https://dup.com')).rejects.toThrow(
                'Duplicate URL',
            );
        });
    });

    describe('deleteProductUrl()', () => {
        it('resolves on success', async () => {
            fetchSpy.mockReturnValue(okResponse({}));
            await expect(service.deleteProductUrl(1, 7)).resolves.toBeUndefined();
        });

        it('throws with server error on failure', async () => {
            fetchSpy.mockReturnValue(errorResponse(400, { error: 'Cannot remove last URL' }));
            await expect(service.deleteProductUrl(1, 1)).rejects.toThrow('Cannot remove last URL');
        });
    });
});
