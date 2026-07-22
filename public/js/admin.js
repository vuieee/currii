// public/js/admin.js
//
// Admin Module — mirrors the 6-step flow:
// 1. Opens the administration dashboard      -> boot()
// 2. Selects a user account to manage        -> selectUser()
// 3. Reviews the user's account information  -> renderDetail()
// 4. Updates, disables, or deletes           -> saveChanges() / toggleStatus() / deleteUser()
// 5. Confirms the administrative action      -> showConfirm() + toast()
// 6. Returns to the user management page     -> loadUsers() re-render after each action

document.addEventListener('DOMContentLoaded', () => {
    const State = {
        users: [],
        selectedId: null,
        selectedUser: null
    };

    // --- DOM references ---
    const gate = document.getElementById('admin-gate');
    const gateMessage = document.getElementById('admin-gate-message');
    const root = document.getElementById('admin-root');
    const whoami = document.getElementById('admin-whoami');

    const userListEl = document.getElementById('admin-user-list');
    const listEmptyEl = document.getElementById('admin-list-empty');
    const refreshBtn = document.getElementById('admin-refresh-btn');
    const logoutBtn = document.getElementById('admin-logout-btn');

    const detailEmpty = document.getElementById('admin-detail-empty');
    const detailForm = document.getElementById('admin-detail-form');
    const detailAvatar = document.getElementById('admin-detail-avatar');
    const detailId = document.getElementById('admin-detail-id');
    const detailJoined = document.getElementById('admin-detail-joined');
    const statSubs = document.getElementById('admin-stat-subs');
    const statBookmarks = document.getElementById('admin-stat-bookmarks');
    const statLastLogin = document.getElementById('admin-stat-lastlogin');
    const fieldEmail = document.getElementById('admin-field-email');
    const fieldRole = document.getElementById('admin-field-role');
    const fieldStatus = document.getElementById('admin-field-status');
    const detailError = document.getElementById('admin-detail-error');
    const toggleStatusBtn = document.getElementById('admin-toggle-status-btn');
    const deleteBtn = document.getElementById('admin-delete-btn');

    const toastContainer = document.getElementById('toast-container');

    // --- Toasts ---
    function toast(message, isError = false) {
        const el = document.createElement('div');
        el.className = `toast ${isError ? 'toast-error' : ''}`;
        el.textContent = message;
        toastContainer.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 200);
        }, 3200);
    }

    // --- Confirmation modal (Step 5) ---
    const appModal = document.getElementById('app-modal');
    const appModalForm = document.getElementById('app-modal-form');
    const appModalTitle = document.getElementById('app-modal-title');
    const appModalMessage = document.getElementById('app-modal-message');
    const appModalCancel = document.getElementById('app-modal-cancel');
    const appModalConfirmBtn = document.getElementById('app-modal-confirm');
    let modalResolver = null;

    function closeModal(result) {
        appModal.close ? appModal.close() : appModal.removeAttribute('open');
        if (modalResolver) { modalResolver(result); modalResolver = null; }
    }
    appModalCancel.addEventListener('click', () => closeModal(false));
    appModal.addEventListener('cancel', (e) => { e.preventDefault(); closeModal(false); });
    appModal.addEventListener('click', (e) => { if (e.target === appModal) closeModal(false); });
    appModalForm.addEventListener('submit', (e) => { e.preventDefault(); closeModal(true); });

    function showConfirm(title, message, danger) {
        appModalTitle.textContent = title;
        appModalMessage.textContent = message;
        appModalMessage.classList.remove('hidden');
        appModalConfirmBtn.textContent = danger ? 'Confirm' : 'OK';
        appModalConfirmBtn.classList.toggle('danger', !!danger);
        appModal.showModal ? appModal.showModal() : appModal.setAttribute('open', '');
        setTimeout(() => appModalConfirmBtn.focus(), 10);
        return new Promise((resolve) => { modalResolver = resolve; }).then(r => !!r);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function formatDate(value) {
        if (!value) return '—';
        const d = new Date(value.replace(' ', 'T'));
        if (isNaN(d)) return value;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // --- Step 1: Opens the administration dashboard ---
    async function boot() {
        try {
            const me = await API.me();
            if (!me.success || me.guest || !me.user || me.user.role !== 'Admin') {
                gateMessage.textContent = 'You need an administrator account to view this page.';
                gate.classList.remove('hidden');
                root.classList.add('hidden');
                return;
            }
            API.csrfToken = me.csrf_token;
            whoami.textContent = me.user.email;
            gate.classList.add('hidden');
            root.classList.remove('hidden');
            await loadUsers();
        } catch (err) {
            gateMessage.textContent = 'Please log in with an administrator account first.';
            gate.classList.remove('hidden');
            root.classList.add('hidden');
        }
    }

    // Action 1: Displays the administration dashboard and the list of registered users.
    async function loadUsers() {
        try {
            const res = await API.adminGetUsers();
            State.users = res.data || [];
            renderUserList();
        } catch (err) {
            toast(err.message, true);
        }
    }

    function renderUserList() {
        userListEl.innerHTML = '';
        listEmptyEl.classList.toggle('hidden', State.users.length > 0);

        State.users.forEach(u => {
            const tr = document.createElement('tr');
            tr.dataset.id = u.id;
            tr.className = String(u.id) === String(State.selectedId) ? 'active' : '';
            tr.innerHTML = `
                <td>${escapeHtml(u.email)}</td>
                <td class="admin-col-role"><span class="admin-role-chip ${u.role}">${u.role}</span></td>
                <td class="admin-col-status"><span class="admin-status-chip ${u.status}">${u.status}</span></td>
                <td>${formatDate(u.created_at)}</td>
            `;
            tr.addEventListener('click', () => selectUser(u.id));
            userListEl.appendChild(tr);
        });
    }

    // Step 2: Selects a user account to manage.
    async function selectUser(id) {
        State.selectedId = id;
        renderUserList(); // update highlighted row
        try {
            const res = await API.adminGetUser(id);
            State.selectedUser = res.data;
            renderDetail(res.data);
        } catch (err) {
            toast(err.message, true);
        }
    }

    // Step 3: Reviews the user's account information.
    function renderDetail(user) {
        detailEmpty.classList.add('hidden');
        detailForm.classList.remove('hidden');
        detailError.classList.add('hidden');

        detailAvatar.textContent = (user.email || '?').charAt(0).toUpperCase();
        detailId.textContent = `User #${user.id}`;
        detailJoined.textContent = `Joined ${formatDate(user.created_at)}`;

        statSubs.textContent = user.subscription_count ?? 0;
        statBookmarks.textContent = user.bookmark_count ?? 0;
        statLastLogin.textContent = user.last_login ? formatDate(user.last_login) : 'Never';

        fieldEmail.value = user.email;
        fieldRole.value = user.role;
        fieldStatus.value = user.status;

        toggleStatusBtn.textContent = user.status === 'Active' ? 'Disable account' : 'Re-enable account';
    }

    // Step 4/5: Updates the selected user's account information, confirms, then refreshes (Step 6).
    detailForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!State.selectedId) return;
        detailError.classList.add('hidden');

        const confirmed = await showConfirm(
            'Save changes?',
            `Update account details for ${State.selectedUser.email}?`
        );
        if (!confirmed) return;

        try {
            const res = await API.adminUpdateUser(
                State.selectedId,
                fieldEmail.value.trim(),
                fieldRole.value,
                fieldStatus.value
            );
            toast(res.message || 'Account updated successfully');
            State.selectedUser = res.data;
            renderDetail(res.data);
            await loadUsers(); // Step 6: return to the (refreshed) user management page
        } catch (err) {
            detailError.textContent = err.message;
            detailError.classList.remove('hidden');
        }
    });

    // Step 4/5: Disable or re-enable the selected account.
    toggleStatusBtn.addEventListener('click', async () => {
        if (!State.selectedId) return;
        const nextStatus = State.selectedUser.status === 'Active' ? 'Disabled' : 'Active';
        const verb = nextStatus === 'Disabled' ? 'disable' : 're-enable';

        const confirmed = await showConfirm(
            `${verb.charAt(0).toUpperCase() + verb.slice(1)} account?`,
            `Are you sure you want to ${verb} ${State.selectedUser.email}?`,
            nextStatus === 'Disabled'
        );
        if (!confirmed) return;

        try {
            const res = await API.adminSetStatus(State.selectedId, nextStatus);
            toast(res.message || 'Status updated');
            State.selectedUser = res.data;
            renderDetail(res.data);
            await loadUsers(); // Step 6
        } catch (err) {
            toast(err.message, true);
        }
    });

    // Step 4/5: Delete the selected account.
    deleteBtn.addEventListener('click', async () => {
        if (!State.selectedId) return;
        const confirmed = await showConfirm(
            'Delete account?',
            `This permanently deletes ${State.selectedUser.email} and all their data. This cannot be undone.`,
            true
        );
        if (!confirmed) return;

        try {
            const res = await API.adminDeleteUser(State.selectedId);
            toast(res.message || 'Account deleted successfully');
            State.selectedId = null;
            State.selectedUser = null;
            detailForm.classList.add('hidden');
            detailEmpty.classList.remove('hidden');
            await loadUsers(); // Step 6: back to the user management page
        } catch (err) {
            toast(err.message, true);
        }
    });

    refreshBtn.addEventListener('click', loadUsers);

    logoutBtn.addEventListener('click', async () => {
        try { await API.logout(); } catch (_) { /* proceed regardless */ }
        location.href = '/';
    });

    boot();

    // See app.js for why this is needed: bfcache restores don't re-run boot()
    // on their own, which can leave a stale gate/dashboard state after using
    // the browser's Back/Forward buttons.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            boot();
        }
    });
});
