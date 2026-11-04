<?php
// One-off check: reads the test contact from Brevo, then deletes it. Deleted after use.
header('Content-Type: text/plain');
$cfg = @include __DIR__ . '/../../kando-private/config.php';
$email = rawurlencode('sofia.marchetti.kando-test@example.com');
foreach (['GET', 'DELETE'] as $m) {
    $ch = curl_init("https://api.brevo.com/v3/contacts/$email?identifierType=email_id");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['brevo_key'], 'accept: application/json']]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($m === 'GET') { $c = json_decode($body, true); echo "get: $code lists=", json_encode($c['listIds'] ?? null), " attributes=", json_encode($c['attributes'] ?? null), "\n"; }
    else echo "delete: $code\n";
}
