<?php
// One-off: ?step=create adds the review fields, ?step=check reads then deletes the test contact. Deleted after use.
header('Content-Type: text/plain');
$cfg = @include __DIR__ . '/../../kando-private/config.php';
function api($method, $path, $data = null) {
    global $cfg;
    $ch = curl_init('https://api.brevo.com/v3' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['brevo_key'], 'accept: application/json', 'content-type: application/json']]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
$step = $_GET['step'] ?? '';
if ($step === 'create') {
    [$c, $b] = api('POST', '/contacts/attributes/category/STATUS', ['type' => 'category', 'enumeration' => [
        ['value' => 1, 'label' => 'Pending'], ['value' => 2, 'label' => 'Accepted'], ['value' => 3, 'label' => 'Declined']]]);
    echo "STATUS: $c $b\n";
    [$c, $b] = api('POST', '/contacts/attributes/normal/REVIEW_NOTE', ['type' => 'text']);
    echo "REVIEW_NOTE: $c $b\n";
}
if ($step === 'check') {
    $email = rawurlencode('sofia.marchetti.kando-test@example.com');
    [$c, $b] = api('GET', "/contacts/$email?identifierType=email_id");
    $j = json_decode($b, true);
    echo "get: $c lists=", json_encode($j['listIds'] ?? null), " attributes=", json_encode($j['attributes'] ?? null), "\n";
    if (($_GET['delete'] ?? '') === '1') { [$c] = api('DELETE', "/contacts/$email?identifierType=email_id"); echo "delete: $c\n"; }
}
