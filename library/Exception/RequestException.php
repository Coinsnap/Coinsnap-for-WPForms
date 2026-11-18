<?php
declare(strict_types=1);
namespace Coinsnap\Exception;

if (!defined('ABSPATH')) {
    exit;
}

class RequestException extends CSException {
    public function __construct(string $method, string $url, int $status, string $body){
        // No store, invoice or webhook IDs and no response body: they end up in logs and error pages (6.13).
        $path = (string) preg_replace('#/(stores|invoices|webhooks)/[^/?]+#', '/$1/{id}', (string) wp_parse_url($url, PHP_URL_PATH));
        $message = 'Error during ' . $method . ' to ' . $path . '. Got response (' . $status . ')';
        parent::__construct($message, $status);
    }
}
