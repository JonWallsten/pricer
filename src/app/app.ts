import { ChangeDetectionStrategy, Component, inject, OnInit, signal } from '@angular/core';
import { RouterOutlet, RouterLink } from '@angular/router';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { AuthService } from './auth.service';
import { ApiService } from './api.service';
import { I18nService } from './i18n.service';

@Component({
    selector: 'app-root',
    imports: [
        RouterOutlet,
        RouterLink,
        MatToolbarModule,
        MatButtonModule,
        MatIconModule,
        MatMenuModule,
        MatProgressSpinnerModule,
    ],
    templateUrl: './app.html',
    styleUrl: './app.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class App implements OnInit {
    protected readonly auth = inject(AuthService);
    protected readonly i18n = inject(I18nService);
    private readonly api = inject(ApiService);

    readonly pendingCount = signal(0);

    private readonly prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
    readonly theme = signal<'light' | 'dark' | 'system'>(this.initTheme());

    ngOnInit() {
        this.auth.init().then(() => {
            if (this.auth.user()?.is_admin) {
                this.refreshPendingCount();
            }
        });
    }

    async refreshPendingCount() {
        try {
            const users = await this.api.getUsers();
            this.pendingCount.set(users.filter((u) => !u.is_approved).length);
        } catch {
            // Non-critical — ignore
        }
    }

    themeIcon(): string {
        const osDark = this.prefersDark.matches;
        const current = this.theme();

        if (current === 'system') {
            return osDark ? '☀️' : '🌙';
        }
        if ((osDark && current === 'light') || (!osDark && current === 'dark')) {
            return osDark ? '🌙' : '☀️';
        }
        return '🖥️';
    }

    toggleTheme(): void {
        const osDark = this.prefersDark.matches;
        const current = this.theme();

        let next: 'light' | 'dark' | 'system';
        if (current === 'system') {
            next = osDark ? 'light' : 'dark';
        } else if ((osDark && current === 'light') || (!osDark && current === 'dark')) {
            next = osDark ? 'dark' : 'light';
        } else {
            next = 'system';
        }

        this.theme.set(next);
        if (next === 'system') {
            delete document.documentElement.dataset['theme'];
            localStorage.removeItem('pricerTheme');
        } else {
            document.documentElement.dataset['theme'] = next;
            localStorage.setItem('pricerTheme', next);
        }
    }

    private initTheme(): 'light' | 'dark' | 'system' {
        const stored = localStorage.getItem('pricerTheme');
        if (stored === 'dark' || stored === 'light') {
            document.documentElement.dataset['theme'] = stored;
            return stored;
        }
        delete document.documentElement.dataset['theme'];
        return 'system';
    }
}
