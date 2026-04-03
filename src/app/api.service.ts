import { Injectable } from '@angular/core';
import {
    Product,
    ProductUrl,
    Alert,
    ExtractionResult,
    PreviewResult,
    PriceHistoryEntry,
    AdminUser,
} from './models';

@Injectable({ providedIn: 'root' })
export class ApiService {
    // ─── Products ─────────────────────────────────────────

    async getProducts(): Promise<Product[]> {
        const res = await fetch('api/products', { credentials: 'include' });
        if (!res.ok) throw new Error('Failed to fetch products');
        const data = await res.json();
        return data.products;
    }

    async getProduct(
        id: number,
    ): Promise<{ product: Product; alerts: Alert[]; urls: ProductUrl[] }> {
        const res = await fetch(`api/products/${id}`, { credentials: 'include' });
        if (!res.ok) throw new Error('Failed to fetch product');
        return await res.json();
    }

    async createProduct(body: {
        name: string;
        urls: { url: string; css_selector?: string | null }[];
    }): Promise<Product> {
        const res = await fetch('api/products', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || 'Failed to create product');
        }
        const data = await res.json();
        return data.product;
    }

    async updateProduct(
        id: number,
        body: {
            name?: string;
            urls?: { id?: number; url: string; css_selector?: string | null }[];
        },
    ): Promise<Product> {
        const res = await fetch(`api/products/${id}`, {
            method: 'PUT',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || 'Failed to update product');
        }
        const data = await res.json();
        return data.product;
    }

    async deleteProduct(id: number): Promise<void> {
        const res = await fetch(`api/products/${id}`, {
            method: 'DELETE',
            credentials: 'include',
        });
        if (!res.ok) throw new Error('Failed to delete product');
    }

    async checkPrice(
        id: number,
    ): Promise<{
        product: Product;
        extraction: ExtractionResult;
        url_results: { url_id: number; url: string; extraction: ExtractionResult }[];
    }> {
        const res = await fetch(`api/products/${id}/check`, {
            method: 'POST',
            credentials: 'include',
        });
        if (!res.ok) throw new Error('Failed to check price');
        return await res.json();
    }

    async checkUrl(
        productId: number,
        urlId: number,
    ): Promise<{ product: Product; url: ProductUrl; extraction: ExtractionResult }> {
        const res = await fetch(`api/products/${productId}/check-url`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url_id: urlId }),
        });
        if (!res.ok) throw new Error('Failed to check URL');
        return await res.json();
    }

    async previewUrl(url: string, cssSelector?: string | null): Promise<PreviewResult> {
        const res = await fetch('api/products/preview', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url, css_selector: cssSelector || null }),
        });
        if (!res.ok) throw new Error('Failed to preview URL');
        const data = await res.json();
        return data.preview;
    }

    async getProductHistory(id: number, period: string): Promise<PriceHistoryEntry[]> {
        const res = await fetch(`api/products/${id}/history?period=${encodeURIComponent(period)}`, {
            credentials: 'include',
        });
        if (!res.ok) throw new Error('Failed to fetch history');
        const data = await res.json();
        return data.history;
    }

    // ─── Alerts ───────────────────────────────────────────

    async createAlert(
        productId: number,
        targetPrice: number,
        notifyBackInStock = false,
    ): Promise<Alert> {
        const res = await fetch(`api/products/${productId}/alerts`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                target_price: targetPrice,
                notify_back_in_stock: notifyBackInStock,
            }),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || 'Failed to create alert');
        }
        const data = await res.json();
        return data.alert;
    }

    async updateAlert(
        id: number,
        body: { target_price?: number; is_active?: boolean; notify_back_in_stock?: boolean },
    ): Promise<Alert> {
        const res = await fetch(`api/alerts/${id}`, {
            method: 'PUT',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || 'Failed to update alert');
        }
        const data = await res.json();
        return data.alert;
    }

    async deleteAlert(id: number): Promise<void> {
        const res = await fetch(`api/alerts/${id}`, {
            method: 'DELETE',
            credentials: 'include',
        });
        if (!res.ok) throw new Error('Failed to delete alert');
    }

    // ─── Admin ────────────────────────────────────────────

    async getUsers(): Promise<AdminUser[]> {
        const res = await fetch('api/admin/users', { credentials: 'include' });
        if (!res.ok) throw new Error('Failed to fetch users');
        const data = await res.json();
        return data.users;
    }

    async approveUser(id: number): Promise<void> {
        const res = await fetch(`api/admin/users/${id}/approve`, {
            method: 'PUT',
            credentials: 'include',
        });
        if (!res.ok) throw new Error('Failed to approve user');
    }

    async rejectUser(id: number): Promise<void> {
        const res = await fetch(`api/admin/users/${id}/reject`, {
            method: 'PUT',
            credentials: 'include',
        });
        if (!res.ok) throw new Error('Failed to reject user');
    }
}
