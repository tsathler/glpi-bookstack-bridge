<?php

namespace GlpiPlugin\Bookstackbridge\Api;

use Closure;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;
use JsonException;

final class BookStackClient
{
    /** @var Closure(string, array, array): array{status:int,body:string} */
    private Closure $transport;
    /** @var array{url: string, host: string, port: int, ip: string, is_literal: bool} */
    private array $destination;

    public function __construct(
        string $baseUrl,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
        ?callable $transport = null,
        string|array $privateIpAllowlist = '',
        ?callable $resolver = null
    ) {
        $this->destination = BaseUrlPolicy::resolve($baseUrl, $privateIpAllowlist, $resolver);
        if ($tokenId === '' || $tokenSecret === '') {
            throw new BookStackException('BookStack API token is not configured.');
        }
        $this->transport = Closure::fromCallable($transport ?? [$this, 'send']);
    }

    /** @return array{data: array, total: int} */
    public function search(string $query, int $page = 1): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 800 || $page < 1 || $page > 10000) {
            throw new BookStackException('Enter a search term of 1–200 characters and a valid page.');
        }
        $path = '/api/search?' . http_build_query(['query' => $query, 'count' => 20, 'page' => $page]);
        return $this->getList($path);
    }

    public function testConnection(): array
    {
        return $this->getList('/api/shelves?' . http_build_query(['count' => 1]));
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function listShelves(int $page = 1): array
    {
        if ($page < 1 || $page > 10000) {
            throw new BookStackException('The requested shelf page is invalid.');
        }

        $count = 100;
        $offset = ($page - 1) * $count;
        return $this->getList('/api/shelves?' . http_build_query(['count' => $count, 'offset' => $offset]));
    }

    /** @return array<string, mixed> */
    public function readShelf(int $shelfId): array
    {
        $this->assertValidId($shelfId);
        return $this->getResource('/api/shelves/' . $shelfId, $shelfId);
    }

    /** @return array<string, mixed> */
    public function readBook(int $bookId): array
    {
        $this->assertValidId($bookId);
        return $this->getResource('/api/books/' . $bookId, $bookId);
    }

    private function assertValidId(int $id): void
    {
        if ($id < 1) {
            throw new BookStackException('The requested BookStack item is invalid.');
        }
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    private function getList(string $path): array
    {
        $payload = $this->requestJson($path);
        if (!isset($payload['data']) || !is_array($payload['data']) || !array_is_list($payload['data'])) {
            throw new BookStackException('BookStack returned an unexpected list response format.');
        }
        foreach ($payload['data'] as $item) {
            if (!is_array($item)) {
                throw new BookStackException('BookStack returned an unexpected list response format.');
            }
        }

        $total = $payload['total'] ?? count($payload['data']);
        if (!is_int($total) || $total < 0) {
            throw new BookStackException('BookStack returned an invalid list total.');
        }
        return ['data' => $payload['data'], 'total' => $total];
    }

    /** @return array<string, mixed> */
    private function getResource(string $path, int $expectedId): array
    {
        $payload = $this->requestJson($path);
        if (array_is_list($payload)) {
            throw new BookStackException('BookStack returned an unexpected item response format.');
        }
        $id = $payload['id'] ?? null;
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id !== $expectedId) {
            throw new BookStackException('BookStack returned an unexpected item response format.');
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    private function requestJson(string $path): array
    {
        try {
            $requestOptions = [
                'connect_timeout' => 5,
                'timeout' => 15,
                'follow_redirects' => false,
                'verify_peer' => true,
                'verify_host' => 2,
                'proxy' => false,
                'host' => $this->destination['host'],
                'port' => $this->destination['port'],
                'resolved_ip' => $this->destination['ip'],
                'resolve' => $this->destination['is_literal'] ? null : $this->curlResolveEntry(),
            ];
            $response = ($this->transport)($this->destination['url'] . $path, [
                'Authorization: Token ' . $this->tokenId . ':' . $this->tokenSecret,
                'Accept: application/json',
            ], $requestOptions);
        } catch (BookStackException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BookStackException('Could not connect to BookStack. Check its URL and network availability.');
        }
        $status = $response['status'] ?? 0;
        if (!is_int($status) || $status < 200 || $status >= 300) {
            if (is_int($status) && $status >= 300 && $status < 400) {
                throw new BookStackException('BookStack attempted an HTTP redirect. Redirects are disabled; verify the configured URL.', $status);
            }
            $messages = [
                401 => 'BookStack rejected the API token.',
                403 => 'The BookStack account cannot access this content.',
                404 => 'The requested BookStack item is unavailable or not visible to this account.',
                429 => 'BookStack rate limit reached. Try again shortly.',
            ];
            throw new BookStackException($messages[$status] ?? 'BookStack returned an unsuccessful response.', is_int($status) ? $status : null);
        }
        try {
            $payload = json_decode($response['body'] ?? '', true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BookStackException('BookStack returned an invalid JSON response.', $status);
        }
        if (!is_array($payload)) {
            throw new BookStackException('BookStack returned an unexpected response format.', $status);
        }
        return $payload;
    }

    private function send(string $url, array $headers, array $requestOptions): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new BookStackException('Could not initialize the HTTP client.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $requestOptions['connect_timeout'],
            CURLOPT_TIMEOUT => $requestOptions['timeout'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
        ];
        if (is_string($requestOptions['resolve'])) {
            $options[CURLOPT_RESOLVE] = [$requestOptions['resolve']];
        }
        $ok = curl_setopt_array($curl, $options);
        if (!$ok) {
            curl_close($curl);
            throw new BookStackException('Could not configure the HTTP client.');
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($body === false || $error !== 0) {
            if ($error === CURLE_OPERATION_TIMEDOUT) {
                throw new BookStackException('Timed out connecting to BookStack over HTTPS. Check routing and firewall access to TCP 443.');
            }
            if ($error === CURLE_COULDNT_RESOLVE_HOST) {
                throw new BookStackException('BookStack hostname could not be resolved. Check DNS configuration.');
            }
            if ($error === CURLE_COULDNT_CONNECT) {
                throw new BookStackException('Could not establish an HTTPS connection to BookStack. Check the service and firewall.');
            }
            if ($error === CURLE_PEER_FAILED_VERIFICATION || $error === CURLE_SSL_CACERT_BADFILE) {
                throw new BookStackException('BookStack TLS certificate validation failed. Check trust chain and certificate SAN.');
            }
            throw new BookStackException('BookStack HTTPS request failed. Check network and TLS configuration.');
        }
        return ['status' => $status, 'body' => $body];
    }

    private function curlResolveEntry(): string
    {
        $address = str_contains($this->destination['ip'], ':')
            ? '[' . $this->destination['ip'] . ']'
            : $this->destination['ip'];
        return $this->destination['host'] . ':' . $this->destination['port'] . ':' . $address;
    }
}
