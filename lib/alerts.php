<?php
// =====================================================================
// DFD process 3.0 - Send urgent alert (email and/or WhatsApp).
// Every attempt is written to the alert_log table with its result.
// =====================================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

// Google Maps link for a bin
function maps_link(array $bin): string
{
    if ($bin['lat'] === null || $bin['lng'] === null) {
        return '(no coordinates set)';
    }
    return 'https://maps.google.com/?q=' . $bin['lat'] . ',' . $bin['lng'];
}

// Text of the FIRE alert (plain text, works for email and WhatsApp).
// $is_test = started from the Simulator page: clearly marked as a test.
function fire_message(array $bin, ?float $temp_c, string $time, bool $is_test = false): array
{
    $temp = $temp_c === null ? 'not available' : number_format($temp_c, 1) . ' °C';
    $subject = ($is_test ? 'TEST - ' : '') . "FIRE ALERT - {$bin['bin_id']} {$bin['name']}";
    $text = ($is_test ? "🧪 TEST ONLY - this is a drill, there is no real fire.\n" : '')
          . "🔥 FIRE ALERT - Smart Bin\n"
          . "Bin: {$bin['bin_id']} ({$bin['name']})\n"
          . "Location: {$bin['location_text']}\n"
          . "Map: " . maps_link($bin) . "\n"
          . "Temperature: {$temp}\n"
          . "Time: {$time}\n"
          . "Call Civil Defence: 9999";
    return [$subject, $text];
}

// Text of the optional "bin full" message (Objective 2)
function full_message(array $bin, float $fill_pct, string $time): array
{
    $subject = "Bin full - {$bin['bin_id']} {$bin['name']}";
    $text = "🗑️ Bin full - Smart Bin\n"
          . "Bin: {$bin['bin_id']} ({$bin['name']})\n"
          . "Fill level: " . round($fill_pct) . "%\n"
          . "Location: {$bin['location_text']}\n"
          . "Map: " . maps_link($bin) . "\n"
          . "Time: {$time}";
    return [$subject, $text];
}

// ---------------------------------------------------------------------
// Send one alert on every enabled channel.
// $kind = 'FIRE' | 'FULL' | 'TEST'. Returns a list of results:
//   [['channel'=>'email','recipient'=>'x','ok'=>true,'info'=>'...'], ...]
// ---------------------------------------------------------------------
function send_alert(mysqli $conn, array $CONFIG, string $kind, ?string $bin_id,
                    ?int $event_id, string $subject, string $text): array
{
    $alerts  = $CONFIG['alerts'] ?? [];
    $timeout = (int)($alerts['timeout_s'] ?? 8);
    $results = [];

    if (!empty($alerts['email']['enabled'])) {
        foreach ((array)$alerts['email']['to'] as $to) {
            $results[] = ['channel' => 'email', 'recipient' => $to]
                       + send_email($alerts['email'], $to, $subject, $text, $timeout);
        }
    }
    if (!empty($alerts['whatsapp']['enabled'])) {
        foreach ((array)$alerts['whatsapp']['recipients'] as $r) {
            $results[] = ['channel' => 'whatsapp', 'recipient' => $r['phone']]
                       + send_whatsapp($r['phone'], $r['apikey'], $text, $timeout);
        }
    }

    // Log every attempt
    foreach ($results as $r) {
        $ok   = $r['ok'] ? 1 : 0;
        $info = mb_substr($r['info'], 0, 500);
        db_exec($conn,
            'INSERT INTO alert_log (event_id, bin_id, kind, channel, recipient, success, response)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssis', [$event_id, $bin_id, $kind, $r['channel'], $r['recipient'], $ok, $info]);
    }
    return $results;
}

// True if at least one channel worked
function any_success(array $results): bool
{
    foreach ($results as $r) {
        if ($r['ok']) return true;
    }
    return false;
}

// Email through Gmail SMTP with PHPMailer
function send_email(array $cfg, string $to, string $subject, string $text, int $timeout): array
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->Port       = (int)$cfg['smtp_port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['app_password'];
        $mail->SMTPSecure = ((int)$cfg['smtp_port'] === 465)
                          ? PHPMailer::ENCRYPTION_SMTPS
                          : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = $timeout;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($cfg['username'], $cfg['from_name'] ?? 'Smart Bin');
        $mail->addAddress($to);
        $mail->Subject    = $subject;
        $mail->Body       = $text;          // plain text email
        $mail->send();
        return ['ok' => true, 'info' => 'sent'];
    } catch (MailException $ex) {
        return ['ok' => false, 'info' => $mail->ErrorInfo ?: $ex->getMessage()];
    }
}

// WhatsApp through CallMeBot (one HTTP GET per recipient)
function send_whatsapp(string $phone, string $apikey, string $text, int $timeout): array
{
    $url = 'https://api.callmebot.com/whatsapp.php?'
         . http_build_query(['phone' => $phone, 'text' => $text, 'apikey' => $apikey]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'SmartBin/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'info' => 'connection failed: ' . $err];
    }
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
    // CallMeBot answers 200 with a text page; it contains "queued" or "sent" on success
    $ok = ($code === 200) && preg_match('/queued|sent|success/i', $plain);
    return ['ok' => (bool)$ok, 'info' => "HTTP $code: " . mb_substr($plain, 0, 300)];
}
