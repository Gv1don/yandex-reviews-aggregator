import axios from 'axios';

const api = axios.create({
    baseURL: '',
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
    },
    withCredentials: true,
    withXSRFToken: true,
});

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        if (error.response?.status === 419) {
            await api.get('/sanctum/csrf-cookie');
            return api(error.config);
        }
        return Promise.reject(error);
    },
);

export default api;
