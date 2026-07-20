<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider\Ovh;

/**
 * Low-level OVH REST API client: request signing per OVH's documented
 * scheme (X-Ovh-Application/-Consumer/-Timestamp headers + X-Ovh-Signature:
 * "$1$" + sha1(AS+"+"+CK+"+"+method+"+"+url+"+"+body+"+"+timestamp)) and
 * dispatch. All network I/O goes through the injectable $transport closure
 * so tests never touch the network — production code leaves it null, which
 * falls back to the real curlTransport().
 */
class OvhApiClient
{
    public const DEFAULT_ENDPOINT = 'https://eu.api.ovh.com/1.0';

    private ?int $timeDelta = null;

    /**
     * @param (\Closure(string, string, array<string, string>, ?string): array{status: int, body: string})|null $transport
     */
    public function __construct(
        private string $applicationKey,
        private string $applicationSecret,
        private ?string $consumerKey = null,
        private string $endpoint = self::DEFAULT_ENDPOINT,
        private ?\Closure $transport = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->call('GET', $path, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        return $this->call('PUT', $path, $body);
    }

    /**
     * Unauthenticated (application key/secret only — no consumer key yet):
     * this IS how a consumer key is obtained (module spec §1.2 étape 2).
     *
     * @param array<int, array{method: string, path: string}> $accessRules
     * @return array{consumerKey: string, validationUrl: string}
     */
    public function requestConsumerKey(array $accessRules, string $redirection = ''): array
    {
        $body = json_encode(['accessRules' => $accessRules, 'redirection' => $redirection]);
        \assert($body !== false);
        $url = $this->endpoint . '/auth/credential';

        $response = $this->dispatch('POST', $url, [
            'Content-Type' => 'application/json',
            'X-Ovh-Application' => $this->applicationKey,
        ], $body);

        $decoded = $this->decodeJsonResponse($response);

        return [
            'consumerKey' => (string) ($decoded['consumerKey'] ?? ''),
            'validationUrl' => (string) ($decoded['validationUrl'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $bodyArray
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $bodyArray): array
    {
        if ($this->consumerKey === null || $this->consumerKey === '') {
            throw new OvhApiException('Consumer Key manquante ou non validée.');
        }

        $body = $bodyArray !== null ? json_encode($bodyArray) : '';
        \assert($body !== false);
        $url = $this->endpoint . $path;
        $timestamp = (string) (time() + $this->getTimeDelta());

        $signature = '$1$' . sha1(implode('+', [
            $this->applicationSecret,
            $this->consumerKey,
            $method,
            $url,
            $body,
            $timestamp,
        ]));

        $headers = [
            'Content-Type' => 'application/json',
            'X-Ovh-Application' => $this->applicationKey,
            'X-Ovh-Consumer' => $this->consumerKey,
            'X-Ovh-Timestamp' => $timestamp,
            'X-Ovh-Signature' => $signature,
        ];

        $response = $this->dispatch($method, $url, $headers, $bodyArray !== null ? $body : null);

        return $this->decodeJsonResponse($response);
    }

    /**
     * Corrects for server/client clock drift — OVH rejects a signature
     * whose timestamp is more than a few minutes off. Computed once per
     * client instance (a fresh instance is built per request) and reused
     * for every subsequent authenticated call.
     */
    private function getTimeDelta(): int
    {
        if ($this->timeDelta !== null) {
            return $this->timeDelta;
        }

        $response = $this->dispatch('GET', $this->endpoint . '/auth/time', [], null);
        $serverTime = (int) trim($response['body']);
        $this->timeDelta = $serverTime > 0 ? $serverTime - time() : 0;

        return $this->timeDelta;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function dispatch(string $method, string $url, array $headers, ?string $body): array
    {
        $transport = $this->transport ?? self::defaultTransport();
        return $transport($method, $url, $headers, $body);
    }

    /**
     * @return \Closure(string, string, array<string, string>, ?string): array{status: int, body: string}
     */
    public static function defaultTransport(): \Closure
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new OvhApiException("Impossible d'initialiser la requête réseau.");
            }

            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = "{$name}: {$value}";
            }

            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $options);

            $responseBody = curl_exec($ch);
            if ($responseBody === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new OvhApiException("Erreur réseau lors de l'appel à l'API OVH : {$error}");
            }
            \assert(is_string($responseBody));

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['status' => $status, 'body' => $responseBody];
        };
    }

    /**
     * @param array{status: int, body: string} $response
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $decoded = $response['body'] !== '' ? json_decode($response['body'], true) : null;

        if ($response['status'] >= 400) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : "Erreur OVH (HTTP {$response['status']}).";
            throw new OvhApiException($message);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
