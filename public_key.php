<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Public keys are designed to be shared. The private key is never served.
if (!is_file(PUBLIC_KEY_PATH) || !is_readable(PUBLIC_KEY_PATH)) {
    http_response_code(503);
    exit('The public key is unavailable.');
}
$publicKey = file_get_contents(PUBLIC_KEY_PATH);
if ($publicKey === false) {
    http_response_code(503);
    exit('The public key is unavailable.');
}
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: inline; filename="secure-portal-public-key.pem"');
header('X-Content-Type-Options: nosniff');
echo $publicKey;
