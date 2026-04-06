import { ChangeDetectionStrategy, Component, inject, signal, OnInit } from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatChipsModule } from '@angular/material/chips';
import { ApiService } from '../../api.service';
import { I18nService } from '../../i18n.service';
import { App } from '../../app';
import { AdminUser } from '../../models';
import { TimeAgoPipe } from '../../pipes/time-ago.pipe';

@Component({
    selector: 'app-admin',
    templateUrl: './admin.html',
    styleUrl: './admin.scss',
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
export class Admin implements OnInit {
    private readonly api = inject(ApiService);
    private readonly shell = inject(App);
    protected readonly i18n = inject(I18nService);

    protected readonly users = signal<AdminUser[]>([]);
    protected readonly loading = signal(true);

    async ngOnInit() {
        await this.loadUsers();
    }

    private async loadUsers() {
        this.loading.set(true);
        try {
            const users = await this.api.getUsers();
            this.users.set(users);
        } catch {
            // Failed to load
        } finally {
            this.loading.set(false);
        }
    }

    async approve(user: AdminUser) {
        try {
            await this.api.approveUser(user.id);
            this.users.update((list) =>
                list.map((u) => (u.id === user.id ? { ...u, is_approved: true } : u)),
            );
            this.shell.refreshPendingCount();
        } catch {
            // Failed
        }
    }

    async reject(user: AdminUser) {
        try {
            await this.api.rejectUser(user.id);
            this.users.update((list) =>
                list.map((u) => (u.id === user.id ? { ...u, is_approved: false } : u)),
            );
            this.shell.refreshPendingCount();
        } catch {
            // Failed
        }
    }
}
