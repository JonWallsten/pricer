import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    const user = auth.user();
    if (!user) {
        return router.parseUrl('/login');
    }

    if (!user.is_approved) {
        return router.parseUrl('/pending');
    }

    return true;
};
