import { createRouter, createWebHistory } from 'vue-router';
import Login from './components/Login.vue';
import Settings from './components/Settings.vue';
import Reviews from './components/Reviews.vue';
import api from './api';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: Login,
    },
    {
        path: '/',
        redirect: '/settings',
    },
    {
        path: '/settings',
        name: 'settings',
        component: Settings,
        meta: { requiresAuth: true },
    },
    {
        path: '/reviews/:orgId',
        name: 'reviews',
        component: Reviews,
        meta: { requiresAuth: true },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to, from, next) => {
    if (to.meta.requiresAuth) {
        try {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.get('/api/user');
            if (!data) {
                return next({ name: 'login' });
            }
        } catch {
            return next({ name: 'login' });
        }
    }
    next();
});

export default router;
