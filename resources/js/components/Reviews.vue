<template>
    <div>
        <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ error }}</div>

        <div v-if="!org && !loading" class="text-center py-12 text-gray-500">
            Organization not found.
            <router-link to="/settings" class="text-blue-600 hover:underline">Go to Settings</router-link>
        </div>

        <div v-if="org" class="bg-white p-4 rounded-xl shadow-sm border mb-6 flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <router-link to="/settings" class="text-sm text-blue-600 hover:underline">← Back</router-link>
                </div>
                <h2 class="font-bold text-lg">{{ org.name }}</h2>
                <p v-if="org.address" class="text-sm text-gray-500">{{ org.address }}</p>
                <div class="flex gap-4 mt-1 text-sm text-gray-500">
                    <span>Rating: <strong class="text-blue-600">{{ org.average_rating }}</strong></span>
                    <span>Ratings: <strong>{{ org.ratings_count }}</strong></span>
                    <span>Reviews: <strong>{{ org.reviews_count }}</strong></span>
                </div>
            </div>
            <button @click="refresh" :disabled="refreshing" class="text-sm px-3 py-1 bg-gray-100 rounded hover:bg-gray-200 transition cursor-pointer">
                {{ refreshing ? 'Refreshing...' : 'Refresh' }}
            </button>
        </div>

        <div v-if="loading" class="text-center py-8 text-gray-500">Loading reviews...</div>

        <div v-for="review in reviews" :key="review.id" class="bg-white p-4 rounded-xl shadow-sm border mb-3">
            <div class="flex justify-between items-start mb-2">
                <div>
                    <span class="font-semibold">{{ review.author }}</span>
                    <span class="text-xs text-gray-400 ml-2">{{ new Date(review.date).toLocaleDateString() }}</span>
                </div>
                <div class="flex items-center">
                    <span v-for="i in 5" :key="i" class="text-sm" :class="i <= review.rating ? 'text-yellow-400' : 'text-gray-200'">★</span>
                </div>
            </div>
            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ review.text }}</p>
        </div>

        <div v-if="paginator" class="flex items-center justify-center gap-4 mt-6">
            <button
                @click="changePage(paginator.current_page - 1)"
                :disabled="!paginator.prev_page_url"
                class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 disabled:opacity-30 transition cursor-pointer"
            >
                ← Prev
            </button>
            <span class="text-sm text-gray-500">
                Page {{ paginator.current_page }} of {{ paginator.last_page }}
            </span>
            <button
                @click="changePage(paginator.current_page + 1)"
                :disabled="!paginator.next_page_url"
                class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 disabled:opacity-30 transition cursor-pointer"
            >
                Next →
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();

const org = ref(null);
const reviews = ref([]);
const paginator = ref(null);
const loading = ref(false);
const refreshing = ref(false);
const page = ref(1);
const error = ref(null);

onMounted(() => {
    page.value = 1;
    fetchData();
});

watch(() => route.params.orgId, () => {
    page.value = 1;
    fetchData();
});

async function fetchData() {
    loading.value = true;
    try {
        const [orgRes, reviewsRes] = await Promise.all([
            api.get(`/api/organizations/${route.params.orgId}`),
            api.get('/api/reviews', { params: { organization_id: route.params.orgId, page: page.value } }),
        ]);
        error.value = null;
        org.value = orgRes.data;
        reviews.value = reviewsRes.data.data;
        paginator.value = reviewsRes.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Failed to load reviews';
        org.value = null;
        reviews.value = [];
        paginator.value = null;
    } finally {
        loading.value = false;
    }
}

function changePage(p) {
    if (p < 1 || p > (paginator.value?.last_page || 1)) return;
    error.value = null;
    page.value = p;
    fetchData();
}

async function refresh() {
    refreshing.value = true;
    try {
        await api.post('/api/organization', { yandex_url: org.value.yandex_url });
    } catch (e) {
        error.value = e.response?.data?.message || 'Failed to refresh reviews';
    }
    refreshing.value = false;
    page.value = 1;
    await fetchData();
}
</script>
