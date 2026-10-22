<?php
/**
 * paypal_helper.php  (project ROOT, next to payment_lib.php)
 * PayPal REST helpers + self-healing schema for the PayPal checkout.
 */
require_once __DIR__ . '/paypal_config.php';
require_once __DIR__ . '/payment_lib.php';

function paypal_json(int $code, array $body): void {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit();
}

/** Short-lived access token from your Client ID + Secret (cached per request). */
function paypalToken(): ?string {
    static $token = null;
    if ($token !== null) return $token;
    $ch = curl_init(PAYPAL_BASE . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return null;
    $res = json_decode($raw, true);
    return $token = ($res['access_token'] ?? null);
}

/** @return array{status:int,data:?array} */
function paypalRequest(string $method, string $path, ?array $body = null): array {
    $token = paypalToken();
    if ($token === null) return ['status' => 0, 'data' => null];
    $ch = curl_init(PAYPAL_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS     => json_encode($body ?? new stdClass()),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => $raw === false ? null : json_decode($raw, true)];
}

/** Adds only what is missing. Safe to run on every request. */
function paypal_ensure_schema(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pc = pay_table_columns($conn, 'payments');
    foreach (['paypal_order_id' => 'VARCHAR(64) NULL', 'capture_id' => 'VARCHAR(64) NULL'] as $col => $def) {
        if (!isset($pc[$col])) $conn->query("ALTER TABLE `payments` ADD COLUMN `$col` $def");
    }
    $idx = $conn->query("SHOW INDEX FROM `payments` WHERE Key_name = 'uq_paypal_order'");
    if ($idx->num_rows === 0) {
        $conn->query("ALTER TABLE `payments` ADD UNIQUE KEY `uq_paypal_order` (`paypal_order_id`)");
    }

    // Make sure payments.method accepts 'paypal'
    if (isset($pc['method'])) {
        $m = $pc['method'];
        $null = $m['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $def = $m['Default'] !== null
            ? "DEFAULT '" . $conn->real_escape_string($m['Default']) . "'"
            : ($m['Null'] === 'YES' ? 'DEFAULT NULL' : '');
        if (stripos($m['Type'], 'enum(') === 0) {
            preg_match_all("/'((?:[^']|'')*)'/", $m['Type'], $mm);
            $vals = array_map(fn($v) => str_replace("''", "'", $v), $mm[1]);
            if (!in_array('paypal', $vals, true)) {
                $vals[] = 'paypal';
                $list = implode(',', array_map(fn($v) => "'" . $conn->real_escape_string($v) . "'", $vals));
                $conn->query("ALTER TABLE `payments` MODIFY `method` ENUM($list) $null $def");
            }
        } elseif (preg_match('/^varchar\((\d+)\)/i', $m['Type'], $mm) && (int)$mm[1] < 20) {
            $conn->query("ALTER TABLE `payments` MODIFY `method` VARCHAR(20) $null $def");
        }
    }

    // A booking is only created AFTER PayPal money is captured.
    // Until then the customer's choices are stored here (server-calculated amounts).
    $conn->query("CREATE TABLE IF NOT EXISTS `paypal_intents` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `paypal_order_id` VARCHAR(64) NOT NULL UNIQUE,
        `user_id` INT NOT NULL,
        `accommodation_id` INT NOT NULL,
        `check_in` DATE NOT NULL,
        `check_out` DATE NOT NULL,
        `addon_ids` TEXT NULL,
        `guests` INT NOT NULL DEFAULT 1,
        `total_amount` DECIMAL(10,2) NOT NULL,
        `down_payment` DECIMAL(10,2) NOT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'created',
        `capture_id` VARCHAR(64) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Older installs created paypal_intents before the guest count existed
    $ic = pay_table_columns($conn, 'paypal_intents');
    if (!isset($ic['guests'])) {
        $conn->query("ALTER TABLE `paypal_intents` ADD COLUMN `guests` INT NOT NULL DEFAULT 1 AFTER `addon_ids`");
    }
}