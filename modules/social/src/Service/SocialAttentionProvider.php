<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\AppClock;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;

/**
 * A Facebook or Instagram connection that no longer works (issue #718 § C
 * point 3), one point per platform.
 *
 * Until now the site said so in one line of the module's journal
 * (ConnectionService::check()) and nowhere else, while sharing quietly
 * stopped. Meta refuses to renew an expired token: a person has to go back
 * through its consent screen, which is what the point asks for.
 *
 * Read from what the nightly check stored — the token's end date and the
 * last verdict — never by calling Meta: the attention page must stay
 * cheap, and a page that asked Meta on every display would also fail
 * whenever Meta does. A check that could not reach Meta records nothing
 * (CheckOutcome::Unreachable), so a passing outage never raises a point.
 */
class SocialAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private ConnectionRepository $connections,
        /** Tests only: the moment to read expiry against. */
        private ?\DateTimeImmutable $now = null
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Réseaux sociaux';
    }

    public function collect(int $scoutYearId): array
    {
        $now = $this->now ?? AppClock::now();

        $points = [];
        foreach (SocialPlatform::cases() as $platform) {
            $connection = $this->connections->find($platform);
            if ($connection === null || !$connection->isConnected()) {
                continue;
            }

            if ($connection->isExpired($now)) {
                $points[] = $this->point(
                    $platform,
                    "L'autorisation " . self::of($platform) . ' a expiré',
                    'Meta refuse de renouveler une autorisation expirée : les publications ' . self::to($platform)
                        . ' échouent tant que quelqu\'un ne refait pas la connexion.'
                );
            } elseif ($connection->checkOk === false) {
                $points[] = $this->point(
                    $platform,
                    'Meta refuse la connexion ' . self::of($platform),
                    'La dernière vérification a été refusée : les publications ' . self::to($platform)
                        . ' échouent. Refaites la connexion, ou vérifiez les droits accordés à l\'application.'
                );
            }
        }

        return $points;
    }

    private function point(SocialPlatform $platform, string $title, string $why): AttentionPoint
    {
        return new AttentionPoint(
            title: $title,
            why: $why,
            actionLabel: $platform === SocialPlatform::Facebook ? 'Reconnecter la Page Facebook' : 'Reconnecter Instagram',
            actionUrl: '/config/reseaux-sociaux',
            severity: AttentionPoint::SEVERITY_ATTENTION
        );
    }

    /** « de la Page Facebook », « d'Instagram ». */
    private static function of(SocialPlatform $platform): string
    {
        return match ($platform) {
            SocialPlatform::Facebook => 'de la Page Facebook',
            SocialPlatform::Instagram => "d'Instagram",
        };
    }

    /** « vers la Page Facebook », « vers Instagram ». */
    private static function to(SocialPlatform $platform): string
    {
        return match ($platform) {
            SocialPlatform::Facebook => 'vers la Page Facebook',
            SocialPlatform::Instagram => 'vers Instagram',
        };
    }
}
