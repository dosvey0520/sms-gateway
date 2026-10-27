<?php
// Router for PHP's built-in server:  php -S 0.0.0.0:8000 -t web web/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/api/v1/') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}
// Never serve config or includes over HTTP.
if (preg_match('#^/(config(\.example)?\.php|inc/|schema\.sql)#', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
return false;   // serve the requested file normally
