<?php

declare(strict_types=1);

namespace Core\Http;

class Response
{
    private string $cspNonce = '';
    private ?bool $forceHttps = null;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $statusCode = 200,
        private array $headers = []
    ) {
    }

    public function setCspNonce(string $nonce): self
    {
        $this->cspNonce = $nonce;
        return $this;
    }

    public function setHttps(bool $isHttps): self
    {
        $this->forceHttps = $isHttps;
        return $this;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    private function buildCsp(): string
    {
        $scriptSrc = $this->cspNonce !== ''
            ? "script-src 'self' 'nonce-{$this->cspNonce}'"
            : "script-src 'self'";

        return "default-src 'self'; {$scriptSrc}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    }

    /**
     * @return array<string, string>
     */
    public function getSecurityHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => $this->buildCsp(),
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        $isHttps = $this->forceHttps ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->getSecurityHeaders() as $name => $value) {
            header("{$name}: {$value}");
        }

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
