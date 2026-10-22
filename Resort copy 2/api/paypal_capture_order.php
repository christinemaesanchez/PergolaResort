<?php
require_once "../config.php";
require_once "../payment_lib.php";
require_once "../paypal_helper.php";
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']))                  paypal_json(401, ['error' => 'Please log in again.']);
if (($_SESSION['role'] ?? '') !== 'customer')     paypal_json(403, ['error' => 'Only customers can book.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')        paypal_json(405, ['error' => 'Method not allowed.']);
if (!csrf_valid($_POST['csrf'] ?? null))          paypal_json(403, ['error' => 'Your session expired. Please refresh the page.']);

$uid     = (int)$_SESSION['user_id'];
$orderId = is_string($_POST['orderID'] ?? null) ? trim($_POST['orderID']) : '';
$capId   = '';   // set once PayPal has taken the money

function set_intent(mysqli $conn, string $orderId, string $status, ?string $capId = null): void {
    $u = $conn->prepare("UPDATE paypal_intents SET status = ?, capture_id = COALESCE(?, capture_id) WHERE paypal_order_id = ?");
    $u->bind_param('sss', $status, $capId, $orderId);
    $u->execute();
    $u->close();
}

try {
    paypal_ensure_schema($conn);
    $conn->begin_transaction();

    // The order must exist, belong to THIS user and not be processed yet. Row lock stops double capture.
    $q = $conn->prepare("SELECT * FROM paypal_intents WHERE paypal_order_id = ? AND user_id = ? AND status = 'created' FOR UPDATE");
    $q->bind_param('si', $orderId, $uid);
    $q->execute();
    $in = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$in) { $conn->rollback(); paypal_json(404, ['error' => 'Order not found.']); }

    $accId = (int)$in['accommodation_id'];
    $ci = $in['check_in']; $co = $in['check_out'];

    // Lock the accommodation (same as book.php) so nobody else books these dates meanwhile.
    $lock = $conn->prepare("SELECT id FROM accommodations WHERE id = ? FOR UPDATE");
    $lock->bind_param('i', $accId);
    $lock->execute();
    $lock->close();

    // Check BEFORE taking money. If it fails the customer is not charged.
    if (!pay_is_available($conn, $accId, $ci, $co)) {
        set_intent($conn, $orderId, 'unavailable');
        $conn->commit();
        paypal_json(409, ['error' => 'Sorry, those dates were just booked. You were not charged.']);
    }
    $addonIds = json_decode((string)$in['addon_ids'], true) ?: [];
    $guests = max((int)($in['guests'] ?? 1), 1);
    $calc = pay_calc_booking($conn, $accId, $ci, $co, $addonIds, $guests);
    if (!$calc['ok']) {
        set_intent($conn, $orderId, 'invalid');
        $conn->commit();
        paypal_json(409, ['error' => $calc['error'] . ' You were not charged.']);
    }

    // Capture: this is the moment money moves.
    $result  = paypalRequest('POST', '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
    $capture = $result['data']['purchase_units'][0]['payments']['captures'][0] ?? null;
    if ($result['status'] !== 201 || $capture === null) {
        $conn->rollback();
        error_log('PayPal capture failed: ' . json_encode($result));
        paypal_json(502, ['error' => 'Payment could not be captured. You were not charged.']);
    }
    $capId = (string)$capture['id'];

    $down   = number_format((float)$in['down_payment'], 2, '.', '');
    $paid   = number_format((float)$capture['amount']['value'], 2, '.', '');
    if ($paid !== $down || $capture['amount']['currency_code'] !== PAYPAL_CURRENCY) {
        throw new RuntimeException("Amount mismatch: expected $down, PayPal collected $paid " . $capture['amount']['currency_code']);
    }

    $completed  = ($capture['status'] ?? '') === 'COMPLETED';
    $res_status = $completed ? 'confirmed' : 'pending_payment_verification';
    $pay_status = $completed ? 'paid' : 'pending';
    $total      = number_format((float)$in['total_amount'], 2, '.', '');
    $remaining  = number_format(max((float)$total - (float)$down, 0), 2, '.', '');

    // ----- Create the reservation (same columns/hints as book.php) -----
    $rid = pay_insert($conn, 'reservations', [
        'user_id' => $uid, 'accommodation_id' => $accId,
        'check_in' => $calc['check_in_dt'], 'check_out' => $calc['check_out_dt'],
        'total_amount' => $total, 'guests' => $guests, 'status' => $res_status,
    ], ['created_at' => 'NOW()'], [
        'price' => $calc['unit_price'], 'unit_price' => $calc['unit_price'], 'room_price' => $calc['unit_price'],
        'rate' => $calc['unit_price'], 'total_price' => $total, 'subtotal' => $total, 'total' => $total,
        'amount' => $total, 'nights' => $calc['nights'], 'num_nights' => $calc['nights'],
        'guests' => 1, 'num_guests' => 1, 'number_of_guests' => 1, 'adults' => 1, 'pax' => 1,
    ]);
    foreach ($calc['addons'] as $a) {
        pay_insert($conn, 'reservation_addons', [
            'reservation_id' => $rid, 'addon_id' => (int)$a['id'],
            'price' => $a['price'], 'quantity' => 1,
            'line_total' => $a['line_total'], 'pricing_method' => $a['pricing_method'],
        ]);
    }

    // ----- Record the payment -----
    $literals = ['method' => "'paypal'", 'status' => "'$pay_status'", 'payment_date' => 'NOW()'];
    if ($completed) $literals['verified_date'] = 'NOW()';
    pay_insert($conn, 'payments', [
        'reservation_id' => $rid, 'amount' => $down,
        'total_amount' => $total, 'down_payment' => $down, 'remaining_balance' => $remaining,
        'paypal_order_id' => $orderId, 'capture_id' => $capId,
        'admin_remarks' => $completed ? 'Verified automatically by PayPal' : 'PayPal payment pending - verify in PayPal dashboard',
    ], $literals);

    set_intent($conn, $orderId, 'completed', $capId);

    // Notifications
    notify_admins($conn, 'reservation_new', 'New reservation (PayPal)',
        ($_SESSION['full_name'] ?? 'A customer') . ' booked ' . $calc['accommodation']['name'] . ' (' . pay_fmt_dt($calc['check_in_dt'], 'in') . ' to '
        . pay_fmt_dt($calc['check_out_dt'], 'out') . '). Down payment ' . peso($down) . ' via PayPal.',
        'admin/reservations.php?search=' . $rid, $rid, 'res_new:' . $rid);
    notify_user($conn, $uid, $completed ? 'payment_approved' : 'reservation_submitted',
        $completed ? 'Payment received - reservation confirmed' : 'Reservation submitted',
        $completed ? 'Your PayPal payment was received and reservation #' . $rid . ' is confirmed.'
                   : 'Reservation #' . $rid . ' was submitted. Your PayPal payment is being verified.',
        'customer/my_bookings.php?res=' . $rid, $rid, 'res_submitted:' . $rid);

    $conn->commit();

    paypal_json(200, ['ok' => true, 'redirect' => 'booking_success.php?id=' . $rid]);

} catch (Throwable $ex) {
    try { $conn->rollback(); } catch (Throwable $e) {}
    error_log('paypal_capture_order: ' . $ex->getMessage() . ($capId ? " [capture $capId]" : ''));
    if ($capId !== '') {
        // Money was taken but the booking could not be saved: keep a trace for the admin.
        try { set_intent($conn, $orderId, 'captured_error', $capId); } catch (Throwable $e) {}
        paypal_json(500, ['error' => "Your PayPal payment went through (Ref: $capId) but we couldn't save the booking. Please contact the resort with this reference."]);
    }
    paypal_json(500, ['error' => 'Server error. You were not charged.']);
}