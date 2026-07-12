<?php

declare(strict_types=1);

namespace Core\Config;

class AppConfig
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(string $configPath)
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Configuration file not found: {$configPath}");
        }

        $this->config = require $configPath;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function isDebug(): bool
    {
        return (bool) ($this->config['debug'] ?? false);
    }
}
