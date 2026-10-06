import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Surface auth failures instead of leaving a blank section on screen.
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;

        if (status === 401) {
            window.location.href = '/login';
        } else if (status === 403) {
            console.warn('GovQueue request denied (403).', error.response?.data);
        }

        return Promise.reject(error);
    },
);

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
