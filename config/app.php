<?php
// config/app.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

date_default_timezone_set('Asia/Karachi');

define('APP_NAME', 'Zafar Brothers Iron Merchants');
define('APP_NAME_URDU', 'ظفر برادرز آئرن مرچنٹس');
define('APP_TAGLINE', 'Gujranwala Steel Group of Industry');
define('APP_VERSION', '3.0.0-PRO');
define('BASE_PATH', dirname(__DIR__));
define('CURRENCY', 'PKR');
define('CURRENCY_SYMBOL', 'Rs ');
define('LOCALE_TIMEZONE', 'Asia/Karachi');
