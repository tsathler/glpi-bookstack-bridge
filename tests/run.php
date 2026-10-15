<?php

declare(strict_types=1);

require __DIR__ . '/../src/Api/BookStackException.php';
require __DIR__ . '/../src/Security/BaseUrlPolicy.php';
require __DIR__ . '/../src/Api/BookStackClient.php';

use GlpiPlugin\Bookstackbridge\Api\BookStackClient;
use GlpiPlugin\Bookstackbridge\Api\BookStackException;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$client = new BookStackClient('https://1.1.1.1', 'id', 'secret', static function (string $url, array $headers): array {
    if (!str_contains($url, '/api/search?query=network+setup&count=20&page=2')) {
        throw new RuntimeException('Search must use BookStack search paging parameters.');
    }
    if (!in_array('Authorization: Token id:secret', $headers, true)) {
        throw new RuntimeException('BookStack token header is missing.');
    }
    return ['status' => 200, 'body' => json_encode(['data' => [['name' => 'Guide']], 'total' => 12])];
});
$result = $client->search('network setup', 2);
$assert($result['total'] === 12 && $result['data'][0]['name'] === 'Guide', 'Parses a valid search response.');

try {
        new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => 401, 'body' => '{}'])->search('x');
    $assert(false, '401 should fail.');
} catch (BookStackException $e) {
    $assert($e->statusCode === 401 && str_contains($e->getMessage(), 'token'), 'Maps authentication failures.');
}

foreach ([403, 429, 500] as $status) {
    try {
        (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => $status, 'body' => '{}']))->search('x');
        $assert(false, "HTTP {$status} should fail.");
    } catch (BookStackException $e) {
        $assert($e->statusCode === $status && $e->getMessage() !== '', "Maps HTTP {$status} failures.");
    }
}

try {
    (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => 200, 'body' => '<html>']))->search('x');
    $assert(false, 'Invalid JSON should fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'JSON'), 'Handles malformed JSON.');
}

try {
    (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => ['status' => 200, 'body' => '{"unexpected":true}']))->search('x');
    $assert(false, 'Unexpected response format should fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'format'), 'Handles unexpected response shapes.');
}

try {
    $client->search('');
    $assert(false, 'Empty query should fail.');
} catch (BookStackException) {
    $assert(true, 'Rejects empty search queries.');
}

try {
    (new BookStackClient('https://1.1.1.1', 'id', 'secret', static fn() => throw new RuntimeException('transport failure')))->search('x');
    $assert(false, 'Transport errors should fail.');
} catch (BookStackException $e) {
    $assert(str_contains($e->getMessage(), 'connect'), 'Maps transport failures without leaking details.');
}

try {
    new BookStackClient('http://docs.example.org', 'id', 'secret');
    $assert(false, 'HTTP should fail.');
} catch (BookStackException) {
    $assert(true, 'Rejects non-HTTPS URLs.');
}

try {
    BaseUrlPolicy::validate('https://127.0.0.1');
    $assert(false, 'Private hosts should fail.');
} catch (BookStackException) {
    $assert(true, 'Rejects private IP addresses.');
}

echo "OK ({$assertions} assertions)\n";
