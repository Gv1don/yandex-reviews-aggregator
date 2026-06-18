<template>
    <div>
        <div class="bg-white p-6 rounded-xl shadow-sm border mb-6">
            <h2 class="text-xl font-bold mb-4">Add Organization</h2>
            <form @submit.prevent="saveOrganization" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Yandex Maps organization URL
                    </label>
                    <input
                        v-model="url"
                        type="url"
                        required
                        class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="https://yandex.ru/maps/org/.../"
                    />
                    <p class="text-xs text-gray-500 mt-1">
                        Paste a link to any organization on Yandex Maps
                    </p>
                </div>
                <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>
                <button
                    type="submit"
                    :disabled="loading"
                    class="py-2 px-6 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition cursor-pointer"
                >
                    {{ loading ? 'Fetching...' : 'Save & Fetch' }}
                </button>
            </form>
        </div>

        <div v-if="organizations.length === 0" class="text-center py-12 text-gray-500">
            No organizations added yet. Paste a Yandex Maps URL above.
        </div>

        <div v-for="org in organizations" :key="org.id" class="bg-white p-6 rounded-xl shadow-sm border mb-4">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-lg font-bold">{{ org.name }}</h3>
                    <p v-if="org.address" class="text-sm text-gray-500">{{ org.address }}</p>
                </div>
                <div class="flex gap-2">
                    <router-link
                        :to="{ name: 'reviews', params: { orgId: org.id } }"
                        class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition cursor-pointer"
                    >
                        View Reviews
                    </router-link>
                    <button
                        @click="deleteOrganization(org)"
                        class="px-3 py-2 bg-red-50 text-red-600 text-sm rounded-lg hover:bg-red-100 transition cursor-pointer"
                    >
                        Delete
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-4 gap-4">
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600">{{ org.average_rating ?? '—' }}</div>
                    <div class="text-xs text-gray-500">Avg Rating</div>
                </div>
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-green-600">{{ org.ratings_count ?? '—' }}</div>
                    <div class="text-xs text-gray-500">Ratings</div>
                </div>
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-orange-600">{{ org.reviews_count ?? '—' }}</div>
                    <div class="text-xs text-gray-500">Reviews</div>
                </div>
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-xs text-gray-500">Parsed</div>
                    <div class="text-xs text-gray-400 mt-1">
                        {{ org.parsed_at ? new Date(org.parsed_at).toLocaleDateString() : 'never' }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';

const url = ref('');
const organizations = ref([]);
const error = ref(null);
const loading = ref(false);

onMounted(fetchOrganizations);

async function fetchOrganizations() {
    try {
        const { data } = await api.get('/api/organizations');
        organizations.value = data ?? [];
    } catch {
        organizations.value = [];
    }
}

async function saveOrganization() {
    error.value = null;
    loading.value = true;
    try {
        await api.post('/api/organization', { yandex_url: url.value });
        url.value = '';
        await fetchOrganizations();
    } catch (e) {
        const msg = e.response?.data?.message
            || Object.values(e.response?.data?.errors || {}).flat().join(', ')
            || 'Failed to fetch organization data';
        error.value = msg;
    } finally {
        loading.value = false;
    }
}

async function deleteOrganization(org) {
    if (!confirm(`Delete "${org.name}"?`)) return;
    try {
        await api.delete(`/api/organizations/${org.id}`);
        await fetchOrganizations();
    } catch {
        error.value = 'Failed to delete organization. Please try again.';
    }
}
</script>
