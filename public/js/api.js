// public/js/api.js

const API = {
    csrfToken: null,

    async request(endpoint, method = 'GET', data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };

        if (this.csrfToken && method !== 'GET') {
            options.headers['X-CSRF-Token'] = this.csrfToken;
        }
        if (data) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(endpoint, options);
            const result = await response.json();

            if (!response.ok || result.error) {
                throw new Error(result.error || `HTTP ${response.status}`);
            }
            return result;
        } catch (error) {
            console.error(`[API Error] ${endpoint}:`, error.message);
            throw error;
        }
    },

    // --- Authentication ---
    login(email, password) {
        return this.request('/api/login', 'POST', { email, password }).then(r => { this.csrfToken = r.token; return r; });
    },
    register: (email, password) => API.request('/api/register', 'POST', { email, password }),
    guestLogin() {
        return this.request('/api/guest', 'POST', {}).then(r => { this.csrfToken = r.token; return r; });
    },
    logout: () => API.request('/api/logout', 'POST', {}),
    me: () => API.request('/api/me', 'GET'),

    // --- Feeds & Sources ---
    getSources: () => API.request('/api/feeds/sources', 'GET'),
    addSource: (url) => API.request('/api/feeds/add', 'POST', { url }),
    removeSource: (feed_id) => API.request('/api/feeds/remove', 'POST', { feed_id }),
    refreshSource: (feed_id) => API.request('/api/feeds/refresh', 'POST', { feed_id }),
    toggleNotification: (feed_id, notify) => API.request('/api/feeds/notify', 'POST', { feed_id, notify }),

    // --- Articles ---
    getArticles: (page = 1) => API.request('/api/articles/feed', 'POST', { page }),
    getBookmarks: (page = 1) => API.request('/api/articles/bookmarks', 'POST', { page }),
    search: (q) => API.request('/api/articles/search', 'POST', { q }),
    toggleArticle: (article_id, field, state) => API.request('/api/articles/toggle', 'POST', { article_id, field, state }),
    markAllRead: () => API.request('/api/articles/mark-all-read', 'POST', {}),
    fetchFullContent: (article_id) => API.request('/api/articles/fetch-full', 'POST', { article_id }),

    // --- Admin ---
    adminGetUsers: () => API.request('/api/admin/users', 'GET'),
    adminGetUser: (id) => API.request('/api/admin/user', 'POST', { id }),
    adminUpdateUser: (id, email, role, status) => API.request('/api/admin/user/update', 'POST', { id, email, role, status }),
    adminSetStatus: (id, status) => API.request('/api/admin/user/status', 'POST', { id, status }),
    adminDeleteUser: (id) => API.request('/api/admin/user/delete', 'POST', { id })
};
