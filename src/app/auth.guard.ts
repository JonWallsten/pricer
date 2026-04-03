import { inject } from '@angular/core';
import {
    CanActivateFn,
    Router,
    ActivatedRouteSnapshot,
    RouterStateSnapshot,
} from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = async (
    _route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot,
) => {
    const auth = inject(AuthService);
    const router = inject(Router);

    await auth.initPromise;

    const user = auth.user();
    if (!user) {
        auth.returnUrl = state.url;
        return router.parseUrl('/login');
    }

    if (!user.is_approved) {
        return router.parseUrl('/pending');
    }

    return true;
};
