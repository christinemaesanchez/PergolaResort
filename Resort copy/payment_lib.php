<?php
/**
 * payment_lib.php  (put in your project ROOT: htdocs/Resort/payment_lib.php)
 * Shared helpers for the 50% down-payment + receipt verification workflow.
 */
if (!defined('PERGOLA_ROOT')) {
    define('PERGOLA_ROOT', __DIR__);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('peso')) {
    function peso($n) { return "₱" . number_format((float)$n, 2); }
}

/* Reservation statuses that block the calendar */
const PAY_BLOCKING_STATUSES = ['pending', 'confirmed', 'pending_payment_verification', 'payment_verification_failed'];

const PAY_RECEIPT_MIMES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];
const PAY_IMAGE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/* ---------------------------------------------------------------
   CSRF
---------------------------------------------------------------- */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_valid($token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------------------------------------------------------------
   Schema: adds ONLY what is missing (safe to run on every request)
---------------------------------------------------------------- */
function pay_table_columns(mysqli $conn, string $table): array {
    $cols = [];
    $r = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($c = $r->fetch_assoc()) $cols[$c['Field']] = $c;
    return $cols;
}

function pay_ensure_status_column(mysqli $conn, string $table, array $needed): void {
    $cols = pay_table_columns($conn, $table);
    if (!isset($cols['status'])) return;
    $m = $cols['status'];
    $null = $m['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
    if ($m['Default'] !== null) {
        $def = "DEFAULT '" . $conn->real_escape_string($m['Default']) . "'";
    } else {
        $def = $m['Null'] === 'YES' ? 'DEFAULT NULL' : '';
    }

    if (stripos($m['Type'], 'enum(') === 0) {
        preg_match_all("/'((?:[^']|'')*)'/", $m['Type'], $mm);
        $vals = array_map(fn($v) => str_replace("''", "'", $v), $mm[1]);
        $missing = array_values(array_diff($needed, $vals));
        if (!$missing) return;
        $all = array_merge($vals, $missing);
        $list = implode(',', array_map(fn($v) => "'" . $conn->real_escape_string($v) . "'", $all));
        $conn->query("ALTER TABLE `$table` MODIFY `status` ENUM($list) $null $def");
    } elseif (preg_match('/^varchar\((\d+)\)/i', $m['Type'], $mm) && (int)$mm[1] < 40) {
        $conn->query("ALTER TABLE `$table` MODIFY `status` VARCHAR(40) $null $def");
    }
}

function ensure_payment_schema(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pc = pay_table_columns($conn, 'payments');
    $add = [
        'receipt_image'     => 'VARCHAR(255) NULL',
        'total_amount'      => 'DECIMAL(10,2) NULL',
        'down_payment'      => 'DECIMAL(10,2) NULL',
        'remaining_balance' => 'DECIMAL(10,2) NULL',
        'verified_by'       => 'INT NULL',
        'verified_date'     => 'DATETIME NULL',
        'admin_remarks'     => 'TEXT NULL',
    ];
    foreach ($add as $col => $def) {
        if (!isset($pc[$col])) {
            $conn->query("ALTER TABLE `payments` ADD COLUMN `$col` $def");
        }
    }

    // accommodations.capacity is used by the booking form (same definition admin/accommodations.php uses)
    $ac = pay_table_columns($conn, 'accommodations');
    if (!isset($ac['capacity'])) {
        $conn->query("ALTER TABLE `accommodations` ADD COLUMN `capacity` INT NOT NULL DEFAULT 2");
    }

    pay_ensure_status_column($conn, 'payments', ['pending', 'paid', 'rejected', 'refunded', 'unpaid']);
    pay_ensure_status_column($conn, 'reservations', ['pending', 'confirmed', 'pending_payment_verification', 'payment_verification_failed']);

    $conn->query("CREATE TABLE IF NOT EXISTS `payment_settings` (
        `id` INT NOT NULL PRIMARY KEY,
        `gcash_name` VARCHAR(100) NOT NULL DEFAULT 'Pergola Resort Official',
        `gcash_number` VARCHAR(30) NOT NULL DEFAULT '0912-345-6789',
        `gcash_qr` VARCHAR(255) NULL,
        `down_payment_percent` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        `instructions` TEXT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT IGNORE INTO `payment_settings` (`id`, `instructions`) VALUES (1,
        'Transfer the required down payment to the GCash account below, then upload a clear screenshot of your receipt.')");
}

function pay_get_settings(mysqli $conn): array {
    $r = $conn->query("SELECT * FROM payment_settings WHERE id = 1");
    $s = $r->fetch_assoc() ?: [];
    $s += [
        'gcash_name' => 'Pergola Resort Official',
        'gcash_number' => '0912-345-6789',
        'gcash_qr' => '',
        'down_payment_percent' => 50,
        'instructions' => '',
    ];
    $pct = (float)$s['down_payment_percent'];
    $s['down_payment_percent'] = ($pct >= 1 && $pct <= 100) ? $pct : 50.0;
    if (empty($s['gcash_qr']) && is_file(PERGOLA_ROOT . '/images/gcash-qr.png')) {
        $s['gcash_qr'] = 'images/gcash-qr.png'; // legacy fallback
    }
    return $s;
}

/* ---------------------------------------------------------------
   Money / status helpers
---------------------------------------------------------------- */
function pay_percent_display(float $p): string {
    return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
}

/** remaining = MAX(total - submitted_downpayment, 0) while the payment is pending/paid; otherwise the full total. */
function pay_remaining(float $total, float $amount, string $status): float {
    if (in_array($status, ['paid', 'pending'], true)) {
        return max($total - $amount, 0);
    }
    return max($total, 0);
}

function pay_status_info(string $s): array {
    switch ($s) {
        case 'paid':     return ['✓ Payment Verified', 'pb-paid'];
        case 'pending':  return ['⏳ Pending Verification', 'pb-pending'];
        case 'rejected': return ['✗ Payment Rejected', 'pb-rejected'];
        case 'refunded': return ['↩ Refunded', 'pb-refunded'];
        case 'unpaid':   return ['Unpaid', 'pb-unpaid'];
        default:         return [ucfirst($s ?: 'unknown'), 'pb-unpaid'];
    }
}
function res_status_info(string $s): array {
    switch ($s) {
        case 'confirmed':                    return ['✓ Reservation Confirmed', 'pb-paid'];
        case 'pending_payment_verification': return ['⏳ Pending Payment Verification', 'pb-pending'];
        case 'pending':                      return ['⏳ Pending', 'pb-pending'];
        case 'payment_verification_failed':  return ['✗ Payment Verification Failed', 'pb-rejected'];
        case 'cancelled':                    return ['Cancelled', 'pb-unpaid'];
        case 'completed':                    return ['Completed', 'pb-paid'];
        default:                             return [ucwords(str_replace('_', ' ', $s ?: 'unknown')), 'pb-unpaid'];
    }
}

/** A customer may cancel only while the reservation is still active and the stay has not started yet. */
function res_can_cancel(string $status, string $checkIn): bool {
    if (!in_array($status, ['pending', 'pending_payment_verification', 'payment_verification_failed', 'confirmed'], true)) {
        return false;
    }
    $ts = strtotime($checkIn);
    return $ts !== false && $ts > time();
}

/** Contact number saved on the customer's profile ('' when none). */
function pay_user_phone(mysqli $conn, int $uid): string {
    $s = $conn->prepare("SELECT phone, contact_number FROM users WHERE id = ? LIMIT 1");
    $s->bind_param("i", $uid);
    $s->execute();
    $row = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    $phone = trim((string)($row['phone'] ?? ''));
    return $phone !== '' ? $phone : trim((string)($row['contact_number'] ?? ''));
}

/* Shared badge CSS (echo once per page inside <style>) */
function pay_badge_css(): string {
    return '.pbadge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.pb-paid{background:#dff0d8;color:#2e6b2e}.pb-pending{background:#fff3cd;color:#8a6d00}
.pb-rejected{background:#f8d7da;color:#a02a35}.pb-refunded{background:#e2e3f3;color:#3b3f8f}.pb-unpaid{background:#e9e9e9;color:#555}';
}

/* ---------------------------------------------------------------
   Booking calculation (ALWAYS from MySQL prices)
---------------------------------------------------------------- */
function pay_calc_booking(mysqli $conn, int $accId, string $in, string $out, array $addonIds, ?int $guests = null): array {
    $fail = fn($m) => ['ok' => false, 'error' => $m];

    $d1 = DateTime::createFromFormat('Y-m-d', $in);
    $d2 = DateTime::createFromFormat('Y-m-d', $out);
    if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $in || $d2->format('Y-m-d') !== $out) {
        return $fail('Please select valid check-in and check-out dates.');
    }
    $d1->setTime(0, 0); $d2->setTime(0, 0);
    if ($d1 < new DateTime('today')) return $fail('Check-in date cannot be in the past.');
    if ($d2 <= $d1) return $fail('Check-out date must be after the check-in date.');
    $nights = (int)$d1->diff($d2)->days;
    if ($nights > 60) return $fail('Bookings are limited to 60 nights.');

    $s = $conn->prepare("SELECT * FROM accommodations WHERE id = ? LIMIT 1");
    $s->bind_param("i", $accId);
    $s->execute();
    $acc = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$acc) return $fail('Accommodation not found.');
    if (isset($acc['is_available']) && (int)$acc['is_available'] === 0) {
        return $fail('This accommodation is currently unavailable.');
    }
    if ($guests !== null) {
        if ($guests < 1) return $fail('Please enter the number of guests (at least 1).');
        $cap = (int)($acc['capacity'] ?? 0);
        if ($cap > 0 && $guests > $cap) {
            return $fail('This accommodation allows a maximum of ' . $cap . ' guest' . ($cap > 1 ? 's' : '') . '.');
        }
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $addonIds), fn($v) => $v > 0)));
    $addons = [];
    $addonsTotal = 0.0;
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $conn->prepare("SELECT id, name, price FROM addons WHERE id IN ($ph)");
        $q->bind_param(str_repeat('i', count($ids)), ...$ids);
        $q->execute();
        $res = $q->get_result();
        while ($row = $res->fetch_assoc()) {
            $addons[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'price' => (float)$row['price']];
            $addonsTotal += (float)$row['price'];
        }
        $q->close();
    }

    $settings = pay_get_settings($conn);
    $pct = (float)$settings['down_payment_percent'];

    $unit = (float)$acc['price'];
    $base = round($unit * $nights, 2);
    $total = round($base + $addonsTotal, 2);
    $down = round($total * $pct / 100, 2);
    $remaining = max(round($total - $down, 2), 0);

    return [
        'ok' => true, 'accommodation' => $acc, 'nights' => $nights, 'unit_price' => $unit, 'guests' => $guests ?? 1,
        'base' => $base, 'addons' => $addons, 'addons_total' => round($addonsTotal, 2),
        'total' => $total, 'percent' => $pct, 'down_payment' => $down, 'remaining' => $remaining,
    ];
}

function pay_is_available(mysqli $conn, int $accId, string $in, string $out, int $excludeId = 0): bool {
    $ph = implode(',', array_fill(0, count(PAY_BLOCKING_STATUSES), '?'));
    $sql = "SELECT COUNT(*) AS c FROM reservations
            WHERE accommodation_id = ? AND status IN ($ph) AND check_in < ? AND check_out > ?";
    $params = array_merge([$accId], PAY_BLOCKING_STATUSES, [$out, $in]);
    $types = 'i' . str_repeat('s', count(PAY_BLOCKING_STATUSES)) . 'ss';
    if ($excludeId > 0) {   // ignore one reservation (used when an admin re-activates it)
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
        $types .= 'i';
    }
    $s = $conn->prepare($sql);
    $s->bind_param($types, ...$params);
    $s->execute();
    $c = (int)$s->get_result()->fetch_assoc()['c'];
    $s->close();
    return $c === 0;
}

/* ---------------------------------------------------------------
   Secure uploads
---------------------------------------------------------------- */
function pay_prepare_dir(string $abs, string $mode): bool {
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) return false;
    $ht = rtrim($abs, '/\\') . '/.htaccess';
    if (!file_exists($ht)) {
        if ($mode === 'private') {
            $rules = "Require all denied\n";
        } else {
            $rules = <<<'HT'
<FilesMatch "\.(php|phtml|phar|php[0-9]|pl|py|cgi|sh)$">
Require all denied
</FilesMatch>

HT;
        }
        @file_put_contents($ht, $rules);
    }
    return is_writable($abs);
}

function pay_upload_error_text(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'The file is larger than the server allows (' . ini_get('upload_max_filesize') . ').';
        case UPLOAD_ERR_NO_FILE:   return 'Please choose a file to upload.';
        default:                   return 'Upload failed (error code ' . $code . ').';
    }
}

/** @return array{ok:bool,error?:string,path?:string,abs?:string} */
function pay_handle_upload(array $f, array $mimeMap, string $absDir, string $relDir, string $prefix, string $mode = 'private', int $max = 5242880): array {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => pay_upload_error_text((int)$err)];
    if (($f['size'] ?? 0) > $max) return ['ok' => false, 'error' => 'File is larger than ' . ($max / 1048576) . 'MB.'];
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return ['ok' => false, 'error' => 'Invalid upload.'];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($mimeMap[$mime])) {
        return ['ok' => false, 'error' => 'Invalid file type. Allowed: ' . strtoupper(implode(', ', array_unique(array_values($mimeMap)))) . '.'];
    }
    if (strpos($mime, 'image/') === 0 && @getimagesize($f['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'The image file appears to be corrupted.'];
    }
    if ($mime === 'application/pdf' && file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') {
        return ['ok' => false, 'error' => 'The PDF file appears to be invalid.'];
    }

    $absDir = rtrim($absDir, '/\\') . '/';
    if (!pay_prepare_dir($absDir, $mode)) return ['ok' => false, 'error' => 'Upload folder is missing or not writable.'];

    $name = $prefix . '_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $mimeMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $absDir . $name)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file.'];
    }
    @chmod($absDir . $name, 0644);
    return ['ok' => true, 'path' => rtrim($relDir, '/') . '/' . $name, 'abs' => $absDir . $name];
}

/** Delete a stored receipt safely (only inside uploads/receipts). */
function pay_delete_receipt(?string $rel): void {
    if (!$rel) return;
    $base = realpath(PERGOLA_ROOT . '/uploads/receipts');
    $abs = realpath(PERGOLA_ROOT . '/' . $rel);
    if ($base && $abs && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
        @unlink($abs);
    }
}


/* ---------------------------------------------------------------
   Smart INSERT: fills any other REQUIRED column (NOT NULL, no default)
   so inserts keep working when your tables have extra columns.
---------------------------------------------------------------- */
function pay_fill_required(mysqli $conn, string $table, array $given, array $hints = []): array {
    $extras = [];
    foreach (pay_table_columns($conn, $table) as $field => $meta) {
        if (in_array($field, $given, true)) continue;
        $extra = strtolower($meta['Extra'] ?? '');
        if ($meta['Null'] !== 'NO' || $meta['Default'] !== null
            || strpos($extra, 'auto_increment') !== false || strpos($extra, 'generated') !== false) continue;

        $lc = strtolower($field);
        if (array_key_exists($lc, $hints)) { $extras[$field] = $hints[$lc]; continue; }

        $t = strtolower($meta['Type']);
        if (preg_match("/^enum\\('((?:[^']|'')*)'/", $meta['Type'], $m)) {
            $extras[$field] = str_replace("''", "'", $m[1]);
        } elseif (preg_match('/int/', $t)) {
            $val = 0;
            if (preg_match('/^(.+)_id$/', $lc, $mm)) {
                // Foreign key style column: use the first row of the related table if it exists
                foreach ([$mm[1] . 's', $mm[1] . 'es', $mm[1]] as $cand) {
                    $chk = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($cand) . "'");
                    if ($chk && $chk->num_rows > 0) {
                        $r = $conn->query("SELECT MIN(id) AS m FROM `$cand`");
                        if ($r && ($row = $r->fetch_assoc()) && $row['m'] !== null) { $val = (int)$row['m']; }
                        break;
                    }
                }
            }
            $extras[$field] = $val;
        } elseif (preg_match('/decimal|float|double|numeric/', $t)) {
            $extras[$field] = 0;
        } elseif (strpos($t, 'datetime') !== false || strpos($t, 'timestamp') !== false) {
            $extras[$field] = date('Y-m-d H:i:s');
        } elseif (strpos($t, 'date') !== false) {
            $extras[$field] = date('Y-m-d');
        } elseif (strpos($t, 'time') !== false) {
            $extras[$field] = '00:00:00';
        } else {
            $extras[$field] = '';
        }
    }
    return $extras;
}

/** $base = column => value (bound), $literals = column => raw SQL (e.g. 'created_at' => 'NOW()') */
function pay_insert(mysqli $conn, string $table, array $base, array $literals = [], array $hints = []): int {
    $extras = pay_fill_required($conn, $table, array_merge(array_keys($base), array_keys($literals), ['id']), $hints);
    foreach ($extras as $k => $v) { $base[$k] = $v; }

    $cols = array_merge(array_keys($base), array_keys($literals));
    $ph = array_merge(array_fill(0, count($base), '?'), array_values($literals));
    $sql = "INSERT INTO `$table` (`" . implode("`, `", $cols) . "`) VALUES (" . implode(", ", $ph) . ")";
    $st = $conn->prepare($sql);
    $vals = array_map(fn($v) => $v === null ? null : (string)$v, array_values($base));
    if ($vals) $st->bind_param(str_repeat('s', count($vals)), ...$vals);
    $st->execute();
    $id = (int)$st->insert_id;
    $st->close();
    return $id;
}