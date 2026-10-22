<?php
// paypal_test.php: checks that your sandbox keys work. Local use only. DELETE when done.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Local access only.'); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paypal_helper.php';

function row($ok, $label, $hint = '') {
    echo '<p style="font-family:sans-serif"><strong style="color:' . ($ok ? '#2e6b2e' : '#a94442') . '">'
       . ($ok ? '✓ ' : '✗ ') . htmlspecialchars($label) . '</strong>'
       . ($hint ? '<br><span style="color:#555">' . htmlspecialchars($hint) . '</span>' : '') . '</p>';
}

row(extension_loaded('curl'), 'cURL extension', 'If missing: enable extension=curl in php.ini and restart Apache.');
$idOk = PAYPAL_CLIENT_ID !== '' && strpos(PAYPAL_CLIENT_ID, 'PASTE_') === false;
$secOk = PAYPAL_SECRET !== '' && strpos(PAYPAL_SECRET, 'PASTE_') === false;
row($idOk, 'Client ID is set', 'Paste your SANDBOX Client ID into paypal_config.php.');
row($secOk, 'Secret is set', 'Paste your SANDBOX Secret into paypal_config.php.');
row(strpos(PAYPAL_BASE, 'sandbox') !== false, 'Using the Sandbox URL (' . PAYPAL_BASE . ')');

if ($idOk && $secOk && extension_loaded('curl')) {
    $t = paypalToken();
    row($t !== null, 'PayPal accepted your credentials (access token received)',
        'If this fails: the Client ID/Secret do not match, are from the Live tab, or are from a different app.');
}
echo '<p style="font-family:sans-serif">Delete paypal_test.php when everything is ✓.</p>';