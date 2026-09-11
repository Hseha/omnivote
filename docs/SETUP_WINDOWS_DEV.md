# Windows Developer Setup Guide

This guide walks Windows developers through setting up the Omnivote project locally for development.

## Prerequisites

### 1. Install PHP 8.2+

**Option A: Using XAMPP (Recommended for beginners)**
1. Download [XAMPP for Windows](https://www.apachefriends.org/download.html)
2. Install with default options (selects Apache, MySQL, PHP)
3. PHP will be available at `C:\xampp\php\`
4. Add `C:\xampp\php\` to your **System PATH** (via Environment Variables)

**Option B: Manual PHP Installation**
1. Download PHP from [php.net](https://windows.php.net/download/)
2. Extract to `C:\php\`
3. Rename `php.ini-development` to `php.ini`
4. Enable required extensions in `php.ini`:
   ```ini
   extension=curl
   extension=gd
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=tokenizer
   extension=xml
   extension=zip
   extension=bcmath
   extension=dom
   extension=fileinfo
   extension=json
   extension=pcntl
   extension=phar
   ```

### 2. Install Composer

1. Download the Windows installer from [getcomposer.org](https://getcomposer.org/Composer-Setup.exe)
2. Run the installer (it automatically adds Composer to your PATH)
3. Verify installation:
   ```powershell
   composer --version
   ```

### 3. Install MySQL or MariaDB

**Using XAMPP (Recommended)**
- Included with XAMPP installation above
- Start MySQL service via XAMPP Control Panel

**Or download MySQL directly:**
- Download [MySQL Community Server](https://dev.mysql.com/downloads/mysql/)
- Install with default settings
- Remember your `root` password (set during installation)

### 4. Install Node.js & npm

1. Download [Node.js for Windows](https://nodejs.org/) (LTS version recommended)
2. Run the installer with default options
3. Verify installation:
   ```powershell
   node --version
   npm --version
   ```

### 5. Install Flutter SDK

1. Download [Flutter SDK for Windows](https://docs.flutter.dev/desktop#download-the-flutter-sdk)
2. Extract `flutter_windows_*.zip` to `C:\src\flutter\`
3. Add `C:\src\flutter\bin` to your **System PATH**
4. Run `flutter doctor` to verify and install missing dependencies

### 6. Install Android Studio (for mobile emulation)

1. Download [Android Studio](https://developer.android.com/studio)
2. Install with default options
3. Open Android Studio → Configure → SDK Manager → Install "Android 14.0 (API 34)" or higher
4. Create an Android Virtual Device (AVD)

## Repository Setup

### 1. Clone the Repository

Open **PowerShell** or **Command Prompt**:

```powershell
git clone https://github.com/Hseha/omnivote.git
cd omnivote
```

### 2. Set Up Backend Environment

```powershell
cd backend-laravel
copy .env.example .env
```

### 3. Configure `.env`

Edit `.env` (use any code editor like VS Code):

```env
APP_NAME=Omnivote
APP_ENV=local
APP_KEY=base64:generate-this-later
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=omnivote_local
DB_USERNAME=root
DB_PASSWORD=        # <-- Enter your MySQL root password here

BROADCAST_DRIVER=log
FILESYSTEM_DRIVER=public
QUEUE_CONNECTION=sync
CACHE_DRIVER=file

SESSION_DRIVER=database
SESSION_LIFETIME=120
```

> 💡 If using XAMPP, skip setting a MySQL password during installation, or ensure you enter it correctly above.

## Initialize Backend (Laravel)

### 1. Generate App Key

```powershell
php artisan key:generate
```

This will populate `APP_KEY` in your `.env` file.

### 2. Install Dependencies

```powershell
composer install --optimize-autoloader
```

### 3. Run Database Migrations & Seeders

Ensure MySQL service is running (via XAMPP Control Panel or Services.msc):

```powershell
php artisan migrate --seed
```

This creates all tables and seeds default admin/teacher records.

### 4. Start Development Server

```powershell
php artisan serve
```

Your Laravel API should now be available at:
👉 http://127.0.0.1:8000

The API endpoints are accessible at:
👉 http://127.0.0.1:8000/api/

## Initialize Frontend Applications

### A. React Admin Panel (`admin-react`)

#### 1. Install Dependencies

```powershell
cd ../admin-react
npm install
```

#### 2. Configure `.env`

Create `.env` file in `admin-react` folder:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000
```

#### 3. Start Development Server

```powershell
npm run dev
```

This starts Vite dev server — typically at:
👉 http://localhost:5173

### B. Flutter Mobile App (`user-flutter`)

#### 1. Install Dependencies

In **PowerShell**:

```powershell
cd ../user-flutter
flutter pub get
```

#### 2. Run on Android Emulator

Make sure an Android Emulator is running:
- Open **Android Studio** → **Tools > Device Manager** → Launch emulator

Then in PowerShell:

```powershell
flutter run
```

Or run directly on a connected Android device:

```powershell
flutter run -d <device-id>
```

> Tip: Run `flutter devices` to see connected devices/emulators.

## Optional: Use Apache Virtual Hosts

If you prefer not to use `php artisan serve`, you can configure XAMPP virtual hosts:

### Configure XAMPP Virtual Host

Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/path/to/omnivote/backend-laravel/public"
    ServerName omnivote.local

    <Directory "C:/path/to/omnivote/backend-laravel/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add to `C:\Windows\System32\drivers\etc\hosts`:

```
127.0.0.1 omnivote.local
```

Restart Apache via XAMPP Control Panel.

## Running Tests

### Laravel Unit Tests

From `backend-laravel` directory:

```powershell
vendor\bin\phpunit
```

### Flutter Unit Tests

From `user-flutter` directory:

```powershell
flutter test
```

## Troubleshooting

### 🔧 Common Errors

#### ❌ "php.exe is not recognized..."

PHP is not in your PATH. Either:
- Reinstall XAMPP with auto PATH option enabled
- Manually add `C:\xampp\php\` to System Environment Variables > PATH

#### ❌ "composer is not recognized..."

Ensure Composer is installed globally:
- Download from https://getcomposer.org/
- Add `C:\ProgramData\composer` (or wherever it installed) to PATH

#### ❌ MySQL Connection Refused / Access Denied

Check:
1. Is MySQL running? (via XAMPP Control Panel or Services.msc)
2. Did you leave DB_PASSWORD blank if no password was set?
3. Try changing `DB_HOST=127.0.0.1` instead of `localhost`

#### ❌ Port 8000 Already in Use

Kill the process occupying the port:

```powershell
netstat -ano | findstr :8000
taskkill /PID <process_id> /F
```

Then restart:

```powershell
php artisan serve
```

#### ❌ Flutter Doctor Reports Missing Components

Run:

```powershell
flutter doctor
```

Follow prompts to install missing tools/components individually.

#### ❌ Android Emulator Not Detected

1. Open **Android Studio**
2. Go to **Tools > Device Manager**
3. Create a New Virtual Device (AVD)
4. Start the emulator before running `flutter run`

## Quick Reference Commands

| Task | PowerShell Command |
|------|--------------------|
| Start Laravel API | `php artisan serve` |
| Start React Dev Server | `cd admin-react; npm run dev` |
| Start Flutter App | `cd user-flutter; flutter run` |
| Run Migrations | `php artisan migrate --seed` |
| Clear Cache | `php artisan cache:clear` |
| Reset DB | `php artisan migrate:fresh --seed` |

## Support

If you encounter issues specific to this repository:
1. Check existing issues on GitHub: [https://github.com/Hseha/omnivote/issues](https://github.com/Hseha/omnivote/issues)
2. Contact project maintainers
3. Refer to documentation in `/docs`

Last updated: September 2025  
Maintained for: Windows 10/11 with PowerShell