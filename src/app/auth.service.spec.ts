import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from './auth.service';

@Component({ template: '' })
class StubComponent {}

function mockFetchOnce(responses: { ok: boolean; json?: object }[]) {
    let callIndex = 0;
    return vi.fn().mockImplementation(() => {
        const resp = responses[callIndex++] ?? { ok: false };
        return Promise.resolve({
            ok: resp.ok,
            json: () => Promise.resolve(resp.json ?? {}),
        });
    });
}

describe('AuthService', () => {
    let service: AuthService;

    afterEach(() => {
        vi.restoreAllMocks();
        TestBed.resetTestingModule();
    });

    describe('init()', () => {
        it('sets loading to false after successful init', async () => {
            vi.stubGlobal(
                'fetch',
                mockFetchOnce([
                    { ok: true, json: { google_client_id: 'client-id' } },
                    { ok: false },
                ]),
            );
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            expect(service.loading()).toBe(true);
            await service.init();
            expect(service.loading()).toBe(false);
        });

        it('sets user when /auth/me returns a valid user', async () => {
            const fakeUser = {
                id: 1,
                email: 'user@example.com',
                name: 'User',
                picture_url: null,
                is_approved: true,
                is_admin: false,
            };
            vi.stubGlobal(
                'fetch',
                mockFetchOnce([
                    { ok: true, json: { google_client_id: 'client-id' } },
                    { ok: true, json: { user: fakeUser } },
                ]),
            );
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            await service.init();
            expect(service.user()).toEqual(fakeUser);
        });

        it('leaves user as null when /auth/me returns 401', async () => {
            vi.stubGlobal(
                'fetch',
                mockFetchOnce([
                    { ok: true, json: { google_client_id: 'client-id' } },
                    { ok: false },
                ]),
            );
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            await service.init();
            expect(service.user()).toBeNull();
        });

        it('resolves initPromise even when config fetch fails', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network error')));
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            await expect(service.init()).resolves.toBeUndefined();
            await expect(service.initPromise).resolves.toBeUndefined();
            expect(service.loading()).toBe(false);
        });

        it('resolves initPromise after successful init', async () => {
            vi.stubGlobal(
                'fetch',
                mockFetchOnce([
                    { ok: true, json: { google_client_id: 'client-id' } },
                    { ok: false },
                ]),
            );
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            await service.init();
            await expect(service.initPromise).resolves.toBeUndefined();
        });
    });

    describe('logout()', () => {
        it('clears the user signal', async () => {
            const fakeUser = {
                id: 1,
                email: 'user@example.com',
                name: 'User',
                picture_url: null,
                is_approved: true,
                is_admin: false,
            };
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({}) }),
            );
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);
            service.user.set(fakeUser);

            await service.logout();
            expect(service.user()).toBeNull();
        });

        it('calls logout endpoint', async () => {
            const fetchSpy = vi
                .fn()
                .mockResolvedValue({ ok: true, json: () => Promise.resolve({}) });
            vi.stubGlobal('fetch', fetchSpy);
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            await service.logout();
            expect(fetchSpy).toHaveBeenCalledWith(
                'api/auth/logout',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        it('navigates to /login after logout', async () => {
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({}) }),
            );
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);
            const router = (await import('@angular/router')).Router;
            const routerService = TestBed.inject(router);
            const navSpy = vi.spyOn(routerService, 'navigate').mockResolvedValue(true);

            await service.logout();
            expect(navSpy).toHaveBeenCalledWith(['/login']);
        });
    });

    describe('renderGoogleButton()', () => {
        it('does nothing when clientId is not loaded', () => {
            vi.stubGlobal('fetch', vi.fn());
            TestBed.configureTestingModule({ providers: [provideRouter([{ path: "**", component: StubComponent }])] });
            service = TestBed.inject(AuthService);

            const el = document.createElement('div');
            // Should not throw — no clientId means early return
            expect(() => service.renderGoogleButton(el)).not.toThrow();
        });
    });
});
