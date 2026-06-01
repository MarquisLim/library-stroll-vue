import axios from 'axios';
import './echo';

window.axios = axios;
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['X-CSRF-TOKEN'] = document
    .querySelector('meta[name="csrf-token"]')
    .getAttribute('content');

axios.interceptors.request.use(config => {
    const token = document.head
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content')
    if (token) {
        config.headers['X-CSRF-TOKEN'] = token
    }
    return config
})
