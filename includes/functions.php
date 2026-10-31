<?php
// includes/functions.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

function formatMoney(float|int|string|null $val, bool $withSymbol = true): string {
    $num = (float)($val ?? 0);
    $formatted = number_format(abs($num), 2, '.', ',');
    if (!$withSymbol) {
        return ($num < 0 ? '-' : '') . $formatted;
    }
    $sym = 'Rs ';
    if (defined('CURRENCY_SYMBOL')) {
        $raw = (string)CURRENCY_SYMBOL;
        if (!is_numeric($raw) && trim($raw) !== '' && preg_match('/[a-zA-Z\$\x{0600}-\x{06FF}]/u', $raw)) {
            $sym = rtrim($raw) . ' ';
        }
    }
    if ($num < 0) {
        return '- ' . $sym . $formatted;
    }
    return $sym . $formatted;
}

function formatQty(float|int|string|null $val, string $unit = 'kg'): string {
    $num = (float)($val ?? 0);
    // If it's a whole number, format without .00, otherwise with up to 2 decimals
    $formatted = (floor($num) == $num) ? number_format($num, 0, '.', ',') : number_format($num, 2, '.', ',');
    return $formatted . ($unit ? ' ' . $unit : '');
}

function getNextDocNo(string $prefix, string $table, string $column): string {
    $db = getDB();
    $year = date('Y');
    $pattern = "{$prefix}-{$year}-%";
    
    // Select the maximum sequential number for the current year
    $stmt = $db->prepare("SELECT {$column} FROM `{$table}` WHERE {$column} LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $lastNo = $stmt->fetchColumn();

    $seq = 1;
    if ($lastNo) {
        $parts = explode('-', $lastNo);
        if (count($parts) >= 3) {
            $seq = (int)end($parts) + 1;
        }
    }
    return sprintf("%s-%s-%05d", $prefix, $year, $seq);
}

function getCompanySettings(): array {
    static $settings = null;
    if ($settings === null) {
        $db = getDB();
        $stmt = $db->query("SELECT setting_key, setting_value FROM company_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Auto-heal corrupted question marks from legacy non-UTF8 SQL imports
        $needsDbUpdate = false;
        $canonicalUrdu = 'ظفر برادرز آئرن مرچنٹس';
        $canonicalMotto = 'آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن، بھروسے کا نام';

        if (empty($settings['company_urdu']) || strpos($settings['company_urdu'], '?') !== false) {
            $settings['company_urdu'] = $canonicalUrdu;
            $needsDbUpdate = true;
        }
        if (empty($settings['company_motto_urdu']) || strpos($settings['company_motto_urdu'], '?') !== false) {
            $settings['company_motto_urdu'] = $canonicalMotto;
            $needsDbUpdate = true;
        }
        if (isset($settings['company_phone']) && strpos($settings['company_phone'], '??') !== false) {
            $settings['company_phone'] = str_replace('??', '·', $settings['company_phone']);
            $needsDbUpdate = true;
        }
        if (isset($settings['company_landline']) && strpos($settings['company_landline'], '??') !== false) {
            $settings['company_landline'] = str_replace('??', '·', $settings['company_landline']);
            $needsDbUpdate = true;
        }

        $defaultTermsTitle = 'شرائط و ضوابط برائے خریدار:';
        $defaultTermsUrdu = "مال گودام یا گاڑی سے نکلنے کے بعد وزن کی ذمہ داری خریدار پر ہوگی۔\nخریدا ہوا مال واپس یا تبدیل نہیں کیا جائے گا۔";
        $defaultTermsEn = 'Goods once sold and loaded cannot be returned or exchanged.';

        if (empty($settings['bill_terms_title_urdu']) || strpos($settings['bill_terms_title_urdu'], '?') !== false) {
            $settings['bill_terms_title_urdu'] = $defaultTermsTitle;
        }
        if (empty($settings['bill_terms_conditions_urdu']) || strpos($settings['bill_terms_conditions_urdu'], '?') !== false) {
            $settings['bill_terms_conditions_urdu'] = $defaultTermsUrdu;
        }
        if (!isset($settings['bill_terms_conditions_en'])) {
            $settings['bill_terms_conditions_en'] = $defaultTermsEn;
        }

        if ($needsDbUpdate) {
            try {
                $upd = $db->prepare("UPDATE company_settings SET setting_value = ? WHERE setting_key = ?");
                $upd->execute([$settings['company_urdu'], 'company_urdu']);
                $upd->execute([$settings['company_motto_urdu'], 'company_motto_urdu']);
                if (isset($settings['company_phone'])) {
                    $upd->execute([$settings['company_phone'], 'company_phone']);
                }
                if (isset($settings['company_landline'])) {
                    $upd->execute([$settings['company_landline'], 'company_landline']);
                }
            } catch (Throwable $t) {
                // Ignore if read-only or restricted
            }
        }
    }
    return $settings;
}

function getSetting(string $key, string $default = ''): string {
    $settings = getCompanySettings();
    return $settings[$key] ?? $default;
}

function setSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO company_settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

function formatWaPhone(?string $phone): string {
    if (!$phone) return '';
    $clean = preg_replace('/\D/', '', $phone);
    if (str_starts_with($clean, '0')) {
        return '92' . substr($clean, 1);
    }
    return $clean;
}

// Convenient Aliases & Flash Helpers
function formatCurrency(float|int|string|null $val, bool $withSymbol = true): string {
    return formatMoney($val, $withSymbol);
}

function generateDocumentNumber(string $prefix, string $table, string $column): string {
    return getNextDocNo($prefix, $table, $column);
}

function setFlash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
