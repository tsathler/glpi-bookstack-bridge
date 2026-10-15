<?php

namespace GlpiPlugin\Bookstackbridge\Api;

use Closure;
use GlpiPlugin\Bookstackbridge\Security\BaseUrlPolicy;
use JsonException;

final class BookStackClient
{
    /** @var Closure(string, array): array{status:int,body:string} */
    private Closure $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
        ?callable $transport = null
    ) {
        BaseUrlPolicy::validate($baseUrl);
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
        return $this->get($path);
    }

    public function testConnection(): array
    {
        return $this->get('/api/shelves?' . http_build_query(['count' => 1]));
    }

    private function get(string $path): array
    {
        try {
            $response = ($this->transport)($this->baseUrl . $path, [
                'Authorization: Token ' . $this->tokenId . ':' . $this->tokenSecret,
                'Accept: application/json',
            ]);
        } catch (BookStackException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BookStackException('Could not connect to BookStack. Check its URL and network availability.');
        }
        $status = $response['status'] ?? 0;
        if (!is_int($status) || $status < 200 || $status >= 300) {
            $messages = [401 => 'BookStack rejected the API token.', 403 => 'The BookStack account cannot access this content.', 429 => 'BookStack rate limit reached. Try again shortly.'];
            throw new BookStackException($messages[$status] ?? 'BookStack returned an unsuccessful response.', is_int($status) ? $status : null);
        }
        try {
            $payload = json_decode($response['body'] ?? '', true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BookStackException('BookStack returned an invalid JSON response.', $status);
        }
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new BookStackException('BookStack returned an unexpected response format.', $status);
        }
        return ['data' => $payload['data'], 'total' => max(0, (int) ($payload['total'] ?? count($payload['data'])))];
    }

    private function send(string $url, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new BookStackException('Could not initialize the HTTP client.');
        }
        $ok = curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (!$ok) {
            curl_close($curl);
            throw new BookStackException('Could not configure the HTTP client.');
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($body === false || $error !== 0) {
            throw new BookStackException('BookStack request failed or timed out.');
        }
        return ['status' => $status, 'body' => $body];
    }
}
