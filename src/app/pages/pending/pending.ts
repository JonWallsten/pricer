import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { AuthService } from '../../auth.service';
import { I18nService } from '../../i18n.service';

@Component({
    selector: 'app-pending',
    templateUrl: './pending.html',
    styleUrl: './pending.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [MatCardModule, MatButtonModule, MatIconModule],
})
export class Pending {
    protected readonly auth = inject(AuthService);
    protected readonly i18n = inject(I18nService);
}
