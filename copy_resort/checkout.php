<?php
require 'config.php';
require 'api/paypal_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$products =$conn->query("SELECT id, name, price FROM products")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Checkout | Notes Shop</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- PayPal JavaScript SDK -->
    <script src="https://www.paypal.com/sdk/js?client-id=<?= PAYPAL_CLIENT_ID ?>&currency=PHP"></script>
</head>
<body style="font-family: Arial, sans-serif; padding: 40px; background: #f9f9f9;">
    <div style="max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <h1>Upgrade Your Notes</h1>
        
        <div style="margin-bottom: 20px;">
            <label for="product" style="display: block; font-weight: bold; margin-bottom: 8px;">Select Product:</label>
            <select id="product" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                <?php foreach ($products as$p): ?>
                    <option value="<?= $p['id'] ?>">
                        <?= htmlspecialchars($p['name']) ?> - PHP <?= number_format($p['price'], 2) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="paypal-buttons" style="margin-bottom: 20px;"></div>
        <p id="message" style="font-weight: bold; color: #333;"></p>

        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

        <h2>My Order History</h2>
        <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
                <tr style="background: #f1f1f1; text-align: left;">
                    <th style="padding: 10px; border: 1px solid #ddd;">ID</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Product</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Amount</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Status</th>
                    <th style="padding: 10px; border: 1px solid #ddd;">Date</th>
                </tr>
            </thead>
            <tbody id="order-history">
                <!-- I-load gamit ang JS fetch() -->
            </tbody>
        </table>
    </div>

    <script>
        const message = document.getElementById('message');

        function loadOrders() {
            fetch('api/orders.php')
                .then(res => res.json())
                .then(orders => {
                    const tbody = document.getElementById('order-history');
                    tbody.innerHTML = '';
                    if (orders.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" style="padding: 10px; text-align: center;">No orders found.</td></tr>';
                        return;
                    }
                    orders.forEach(o => {
                        tbody.innerHTML += `
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">#${o.id}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">${o.product_name}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">₱${parseFloat(o.amount).toFixed(2)}</td>
                                <td style="padding: 10px; border: 1px solid #ddd;"><strong>${o.status}</strong></td>
                                <td style="padding: 10px; border: 1px solid #ddd;">${o.created_at}</td>
                            </tr>
                        `;
                    });
                });
        }

        // Load orders on page load
        loadOrders();

        paypal.Buttons({
            createOrder: function () {
                return fetch('api/paypal_create_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: document.getElementById('product').value })
                })
                .then(res => res.json())
                .then(order => {
                    if (!order.id) throw new Error(order.error);
                    return order.id;
                });
            },
            onApprove: function (data) {
                return fetch('api/paypal_capture_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ orderID: data.orderID })
                })
                .then(res => res.json())
                .then(result => {
                    message.textContent = result.error 
                        ? 'Payment failed: ' + result.error 
                        : 'Payment ' + result.status + '. Reference: ' + result.capture_id;
                    loadOrders(); // Refresh order history list dynamically
                });
            },
            onCancel: function () {
                message.textContent = 'You cancelled the payment. You were not charged.';
            },
            onError: function (err) {
                message.textContent = 'Something went wrong. Please try again.';
                console.error(err);
            }
        }).render('#paypal-buttons');
    </script>
</body>
</html>