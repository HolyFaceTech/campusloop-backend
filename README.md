# 🎓 CampusLoop - Backend API

This is the robust backend RESTful API for **CampusLoop**, an LMS and Student Portal for Senior High School. Built with Laravel 12, it handles authentication, data management, file uploads, PDF generation, email services via PHPMailer, and WebSockets via Laravel Reverb for real-time notifications.

## 🛠️ Tech Stack & Dependencies

- **Framework:** Laravel v12.0 (PHP 8.2.12)
- **Authentication:** Laravel Sanctum v4.0 (Token-based)
- **Database:** MySQL (using UUIDs as primary keys & SoftDeletes for Recycle Bin)
- **WebSockets:** Laravel Reverb v1.8 (Real-time notifications)
- **Mail:** PHPMailer v7.0
- **PDF Generation:** DOMPDF v3.1
- **Security:** Rate Limiting (Throttle), Single Session Policy.

## 📋 Prerequisites

Before you begin, ensure you have the following installed:

- PHP >= 8.2.12
- Composer >= 2.8.12
- MySQL Database (via XAMPP/Laragon)
- Git

## 🚀 Installation & Setup Guide

**1. Clone the repository**

```bash
git clone [https://github.com/yourusername/campusloop-backend.git](https://github.com/yourusername/campusloop-backend.git)
cd campusloop-backend

```

**2. Install dependencies**

```bash
composer install

```

**3. Set up Environment Variables**
Copy the `.env.example` file to create your `.env` file:

```bash
cp .env.example .env

```

Open the `.env` file and update the following critical configurations:

```env
# Database Config
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=campusloop_db
DB_USERNAME=root
DB_PASSWORD=

# Frontend Connection
FRONTEND_URL=http://localhost:5173

# Mail Configuration (Example using Gmail SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls

```

**4. Generate Application Key**

```bash
php artisan key:generate

```

**5. Run Database Migrations**
_(Ensure your MySQL server is running and the `campusloop_db` is created)_

```bash
php artisan migrate

```

**6. Create Storage Link**
Required for viewing and downloading uploaded files (e.g., e-library, user files):

```bash
php artisan storage:link

```

**7. Start the Development Server**

```bash
php artisan serve

```

The API will now be available at `http://localhost:8000/api`.

## 🛣️ API Routing Architecture

The API is strictly separated into public and protected routes in `routes/api.php`:

- **Public Routes (`throttle:5,1`):** `/login`, `/forgot-password`, `/reset-password`, `/verify-email`.
- **Protected Routes (`auth:sanctum`):** Divided into `Admin`, `Teacher`, and `Student` controllers ensuring strict Role-Based Access Control (RBAC).

## 🔒 Security Implementations

- **Single Session Policy:** Logging in from a new device automatically invalidates the old session token.
- **Information Leakage Protection:** Database operations and file handling are wrapped in Transaction blocks (`DB::beginTransaction()`) and safe `try-catch` structures logging to `storage/logs/laravel.log`.
