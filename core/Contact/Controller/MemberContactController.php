<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Controller;

use Core\Contact\ContactCardException;
use Core\Contact\ContactCardService;
use Core\Contact\ContactQrCodeBuilder;
use Core\Contact\VCardBuilder;
use Core\Contact\VCardVariant;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Service\TextNormalizerService;
use Twig\Environment;

/**
 * The two payloads behind « Ajouter à mes contacts » on a member's admin
 * page (`/admin/members/{id}`, `role_min: admin`).
 *
 * **Only there.** Not on the trombinoscope, where it would hand every
 * identified member a one-click way to hoover up the staff's contact
 * details one by one; not on the member's own page; not on Staffs. The
 * floor of both routes is the floor of the page that offers them.
 *
 * Both are offered together, with **no device detection**: the QR code for
 * a phone held up to the screen, the file for everything else, and the
 * reader decides — a browser's idea of what device it is running on has
 * never been reliable enough to take a choice away from somebody.
 *
 * Nothing is written to disk. The card is built in memory and streamed;
 * `SECURITY.md` §5 requires personal data on disk to be encrypted or
 * strictly temporary, and this takes neither risk.
 */
class MemberContactController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberService $memberService,
        private ContactCardService $contactCardService,
        private VCardBuilder $vcardBuilder,
        private ContactQrCodeBuilder $qrCodeBuilder,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /admin/members/{id}/contact-vcard — the complete card as a
     * `text/vcard` download: portrait, every affiliation.
     *
     * @param array<string, string> $params
     */
    public function vcard(Request $request, array $params): Response
    {
        [$profile, $scoutYearId, $failure] = $this->resolveProfile($params);
        if ($profile === null) {
            return $failure ?? $this->notFound();
        }

        $card = $this->contactCardService->build($profile, VCardVariant::Full, $scoutYearId);
        $body = $this->vcardBuilder->build($card, VCardVariant::Full);

        $this->journal('member_contact_vcard_downloaded', $profile->memberId);

        return (new Response($body))
            ->setHeader('Content-Type', 'text/vcard; charset=utf-8')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->filename($profile) . '"'
            )
            // A contact card is somebody's home address and phone number:
            // it must not sit in a shared proxy or in the back/forward
            // cache of a browser on a staff laptop.
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * GET /admin/members/{id}/contact-qr — the same card as a QR code,
     * without the portrait and with the history capped
     * ({@see VCardVariant}).
     *
     * @param array<string, string> $params
     */
    public function qrCode(Request $request, array $params): Response
    {
        [$profile, $scoutYearId, $failure] = $this->resolveProfile($params);
        if ($profile === null) {
            return $failure ?? $this->notFound();
        }

        $card = $this->contactCardService->build($profile, VCardVariant::Qr, $scoutYearId);
        $body = $this->vcardBuilder->build($card, VCardVariant::Qr);

        try {
            $png = $this->qrCodeBuilder->build($body);
        } catch (ContactCardException $e) {
            // The one refusal this can produce, and it names nobody.
            //
            // **The reader never sees this sentence**, and that is by
            // construction rather than by oversight: this route is only
            // ever an `<img>` source, and a browser handed a non-image
            // answer draws a broken icon in silence. What the reader gets
            // is the French sentence the dialog itself carries, revealed
            // by `public/assets/js/member-search.js` on the image's
            // `error` event — one message, true for a card too long AND
            // for a request that never arrived, naming the same way out
            // in both cases. The status and the body stay honest for what
            // they are: an HTTP answer, and the one a future non-`<img>`
            // caller would read.
            return new Response($e->getMessage(), 422);
        }

        $this->journal('member_contact_qr_served', $profile->memberId);

        return (new Response($png))
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     * @return array{0: ?MemberProfile, 1: int, 2: ?Response}
     */
    private function resolveProfile(array $params): array
    {
        $memberYearId = (int) ($params['id'] ?? 0);
        if ($memberYearId <= 0) {
            return [null, 0, $this->notFound()];
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException) {
            return [null, 0, $this->notFound()];
        }

        $scoutYearId = $this->memberService->getScoutYearIdForMemberYear($memberYearId);
        if ($scoutYearId === null) {
            return [null, 0, $this->notFound()];
        }

        return [$profile, $scoutYearId, null];
    }

    /**
     * An export of somebody's contact details is a sensitive action, so it
     * is journaled — with the member's **identifier and nothing else**.
     * Not their name, not their e-mail address, not a phone number: the
     * journal is read on screen, travels in the support archive, and
     * outlives the reason the card was produced.
     */
    private function journal(string $type, int $memberId): void
    {
        $this->journalService->log(
            'core',
            $type,
            'security',
            'Export de la fiche de contact d\'un membre',
            ['member_id' => $memberId],
            AuthSession::getUserAccountId()
        );
    }

    /**
     * `dupont-jean.vcf` — derived from the member's name, reduced to the
     * ASCII a filename can carry on any of the three desktop platforms a
     * chef d'unité might be on.
     *
     * Through `TextNormalizerService::fold()` rather than
     * `iconv('ASCII//TRANSLIT')`, whose output depends on the C library:
     * « Noël » comes back as `noel` on glibc and as `no"el` on the
     * libiconv macOS and musl ship, which would put a double quote inside
     * a `Content-Disposition` filename. Pinned by
     * `Tests\Architecture\AccentFoldingTest`.
     */
    private function filename(MemberProfile $profile): string
    {
        $folded = TextNormalizerService::fold($profile->lastName . ' ' . $profile->firstName);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $folded), '-');

        return ($slug !== '' ? $slug : 'contact') . '.vcf';
    }
}
