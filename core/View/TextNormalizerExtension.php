<?php

declare(strict_types=1);

namespace Core\View;

use Core\Service\TextNormalizerService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes TextNormalizerService as display-only Twig filters:
 * |normalize_name, |normalize_totem, |normalize_phone, |normalize_address.
 */
class TextNormalizerExtension extends AbstractExtension
{
    /**
     * @return array<int, TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('normalize_name', static fn(?string $v): string => TextNormalizerService::normalizeName((string) $v)),
            new TwigFilter('normalize_totem', static fn(?string $v): string => TextNormalizerService::normalizeTotem((string) $v)),
            new TwigFilter('normalize_phone', static fn(?string $v): string => TextNormalizerService::normalizePhone((string) $v)),
            new TwigFilter('normalize_address', static fn(?string $v): string => TextNormalizerService::normalizeAddress((string) $v)),
        ];
    }
}
