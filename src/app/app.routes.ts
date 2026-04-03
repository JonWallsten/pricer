import { Routes } from '@angular/router';
import { authGuard } from './auth.guard';
import { adminGuard } from './admin.guard';

export const routes: Routes = [
    {
        path: 'login',
        loadComponent: () => import('./pages/login/login').then((m) => m.Login),
    },
    {
        path: 'pending',
        loadComponent: () => import('./pages/pending/pending').then((m) => m.Pending),
    },
    {
        path: 'admin',
        canActivate: [authGuard, adminGuard],
        loadComponent: () => import('./pages/admin/admin').then((m) => m.Admin),
    },
    {
        path: '',
        canActivate: [authGuard],
        loadComponent: () => import('./pages/dashboard/dashboard').then((m) => m.Dashboard),
    },
    {
        path: 'products/new',
        canActivate: [authGuard],
        loadComponent: () => import('./pages/product-form/product-form').then((m) => m.ProductForm),
    },
    {
        path: 'products/:id/edit',
        canActivate: [authGuard],
        loadComponent: () => import('./pages/product-form/product-form').then((m) => m.ProductForm),
    },
    {
        path: 'products/:id',
        canActivate: [authGuard],
        loadComponent: () =>
            import('./pages/product-detail/product-detail').then((m) => m.ProductDetail),
    },
    {
        path: '**',
        redirectTo: '',
    },
];
