import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Ensure session cookies are included (helps when app is served on a different port/domain in dev).
window.axios.defaults.withCredentials = true;

// Global response guard: if backend returns too many requests (429) or 419 (expired/invalid session),
// send the user to force logout to refresh credentials.
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        if (status === 429 || status === 419) {
            window.location = '/force-logout';
            return;
        }
        return Promise.reject(error);
    }
);
