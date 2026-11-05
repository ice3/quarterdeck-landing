<?php
// Temporary: checks which client IP PHP sees on OVH. Delete after use.
header('Content-Type: text/plain');
echo ($_SERVER['REMOTE_ADDR'] ?? '-') . ' | ' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '-') . "\n";
