<?php
require_once "../config.php";
require_once "../payment_lib.php";
require_once "../paypal_helper.php";
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']))                  paypal_json(401, ['error' => 'Please log in again.']);
if (($_SESSION['role'] ?? '') !== 'customer')     paypal_json(403, ['error' => 'Only customers can book.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')        paypal_json(405, ['error' => 'Method not allowed.']);
if (!csrf_valid($_POST['csrf'] ?? null))          paypal_json(403, ['error' => 'Your session expired. Please refresh the page.']);

try {
    paypal_ensure_schema($conn);
    $uid   = (int)$_SESSION['user_id'];
    $accId = (int)($_POST['accommodation_id'] ?? 0);
    $in    = trim($_POST['check_in'] ?? '');
    $out   = trim($_POST['check_out'] ?? '');
    $sel   = $_POST['addons'] ?? [];
    if (!is_array($sel)) $sel = [];
    $guestsIn = filter_var(trim((string)($_POST['guests'] ?? '')), FILTER_VALIDATE_INT);
    $guests   = ($guestsIn === false) ? 0 : (int)$guestsIn;

    if (pay_user_phone($conn, $uid) === '') {
        paypal_json(422, ['error' => 'Please add your contact number in your profile before booking.']);
    }

    // Price is ALWAYS calculated from MySQL. The browser never sends an amount.
    $calc = pay_calc_booking($conn, $accId, $in, $out, $sel, $guests);
    if (!$calc['ok']) paypal_json(422, ['error' => $calc['error']]);
    if (!pay_is_available($conn, $accId, $in, $out)) {
        paypal_json(409, ['error' => 'This accommodation is already booked for the selected dates.']);
    }

    $total = number_format($calc['total'], 2, '.', '');
    $down  = number_format($calc['down_payment'], 2, '.', '');
    if ((float)$down <= 0) paypal_json(422, ['error' => 'Invalid down payment amount.']);

    $result = paypalRequest('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'description' => mb_substr('Down payment - ' . $calc['accommodation']['name'], 0, 120),
            'amount'      => ['currency_code' => PAYPAL_CURRENCY, 'value' => $down],
        ]],
    ]);
    if (!in_array($result['status'], [200, 201], true) || empty($result['data']['id'])) {
        error_log('PayPal create failed: ' . json_encode($result));
        paypal_json(502, ['error' => 'Could not create the PayPal order. Please try again.']);
    }
    $orderId = $result['data']['id'];

    $addonIds = json_encode(array_map(fn($a) => (int)$a['id'], $calc['addons']));
    $st = $conn->prepare("INSERT INTO paypal_intents
        (paypal_order_id, user_id, accommodation_id, check_in, check_out, addon_ids, guests, total_amount, down_payment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->bind_param('siisssiss', $orderId, $uid, $accId, $in, $out, $addonIds, $guests, $total, $down);
    $st->execute();
    $st->close();

    paypal_json(200, ['id' => $orderId]);
} catch (Throwable $ex) {
    error_log('paypal_create_order: ' . $ex->getMessage());
    paypal_json(500, ['error' => 'Server error. Please try again.']);
}