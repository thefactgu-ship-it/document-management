// sw.js - Service Worker for Push Notifications

const CACHE_NAME = 'document-system-v1';
const urlsToCache = [
    '/',
    '/assets/css/styles.css',
    '/assets/js/push-notification.js',
    '/assets/img/icon-192x192.png',
    '/assets/img/badge-72x72.png',
    '/assets/img/icon-512x512.png'
];

// Install Service Worker
self.addEventListener('install', event => {
    console.log('Service Worker installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Opened cache');
                return cache.addAll(urlsToCache);
            })
            .catch(error => {
                console.error('Error caching files:', error);
            })
    );
    self.skipWaiting();
});

// Activate Service Worker
self.addEventListener('activate', event => {
    console.log('Service Worker activating...');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// Fetch event - Basic caching strategy
self.addEventListener('fetch', event => {
    // Skip cross-origin requests
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Return cached version or fetch from network
                if (response) {
                    return response;
                }
                return fetch(event.request);
            })
    );
});

// Push event - Handle incoming push notifications
self.addEventListener('push', event => {
    console.log('Push notification received:', event);

    let notificationData = {
        title: 'เอกสารใหม่',
        body: 'คุณมีเอกสารใหม่',
        icon: '/assets/img/icon-192x192.png',
        badge: '/assets/img/badge-72x72.png',
        tag: 'document-notification',
        requireInteraction: true,
        data: {
            url: '/',
            timestamp: Date.now()
        },
        actions: [
            {
                action: 'open',
                title: 'เปิดดู',
                icon: '/assets/img/icon-192x192.png'
            },
            {
                action: 'close',
                title: 'ปิด'
            }
        ]
    };

    // Parse notification data if available
    if (event.data) {
        try {
            const pushData = event.data.json();
            console.log('Push data received:', pushData);

            notificationData = {
                ...notificationData,
                title: pushData.title || notificationData.title,
                body: pushData.body || notificationData.body,
                icon: pushData.icon || notificationData.icon,
                badge: pushData.badge || notificationData.badge,
                tag: pushData.tag || notificationData.tag,
                requireInteraction: pushData.requireInteraction !== false,
                data: {
                    ...notificationData.data,
                    ...pushData.data,
                    type: pushData.data?.type || 'general',
                    url: pushData.data?.url || '/',
                    document_number: pushData.data?.document_number,
                    transfer_id: pushData.data?.transfer_id
                },
                actions: getNotificationActions(pushData.data?.type)
            };

            // Add vibration pattern for mobile devices
            if (pushData.data?.type === 'document_transfer') {
                notificationData.vibrate = [200, 100, 200];
            }

        } catch (error) {
            console.error('Error parsing push data:', error);
        }
    }

    event.waitUntil(
        self.registration.showNotification(notificationData.title, {
            body: notificationData.body,
            icon: notificationData.icon,
            badge: notificationData.badge,
            tag: notificationData.tag,
            requireInteraction: notificationData.requireInteraction,
            data: notificationData.data,
            actions: notificationData.actions,
            vibrate: notificationData.vibrate,
            timestamp: notificationData.data.timestamp || Date.now()
        })
    );
});

// Notification click event
self.addEventListener('notificationclick', event => {
    console.log('Notification clicked:', event);

    const notification = event.notification;
    const action = event.action;
    const data = notification.data || {};

    notification.close();

    if (action === 'close') {
        return;
    }

    let targetUrl = '/';

    // Determine target URL based on notification type
    if (data.type === 'document_transfer') {
        targetUrl = data.url || '/documents_received.php';
        if (data.transfer_id) {
            targetUrl += `?highlight=${data.transfer_id}`;
        }
    } else if (data.type === 'document_response') {
        targetUrl = data.url || '/documents_sent.php';
        if (data.transfer_id) {
            targetUrl += `?highlight=${data.transfer_id}`;
        }
    } else if (data.url) {
        targetUrl = data.url;
    }

    // Handle notification click actions
    const actionHandlers = {
        'open': () => openWindow(targetUrl),
        'mark_read': () => markNotificationAsRead(data),
        'reply': () => openWindow(targetUrl + '&action=reply'),
        'view_document': () => openWindow(`/view_document.php?id=${data.document_id || ''}`),
        'default': () => openWindow(targetUrl)
    };

    const handler = actionHandlers[action] || actionHandlers['default'];
    event.waitUntil(handler());
});

// Helper function to get notification actions based on type
function getNotificationActions(type) {
    const commonActions = [
        {
            action: 'open',
            title: 'เปิดดู',
            icon: '/assets/img/icon-192x192.png'
        },
        {
            action: 'close',
            title: 'ปิด'
        }
    ];

    switch (type) {
        case 'document_transfer':
            return [
                {
                    action: 'open',
                    title: 'ดูเอกสาร',
                    icon: '/assets/img/icon-192x192.png'
                },
                {
                    action: 'mark_read',
                    title: 'ทำเครื่องหมายอ่านแล้ว'
                },
                {
                    action: 'close',
                    title: 'ปิด'
                }
            ];

        case 'document_response':
            return [
                {
                    action: 'open',
                    title: 'ดูการตอบกลับ',
                    icon: '/assets/img/icon-192x192.png'
                },
                {
                    action: 'view_document',
                    title: 'ดูเอกสาร'
                },
                {
                    action: 'close',
                    title: 'ปิด'
                }
            ];

        case 'document_deadline':
            return [
                {
                    action: 'open',
                    title: 'ดูรายละเอียด',
                    icon: '/assets/img/icon-192x192.png'
                },
                {
                    action: 'reply',
                    title: 'ตอบกลับ'
                },
                {
                    action: 'close',
                    title: 'ปิด'
                }
            ];

        default:
            return commonActions;
    }
}

// Helper function to open window or focus existing tab
function openWindow(url) {
    return clients.matchAll({
        type: 'window',
        includeUncontrolled: true
    }).then(clientList => {
        // Check if there's already a window/tab open with the target URL
        for (let i = 0; i < clientList.length; i++) {
            const client = clientList[i];
            if (client.url.includes(url.split('?')[0]) && 'focus' in client) {
                return client.focus();
            }
        }

        // If no existing window, open a new one
        if (clients.openWindow) {
            return clients.openWindow(url);
        }
    });
}

// Helper function to mark notification as read
function markNotificationAsRead(data) {
    if (data.transfer_id) {
        return fetch('/api/mark-notification-read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                transfer_id: data.transfer_id,
                type: data.type
            })
        }).catch(error => {
            console.error('Error marking notification as read:', error);
        });
    }
    return Promise.resolve();
}

// Background sync event (for offline capabilities)
self.addEventListener('sync', event => {
    console.log('Background sync triggered:', event.tag);

    if (event.tag === 'background-sync-notifications') {
        event.waitUntil(
            // Sync any pending notification acknowledgments
            syncNotificationStatus()
        );
    }
});

// Helper function to sync notification status
function syncNotificationStatus() {
    return fetch('/api/sync-notification-status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            timestamp: Date.now()
        })
    }).catch(error => {
        console.error('Error syncing notification status:', error);
    });
}

// Message event - Handle messages from main thread
self.addEventListener('message', event => {
    console.log('Service Worker received message:', event.data);

    const { type, payload } = event.data;

    switch (type) {
        case 'SKIP_WAITING':
            self.skipWaiting();
            break;

        case 'GET_VERSION':
            event.ports[0].postMessage({
                type: 'VERSION',
                version: CACHE_NAME
            });
            break;

        case 'CLEAR_CACHE':
            event.waitUntil(
                caches.delete(CACHE_NAME).then(() => {
                    event.ports[0].postMessage({
                        type: 'CACHE_CLEARED',
                        success: true
                    });
                })
            );
            break;

        case 'TEST_NOTIFICATION':
            event.waitUntil(
                self.registration.showNotification('ทดสอบการแจ้งเตือน', {
                    body: 'Service Worker ทำงานได้ปกติ',
                    icon: '/assets/img/icon-192x192.png',
                    badge: '/assets/img/badge-72x72.png',
                    tag: 'test-notification',
                    data: {
                        type: 'test',
                        timestamp: Date.now()
                    }
                })
            );
            break;

        default:
            console.log('Unknown message type:', type);
    }
});

// Error handling
self.addEventListener('error', event => {
    console.error('Service Worker error:', event.error);
});

self.addEventListener('unhandledrejection', event => {
    console.error('Service Worker unhandled promise rejection:', event.reason);
});

console.log('Service Worker loaded successfully');