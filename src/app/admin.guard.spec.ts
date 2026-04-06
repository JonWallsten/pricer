import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
    provideRouter,
    Router,
    ActivatedRouteSnapshot,
    RouterStateSnapshot,
} from '@angular/router';
import { adminGuard } from './admin.guard';
import { AuthService } from './auth.service';
import { User } from './models';

const mockRoute = {} as ActivatedRouteSnapshot;
const mockState = {} as RouterStateSnapshot;

function makeAuthService(user: User | null = null) {
    return {
        initPromise: Promise.resolve(),
        user: signal<User | null>(user),
    };
}

describe('adminGuard', () => {
    let router: Router;

    afterEach(() => {
        TestBed.resetTestingModule();
    });

    describe('when user is null', () => {
        beforeEach(() => {
            TestBed.configureTestingModule({
                providers: [
                    provideRouter([]),
                    { provide: AuthService, useValue: makeAuthService(null) },
                ],
            });
            router = TestBed.inject(Router);
        });

        it('redirects to root', async () => {
            const result = await TestBed.runInInjectionContext(() =>
                adminGuard(mockRoute, mockState),
            );
            expect(result).toEqual(router.parseUrl('/'));
        });
    });

    describe('when user is not an admin', () => {
        beforeEach(() => {
            const regularUser: User = {
                id: 2,
                email: 'user@example.com',
                name: 'Regular',
                picture_url: null,
                is_approved: true,
                is_admin: false,
            };
            TestBed.configureTestingModule({
                providers: [
                    provideRouter([]),
                    { provide: AuthService, useValue: makeAuthService(regularUser) },
                ],
            });
            router = TestBed.inject(Router);
        });

        it('redirects to root', async () => {
            const result = await TestBed.runInInjectionContext(() =>
                adminGuard(mockRoute, mockState),
            );
            expect(result).toEqual(router.parseUrl('/'));
        });
    });

    describe('when user is an admin', () => {
        beforeEach(() => {
            const adminUser: User = {
                id: 3,
                email: 'admin@example.com',
                name: 'Admin',
                picture_url: null,
                is_approved: true,
                is_admin: true,
            };
            TestBed.configureTestingModule({
                providers: [
                    provideRouter([]),
                    { provide: AuthService, useValue: makeAuthService(adminUser) },
                ],
            });
        });

        it('returns true', async () => {
            const result = await TestBed.runInInjectionContext(() =>
                adminGuard(mockRoute, mockState),
            );
            expect(result).toBe(true);
        });
    });
});
