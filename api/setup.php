<?php
// One-off cleanup: removes the duplicate FIRSTNAME and LASTNAME fields (the account uses PRENOM and NOM). Deleted after use.
header('Content-Type: text/plain');
$cfg = @include __DIR__ . '/../../kando-private/config.php';
if (!is_array($cfg) || empty($cfg['brevo_key'])) { echo "no config\n"; exit; }
foreach (['FIRSTNAME', 'LASTNAME'] as $a) {
    $ch = curl_init("https://api.brevo.com/v3/contacts/attributes/normal/$a");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['brevo_key'], 'accept: application/json']]);
    curl_exec($ch);
    echo "delete $a: ", curl_getinfo($ch, CURLINFO_HTTP_CODE), "\n";
    curl_close($ch);
}
