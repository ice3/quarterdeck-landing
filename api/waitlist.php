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

if ($status === 201) send_confirmation($cfg['brevo_key'], $email, $first);
if ($status === 201 || $status === 204) reply(200, true);
error_log('KANDO waitlist: Brevo answered ' . $status . ' ' . substr((string)$body, 0, 300));
reply(502, false, 'brevo');
