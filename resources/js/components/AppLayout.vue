<template>
    <div class="min-h-screen bg-gray-50">
        <nav v-if="user" class="bg-white shadow-sm border-b">
            <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
                <div class="flex items-center gap-6">
                    <router-link to="/settings" class="font-semibold text-gray-800 hover:text-blue-600 transition">
                        Organizations
                    </router-link>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-500">{{ user?.email }}</span>
                    <button @click="logout" class="text-sm text-red-600 hover:text-red-800 transition cursor-pointer">
                        Logout
                    </button>
                </div>
            </div>
        </nav>
        <main class="max-w-5xl mx-auto px-4 py-8">
            <router-view @login="onLogin" />
        </main>
    </div>
</template>

<script setup>
import { ref, provide } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const router = useRouter();
const user = ref(null);

provide('user', user);

async function checkAuth() {
    try {
        await api.get('/sanctum/csrf-cookie');
        const { data } = await api.get('/api/user');
        user.value = data;
    } catch {
        user.value = null;
    }
}

function onLogin(u) {
    user.value = u;
}

async function logout() {
    await api.post('/api/logout');
    user.value = null;
    router.push({ name: 'login' });
}

checkAuth();
</script>
