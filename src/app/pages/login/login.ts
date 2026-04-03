import {
    ChangeDetectionStrategy,
    Component,
    ElementRef,
    OnInit,
    inject,
    viewChild,
} from '@angular/core';
import { Router } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { AuthService } from '../../auth.service';
import { I18nService } from '../../i18n.service';

@Component({
    selector: 'app-login',
    templateUrl: './login.html',
    styleUrl: './login.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [MatCardModule, MatProgressSpinnerModule],
})
export class Login implements OnInit {
    protected readonly auth = inject(AuthService);
    protected readonly i18n = inject(I18nService);
    private readonly router = inject(Router);

    readonly googleBtn = viewChild<ElementRef<HTMLDivElement>>('googleBtn');

    ngOnInit() {
        // If already logged in, redirect
        if (this.auth.user()) {
            this.router.navigate(['/']);
            return;
        }

        // Wait for GSI script to load, then render the button
        this.waitForGsi();
    }

    private waitForGsi() {
        const tryRender = () => {
            const el = this.googleBtn()?.nativeElement;
            if (el && typeof google !== 'undefined') {
                this.auth.renderGoogleButton(el);
            } else {
                setTimeout(tryRender, 200);
            }
        };
        tryRender();
    }
}

declare const google: unknown;
