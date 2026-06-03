import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Sanctum CSRF cookie is handled automatically by Axios when using session auth.
// For SPA token auth, attach Bearer token from local storage if present.
const token = localStorage.getItem('sanctum_token');
if (token) {
    window.axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
}
