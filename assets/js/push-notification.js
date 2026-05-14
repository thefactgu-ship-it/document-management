// assets/js/push-notification.js

class PushNotificationManager {
    constructor() {
        this.publicVapidKey = null;
        this.isSupported = 'serviceWorker' in navigator && 'PushManager' in window;
        this.permission = Notification.permission;
        
        if (this.isSupported) {
            this.init();
        }
    }

    async init() {
        try {
            // ลงทะเบียน service worker
            const registration = await navigator.serviceWorker.register('/sw.js', {
                scope: '/'
            });
            console.log('Service Worker registered:', registration);

            // รับ VAPID public key จากเซิร์ฟเวอร์
            await this.fetchVapidKey();

            // ตรวจสอบสถานะ subscription
            await this.checkSubscriptionStatus();

        } catch (error) {
            console.error('Error initializing push notifications:', error);
        }
    }

    async fetchVapidKey() {
        try {
            const response = await fetch('/api/get-vapid-key.php');
            const data = await response.json();
            this.publicVapidKey = data.publicKey;
        } catch (error) {
            console.error('Error fetching VAPID key:', error);
        }
    }

    async checkSubscriptionStatus() {
        if (!this.isSupported) return;

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (subscription) {
                console.log('User is subscribed to push notifications');
                await this.sendSubscriptionToServer(subscription);
            } else {
                console.log('User is not subscribed to push notifications');
                this.showNotificationPrompt();
            }
        } catch (error) {
            console.error('Error checking subscription status:', error);
        }
    }

    showNotificationPrompt() {
        // แสดง UI prompt ให้ผู้ใช้เปิดการแจ้งเตือน
        const promptHTML = `
            <div id="notification-prompt" class="alert alert-info glassmorphism p-3 mb-4 d-flex align-items-center justify-content-between" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-bell me-2"></i>
                    <div>
                        <strong>เปิดการแจ้งเตือน</strong><br>
                        <small>รับการแจ้งเตือนเมื่อมีเอกสารใหม่</small>
                    </div>
                </div>
                <div>
                    <button type="button" class="btn btn-primary-glass btn-sm me-2" onclick="pushManager.requestPermission()">
                        <i class="fas fa-check me-1"></i> เปิดใช้งาน
                    </button>
                    <button type="button" class="btn btn-secondary-glass btn-sm" onclick="pushManager.dismissPrompt()">
                        <i class="fas fa-times me-1"></i> ไม่ใช่ตอนนี้
                    </button>
                </div>
            </div>
        `;

        // แสดง prompt ถ้ายังไม่เคยปฏิเสธ
        if (this.permission === 'default') {
            const container = document.querySelector('.container-fluid');
            if (container) {
                container.insertAdjacentHTML('afterbegin', promptHTML);
            }
        }
    }

    dismissPrompt() {
        const prompt = document.getElementById('notification-prompt');
        if (prompt) {
            prompt.remove();
        }
    }

    async requestPermission() {
        if (!this.isSupported) {
            alert('เบราว์เซอร์ของคุณไม่รองรับการแจ้งเตือน');
            return false;
        }

        try {
            const permission = await Notification.requestPermission();
            this.permission = permission;

            if (permission === 'granted') {
                await this.subscribeUser();
                this.dismissPrompt();
                this.showSuccessMessage('เปิดการแจ้งเตือนสำเร็จแล้ว');
                return true;
            } else if (permission === 'denied') {
                this.showErrorMessage('การแจ้งเตือนถูกปฏิเสธ คุณสามารถเปิดใช้งานได้ในการตั้งค่าเบราว์เซอร์');
                this.dismissPrompt();
                return false;
            }
        } catch (error) {
            console.error('Error requesting notification permission:', error);
            this.showErrorMessage('เกิดข้อผิดพลาดในการขอสิทธิ์การแจ้งเตือน');
            return false;
        }
    }

    async subscribeUser() {
        if (!this.publicVapidKey) {
            console.error('VAPID public key not available');
            return false;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.publicVapidKey)
            });

            console.log('User subscribed:', subscription);
            await this.sendSubscriptionToServer(subscription);
            return true;
        } catch (error) {
            console.error('Error subscribing user:', error);
            return false;
        }
    }

    async sendSubscriptionToServer(subscription) {
        try {
            const response = await fetch('/api/save-subscription.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(subscription)
            });

            const data = await response.json();
            if (data.success) {
                console.log('Subscription saved to server');
            } else {
                console.error('Error saving subscription:', data.error);
            }
        } catch (error) {
            console.error('Error sending subscription to server:', error);
        }
    }

    async unsubscribeUser() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (subscription) {
                await subscription.unsubscribe();
                await this.removeSubscriptionFromServer(subscription);
                console.log('User unsubscribed');
                this.showSuccessMessage('ปิดการแจ้งเตือนแล้ว');
                return true;
            }
        } catch (error) {
            console.error('Error unsubscribing user:', error);
            return false;
        }
    }

    async removeSubscriptionFromServer(subscription) {
        try {
            const response = await fetch('/api/remove-subscription.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(subscription)
            });

            const data = await response.json();
            if (!data.success) {
                console.error('Error removing subscription:', data.error);
            }
        } catch (error) {
            console.error('Error removing subscription from server:', error);
        }
    }

    // Helper function to convert VAPID key
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    showSuccessMessage(message) {
        this.showMessage(message, 'success');
    }

    showErrorMessage(message) {
        this.showMessage(message, 'danger');
    }

    showMessage(message, type) {
        const alertHTML = `
            <div class="alert alert-${type}-glass glassmorphism p-3 mb-4 d-flex align-items-center alert-dismissible fade show" role="alert">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'} me-2"></i>
                <div>${message}</div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;

        const container = document.querySelector('.container-fluid');
        if (container) {
            container.insertAdjacentHTML('afterbegin', alertHTML);
            
            // Auto dismiss after 5 seconds
            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        }
    }

    // Test notification
    async testNotification() {
        if (this.permission === 'granted') {
            new Notification('ทดสอบการแจ้งเตือน', {
                body: 'การแจ้งเตือนทำงานได้ปกติ',
                icon: '/assets/img/icon-192x192.png',
                badge: '/assets/img/badge-72x72.png',
                tag: 'test-notification'
            });
        } else {
            await this.requestPermission();
        }
    }

    // Get current subscription status
    async getSubscriptionStatus() {
        if (!this.isSupported) return { supported: false };

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            return {
                supported: true,
                permission: this.permission,
                subscribed: !!subscription,
                subscription: subscription
            };
        } catch (error) {
            console.error('Error getting subscription status:', error);
            return { supported: true, error: error.message };
        }
    }
}

// สร้าง instance และทำให้เข้าถึงได้จาก global scope
const pushManager = new PushNotificationManager();

// Export สำหรับใช้ใน module
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PushNotificationManager;
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Push Notification Manager initialized');
});