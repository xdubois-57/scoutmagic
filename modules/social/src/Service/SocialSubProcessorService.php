<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Module\SubProcessorProvider;
use Core\Module\SubProcessorView;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;

/**
 * Meta, for the RGPD page — declared while, and only while, an account is
 * connected. An enabled module with nothing connected sends nothing to
 * anyone, and a page claiming otherwise would be as wrong as one that
 * forgot Meta once it is.
 */
final class SocialSubProcessorService implements SubProcessorProvider
{
    public function __construct(private readonly ConnectionRepository $connections)
    {
    }

    public function getSubProcessors(): array
    {
        $connected = [];
        foreach (SocialPlatform::cases() as $platform) {
            if ($this->connections->find($platform)?->isConnected() === true) {
                $connected[] = $platform->label();
            }
        }

        if ($connected === []) {
            return [];
        }

        return [new SubProcessorView(
            SubProcessorView::CATEGORY_SOCIAL_PUBLISHING,
            'Meta Platforms Ireland Limited (Irlande, UE ; transferts possibles vers Meta Platforms, Inc., États-Unis)',
            "Publication, sur les comptes de l'unité, des images et des textes qu'un administrateur choisit d'y partager",
            'Comptes raccordés : ' . implode(' et ', $connected)
        )];
    }
}
