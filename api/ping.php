<?php
header('Content-Type: text/plain');
$ip = @file_get_contents('https://api.ipify.org');
echo "php ok ", PHP_VERSION, " outbound_ip=", $ip ?: 'unknown', "\n";
