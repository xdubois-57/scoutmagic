<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\DecryptionException;
use Core\Security\SsrfUrlValidator;
use Core\Storage\Location\Backend\ObjectStorageBackend;
use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Config\GoogleDriveGrantSummary;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Protection\StorageProtectionService;
use Core\Storage\Location\Protection\Task\RepatriateFromCopyHandler;
use Core\Storage\Location\Diagnostics\ObjectStorageErrorExplainer;
use Core\Storage\Location\Diagnostics\ObjectStorageTestFailure;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Volume\VolumeInventory;
use Twig\Environment;

/**
 * « Stockage » — where an administrator declares the destinations this
 * site writes to, and sees what is on them.
 *
 * **In the core, and superadmin, for the same reason the model is.** Backups
 * are core and cannot depend on a module (D1); the gallery was the first
 * consumer of storage locations, never their owner. Until IT-02 this
 * screen lived inside the gallery's own configuration page, which meant an
 * installation without a gallery had no way to declare a destination at
 * all, and an administrator looking for « where do my files go » had to
 * guess that the answer was filed under photographs.
 *
 * **Two sub-pages, and deliberately not three.** The dashboard answers
 * « is anything wrong, and how full is it »; the locations page answers
 * « what is declared, and let me change it ». There is no « Usages »
 * page — who uses what is read at the top of the dashboard and again on
 * each location's « Sert : … » line, each at the moment it is the question
 * being asked, and a third copy of it on a page of its own would be a
 * third thing to keep in agreement with the other two.
 *
 * **What this screen shows is consequences, never capabilities** (D3).
 * Nobody chooses a storage type on « lecture par plage d'octets ». The
 * capability enumeration stays in the code and produces the sentences;
 * the comparison table that turns it into a choosing aid is IT-07.
 */
class StorageConfigController extends AbstractController
{
    /** @var string[] */
    public const S3_PROVIDERS = ['hetzner', 'cloudflare_r2', 'scaleway', 'ovhcloud', 'custom'];

    /** Where every form on this controller comes back to. */
    private const LOCATIONS_URL = '/config/stockage/emplacements';

    public function __construct(
        protected Environment $twig,
        private StorageLocationRepository $storageLocationRepository,
        private StorageLocationService $storageLocationService,
        private StorageLocationConsumerRegistry $consumers,
        private VolumeInventory $volumes,
        private JournalService $journalService,
        private ObjectStorageErrorExplainer $s3ErrorExplainer,
        /**
         * The directory the web server actually serves. Needed to refuse
         * a location pointing inside it — see {@see normalizeLocalPath()}.
         */
        private string $publicPath,
        /**
         * Declaring a location's safety copy (IT-04).
         *
         * **Last, and optional, so that adding it moved no existing
         * argument.** A required parameter inserted in the middle of a
         * constructor is a silent re-binding of every positional call
         * site — which is caught here by a type error, and would not be
         * where two of the arguments happened to share a type.
         *
         * Null means the Protection block is not offered on the screen —
         * never that a protection stops being honoured, which is the
         * scheduled task's business and not this screen's.
         */
        private ?StorageProtectionService $protections = null,
        private ?SchedulerService $scheduler = null,
        /**
         * Only ever read for `base_url`, which is what composes the OAuth
         * redirect address a Google Drive location's card has to print
         * character for character. Trailing and optional for the reason
         * the two above are.
         */
        private ?\Core\Config\SettingService $settings = null
    ) {
    }

    /**
     * GET /config/stockage — what is wrong first, then the space, then the
     * destinations that are not on this server.
     *
     * The order is the mockup's and it is an order of urgency rather than
     * of category: a location in error is the only thing on this page that
     * is costing something right now, and it is stated with its
     * consequence — « les sauvegardes distantes ne partent plus » — rather
     * than as a red badge somebody has to interpret.
     *
     * @param array<string, string> $params
     */
    public function dashboard(Request $request, array $params): Response
    {
        $this->storageLocationService->ensureDefaultExists();
        $locations = $this->freshLocations();

        return $this->render('config/storage/dashboard.html.twig', [
            'locations' => $locations,
            'failing' => array_values(array_filter(
                $locations,
                static fn (StorageLocation $l): bool => $l->lastCheckOk === false
            )),
            // Who uses what, asked of the consumers rather than known here:
            // the storage page has no idea what a gallery is, and D4 keeps
            // it that way.
            'usages' => $this->usageRows($locations),
            // **A real filter since IT-04**, which is what the variable
            // was introduced for. Until a location could name another as
            // its copy this was every declared location — no archive
            // carries one (D10) — and now it is the ones that still have
            // no copy, or whose copy is paused. A paused relation counts
            // as unprotected on purpose: what an administrator needs from
            // this block is « what would I lose tonight », and a copy that
            // is not running protects exactly nothing.
            'unprotected' => $this->locationsWithoutAWorkingCopy($locations),
            'volumes' => $this->volumes->measure(),
            'remote_locations' => array_values(array_filter(
                $locations,
                static fn (StorageLocation $l): bool => $l->type !== StorageLocationType::Local
            )),
            'health_ttl_minutes' => (int) round(StorageLocationService::HEALTH_CHECK_TTL_SECONDS / 60),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /config/stockage/emplacements/{id}/protection — declare or
     * correct this location's safety copy.
     *
     * @param array<string, string> $params
     */
    public function saveProtection(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::LOCATIONS_URL)) !== null) {
            return $guard;
        }
        if ($this->protections === null) {
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationRepository->findById((int) ($params['id'] ?? 0));
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $destinationId = (int) $request->getBody('destination_location_id');
        $gracePeriodDays = (int) $request->getBody('grace_period_days');

        try {
            $this->protections->save(
                $location->id,
                $destinationId,
                $gracePeriodDays,
                (int) $request->getBody('cadence_hours'),
                $request->getBody('enabled') !== null
            );
        } catch (StorageLocationException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::LOCATIONS_URL);
        }

        $this->journalService->log(
            'storage',
            'storage_protection_declared',
            'security',
            "Copie de secours déclarée pour l'emplacement « {$location->label} »",
            [],
            (int) AuthSession::getUserAccountId()
        );

        // **What is true about the relation that was just accepted**, said
        // at the one moment it can be acted on. These are the arrangements
        // the service deliberately does not refuse — two remote locations,
        // whose every byte transits through this server; a grace period
        // shorter than the oldest restorable backup, which would let a
        // restore resurrect album rows whose files the copy has already
        // erased. Computed and then never shown, they protected nobody:
        // the screen's static hint says the same thing whatever was
        // submitted, and cannot name the number that makes it matter.
        $destination = $this->storageLocationRepository->findById($destinationId);
        $warnings = $destination === null
            ? []
            : $this->protections->warningsFor($location, $destination, $gracePeriodDays);

        $confirmation = "Copie de secours enregistrée pour « {$location->label} ».";
        if ($warnings !== []) {
            // One flash holds one message, so the confirmation and what
            // qualifies it travel together rather than one replacing the
            // other. A « warning » that did not also confirm would leave
            // an administrator unsure whether anything was saved.
            FlashMessage::set('warning', $confirmation . ' ' . implode(' ', $warnings));

            return $this->redirect(self::LOCATIONS_URL);
        }

        FlashMessage::set('success', $confirmation);

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * POST /config/stockage/emplacements/{id}/protection/suppression
     *
     * **The copy already written is not touched**, exactly as deleting a
     * location has never deleted its files. What stops is the relation.
     *
     * @param array<string, string> $params
     */
    public function deleteProtection(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::LOCATIONS_URL)) !== null) {
            return $guard;
        }
        if ($this->protections === null) {
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationRepository->findById((int) ($params['id'] ?? 0));
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $protection = $this->protections->forSource($location->id);
        if ($protection !== null) {
            $this->protections->delete($protection->id);
            $this->journalService->log(
                'storage',
                'storage_protection_removed',
                'security',
                "Copie de secours retirée pour l'emplacement « {$location->label} » — les fichiers déjà "
                    . 'copiés restent à la destination',
                [],
                (int) AuthSession::getUserAccountId()
            );
        }

        FlashMessage::set('success', "Copie de secours retirée pour « {$location->label} ».");

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * POST /config/stockage/emplacements/{id}/protection/rapatriement —
     * bring back, from the copy, what this location no longer has.
     *
     * **Scheduled, never done in the request.** It asks the source about
     * every file the copy holds, which on a bucket is one request each; a
     * page that did it inline would time out on any installation big
     * enough to need it.
     *
     * @param array<string, string> $params
     */
    public function repatriate(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::LOCATIONS_URL)) !== null) {
            return $guard;
        }
        if ($this->protections === null || $this->scheduler === null) {
            return new Response('Not Found', 404);
        }

        $location = $this->storageLocationRepository->findById((int) ($params['id'] ?? 0));
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $protection = $this->protections->forSource($location->id);
        if ($protection === null) {
            FlashMessage::set('error', "« {$location->label} » n'a pas de copie de secours à rapatrier.");

            return $this->redirect(self::LOCATIONS_URL);
        }

        $this->scheduler->scheduleAfter(
            'core',
            RepatriateFromCopyHandler::TASK_KEY,
            0,
            ['protection_id' => $protection->id],
            null,
            (int) AuthSession::getUserAccountId()
        );

        $this->journalService->log(
            'storage',
            'storage_repatriation_requested',
            'security',
            "Rapatriement demandé pour l'emplacement « {$location->label} »",
            [],
            (int) AuthSession::getUserAccountId()
        );

        FlashMessage::set(
            'success',
            "Rapatriement lancé pour « {$location->label} ». Les fichiers reviennent en arrière-plan ; "
                . 'le journal dira combien ont été remis en place.'
        );

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * GET /config/stockage/emplacements — one card per location.
     *
     * @param array<string, string> $params
     */
    public function locations(Request $request, array $params): Response
    {
        $this->storageLocationService->ensureDefaultExists();
        $locations = $this->freshLocations();

        return $this->render('config/storage/locations.html.twig', [
            'locations' => $locations,
            'location_usages' => $this->usagesByLocationId($locations),
            // Keyed by SOURCE, which is the card the block is rendered on.
            'protections' => $this->protectionsBySourceId(),
            // Every location may be somebody's destination, itself
            // included — the refusals are the service's to state, in
            // words, rather than a picker's to hide. A destination missing
            // from the list because the screen guessed it was invalid is a
            // destination nobody can find out why they cannot choose.
            'protection_offered' => $this->protections !== null,
            // A destination's label, by id — so a card can name where its
            // copy goes without the template walking the list itself.
            // A relation whose destination is gone renders « un
            // emplacement supprimé » rather than a blank, because a blank
            // reads as « nowhere » and this one means « somewhere that no
            // longer exists », which is a different thing to do about.
            'location_labels' => array_reduce(
                $locations,
                static function (array $carry, StorageLocation $location): array {
                    $carry[$location->id] = $location->label;

                    return $carry;
                },
                []
            ),
            'restorable_horizon_days' => $this->protections?->restorableHorizonInDays(),
            // ——— Google Drive (IT-05) ———
            // Read once per Drive location rather than once per render of
            // the card: decrypting a secret is cheap, doing it inside a
            // Twig loop is how it stops being.
            'drive_secrets' => $this->driveSecretsFor($locations),
            'drive_redirect_uri' => GoogleDriveConnectionController::redirectUriFor(
                (string) ($this->settings?->get('base_url') ?: '')
            ),
            'drive_testing_token_days' => GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * What the page may know of every Drive location's grant, by id —
     * never the credentials themselves ({@see driveGrantOf()}).
     *
     * @param list<StorageLocation> $locations
     * @return array<int, GoogleDriveGrantSummary>
     */
    private function driveSecretsFor(array $locations): array
    {
        $secrets = [];
        foreach ($locations as $location) {
            if ($location->type === StorageLocationType::GoogleDrive) {
                $secrets[$location->id] = $this->driveGrantOf($location);
            }
        }

        return $secrets;
    }

    /**
     * The locations nothing is copying tonight.
     *
     * @param list<StorageLocation> $locations
     * @return list<StorageLocation>
     */
    private function locationsWithoutAWorkingCopy(array $locations): array
    {
        $protections = $this->protectionsBySourceId();

        return array_values(array_filter(
            $locations,
            static function (StorageLocation $location) use ($protections): bool {
                $protection = $protections[$location->id] ?? null;
                if ($protection === null || !$protection->enabled) {
                    return true;
                }

                // **A declared relation is not a copy.** What this list
                // answers is « what would I lose tonight », and a
                // protection that has never completed a pass holds
                // nothing yet, while one whose last pass failed has
                // stopped holding more. Both look identical to an
                // administrator reading « Ces emplacements n'ont pas de
                // copie de secours qui tourne » — which is the point of
                // the line — and counting them as protected because a row
                // exists is how a copy that has been failing for a week
                // goes unnoticed. `last_error` is cleared by a completed
                // pass, so it means « the most recent outcome was a
                // failure », not « something once went wrong ».
                return $protection->lastCompletedPassAt === null || $protection->lastError !== null;
            }
        ));
    }

    /**
     * Every declared protection, keyed by the location it protects.
     *
     * @return array<int, \Core\Storage\Location\Protection\StorageProtection>
     */
    private function protectionsBySourceId(): array
    {
        if ($this->protections === null) {
            return [];
        }

        $bySource = [];
        foreach ($this->protections->all() as $protection) {
            $bySource[$protection->sourceLocationId] = $protection;
        }

        return $bySource;
    }

    /**
     * GET /config/stockage/emplacements/nouveau
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->render('config/storage/location_form.html.twig', $this->formContext(null));
    }

    /**
     * POST /config/stockage/emplacements
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            $context = $this->formContext(null);
            $context['submit_error'] = self::SESSION_EXPIRED_MESSAGE;

            return $this->render('config/storage/location_form.html.twig', $context)->setStatusCode(403);
        }

        // Through `tryFrom()`, which is the enum's own door and the only
        // validation the VARCHAR column has (see StorageLocationType).
        // The chain of ternaries this replaces had to grow a branch per
        // type, and the branch somebody forgets is a form that silently
        // creates a local folder instead of what was asked for.
        $type = StorageLocationType::tryFrom((string) $request->getBody('type', ''))
            ?? StorageLocationType::Local;
        $label = trim((string) $request->getBody('label', ''));

        try {
            if ($label === '') {
                throw new StorageLocationException("Le nom de l'emplacement est obligatoire.");
            }

            $id = $this->storageLocationService->create(
                $type,
                $label,
                $this->configFromRequest($type, $request, null),
                $this->secretFromRequest($type, $request, null)
            );
        } catch (StorageLocationException $e) {
            $context = $this->formContext(null);
            $context['submit_error'] = $e->getMessage();

            return $this->render('config/storage/location_form.html.twig', $context)->setStatusCode(422);
        }

        $location = $this->storageLocationRepository->findById($id);
        // **A Drive location is not tested on creation**, and the reason
        // is that it cannot be: the row exists before any account is
        // behind it, so a check here would record « échec » on a
        // destination nobody has had the chance to connect yet — and that
        // red line is the first thing the administrator would see on the
        // card they now have to use.
        if ($location !== null && $type !== StorageLocationType::GoogleDrive) {
            $this->storageLocationService->checkNow($location);
        }

        // `security`, not `info`: a storage location decides where this
        // unit's files leave to, and the transverse table of the chantier
        // puts every declaration of one at that level for exactly that
        // reason. The label is the administrator's own word for it and
        // carries no personal data; the path, the endpoint and the
        // credentials are deliberately absent.
        $this->journalService->log(
            'storage',
            'storage_location_created',
            'security',
            "Emplacement de stockage « {$label} » créé",
            ['type' => $type->value],
            (int) AuthSession::getUserAccountId()
        );

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * GET /config/stockage/emplacements/{id}/modification
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('config/storage/location_form.html.twig', $this->formContext($location));
    }

    /**
     * POST /config/stockage/emplacements/{id} — the type is immutable once
     * created: an album's location, and by extension the location's own
     * storage kind, never changes after the fact. Only the label and the
     * connection details are editable here.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $location = $this->storageLocationRepository->findById((int) ($params['id'] ?? 0));
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            $context = $this->formContext($location);
            $context['submit_error'] = self::SESSION_EXPIRED_MESSAGE;

            return $this->render('config/storage/location_form.html.twig', $context)->setStatusCode(403);
        }

        $label = trim((string) $request->getBody('label', ''));
        $secret = $this->secretFromRequest($location->type, $request, $location);
        $secretChanged = $secret !== null;

        try {
            if ($label === '') {
                throw new StorageLocationException("Le nom de l'emplacement est obligatoire.");
            }

            $config = $this->configFromRequest($location->type, $request, $location);
            $this->assertNoConsumerObjects($location, $config, $location->isDefault);

            $this->storageLocationService->update($location->id, $label, $config, $secret);
        } catch (StorageLocationException $e) {
            $context = $this->formContext($location);
            $context['submit_error'] = $e->getMessage();

            return $this->render('config/storage/location_form.html.twig', $context)->setStatusCode(422);
        }

        $refreshed = $this->storageLocationRepository->findById($location->id);
        if ($refreshed !== null && $location->type !== StorageLocationType::GoogleDrive) {
            // See store(): a Drive location with no account behind it
            // cannot be exercised, and recording a failure for that would
            // describe the setup rather than a fault.
            $this->storageLocationService->checkNow($refreshed);
        }

        $this->journalService->log(
            'storage',
            'storage_location_updated',
            'security',
            "Emplacement de stockage « {$label} » modifié",
            ['type' => $location->type->value],
            (int) AuthSession::getUserAccountId()
        );

        // A separate line, and the transverse table asks for it separately:
        // « les identifiants ont changé » is the event somebody auditing
        // this site looks for, and it is invisible inside « l'emplacement a
        // été modifié », which is also what a renamed label produces.
        if ($secretChanged) {
            $this->journalService->log(
                'storage',
                'storage_location_credentials_changed',
                'security',
                "Identifiants de l'emplacement de stockage « {$label} » modifiés",
                [],
                (int) AuthSession::getUserAccountId()
            );
        }

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * POST /config/stockage/emplacements/{id}/suppression — a form post,
     * not an AJAX call, and that is the point.
     *
     * This is the page's one destructive action, and AGENTS.md asks for a
     * destructive confirmation on a FORM. A button only a script can
     * actuate is a button that vanishes the day the script does not load —
     * and deleting a location has no second door, unlike « Tester » or
     * « Définir par défaut », whose absence costs a convenience rather than
     * the ability to repair the configuration at all. So the refusals land
     * as a flash message on the list rather than as a JSON envelope: the
     * commonest of them (« un usage en dépend ») is a sentence the
     * administrator has to read, not a toast.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::LOCATIONS_URL)) !== null) {
            return $guard;
        }

        $location = $this->storageLocationRepository->findById((int) ($params['id'] ?? 0));
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        try {
            $this->storageLocationService->delete($location->id);
        } catch (StorageLocationException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::LOCATIONS_URL);
        }

        $this->journalService->log(
            'storage',
            'storage_location_deleted',
            'security',
            "Emplacement de stockage « {$location->label} » supprimé",
            ['type' => $location->type->value],
            (int) AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', "Emplacement « {$location->label} » supprimé.");

        return $this->redirect(self::LOCATIONS_URL);
    }

    /**
     * POST /config/stockage/emplacements/{id}/defaut — promotes this
     * location to the sole default.
     *
     * @param array<string, string> $params
     */
    public function setDefault(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return $this->json(['success' => false, 'error' => 'Emplacement introuvable.'], 404);
        }

        try {
            // `true` because the question is about the location this is
            // ABOUT to make the default: a consumer's rows that pin nothing
            // are standing on whichever location holds that title, and the
            // promotion is how they arrive somewhere never checked for
            // them.
            $this->assertNoConsumerObjects($location, $location->config, true);
            $this->storageLocationService->setDefault($location->id);
        } catch (StorageLocationException $e) {
            // The row can vanish between the findById() above and this
            // promotion — deleted in another session, or this page reopened
            // after a deletion — and the repository refuses that rather
            // than demoting everything and promoting nobody. This endpoint
            // answers in JSON, so the refusal has to as well: an uncaught
            // exception is an HTML error page landing in a fetch() that
            // expects an object, which tells the administrator nothing.
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $this->journalService->log(
            'storage',
            'storage_location_default_changed',
            'security',
            "Emplacement de stockage « {$location->label} » défini par défaut",
            [],
            (int) AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/stockage/emplacements/{id}/test — forces an immediate,
     * non-cached health check and returns the fresh result.
     *
     * @param array<string, string> $params
     */
    public function test(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return $this->json(['success' => false, 'error' => 'Emplacement introuvable.'], 404);
        }

        $this->storageLocationService->checkNow($location);
        $refreshed = $this->storageLocationRepository->findById($location->id);

        // `info`, not `security`: running a test changes nothing, and the
        // line is here so that « it was already broken at 08:12 » can be
        // established afterwards. The recorded error is a French sentence
        // this application wrote, never the provider's own words — those
        // stay in the S3 branch below, which has a reader for them.
        $this->journalService->log(
            'storage',
            'storage_location_tested',
            'info',
            sprintf(
                "Test de l'emplacement de stockage « %s » : %s",
                $location->label,
                $refreshed?->lastCheckOk === true ? 'joignable' : 'en erreur'
            ),
            ['error' => $refreshed?->lastCheckError],
            (int) AuthSession::getUserAccountId()
        );

        return $this->json([
            'success' => true,
            'ok' => $refreshed?->lastCheckOk,
            'error' => $refreshed?->lastCheckError,
        ]);
    }

    /**
     * POST /config/stockage/test-connexion — builds a throwaway S3 client
     * from the submitted (not necessarily saved) form values, so an
     * administrator can verify credentials before committing them.
     *
     * @param array<string, string> $params
     */
    public function testConnection(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        // The edit form deliberately leaves the secret field blank
        // (« laisser vide pour conserver la clé actuelle »), so testing an
        // existing location used to send an empty secret and always fail on
        // authentication. When the caller names the location it is editing,
        // fall back on that location's stored secret.
        $secretKey = (string) ($data['secret_key'] ?? '');
        if ($secretKey === '') {
            $locationId = (int) ($data['location_id'] ?? 0);
            $location = $locationId > 0 ? $this->storageLocationRepository->findById($locationId) : null;
            if ($location !== null && $location->type === StorageLocationType::ObjectStorage) {
                // **The strict reader, and this is the line that decides
                // it.** Everything else on this screen merely DISPLAYS the
                // record and wants `getSecretForDisplay()`; this one hands
                // the secret to a backend that is about to talk to the
                // service. Degrading to « none » here would send an empty
                // secret and report « vos identifiants sont refusés » to
                // an administrator who never touched their credentials
                // and left the field blank on purpose — the wrong repair,
                // pointing away from the real one.
                try {
                    $secretKey = (string) $this->storageLocationRepository->getSecret($location->id);
                } catch (DecryptionException) {
                    return $this->json([
                        'success' => false,
                        'error' => 'La clé secrète enregistrée n\'est plus lisible sur ce serveur : '
                            . 'saisissez-la à nouveau dans le champ ci-dessus pour tester la connexion.',
                    ], 422);
                }
            }
        }

        // The endpoint is connected to server-side with the access key and
        // secret, so it must be a genuine public https host — never http://
        // (which would send the credentials in plaintext) and never an
        // internal address (SSRF), audit M6. A custom port is allowed since
        // S3-compatible providers vary; the host must still be public.
        $endpoint = (string) ($data['endpoint'] ?? '');
        if (!SsrfUrlValidator::isPublicHttpsUrl($endpoint, true)) {
            return $this->json(
                ['success' => false, 'error' => "L'adresse du service doit être une URL https publique."],
                422
            );
        }

        $backend = new ObjectStorageBackend(
            $endpoint,
            (string) ($data['region'] ?? ''),
            (string) ($data['bucket'] ?? ''),
            (string) ($data['access_key'] ?? ''),
            $secretKey
        );

        $error = $backend->testConnection();
        if ($error === null) {
            ObjectStorageTestFailure::forget();

            return $this->json(['success' => true]);
        }

        // $error is already a French sentence; the AWS SDK's own words are
        // on lastTechnicalError() and stay here — in the journal, and in the
        // session for explainS3Error(), the one reader that has any use for
        // them. They never reach the page.
        $summary = 'Connexion impossible : ' . $error;
        $this->journalService->log(
            'storage',
            's3_test_connection_failed',
            'info',
            'Échec du test de connexion à un stockage S3',
            ['bucket' => (string) ($data['bucket'] ?? ''), 'sdk_error' => $backend->lastTechnicalError()],
            (int) AuthSession::getUserAccountId()
        );
        ObjectStorageTestFailure::remember($summary, $backend->lastTechnicalError());

        return $this->json(['success' => false, 'error' => $summary], 422);
    }

    /**
     * POST /config/stockage/expliquer-erreur-s3 — asks the LLM connector to
     * diagnose a failed S3 test for the administrator, given only the
     * non-secret configuration fields, the secret key's LENGTH, and the
     * provider's own error message. Never receives or forwards the secret.
     *
     * @param array<string, string> $params
     */
    public function explainS3Error(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        // The failure comes from the session, never from the request body.
        // The browser only ever had the French summary — useless to
        // diagnose — and a string the browser supplies is a string that
        // goes into a model's prompt having been through a page the
        // administrator can edit.
        $failure = ObjectStorageTestFailure::read();
        if ($failure === null) {
            return $this->json([
                'success' => false,
                'error' => "Lancez d'abord un test de connexion : il n'y a rien à expliquer pour le moment.",
            ], 422);
        }

        try {
            $explanation = $this->s3ErrorExplainer->explain(
                (string) ($data['provider'] ?? 'custom'),
                (string) ($data['endpoint'] ?? ''),
                (string) ($data['region'] ?? ''),
                (string) ($data['bucket'] ?? ''),
                (string) ($data['access_key'] ?? ''),
                (int) ($data['secret_key_length'] ?? 0),
                $failure['summary'],
                $failure['technical']
            );
        } catch (StorageLocationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'explanation' => $explanation]);
    }

    /**
     * Every location, health refreshed when the recorded result has aged
     * past its TTL. This is a page an administrator opened on purpose, so
     * a stale « Joignable » on it would be the one thing here nobody could
     * trust.
     *
     * @return list<StorageLocation>
     */
    private function freshLocations(): array
    {
        return array_map(
            fn (StorageLocation $l): StorageLocation => $this->storageLocationService->checkFresh($l),
            $this->storageLocationRepository->findAll()
        );
    }

    /**
     * « Galeries photo → Nextcloud de l'unité », resolved to labels here so
     * the template never has to look a location up by identifier.
     *
     * @param list<StorageLocation> $locations
     * @return list<array{usage: string, locations: list<string>}>
     */
    private function usageRows(array $locations): array
    {
        $labelsById = [];
        foreach ($locations as $location) {
            $labelsById[$location->id] = $location->label;
        }

        $rows = [];
        foreach ($this->consumers->all() as $row) {
            $names = [];
            foreach ($row['locationIds'] as $id) {
                if (isset($labelsById[$id])) {
                    $names[] = $labelsById[$id];
                }
            }
            $rows[] = ['usage' => $row['usage'], 'locations' => $names];
        }

        return $rows;
    }

    /**
     * What still stands on each location, by name — the « Sert : … » line,
     * and the reason a deletion button is or is not offered.
     *
     * @param list<StorageLocation> $locations
     * @return array<int, list<string>>
     */
    private function usagesByLocationId(array $locations): array
    {
        $usages = [];
        foreach ($locations as $location) {
            $usages[$location->id] = $this->storageLocationService->usagesOf($location->id);
        }

        return $usages;
    }

    /**
     * Asks every consumer whether it could live with this configuration,
     * and turns the first objection into the refusal.
     *
     * **A consumer that cannot be asked is a refusal, not a permission** —
     * the same rule {@see StorageLocationService::delete()} follows, for
     * the same reason. « I could not find out whether this would break
     * something » and « this breaks nothing » are opposite conclusions, and
     * only one of them may end in a saved configuration.
     *
     * @throws StorageLocationException when a consumer objects, or when one could not be asked
     */
    private function assertNoConsumerObjects(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): void {
        try {
            $objection = $this->consumers->objectionTo($location, $proposedConfig, $wouldBeDefault);
        } catch (\Throwable $e) {
            throw new StorageLocationException(
                "Impossible de vérifier ce que ce changement ferait aux fichiers déjà hébergés ici — rien n'a "
                    . 'été enregistré. Réessayez dans un instant.',
                0,
                $e
            );
        }

        if ($objection !== null) {
            throw new StorageLocationException($objection);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(?StorageLocation $location): array
    {
        return [
            'location' => $location,
            // What still stands on this location, by name — « Galeries
            // photo ». A count would only say « 3 » and leave the
            // administrator to guess three what.
            'usages' => $location !== null ? $this->storageLocationService->usagesOf($location->id) : [],
            's3_ai_available' => $this->s3ErrorExplainer->isAvailable(),
            // ——— Google Drive (IT-05) ———
            // The address to register in Google's console, spelled by the
            // controller that also builds it for the flow: two spellings
            // would be a `redirect_uri_mismatch` nobody could diagnose
            // from either of them. Empty when this site does not know its
            // own address, which the card turns into an instruction.
            'drive_redirect_uri' => GoogleDriveConnectionController::redirectUriFor(
                (string) ($this->settings?->get('base_url') ?: '')
            ),
            'drive_secret' => $this->driveGrantOf($location),
            // The number the warning quotes comes from the client that
            // suffers it, so the screen and the code cannot drift.
            'drive_testing_token_days' => GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS,
            // One spelling of « the default folder », shared with
            // StorageLocationService::ensureDefaultExists() and with
            // normalizeLocalPath()'s blank-submit fallback.
            'default_local_path' => StorageLocationService::DEFAULT_PATH,
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    /**
     * Where a local location points: a folder under `storage/`, or an
     * absolute path anywhere this server can see.
     *
     * **Absolute paths are accepted here, and that is new in IT-02.** The
     * model has always resolved them — `StorageBackendFactory` settles
     * relative-versus-absolute in one place — but the form refused them,
     * which meant the only destinations an administrator could declare
     * were folders inside `storage/`. That is exactly the case
     * per-volume measurement exists for: a network mount or a second disk
     * is reached by an absolute path and by nothing else.
     *
     * Three refusals, and each of them is a real failure mode rather than
     * a tidiness rule:
     *
     * - **Inside the web root.** A directory the web server serves is a
     *   directory where every photograph is fetchable without passing a
     *   single access check — the one thing `ARCHITECTURE.md` §8.3 and
     *   `SECURITY.md` both state outright. This is checked lexically
     *   against the real `public/` path rather than by convention, because
     *   a symbolic link makes the convention wrong.
     * - **A `..` segment**, absolute or not. An administrator who typed it
     *   meant somewhere else, and quietly normalising it away is a
     *   surprise rather than a fix.
     * - **A relative path with a character outside `[A-Za-z0-9._-]`**,
     *   unchanged from before.
     *
     * An absolute path is otherwise taken as typed, including its
     * characters: a mount point may legitimately be `/mnt/nas photos`, and
     * this application is in no position to decide which directories a
     * server is allowed to have.
     *
     * @throws StorageLocationException on a path this application refuses
     */
    private function normalizeLocalPath(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        $isAbsolute = str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $raw) === 1;

        if ($raw === '') {
            return StorageLocationService::DEFAULT_PATH;
        }
        if (mb_strlen($raw) > 255) {
            throw new StorageLocationException('Le chemin ne peut pas dépasser 255 caractères.');
        }

        foreach (explode('/', str_replace('\\', '/', $raw)) as $segment) {
            if ($segment === '..') {
                throw new StorageLocationException(
                    'Le chemin ne peut pas contenir « .. ». Indiquez un sous-dossier de l\'espace de stockage '
                        . 'du site, ou le chemin absolu complet du dossier.'
                );
            }
        }

        if ($isAbsolute) {
            // A NUL or a control character is refused on BOTH branches, and
            // the absolute one needs saying because it otherwise accepts
            // the path as typed. A NUL truncates a path in every C library
            // underneath PHP, so « /mnt/nas\0/../../public » is one string
            // to this application's checks and a different, shorter one to
            // the filesystem. That is the whole shape of the bug, and it
            // costs one `preg_match` to close.
            if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
                throw new StorageLocationException(
                    'Le chemin contient un caractère que le système de fichiers ne peut pas interpréter.'
                );
            }

            $this->assertOutsideWebRoot($raw);

            return rtrim($raw, '/') !== '' ? rtrim($raw, '/') : '/';
        }

        $subdir = trim($raw, " \t\n\r\0\x0B/");
        if ($subdir === '') {
            // The SAME folder ensureDefaultExists() creates, not a second
            // spelling of « the default ». Two spellings is how a form
            // accepted blank creates a location pointing at a different
            // directory from the one the site already made, both presented
            // as the default local storage.
            return StorageLocationService::DEFAULT_PATH;
        }

        foreach (explode('/', $subdir) as $segment) {
            if ($segment === '' || $segment === '.' || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                throw new StorageLocationException(
                    'Le sous-dossier ne peut contenir que des lettres, chiffres, points, tirets et « / ». '
                        . 'Pour un dossier ailleurs sur le serveur, indiquez son chemin absolu.'
                );
            }
        }

        return $subdir;
    }

    /**
     * Refuses a directory the web server would serve directly.
     *
     * Everything this site stores is handed out through a route that
     * checks who is asking; a location inside `public/` bypasses all of
     * it, for every file, permanently and silently.
     *
     * **Both sides are canonical, and the candidate's canonical form is
     * built rather than asked for.** `realpath()` answers false for a
     * directory that does not exist yet — which is the ORDINARY case here,
     * since declaring a location is how the directory comes to exist — so
     * an earlier version fell back on the raw string and compared two
     * different alphabets. A `.` segment or a doubled slash placed before
     * the `public` component was then enough to walk straight in:
     * `/var/www/./public/photos` does not start with `/var/www/public/`
     * as text and is the same directory to the kernel. Same for a leaf
     * under a symlinked ancestor, which is exactly the case this check
     * claims to close. {@see canonicalise()} resolves what exists and
     * re-attaches what does not.
     *
     * @throws StorageLocationException
     */
    private function assertOutsideWebRoot(string $path): void
    {
        $webRoot = realpath($this->publicPath);
        $webRoot = $webRoot !== false ? $webRoot : rtrim($this->publicPath, '/');
        $candidate = self::canonicalise($path);

        if ($webRoot === '' || $candidate === '') {
            return;
        }
        if ($candidate === $webRoot || str_starts_with($candidate, rtrim($webRoot, '/') . '/')) {
            throw new StorageLocationException(
                'Ce dossier est dans la partie du site servie directement par le serveur web : tout ce qui y '
                    . 'serait déposé deviendrait téléchargeable sans aucun contrôle d\'accès. Choisissez un '
                    . 'dossier situé ailleurs.'
            );
        }
    }

    /**
     * A path in its canonical form, whether or not it exists yet.
     *
     * Walks up to the nearest ancestor the filesystem can resolve,
     * `realpath()`s that — which settles `.`, doubled slashes and symbolic
     * links in one step — then re-attaches the segments that do not exist
     * yet. A path with no resolvable ancestor at all comes back trimmed
     * rather than empty, so the caller still has something to compare.
     *
     * The result is used for COMPARING only, never stored: resolving
     * symbolic links into what gets written down would turn a deploy
     * layout's `/var/www/current/photos` into `/var/www/releases/41/photos`
     * and break it at the next release.
     */
    private static function canonicalise(string $path): string
    {
        $existing = rtrim($path, '/');
        if ($existing === '') {
            return '/';
        }

        /** @var list<string> $missing */
        $missing = [];
        while (!file_exists($existing)) {
            $segment = basename($existing);
            $parent = dirname($existing);
            if ($parent === $existing) {
                return rtrim($path, '/');
            }
            // A `.` segment names its own parent and must not come back as
            // a directory name when the tail is re-attached.
            if ($segment !== '' && $segment !== '.') {
                $missing[] = $segment;
            }
            $existing = $parent;
        }

        $resolved = realpath($existing);
        if ($resolved === false) {
            return rtrim($path, '/');
        }

        return $missing === []
            ? $resolved
            : rtrim($resolved, '/') . '/' . implode('/', array_reverse($missing));
    }

    /**
     * The per-type configuration record the form just described. One method
     * for both create and update, because the two used to hold two copies
     * of the same call and the S3 endpoint check had already been forgotten
     * in one of them once.
     *
     * @throws StorageLocationException on a sub-directory or an endpoint the site refuses
     */
    private function configFromRequest(
        StorageLocationType $type,
        Request $request,
        ?StorageLocation $location
    ): LocationConfig
    {
        if ($type === StorageLocationType::Local) {
            return new LocalLocationConfig($this->normalizeLocalPath($request->getBody('subdir')));
        }

        if ($type === StorageLocationType::GoogleDrive) {
            // **The folder and the connection date are never taken from
            // the form**, which is why they are read back from the row
            // rather than defaulted away. They are written by the consent
            // round trip alone ({@see GoogleDriveConnectionController}),
            // and a request that omitted them — every request from this
            // form does — would otherwise silently disconnect a working
            // destination by saving its own label.
            $existing = $this->driveConfigOf($location);

            return new GoogleDriveLocationConfig(
                clientId: trim((string) $request->getBody('drive_client_id', '')),
                folderId: $existing->folderId,
                connectedAt: $existing->connectedAt
            );
        }

        $endpoint = (string) $request->getBody('s3_endpoint', '');
        // Validated at save time and not only on « Tester » : runtime reads
        // and writes trust the stored value.
        if (!SsrfUrlValidator::isPublicHttpsUrl($endpoint, true)) {
            throw new StorageLocationException("L'adresse du service S3 doit être une URL https publique.");
        }

        return new ObjectStorageLocationConfig(
            endpoint: $endpoint,
            region: (string) $request->getBody('s3_region', ''),
            bucket: (string) $request->getBody('s3_bucket', ''),
            accessKey: (string) $request->getBody('s3_access_key', ''),
            provider: $this->nullableProvider($request->getBody('s3_provider')),
            publicUrl: $this->nullableString($request->getBody('s3_public_url'))
        );
    }

    /**
     * The encrypted half this form is submitting, or **null for « leave
     * what is stored alone »**.
     *
     * That convention is the one every credential field in this codebase
     * uses, and it is what lets an administrator correct a bucket name
     * without re-typing a key they do not have in front of them. It is
     * also why a Drive secret is MERGED rather than replaced: the record
     * holds three values and this form submits one of them, so writing
     * what the form sent would drop the grant and the account every time
     * somebody renamed a location.
     */
    private function secretFromRequest(
        StorageLocationType $type,
        Request $request,
        ?StorageLocation $location
    ): ?string {
        if ($type === StorageLocationType::ObjectStorage) {
            return $this->nullableString($request->getBody('s3_secret_key'));
        }

        if ($type !== StorageLocationType::GoogleDrive) {
            return null;
        }

        $clientSecret = $this->nullableString($request->getBody('drive_client_secret'));
        if ($clientSecret === null) {
            return null;
        }

        $existing = $location === null
            ? new GoogleDriveSecret()
            : GoogleDriveSecret::fromStorage($this->storageLocationRepository->getSecretForDisplay($location->id));

        return $existing->withClientSecret(trim($clientSecret))->toStorage();
    }

    /**
     * The Drive record as it stands in the row, or an empty one.
     *
     * Empty for a location being created, and empty rather than a refusal
     * for a row whose record cannot be read: what this is used for is
     * preserving the folder and the connection date across an edit, and
     * having neither is the truthful state of a destination nobody has
     * connected yet.
     */
    /**
     * The encrypted half of a Drive location, for the card that shows the
     * account and whether a client secret is stored.
     *
     * **The credentials do not leave this method**, and they cannot: it
     * narrows to {@see GoogleDriveGrantSummary}, which has no field to
     * carry them. Both callers put what they get straight into a render
     * context, and this used to return the secret itself — templates
     * reading only three of its fields was the state of two files, not a
     * guarantee, and one `dump()` under a debug Twig would have printed
     * the client secret and the refresh token verbatim.
     *
     * The account address deliberately survives that narrowing: it is the
     * e-mail of a real person, kept encrypted because a support package
     * would otherwise carry it, and the one place it legitimately appears
     * is in front of the administrator who is deciding whether the right
     * account is connected.
     */
    private function driveGrantOf(?StorageLocation $location): GoogleDriveGrantSummary
    {
        if ($location === null || $location->type !== StorageLocationType::GoogleDrive) {
            return new GoogleDriveGrantSummary();
        }

        return GoogleDriveGrantSummary::of(GoogleDriveSecret::fromStorage(
            $this->storageLocationRepository->getSecretForDisplay($location->id)
        ));
    }

    private function driveConfigOf(?StorageLocation $location): GoogleDriveLocationConfig
    {
        $config = $location?->config;

        return $config instanceof GoogleDriveLocationConfig ? $config : new GoogleDriveLocationConfig();
    }

    private function nullableProvider(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return in_array($value, self::S3_PROVIDERS, true) ? $value : 'custom';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return trim($value) === '' ? null : $value;
    }
}
