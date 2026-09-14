<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Security\AuthSession;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageLocationRepository;
use Twig\Environment;

/**
 * The two things about the off-site backup that are not a storage
 * location: the phrase its archives are encrypted with, and which
 * location they go to.
 *
 * **What this controller lost in IT-05 is most of what it was.** It used
 * to hold the whole Google conversation — client credentials, a consent
 * round trip, a connection test, a disconnection — because the off-site
 * backup was the only thing in the application that could talk to Drive.
 * All of that moved to
 * {@see GoogleDriveConnectionController}, beside the declaration of the
 * location it now configures. What stayed is what genuinely belongs to
 * the backup rather than to the destination.
 *
 * **The phrase stays here, and that is not an accident of where the code
 * was.** It encrypts the ARCHIVES, not the destination they happen to be
 * sent to, it has to outlive every destination an operator ever connects,
 * and it is the one secret of this feature that remains in `secrets.enc`
 * for exactly that reason ({@see RemotePassphrase}).
 *
 * **Its own controller, rather than three more methods on
 * `MaintenanceController`.** That class is already past every PHPMD
 * threshold the project measures. The Maintenance page renders the block;
 * nothing else here belongs to it.
 */
final class RemoteBackupController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private readonly RemoteBackupDestination $destination,
        private readonly StorageLocationRepository $locations,
        private readonly JournalService $journalService,
        private readonly RemotePassphrase $passphrase
    ) {
        parent::__construct($twig);
    }

    /**
     * POST — shows the phrase the off-site archives are encrypted with.
     *
     * **A deliberate departure from the webhook secret, which is shown
     * once and never again.** That one can be regenerated at no cost:
     * GitHub is told the new value and nothing that came before matters.
     * This phrase opens archives that already exist, on a service this
     * site may not be around to talk to — and it has to live in
     * `secrets.enc` anyway, because the scheduled send encrypts with it
     * at four in the morning with nobody there to type anything. So
     * anybody who can read the server already has it: hiding it from the
     * administrator protects nothing, and guarantees that one day, with
     * the server still running, nobody will be able to open the archives
     * it spent a year uploading.
     *
     * POST rather than GET, and behind the CSRF token, so that the phrase
     * cannot be pulled out by a link somebody was persuaded to follow.
     *
     * @param array<string, string> $params
     */
    public function revealPassphrase(Request $request, array $params): Response
    {
        $guard = $this->guardCsrfJson($request);
        if ($guard !== null) {
            return $guard;
        }

        try {
            $phrase = $this->passphrase->current();
        } catch (RemoteBackupException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        // **Journaled, and journaled as a security event.** Reading this
        // is reading the key to every archive off this server; an
        // administrator who did not do it should be able to find out that
        // somebody did. The phrase itself is not in the entry, for the
        // reason AGENTS.md gives about the journal: it is shown on screen
        // and it travels in the support archive.
        $this->journalService->log(
            'core',
            'remote_backup_passphrase_revealed',
            'security',
            'Phrase de passe des sauvegardes hors site affichée',
            ['generation' => $this->passphrase->generation()],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'passphrase' => $phrase]);
    }

    /**
     * POST — draws a new phrase, and leaves every archive already sent
     * unreadable by it.
     *
     * That consequence is the whole reason this is a separate, confirmed
     * action rather than a side effect of anything: nothing re-encrypts
     * what is already on Drive, and the old phrase is gone from
     * `secrets.enc` the moment this returns. The generation counter is
     * what keeps the folder legible afterwards — it is in the name of
     * every file sent, so an operator holding two phrases can tell which
     * opens which.
     *
     * @param array<string, string> $params
     */
    public function regeneratePassphrase(Request $request, array $params): Response
    {
        $guard = $this->guardCsrf($request, '/config/maintenance');
        if ($guard !== null) {
            return $guard;
        }

        $previous = $this->passphrase->generation();

        try {
            $this->passphrase->regenerate();
        } catch (RemoteBackupException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $this->journalService->log(
            'core',
            'remote_backup_passphrase_regenerated',
            'security',
            'Phrase de passe des sauvegardes hors site régénérée',
            ['previous_generation' => $previous, 'generation' => $this->passphrase->generation()],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set(
            'success',
            'Nouvelle phrase de passe. Les archives déjà envoyées ne s\'ouvrent plus qu\'avec l\'ancienne : '
            . 'notez-la si vous la connaissez encore, ou supprimez-les du compte distant.'
        );

        return $this->redirect('/config/maintenance#remote-backup');
    }

    /**
     * POST — points the off-site backup at a declared storage location.
     *
     * **D4, spelled out.** Locations are declared centrally and chosen
     * locally; the choice lives in this consumer's own setting, and there
     * is deliberately no join table in the middle. So the list this offers
     * is read from the storage model and the answer is written to one
     * setting — the storage page never learns what a backup is, and this
     * page never learns what an S3 endpoint is.
     *
     * **Narrowed to destinations that can resume an interrupted upload**,
     * which is the one capability an archive measured in gibibytes cannot
     * do without over a domestic upstream link. Refused here rather than
     * discovered at four in the morning: the send would meet the same
     * refusal, journal it, and go on failing nightly with nobody reading
     * the journal.
     *
     * @param array<string, string> $params
     */
    public function chooseDestination(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/maintenance')) !== null) {
            return $guard;
        }

        $locationId = (int) $request->getBody('location_id');
        if ($locationId === 0) {
            $this->destination->choose(0);
            $this->journalService->log(
                'core',
                'remote_backup_destination_cleared',
                'security',
                'Destination des sauvegardes hors site retirée : plus rien ne quitte ce serveur',
                [],
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('warning', 'Aucune destination hors site : les sauvegardes ne quittent plus ce '
                . 'serveur.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $location = $this->locations->findById($locationId);
        if ($location === null) {
            FlashMessage::set('error', 'Cet emplacement de stockage n\'existe plus — la page a peut-être été '
                . 'rouverte après sa suppression.');

            return $this->redirect('/config/maintenance#remote-backup');
        }

        if (!$location->supports(StorageCapability::ResumableUpload)) {
            FlashMessage::set('error', sprintf(
                'L\'emplacement « %s » ne sait pas %s. Une archive de sauvegarde ne part jamais en une seule '
                . 'fois : choisissez une destination qui sait reprendre un envoi interrompu.',
                $location->label,
                StorageCapability::ResumableUpload->frenchDescription()
            ));

            return $this->redirect('/config/maintenance#remote-backup');
        }

        $this->destination->choose($location->id);

        // `security`, and the label rather than the endpoint: this decides
        // where a copy of the whole site leaves to, which is exactly what
        // the transverse table of the chantier puts at that level. The
        // label is the administrator's own word for it and carries no
        // personal data.
        $this->journalService->log(
            'core',
            'remote_backup_destination_chosen',
            'security',
            "Sauvegardes hors site dirigées vers l'emplacement « {$location->label} »",
            ['location_id' => $location->id],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', "Les sauvegardes hors site partiront vers « {$location->label} ».");

        return $this->redirect('/config/maintenance#remote-backup');
    }
}
