<template>
    <div class="max-w-sm mx-auto mt-20">
        <div class="bg-white p-8 rounded-xl shadow-sm border">
            <h1 class="text-2xl font-bold mb-6 text-center">Sign in</h1>
            <form @submit.prevent="login" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="test@example.com"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="password"
                    />
                </div>
                <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>
                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full py-2 px-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition cursor-pointer"
                >
                    {{ loading ? 'Signing in...' : 'Sign in' }}
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const router = useRouter();
const emit = defineEmits(['login']);

const form = ref({ email: '', password: '' });
const error = ref(null);
const loading = ref(false);

async function login() {
    error.value = null;
    loading.value = true;
    try {
        await api.get('/sanctum/csrf-cookie');
        const { data } = await api.post('/api/login', form.value);
        emit('login', data.user);
        router.push({ name: 'settings' });
    } catch (e) {
        error.value = e.response?.data?.message || 'Invalid credentials';
    } finally {
        loading.value = false;
    }
}
</script>
