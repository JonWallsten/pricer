export interface User {
    id: number;
    email: string;
    name: string;
    picture_url: string | null;
    is_approved: boolean;
    is_admin: boolean;
}

export interface AdminUser {
    id: number;
    email: string;
    name: string;
    picture_url: string | null;
    is_approved: boolean;
    is_admin: boolean;
    created_at: string;
    last_login_at: string | null;
}

export type Availability = 'in_stock' | 'out_of_stock' | 'preorder' | 'unknown';

export interface ProductUrl {
    id: number;
    product_id: number;
    url: string;
    css_selector: string | null;
    current_price: number | null;
    currency: string;
    image_url: string | null;
    availability: Availability;
    last_checked_at: string | null;
    last_check_status: 'pending' | 'success' | 'error' | null;
    last_check_error: string | null;
    created_at: string;
}

export interface Product {
    id: number;
    user_id: number;
    name: string;
    url: string;
    css_selector: string | null;
    image_url: string | null;
    availability: Availability;
    current_price: number | null;
    currency: string;
    last_checked_at: string | null;
    last_check_status: 'pending' | 'success' | 'error';
    last_check_error: string | null;
    created_at: string;
    updated_at: string;
    active_alerts?: number;
    urls_count?: number;
    urls?: ProductUrl[];
}

export interface Alert {
    id: number;
    product_id: number;
    user_id: number;
    target_price: number;
    is_active: boolean;
    notify_back_in_stock: boolean;
    last_notified_price: number | null;
    last_notified_at: string | null;
    created_at: string;
}

export interface ExtractionResult {
    price: number | null;
    currency: string | null;
    method: string | null;
    error: string | null;
    image_url: string | null;
}

export interface PreviewResult extends ExtractionResult {
    availability: Availability;
    page_title: string | null;
}

export interface PriceHistoryEntry {
    price: number;
    currency: string;
    recorded_at: string;
}
