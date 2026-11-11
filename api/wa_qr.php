<?php
// =====================================================================
// GET /smart_bin/api/wa_qr.php   (login required)
// Shows the WhatsApp gateway's QR page, so the operator can link the
// spare phone from Settings without the gateway secret in any URL.
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/alerts.php';
require_login();

$cfg = $CONFIG['alerts']['wa_gateway'] ?? null;
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (!$cfg) {
    exit('<p style="font:16px sans-serif">The WhatsApp gateway is not set up in config.php.</p>');
}

$ch = curl_init(rtrim($cfg['url'], '/') . '/qr');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['X-Gateway-Secret: ' . $cfg['secret']],
]);
$page = curl_exec($ch);
curl_close($ch);

echo $page === false
    ? '<p style="font:16px sans-serif">The gateway is not running. Start <b>start-wa-gateway.bat</b>, then reload.</p>
       <script>setTimeout(()=>location.reload(),5000)</script>'
    : $page;   // the gateway's own page (QR image + auto reload)
