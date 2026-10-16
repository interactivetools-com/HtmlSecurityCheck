<?php
declare(strict_types=1);

/*
 * Router for PHP's built-in server, started by tools/css-fuzz.php: serves page.html from the
 * folder it serves and appends the path of every other request to requests.log there, one per
 * line, answering 204.
 *
 *     php -S 127.0.0.1:<port> -t <folder> tools/css-fuzz-log.php
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/page.html') {
    return false;   // the built-in server sends the file
}
file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/requests.log', "$path\n", FILE_APPEND | LOCK_EX);
http_response_code(204);
