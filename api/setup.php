<?php
// One-off: creates the Brevo contact fields and the Waitlist list. Deleted after use.
header('Content-Type: text/plain');
$cfg = @include __DIR__ . '/../../kando-private/config.php';
if (!is_array($cfg) || empty($cfg['brevo_key'])) { echo "no config\n"; exit; }
function api($method, $path, $data = null) {
    global $cfg;
    $ch = curl_init('https://api.brevo.com/v3' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['brevo_key'], 'accept: application/json', 'content-type: application/json'],
    ]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true)];
}
[$c, $attrs] = api('GET', '/contacts/attributes');
echo "attributes: $c ", $attrs['message'] ?? '', "\n";
if ($c !== 200) exit;
$names = array_column(array_filter($attrs['attributes'] ?? [], fn($a) => ($a['category'] ?? '') === 'normal'), 'name');
echo "existing: ", implode(' ', $names), "\n";
foreach (['FIRSTNAME', 'LASTNAME', 'COMPANY', 'COMMENTS'] as $a) {
    if (!in_array($a, $names, true)) { [$c] = api('POST', "/contacts/attributes/normal/$a", ['type' => 'text']); echo "create $a: $c\n"; }
}
[, $folders] = api('GET', '/contacts/folders?limit=50&offset=0');
$folder = null;
foreach ($folders['folders'] ?? [] as $f) if ($f['name'] === 'KANDO') $folder = $f['id'];
if (!$folder) { [, $r] = api('POST', '/contacts/folders', ['name' => 'KANDO']); $folder = $r['id'] ?? null; }
[, $lists] = api('GET', '/contacts/lists?limit=50&offset=0');
$list = null;
foreach ($lists['lists'] ?? [] as $l) if ($l['name'] === 'Waitlist') $list = $l['id'];
if (!$list) { [, $r] = api('POST', '/contacts/lists', ['name' => 'Waitlist', 'folderId' => $folder]); $list = $r['id'] ?? null; }
echo "folder KANDO: $folder\nlist Waitlist: $list\n";
