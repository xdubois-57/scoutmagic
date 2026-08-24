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
use Modules\Camps\Repository\Contact;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\ContactService;
use Modules\Camps\Service\DocumentService;
use Modules\Camps\Service\LinkService;
use Modules\Camps\Service\ReviewService;
use Twig\Environment;

/**
 * Everything attached to a stay: its contacts, its links, its documents,
 * its photos — and the erasure of a contact.
 *
 * Split from Controller\CampsChiefController because those three screens
 * are one story (a stay and what is known about it) and these are five
 * different kinds of attachment with five different lifecycles; one
 * controller holding both would be the module's largest file by twice
 * over.
 */
class CampsAttachmentController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private CampRepository $camps,
        private PlaceRepository $places,
        private ContactRepository $contacts,
        private LinkRepository $links,
        private DocumentRepository $documents,
        private ContactService $contactService,
        private LinkService $linkService,
        private DocumentService $documentService,
        private CampAlbumService $albumService,
        private ReviewService $reviewService,
        private ReviewRepository $reviews
    ) {
    }

    // ── Contacts ────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function storeContact(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->campUrl($camp))) !== null) {
            return $guard;
        }

        try {
            $this->contactService->create($camp->id, $this->contactFields($request), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Contact ajouté.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->campUrl($camp));
    }

    /**
     * @param array<string, string> $params
     */
    public function updateContact(Request $request, array $params): Response
    {
        $contact = $this->contacts->findById((int) ($params['id'] ?? 0));
        if ($contact === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/sejours/' . $contact->campId)) !== null) {
            return $guard;
        }

        try {
            $this->contactService->update($contact, $this->contactFields($request), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Contact mis à jour.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/chefs/camps/sejours/' . $contact->campId);
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteContact(Request $request, array $params): Response
    {
        $contact = $this->contacts->findById((int) ($params['id'] ?? 0));
        if ($contact === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/sejours/' . $contact->campId)) !== null) {
            return $guard;
        }

        $this->contactService->delete($contact, AuthSession::getUserAccountId());
        FlashMessage::set('success', 'Contact supprimé.');

        return $this->redirect('/chefs/camps/sejours/' . $contact->campId);
    }

    /**
     * The screen that says what erasure will touch BEFORE it happens.
     *
     * Anonymisation is irreversible and reaches past the stay the chief
     * is looking at — every contact row sharing that e-mail, anywhere in
     * the module, and the values those rows left in each affected stay's
     * history. Showing the count afterwards would be showing it too late.
     *
     * @param array<string, string> $params
     */
    public function confirmAnonymise(Request $request, array $params): Response
    {
        $contact = $this->contacts->findById((int) ($params['id'] ?? 0));
        if ($contact === null) {
            return $this->notFound();
        }

        $scope = $this->contactService->anonymisationScope($contact);
        $camps = [];
        foreach ($scope['camp_ids'] as $campId) {
            $camp = $this->camps->findById($campId);
            if ($camp === null) {
                continue;
            }
            $place = $this->places->findById($camp->placeId);
            $camps[] = [
                'camp' => $camp,
                'label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
                'place_name' => $place !== null ? $place->name : 'Lieu inconnu',
            ];
        }

        return $this->render('@camps/contact_anonymise.html.twig', [
            'contact' => $contact,
            'camps' => $camps,
            'contact_count' => count($scope['contacts']),
            'has_email' => $contact->email !== null,
            'breadcrumb_current' => 'Anonymiser un contact',
            'breadcrumb_trail' => [
                ['label' => 'Camps', 'url' => '/chefs/camps'],
                ['label' => 'Séjour', 'url' => '/chefs/camps/sejours/' . $contact->campId],
            ],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function anonymise(Request $request, array $params): Response
    {
        $contact = $this->contacts->findById((int) ($params['id'] ?? 0));
        if ($contact === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/sejours/' . $contact->campId)) !== null) {
            return $guard;
        }

        $result = $this->contactService->anonymise($contact, AuthSession::getUserAccountId());
        FlashMessage::set('success', sprintf(
            'Contact anonymisé : %d fiche%s de contact et l\'historique de %d séjour%s.',
            $result['contacts'],
            $result['contacts'] > 1 ? 's' : '',
            $result['camps'],
            $result['camps'] > 1 ? 's' : ''
        ));

        return $this->redirect('/chefs/camps/sejours/' . $contact->campId);
    }

    // ── Links ───────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function storeLink(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->campUrl($camp))) !== null) {
            return $guard;
        }

        try {
            $this->linkService->attach($camp->id, (string) $request->getBody('url', ''), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Lien ajouté.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->campUrl($camp));
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteLink(Request $request, array $params): Response
    {
        $link = $this->links->findById((int) ($params['id'] ?? 0));
        if ($link === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/sejours/' . $link->campId)) !== null) {
            return $guard;
        }

        $this->linkService->detach($link, AuthSession::getUserAccountId());
        FlashMessage::set('success', 'Lien retiré.');

        return $this->redirect('/chefs/camps/sejours/' . $link->campId);
    }

    // ── Documents ───────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function documents(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        $place = $this->places->findById($camp->placeId);

        return $this->render('@camps/documents.html.twig', [
            'camp' => $camp,
            'camp_label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            'place' => $place,
            'documents' => $this->documents->findByCamp($camp->id),
            'breadcrumb_current' => 'Documents',
            'breadcrumb_trail' => $this->trail($camp, $place?->name),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function storeDocument(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        $target = $this->campUrl($camp) . '/documents';
        if (($guard = $this->guardCsrf($request, $target)) !== null) {
            return $guard;
        }

        $file = $request->getFile('document');
        if ($file === null) {
            FlashMessage::set('error', 'Choisissez un fichier à joindre.');

            return $this->redirect($target);
        }

        try {
            $this->documentService->upload(
                $camp->id,
                $file,
                (string) $request->getBody('title', ''),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Document ajouté.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($target);
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteDocument(Request $request, array $params): Response
    {
        $document = $this->documents->findById((int) ($params['id'] ?? 0));
        if ($document === null) {
            return $this->notFound();
        }
        $target = '/chefs/camps/sejours/' . $document->campId . '/documents';
        if (($guard = $this->guardCsrf($request, $target)) !== null) {
            return $guard;
        }

        $this->documentService->delete($document, AuthSession::getUserAccountId());
        FlashMessage::set('success', 'Document supprimé.');

        return $this->redirect($target);
    }

    // ── Reviews ─────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function saveReview(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->campUrl($camp))) !== null) {
            return $guard;
        }

        try {
            $this->reviewService->save(
                $camp,
                [
                    'rating' => (string) $request->getBody('rating', ''),
                    'comment' => (string) $request->getBody('comment', ''),
                ],
                $this->reviews->memberIdForMemberYears(AuthSession::getLinkedMembers()),
                AuthSession::getUserAccountId(),
                new \DateTimeImmutable('today')
            );
            FlashMessage::set('success', 'Avis enregistré. Merci pour les staffs suivants.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->campUrl($camp));
    }

    /**
     * "Retirer l'avis" — the way back out of an opinion that was written
     * about the wrong stay, or that the staff no longer stands behind.
     *
     * A stay is never deleted and a review is never one person's, so the
     * only correction available otherwise was overwriting it with another
     * opinion — which is not the same thing as having none.
     *
     * @param array<string, string> $params
     */
    public function deleteReview(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->campUrl($camp))) !== null) {
            return $guard;
        }

        $this->reviewService->delete($camp, AuthSession::getUserAccountId());
        FlashMessage::set('success', 'Avis retiré.');

        return $this->redirect($this->campUrl($camp));
    }

    // ── Photos ──────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function photos(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        $place = $this->places->findById($camp->placeId);
        $albumId = $this->albumId($camp, $place?->name);

        return $this->render('@camps/photos.html.twig', [
            'camp' => $camp,
            'camp_label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            'place' => $place,
            'album_available' => $this->albumService->isAvailable() && $albumId !== null,
            'media' => $this->albumService->listMedia($albumId),
            'breadcrumb_current' => 'Photos',
            'breadcrumb_trail' => $this->trail($camp, $place?->name),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function storePhoto(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        $target = $this->campUrl($camp) . '/photos';
        if (($guard = $this->guardCsrf($request, $target)) !== null) {
            return $guard;
        }

        $place = $this->places->findById($camp->placeId);
        $albumId = $this->albumId($camp, $place?->name);
        $file = $request->getFile('photo');
        if ($albumId === null || $file === null) {
            FlashMessage::set('error', $albumId === null
                ? 'Les photos ne sont pas disponibles pour ce séjour.'
                : 'Choisissez une photo à envoyer.');

            return $this->redirect($target);
        }

        try {
            $this->albumService->addPhoto($camp, $albumId, $file, AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Photo ajoutée.');
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($target);
    }

    /**
     * @param array<string, string> $params
     */
    public function deletePhoto(Request $request, array $params): Response
    {
        [$camp, $error] = $this->requireCamp($params);
        if ($camp === null) {
            return $error;
        }
        $target = $this->campUrl($camp) . '/photos';
        if (($guard = $this->guardCsrf($request, $target)) !== null) {
            return $guard;
        }

        $place = $this->places->findById($camp->placeId);
        $albumId = $this->albumId($camp, $place?->name);
        $mediaId = (int) $request->getBody('media_id', 0);
        if ($albumId !== null && $mediaId > 0) {
            try {
                $this->albumService->deletePhoto($camp, $albumId, $mediaId, AuthSession::getUserAccountId());
                FlashMessage::set('success', 'Photo supprimée.');
            } catch (CampsException $e) {
                FlashMessage::set('error', $e->getMessage());
            }
        }

        return $this->redirect($target);
    }

    // ── Shared ──────────────────────────────────────────────────────

    private function albumId(Camp $camp, ?string $placeName): ?int
    {
        $label = CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly);

        return $this->albumService->albumIdFor(
            $camp,
            trim(($placeName ?? 'Camp') . ' — ' . $label),
            AuthSession::getUserAccountId() ?? 0
        );
    }

    /**
     * @param array<string, string> $params
     * @return array{0: ?Camp, 1: Response}
     */
    private function requireCamp(array $params): array
    {
        $camp = $this->camps->findById((int) ($params['id'] ?? 0));

        return $camp !== null ? [$camp, new Response('')] : [null, $this->notFound()];
    }

    private function campUrl(Camp $camp): string
    {
        return '/chefs/camps/sejours/' . $camp->id;
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    private function trail(Camp $camp, ?string $placeName): array
    {
        $trail = [['label' => 'Camps', 'url' => '/chefs/camps']];
        if ($placeName !== null) {
            $trail[] = ['label' => $placeName, 'url' => '/chefs/camps/lieux/' . $camp->placeId];
        }
        $trail[] = [
            'label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            'url' => $this->campUrl($camp),
        ];

        return $trail;
    }

    /**
     * @return array<string, string|null>
     */
    private function contactFields(Request $request): array
    {
        return [
            'name' => (string) $request->getBody('name', ''),
            'role_label' => (string) $request->getBody('role_label', ''),
            'email' => (string) $request->getBody('email', ''),
            'phone' => (string) $request->getBody('phone', ''),
            'other_details' => (string) $request->getBody('other_details', ''),
        ];
    }

    private function notFound(): Response
    {
        return (new Response('', 404))->setBody('Not Found');
    }
}
