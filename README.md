# Document Management System

A PHP and MySQL web application for registering, routing, tracking, and reporting internal documents. The project demonstrates a practical back-office workflow with role-based access, document inboxes, file uploads, transfer history, administration screens, and optional notification integrations.

This repository is prepared as an interview/recruiter-friendly portfolio project. Organization-specific branding has been replaced with neutral example data.

## Project Overview

The system supports two primary user roles:

- **Admin**: manages users, departments, document types, system settings, reports, and all documents.
- **User**: registers documents, receives assigned documents, forwards documents, tracks sent documents, and transfers personal files.

The application is implemented as a traditional server-rendered PHP application. Pages are organized by workflow instead of a framework router, with shared database, authentication, header, footer, and notification helpers.

## Features

- Secure login with PHP sessions and hashed passwords.
- Admin dashboard with user, department, document type, settings, report, and document management.
- Document registration with metadata, priority, due date, recipients, and file uploads.
- Inbox and sent views for document tracking.
- Document transfer workflow with pending, accepted, rejected, and revision states.
- Personal file transfer workflow separate from official document records.
- Search/reporting screens for operational visibility.
- Configurable site name, timezone, file size, file type, and Telegram token through database settings.
- Telegram and LINE notification helper classes.
- Progressive Web App assets and service worker for push notification experiments.

## Tech Stack

- **Backend**: PHP 8.x, mysqli
- **Database**: MySQL / MariaDB
- **Frontend**: HTML, CSS, Bootstrap 5, Font Awesome, vanilla JavaScript
- **Notifications**: Telegram Bot API, LINE Messaging API, Web Push service worker
- **Local runtime**: Laragon, XAMPP, MAMP, or PHP built-in server

## Installation Steps

1. Clone or copy the project into your web server document root.

   ```bash
   git clone <repository-url> document-management-system
   cd document-management-system
   ```

2. Create a MySQL database.

   ```sql
   CREATE DATABASE official_document_system
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;
   ```

3. Import the schema and seed data.

   ```bash
   mysql -u root -p official_document_system < database/official_document_system.sql
   ```

4. Configure database credentials in `config/database.php`.

5. Make upload folders writable by the web server:

   - `uploads/`
   - `personal_uploads/`
   - `personal_response_uploads/`

6. Open the app in a browser through your local server.

## Environment Setup

This project currently stores configuration in `config/database.php` and the `system_settings` table.

Update these constants for your local environment:

```php
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'official_document_system');
```

Optional notification settings:

- `telegram_bot_token` in `system_settings`
- `line_channel_access_token` in `system_settings`
- `line_channel_secret` in `system_settings`
- `vapid_public_key`, `vapid_private_key`, and `vapid_subject` for Web Push experiments

The standalone `my_line_webhook.php` file uses placeholder LINE credentials. Replace them only in a private local environment or, preferably, refactor them into environment variables before production use.

## Database Setup

The SQL dump is located at:

```text
database/official_document_system.sql
```

Core tables include:

- `users`: login accounts, roles, department assignment, notification IDs
- `departments`: department master data
- `document_types`: configurable document categories
- `documents`: registered document records and file metadata
- `document_recipients`: recipient assignments and receive/reject status
- `document_transfers`: document forwarding workflow
- `system_settings`: runtime settings used by the app
- `telegram_logs`: Telegram notification delivery history

Default demo account:

```text
Username: admin
Password: password
Role: admin
```

## Running Frontend/Backend

Because this is a server-rendered PHP app, the frontend and backend run together from the same PHP server.

### Laragon/XAMPP/MAMP

Place the project under your web root and visit:

```text
http://localhost/document/
```

### PHP Built-In Server

From the project root:

```bash
php -S 127.0.0.1:8000
```

Then open:

```text
http://127.0.0.1:8000/login.php
```

## API Overview

This project is primarily page-based, but it includes integration endpoints and helper classes:

| Endpoint / Class | Purpose |
| --- | --- |
| `login.php` | Handles username/password authentication. |
| `logout.php` | Clears the current session. |
| `my_line_webhook.php` | Receives LINE webhook events, validates the LINE signature, and replies to basic events. |
| `includes/TelegramApi.php` | Sends Telegram messages and logs delivery results. |
| `includes/LineAPI.php` | Sends LINE push and flex messages. |
| `includes/PushNotificationManager.php` | Stores push subscriptions and sends Web Push payloads. |
| `sw.js` | Handles browser push notifications and notification click actions. |

Note: `assets/js/push-notification.js` references `/api/*` endpoints for Web Push subscription management, but those endpoint files are not present in this repository. They are good candidates for future implementation.

## Folder Structure

```text
.
|-- admin/                       # Admin dashboards and management screens
|-- assets/
|   |-- img/                     # Generic app icons and PWA assets
|   `-- js/                      # Bootstrap assets and push notification script
|-- config/
|   `-- database.php             # Database connection and runtime constants
|-- database/
|   `-- official_document_system.sql
|-- includes/                    # Shared auth, layout, and notification helpers
|-- uploads/                     # Uploaded document files
|-- personal_uploads/            # Personal transfer uploads
|-- personal_response_uploads/   # Personal transfer response files
|-- dashboard.php                # User dashboard
|-- documents_*.php              # Document registration, inbox, sent, and transfer pages
|-- personal_*.php               # Personal file transfer pages
|-- login.php
|-- logout.php
|-- my_line_webhook.php
|-- sw.js
`-- README.md
```

## Future Improvements

- Move secrets and database credentials into environment variables.
- Add missing Web Push API endpoints referenced by `assets/js/push-notification.js`.
- Add CSRF protection to state-changing forms.
- Add automated tests for authentication, document routing, and admin CRUD workflows.
- Introduce a migration system instead of a single SQL dump.
- Normalize notification settings for Telegram, LINE, and Web Push.
- Add audit logs for document status changes and file downloads.
- Improve error handling and validation around uploads.
- Add role/permission granularity beyond `admin` and `user`.
- Containerize the project with Docker for faster reviewer setup.
<img width="1905" height="943" alt="image" src="https://github.com/user-attachments/assets/94285f00-3b5a-48cc-9def-bdab660245ef" />
