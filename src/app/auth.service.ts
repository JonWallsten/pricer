import { Injectable, signal, inject, NgZone } from '@angular/core';
import { Router } from '@angular/router';
import { User } from './models';

declare const google: {
    accounts: {
        id: {
            initialize(config: {
                client_id: string;
                callback: (response: { credential: string }) => void;
                auto_select?: boolean;
            }): void;
            renderButton(
                element: HTMLElement,
                config: {
                    theme?: string;
                    size?: string;
                    width?: number;
                    text?: string;
                    shape?: string;
                },
            ): void;
            revoke(email: string, callback: () => void): void;
        };
    };
};

@Injectable({ providedIn: 'root' })
export class AuthService {
    private readonly router = inject(Router);
    private readonly zone = inject(NgZone);

    readonly user = signal<User | null>(null);
    readonly loading = signal(true);
    private clientId = '';

    async init() {
        try {
            const res = await fetch('api/auth/config');
            if (!res.ok) throw new Error('Failed to fetch auth config');
            const data = await res.json();
            this.clientId = data.google_client_id;
        } catch {
            this.loading.set(false);
            return;
        }

        // Try to restore session
        try {
            const res = await fetch('api/auth/me', { credentials: 'include' });
            if (res.ok) {
                const data = await res.json();
                this.user.set(data.user);
            }
        } catch {
            // No active session
        }

        this.loading.set(false);
    }

    renderGoogleButton(element: HTMLElement) {
        if (!this.clientId || typeof google === 'undefined') return;

        google.accounts.id.initialize({
            client_id: this.clientId,
            callback: (response) => {
                this.zone.run(() => this.handleCredentialResponse(response.credential));
            },
        });

        google.accounts.id.renderButton(element, {
            theme: 'outline',
            size: 'large',
            shape: 'pill',
            text: 'signin_with',
            width: 300,
        });
    }

    private async handleCredentialResponse(credential: string) {
        this.loading.set(true);

        try {
            const res = await fetch('api/auth/google', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: credential }),
            });

            if (!res.ok) throw new Error('Auth failed');

            const data = await res.json();
            this.user.set(data.user);
            this.router.navigate([data.user.is_approved ? '/' : '/pending']);
        } catch {
            // Auth failed
        } finally {
            this.loading.set(false);
        }
    }

    async logout() {
        try {
            await fetch('api/auth/logout', {
                method: 'POST',
                credentials: 'include',
            });
        } catch {
            // Ignore
        }

        const email = this.user()?.email;
        this.user.set(null);

        if (email && typeof google !== 'undefined') {
            google.accounts.id.revoke(email, () => {});
        }

        this.router.navigate(['/login']);
    }
}
