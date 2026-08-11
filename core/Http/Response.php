<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

class Response
{
    private string $cspNonce = '';
    private ?bool $forceHttps = null;
    /** @var string[] */
    private array $extraImgSrc = [];

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

    /**
     * Allows an additional origin in the img-src directive — e.g. the
     * gallery module's currently-configured S3-compatible origin, so
     * photos actually load regardless of which provider is configured
     * (Modules\Gallery\Service\Storage\S3StorageBackend::servingOrigin()).
     */
    public function addImgSrcOrigin(string $origin): self
    {
        if ($origin !== '' && !in_array($origin, $this->extraImgSrc, true)) {
            $this->extraImgSrc[] = $origin;
        }
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

        $imgSrc = implode(' ', array_merge(["'self'", 'data:', 'blob:'], $this->extraImgSrc));

        return "default-src 'self'; {$scriptSrc}; style-src 'self' 'unsafe-inline'; img-src {$imgSrc}; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
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
