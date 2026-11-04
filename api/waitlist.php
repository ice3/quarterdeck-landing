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

if ($status === 201 || $status === 204) reply(200, true);
error_log('KANDO waitlist: Brevo answered ' . $status . ' ' . substr((string)$body, 0, 300));
reply(502, false, 'brevo');
