<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\MergeService;
use Modules\Camps\Service\PlaceArchiveService;
use Twig\Environment;

/**
 * Merging two rows that are one thing, and archiving a place.
 *
 * Every action here starts on a screen that says what will happen, and
 * none of them is reachable without a human pressing a button: a merge
 * cannot be undone in practice, and archiving takes a place off every
 * normal screen at once.
 */
class CampsMergeController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private PlaceRepository $places,
        private CampRepository $camps,
        private MergeService $mergeService,
        private PlaceArchiveService $archiveService
    ) {
    }

    // ── Places ──────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function choosePlaceMerge(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }

        $target = $this->places->findById((int) $request->getQuery('vers', 0));
        $preview = $target !== null ? $this->mergeService->placeMergePreview($place, $target) : null;

        return $this->render('@camps/place_merge.html.twig', [
            'place' => $place,
            'target' => $target,
            'preview' => $preview,
            'candidate_options' => $this->placeOptions($place, $target),
            'breadcrumb_current' => 'Fusionner ' . $place->name,
            'breadcrumb_trail' => [
                ['label' => $place->name, 'url' => '/chefs/camps/lieux/' . $place->id],
            ],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function mergePlace(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/lieux/' . $place->id)) !== null) {
            return $guard;
        }

        $target = $this->places->findById((int) $request->getBody('target_id', 0));
        if ($target === null) {
            FlashMessage::set('error', 'Choisissez le lieu dans lequel fusionner celui-ci.');

            return $this->redirect('/chefs/camps/lieux/' . $place->id . '/fusionner');
        }

        try {
            $moved = $this->mergeService->mergePlaces($place, $target, AuthSession::getUserAccountId());
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/chefs/camps/lieux/' . $place->id . '/fusionner');
        }

        FlashMessage::set('success', sprintf(
            '%d séjour%s repris. « %s » est archivé.',
            $moved,
            $moved > 1 ? 's' : '',
            $place->name
        ));

        return $this->redirect('/chefs/camps/lieux/' . $target->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function archivePlace(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/lieux/' . $place->id)) !== null) {
            return $guard;
        }

        try {
            $this->archiveService->archive($place, AuthSession::getUserAccountId(), new \DateTimeImmutable('today'));
            FlashMessage::set('success', 'Lieu archivé. Il reste consultable depuis les Archives.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/chefs/camps/lieux/' . $place->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function restorePlace(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/lieux/' . $place->id)) !== null) {
            return $guard;
        }

        $this->archiveService->restore($place, AuthSession::getUserAccountId());
        FlashMessage::set('success', 'Lieu restauré.');

        return $this->redirect('/chefs/camps/lieux/' . $place->id);
    }

    // ── Stays ───────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function chooseCampMerge(Request $request, array $params): Response
    {
        $camp = $this->camps->findById((int) ($params['id'] ?? 0));
        if ($camp === null) {
            return $this->notFound();
        }
        $place = $this->places->findById($camp->placeId);

        // Only stays of the SAME place are offered. Merging across places
        // is not supported, and a picker that offered it would be
        // teaching the wrong mental model before the service refuses.
        $siblings = array_values(array_filter(
            $this->camps->findByPlace($camp->placeId),
            static fn(Camp $c): bool => $c->id !== $camp->id
        ));

        return $this->render('@camps/camp_merge.html.twig', [
            'camp' => $camp,
            'camp_label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            'place' => $place,
            'siblings' => $siblings,
            'sibling_options' => array_map(
                static fn(Camp $c): array => [
                    'value' => (string) $c->id,
                    'label' => CampLabels::dateRange($c->startDate, $c->endDate, $c->yearOnly)
                        . ' — ' . CampLabels::status($c->status),
                    'selected' => false,
                ],
                $siblings
            ),
            'breadcrumb_current' => 'Fusionner ce séjour',
            'breadcrumb_trail' => [
                ['label' => $place !== null ? $place->name : 'Lieu', 'url' => '/chefs/camps/lieux/' . $camp->placeId],
            ],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function mergeCamp(Request $request, array $params): Response
    {
        $camp = $this->camps->findById((int) ($params['id'] ?? 0));
        if ($camp === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/sejours/' . $camp->id)) !== null) {
            return $guard;
        }

        $target = $this->camps->findById((int) $request->getBody('target_id', 0));
        if ($target === null) {
            FlashMessage::set('error', 'Choisissez le séjour dans lequel fusionner celui-ci.');

            return $this->redirect('/chefs/camps/sejours/' . $camp->id . '/fusionner');
        }

        try {
            $lost = $this->mergeService->mergeCamps(
                $camp,
                $target,
                AuthSession::getUserAccountId(),
                new \DateTimeImmutable('today')
            );
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/chefs/camps/sejours/' . $camp->id . '/fusionner');
        }

        FlashMessage::set('success', $lost === []
            ? 'Séjours fusionnés.'
            : 'Séjours fusionnés. Les valeurs remplacées ont été ajoutées à la note.');

        return $this->redirect('/chefs/camps/sejours/' . $target->id);
    }

    /**
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    private function placeOptions(Place $current, ?Place $target): array
    {
        $options = [];
        foreach ($this->places->findAllVisible() as $place) {
            if ($place->id === $current->id) {
                continue;
            }
            $locality = $place->locality();
            $options[] = [
                'value' => (string) $place->id,
                'label' => $place->name . ($locality !== '' ? ' — ' . $locality : ''),
                'selected' => $target !== null && $target->id === $place->id,
            ];
        }

        return $options;
    }
}
