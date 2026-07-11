<?php

declare(strict_types=1);

namespace NowPayments;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for NOWPayments API.
 */
class HttpClient
{
    private const PRODUCTION_URL = 'https://api.nowpayments.io';
    private const SANDBOX_URL = 'https://api-sandbox.nowpayments.io';

    private Client $client;
    private array $config;

    public function __construct(array $config)
    {
        $apiKey = $config['apiKey'] ?? '';
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'NOWPayments API key is required. Get yours at https://account.nowpayments.io'
            );
        }

        $this->config = $config;
        $baseUrl = $config['baseUrl'] ?? ($config['sandbox'] ?? false ? self::SANDBOX_URL : self::PRODUCTION_URL);
        $timeout = $config['timeout'] ?? 30;
        // If timeout > 100, assume milliseconds (Node SDK uses 30000 ms)
        if ($timeout > 100) {
            $timeout = (int) ($timeout / 1000);
        }

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => trim((string) $config['apiKey']),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $params Query parameters
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = [], ?string $jwtToken = null): array
    {
        $options = ['query' => $params];
        $options = $this->withJwt($options, $jwtToken);
        return $this->request('GET', $path, $options);
    }

    /**
     * @param mixed $body Request body (will be JSON encoded). Pass null for empty-body POST.
     * @param array<string, string> $extraHeaders Extra request headers (e.g. origin-ip)
     * @return array<string, mixed>
     */
    public function post(string $path, $body = null, ?string $jwtToken = null, array $extraHeaders = []): array
    {
        $options = [];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($extraHeaders !== []) {
            $options['headers'] = $extraHeaders;
        }
        $options = $this->withJwt($options, $jwtToken);
        return $this->request('POST', $path, $options);
    }

    /**
     * @param mixed $body Request body (will be JSON encoded)
     * @return array<string, mixed>
     */
    public function patch(string $path, $body = null, ?string $jwtToken = null): array
    {
        $options = ['json' => $body];
        $options = $this->withJwt($options, $jwtToken);
        return $this->request('PATCH', $path, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path, ?string $jwtToken = null): array
    {
        $options = $this->withJwt([], $jwtToken);
        return $this->request('DELETE', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->client->request($method, $path, $options);
            return $this->decodeResponse($response);
        } catch (RequestException $e) {
            throw $this->handleRequestException($e);
        } catch (GuzzleException $e) {
            $msg = strpos($e->getMessage(), 'timed out') !== false
                ? 'Request timed out. Check your connection or try again.'
                : ($e->getMessage() ?: 'Network error. Check your connection.');
            throw new NowPaymentsError($msg, null, null, $e);
        }
    }

    /**
     * Add Authorization header for JWT.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function withJwt(array $options, ?string $jwtToken): array
    {
        if ($jwtToken !== null && trim($jwtToken) !== '') {
            $options['headers'] = array_merge($options['headers'] ?? [], [
                'Authorization' => 'Bearer ' . trim($jwtToken),
            ]);
        }
        return $options;
    }

    private function decodeResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                return $decoded;
            }
            // JSON scalar (e.g. "OK") — normalize for typed array returns
            return ['result' => $decoded];
        }
        // Plain-text success bodies (e.g. verify payout returns OK)
        return ['result' => trim($body)];
    }

    private function handleRequestException(RequestException $e): NowPaymentsError
    {
        $response = $e->getResponse();
        $statusCode = $response ? $response->getStatusCode() : null;
        $body = $response ? (string) $response->getBody() : '';
        $data = json_decode($body, true);

        $message = $this->extractErrorMessage($data, $e->getMessage());
        $code = is_array($data) && isset($data['code']) ? (string) $data['code'] : null;

        return new NowPaymentsError($message, $statusCode, $code, $data ?? $body);
    }

    /**
     * @param mixed $data
     */
    private function extractErrorMessage($data, string $fallback): string
    {
        if (!is_array($data)) {
            return $fallback ?: 'Request failed';
        }
        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        if (isset($data['msg']) && is_string($data['msg'])) {
            return $data['msg'];
        }
        if (isset($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }
        return $fallback ?: 'Request failed';
    }
}
