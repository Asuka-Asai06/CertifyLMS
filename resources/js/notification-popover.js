/**
 * 通知ポップオーバーを初期化する。
 */
export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');

    if (!root) {
        return;
    }

    const trigger = root.querySelector('[data-notification-popover-trigger]');
    const panel = root.querySelector('[data-notification-popover-panel]');
    const badge = root.querySelector('[data-notification-popover-badge]');
    const tabs = root.querySelectorAll('[data-notification-popover-tab]');
    const unreadCountElement = root.querySelector(
        '[data-notification-popover-unread-count]',
    );
    const markAllButton = root.querySelector(
        '[data-notification-popover-mark-all]',
    );
    const loadingElement = root.querySelector(
        '[data-notification-popover-loading]',
    );
    const emptyElement = root.querySelector(
        '[data-notification-popover-empty]',
    );
    const itemsElement = root.querySelector(
        '[data-notification-popover-items]',
    );
    const rowTemplate = root.querySelector(
        '[data-notification-popover-row-template]',
    );

    if (
        !trigger ||
        !panel ||
        !itemsElement ||
        !rowTemplate ||
        !loadingElement ||
        !emptyElement
    ) {
        return;
    }

    const api = window.axios;

    if (!api) {
        console.error('Axios が読み込まれていません。');
        return;
    }

    api.defaults.withCredentials = true;
    api.defaults.withXSRFToken = true;

    let notifications = [];
    let currentTab = 'all';
    let isOpen = false;
    let isLoading = false;

    function updateUnreadCount(count) {
        const unreadCount = Number(count) || 0;

        if (badge) {
            badge.textContent = String(unreadCount);
            badge.classList.toggle('hidden', unreadCount === 0);
        }

        if (unreadCountElement) {
            unreadCountElement.textContent = String(unreadCount);
        }
    }

    function formatRelativeTime(createdAt) {
        if (!createdAt) {
            return '';
        }

        const timestamp = new Date(createdAt).getTime();

        if (Number.isNaN(timestamp)) {
            return '';
        }

        const elapsedSeconds = Math.max(
            0,
            Math.floor((Date.now() - timestamp) / 1000),
        );

        if (elapsedSeconds < 60) {
            return 'たった今';
        }

        if (elapsedSeconds < 3600) {
            return `${Math.floor(elapsedSeconds / 60)}分前`;
        }

        if (elapsedSeconds < 86400) {
            return `${Math.floor(elapsedSeconds / 3600)}時間前`;
        }

        if (elapsedSeconds < 604800) {
            return `${Math.floor(elapsedSeconds / 86400)}日前`;
        }

        return new Intl.DateTimeFormat('ja-JP', {
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
        }).format(new Date(timestamp));
    }

    function getSafeUrl(url) {
        try {
            const destination = new URL(url || '/', window.location.origin);

            if (destination.origin !== window.location.origin) {
                return '/notifications';
            }

            return destination.href;
        } catch {
            return '/notifications';
        }
    }

    function renderNotifications() {
        itemsElement.replaceChildren();

        const visibleNotifications = notifications.filter((notification) => {
            return currentTab === 'all' || !notification.is_read;
        });

        visibleNotifications.forEach((notification) => {
            const fragment = rowTemplate.content.cloneNode(true);
            const row = fragment.querySelector(
                '[data-notification-popover-row]',
            );

            const dot = fragment.querySelector(
                '[data-notification-popover-row-dot]',
            );
            const title = fragment.querySelector(
                '[data-notification-popover-row-title]',
            );
            const message = fragment.querySelector(
                '[data-notification-popover-row-message]',
            );
            const time = fragment.querySelector(
                '[data-notification-popover-row-time]',
            );

            if (!row || !title || !message || !time || !dot) {
                return;
            }

            row.href = getSafeUrl(notification.url);
            row.dataset.unread = String(!notification.is_read);
            row.setAttribute(
                'aria-label',
                `${notification.title}${notification.is_read ? '' : '（未読）'}`,
            );

            // textContent を使用し、通知内容を HTML として実行させない。
            title.textContent = notification.title;
            message.textContent = notification.preview || notification.message;
            time.textContent = formatRelativeTime(notification.created_at);

            dot.classList.toggle('hidden', notification.is_read);

            row.addEventListener('click', async (event) => {
                if (notification.is_read) {
                    return;
                }

                event.preventDefault();

                try {
                    const response = await api.post(
                        `/api/v1/notifications/${encodeURIComponent(notification.id)}/read`,
                    );

                    notification.is_read = true;
                    updateUnreadCount(response.data.unread_count);
                    renderNotifications();

                    window.location.assign(getSafeUrl(notification.url));
                } catch (error) {
                    console.error('通知を既読にできませんでした。', error);
                    window.alert('通知を開けませんでした。もう一度お試しください。');
                }
            });

            itemsElement.appendChild(fragment);
        });

        emptyElement.classList.toggle(
            'hidden',
            isLoading || visibleNotifications.length > 0,
        );
    }

    /**
     * API から通知一覧を取得する。
     */
    async function fetchNotifications() {
        if (isLoading) {
            return;
        }

        isLoading = true;
        loadingElement.classList.remove('hidden');
        emptyElement.classList.add('hidden');

        try {
            const response = await api.get('/api/v1/notifications');

            notifications = Array.isArray(response.data.data)
                ? response.data.data
                : [];

            updateUnreadCount(response.data.unread_count);
            renderNotifications();
        } catch (error) {
            console.error('通知を取得できませんでした。', error);
            emptyElement.textContent = '通知を読み込めませんでした。';
            emptyElement.classList.remove('hidden');
        } finally {
            isLoading = false;
            loadingElement.classList.add('hidden');
            renderNotifications();
        }
    }

    /**
     * ポップオーバーを開く。
     */
    async function openPopover() {
        isOpen = true;
        panel.classList.remove('hidden');
        panel.style.display = 'flex';

        trigger.setAttribute('aria-expanded', 'true');

        requestAnimationFrame(() => {
            panel.classList.remove('opacity-0', '-translate-y-1');
            panel.classList.add('opacity-100', 'translate-y-0');
        });

        await fetchNotifications();
    }

    /**
     * ポップオーバーを閉じる。
     */
    function closePopover() {
        isOpen = false;
        panel.classList.remove('opacity-100', 'translate-y-0');
        panel.classList.add('opacity-0', '-translate-y-1');

        trigger.setAttribute('aria-expanded', 'false');

        // CSS のトランジション時間に合わせて非表示にする。
        window.setTimeout(() => {
            if (!isOpen) {
                panel.classList.add('hidden');
                panel.style.display = 'none';
            }
        }, 150);
    }

    trigger.addEventListener('click', () => {
        if (isOpen) {
            closePopover();
        } else {
            openPopover();
        }
    });

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            currentTab = tab.dataset.notificationPopoverTab;

            tabs.forEach((item) => {
                item.setAttribute(
                    'aria-selected',
                    String(item === tab),
                );
            });

            renderNotifications();
        });
    });

    if (markAllButton) {
        markAllButton.addEventListener('click', async () => {
            markAllButton.disabled = true;

            try {
                await api.get('/sanctum/csrf-cookie');

                const response = await api.post(
                    '/api/v1/notifications/read-all',
                );

                notifications.forEach((notification) => {
                    notification.is_read = true;
                });

                updateUnreadCount(response.data.unread_count);
                renderNotifications();
            } catch (error) {
                console.error('通知を一括既読にできませんでした。', error);
                window.alert('通知を更新できませんでした。もう一度お試しください。');
            } finally {
                markAllButton.disabled = false;
            }
        });
    }

    document.addEventListener('click', (event) => {
        if (isOpen && !root.contains(event.target)) {
            closePopover();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen) {
            closePopover();
            trigger.focus();
        }
    });

    trigger.setAttribute('aria-expanded', 'false');
}
