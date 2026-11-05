<?php
// Waitlist endpoint: validates the form and adds the contact to the Brevo list.
header('Content-Type: application/json');
header('Cache-Control: no-store');

const LIST_ID = 4; // Brevo list "Waitlist" in folder "KANDO"

function reply($code, $ok, $error = null) {
    http_response_code($code);
    echo json_encode($error ? ['ok' => $ok, 'error' => $error] : ['ok' => $ok]);
    exit;
}
function field($in, $key, $max) {
    return mb_substr(trim((string)($in[$key] ?? '')), 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, false, 'method');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) reply(400, false, 'body');

// At most 5 attempts per IP in 10 minutes. The IP is stored hashed, outside www/.
function too_many_attempts($ip) {
    $dir = __DIR__ . '/../../kando-private/rate';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f = @fopen($dir . '/' . hash('sha256', $ip) . '.txt', 'c+');
    if (!$f) return false; // never block signups because of a file problem
    flock($f, LOCK_EX);
    $now = time();
    $times = array_filter(array_map('intval', explode(',', stream_get_contents($f))), function ($t) use ($now) { return $t > $now - 600; });
    $blocked = count($times) >= 5;
    if (!$blocked) $times[] = $now;
    ftruncate($f, 0);
    rewind($f);
    fwrite($f, implode(',', $times));
    flock($f, LOCK_UN);
    fclose($f);
    return $blocked;
}
if (too_many_attempts($_SERVER['REMOTE_ADDR'] ?? '')) reply(429, false, 'rate');

// Honeypot: real visitors never fill this field.
if (field($in, 'website', 200) !== '') reply(200, true);

$first   = field($in, 'first_name', 80);
$last    = field($in, 'last_name', 80);
$company = field($in, 'company', 120);
$email   = field($in, 'email', 200);
$comments = field($in, 'comments', 2000);

if ($first === '' || $last === '' || $company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(422, false, 'fields');
}

// Names are echoed in the emails we send, so keep them short and free of links.
foreach (['first_name', 'last_name'] as $key) {
    $raw = (string)($in[$key] ?? '');
    if (mb_strlen(trim($raw)) > 50 || preg_match('~https?:|www\.|@|[\r\n]~i', $raw)) reply(422, false, 'name');
}

$cfg = @include __DIR__ . '/../../kando-private/config.php';
if (!is_array($cfg) || empty($cfg['brevo_key']) || !LIST_ID) reply(503, false, 'config');

$attributes = ['PRENOM' => $first, 'NOM' => $last, 'COMPANY' => $company];
if ($comments !== '') $attributes['COMMENTS'] = $comments;

function brevo($method, $path, $key, $data) {
    $ch = curl_init('https://api.brevo.com/v3' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'accept: application/json', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

// Sent once, to new contacts only. A failure here never blocks the signup.
function send_confirmation($key, $email, $first) {
    $name = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
    $text = "Hi $first,\n\n"
        . "Thank you for joining the KANDO waitlist.\n\n"
        . "KANDO is a professional networking platform created exclusively for the superyacht industry. We review every registration to keep it that way, and we will be in touch as soon as early access opens.\n\n"
        . "Have an idea for KANDO? Simply reply to this email. We read everything.\n\n"
        . "The KANDO team\nkando.network\n";
    $html = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#1f1b16;max-width:520px">'
        . '<p style="font-size:13px;letter-spacing:.3em;color:#A68959;margin:0 0 24px">KANDO</p>'
        . "<p>Hi $name,</p>"
        . '<p>Thank you for joining the KANDO waitlist.</p>'
        . '<p>KANDO is a professional networking platform created exclusively for the superyacht industry. We review every registration to keep it that way, and we will be in touch as soon as early access opens.</p>'
        . '<p>Have an idea for KANDO? Simply reply to this email. We read everything.</p>'
        . '<p>The KANDO team<br><a href="https://kando.network" style="color:#A68959">kando.network</a></p></div>';
    [$status, $body] = brevo('POST', '/smtp/email', $key, [
        'sender' => ['name' => 'KANDO', 'email' => 'contact@kando.network'],
        'replyTo' => ['email' => 'contact@kando.network'],
        'to' => [['email' => $email, 'name' => $first]],
        'subject' => "You're on the KANDO waitlist",
        'htmlContent' => $html,
        'textContent' => $text,
    ]);
    if ($status !== 201) error_log('KANDO confirmation: Brevo answered ' . $status . ' ' . substr((string)$body, 0, 300));
}

// Tells the team about each new signup. Reply-to is the applicant, so answering goes straight to them.
function send_notification($key, $email, $first, $last, $company, $comments) {
    $e = function ($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
    $rows = ['Name' => "$first $last", 'Company' => $company, 'Email' => $email, 'Comments' => $comments !== '' ? $comments : '-'];
    $text = "New KANDO waitlist signup\n\n";
    $html = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f1b16"><p>New KANDO waitlist signup</p><table cellpadding="4">';
    foreach ($rows as $label => $value) {
        $text .= "$label: $value\n";
        $html .= '<tr><td style="color:#8a8073;vertical-align:top">' . $label . '</td><td>' . nl2br($e($value)) . '</td></tr>';
    }
    $html .= '</table><p><a href="https://app.brevo.com/contact/list-listing/id/' . LIST_ID . '" style="color:#A68959">Open the Waitlist in Brevo</a></p></div>';
    [$status, $body] = brevo('POST', '/smtp/email', $key, [
        'sender' => ['name' => 'KANDO waitlist', 'email' => 'contact@kando.network'],
        'replyTo' => ['email' => $email, 'name' => "$first $last"],
        'to' => [['email' => 'contact@kando.network']],
        'subject' => "New signup: $first $last, $company",
        'htmlContent' => $html,
        'textContent' => $text,
    ]);
    if ($status !== 201) error_log('KANDO notification: Brevo answered ' . $status . ' ' . substr((string)$body, 0, 300));
}

// New contacts start as Pending (STATUS 1). A returning contact keeps the status we already gave them.
[$status, $body] = brevo('POST', '/contacts', $cfg['brevo_key'], [
    'email' => $email,
    'attributes' => $attributes + ['STATUS' => 1],
    'listIds' => [LIST_ID],
]);
if ($status === 400 && strpos((string)$body, 'duplicate') !== false) {
    [$status, $body] = brevo('PUT', '/contacts/' . rawurlencode($email) . '?identifierType=email_id', $cfg['brevo_key'], [
        'attributes' => $attributes,
        'listIds' => [LIST_ID],
    ]);
}

if ($status === 201) {
    send_confirmation($cfg['brevo_key'], $email, $first);
    send_notification($cfg['brevo_key'], $email, $first, $last, $company, $comments);
}
if ($status === 201 || $status === 204) reply(200, true);
error_log('KANDO waitlist: Brevo answered ' . $status . ' ' . substr((string)$body, 0, 300));
reply(502, false, 'brevo');
