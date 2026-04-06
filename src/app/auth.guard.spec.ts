import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
    provideRouter,
    Router,
    ActivatedRouteSnapshot,
    RouterStateSnapshot,
} from '@angular/router';
import { authGuard } from './auth.guard';
import { AuthService } from './auth.service';
import { User } from './models';

function makeAuthService(user: User | null = null) {
    return {
        initPromise: Promise.resolve(),
        user: signal<User | null>(user),
        returnUrl: '/',
    };
}

const mockRoute = {} as ActivatedRouteSnapshot;

describe('authGuard', () => {
    let router: Router;

    afterEach(() => {
        TestBed.resetTestingModule();
    });

    describe('when user is not logged in', () => {
        beforeEach(() => {
            TestBed.configureTestingModule({
                providers: [
                    provideRouter([]),
                    { provide: AuthService, useValue: makeAuthService(null) },
                ],
            });
            router = TestBed.inject(Router);
        });

        it('returns a UrlTree redirecting to /login', async () => {
            const state = { url: '/dashboard' } as RouterStateSnapshot;
            const result = await TestBed.runInInjectionContext(() => authGuard(mockRoute, state));
            expect(result).toEqual(router.parseUrl('/login'));
        });

        it('stores the attempted URL in returnUrl', async () => {
            const authService = TestBed.inject(AuthService);
            const state = { url: '/products/1' } as RouterStateSnapshot;
            await TestBed.runInInjectionContext(() => authGuard(mockRoute, state));
            expect(authService.returnUrl).toBe('/products/1');
        });
    });

    describe('when user is logged in but not approved', () => {
        beforeEach(() => {
            const unapprovedUser: User = {
                id: 1,
                email: 'test@example.com',
                name: 'Test',
                picture_url: null,
                is_approved: false,
                is_admin: false,
            };
            TestBed.configureTestingModule({
                providers: [
                    provideRouter([]),
                    { provide: AuthService, useValue: makeAuthService(unapprovedUser) },
                ],
            });
            router = TestBed.inject(Router);
        });

        it('returns a UrlTree redirecting to /pending', async () => {
            const state = { url: '/dashboard' } as RouterStateSnapshot;
            const result = await TestBed.runInInjectionContext(() => authGuard(mockRoute, state));
            expect(result).toEqual(router.parseUrl('/pending'));
        });
    });

    describe('when user is logged in and approved', () => {
        beforeEach(() => {
            const approvedUser: User = {
                id: 1,
                email: 'test@example.com',
                name: 'Test',
                picture_url: null,
                is_approved: true,
                is_admin: false,
            };
            TestBed.configureTestingModule({
                providers: [
                    provideRouter([]),
                    { provide: AuthService, useValue: makeAuthService(approvedUser) },
                ],
            });
        });

        it('returns true', async () => {
            const state = { url: '/dashboard' } as RouterStateSnapshot;
            const result = await TestBed.runInInjectionContext(() => authGuard(mockRoute, state));
            expect(result).toBe(true);
        });
    });
});
