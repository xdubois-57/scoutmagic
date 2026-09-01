<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

class HelpServiceTest extends TestCase
{
    use HelpTopicFileFixtures;

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    private function service(string $coreDir): HelpService
    {
        return new HelpService(new HelpRegistry($coreDir));
    }

    // --- Path matching ---

    public function testFindsAnExactPathMatch(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'import', ['paths' => '/admin/import']);

        $found = $this->service($dir)->findForPath('/admin/import', Role::SUPERADMIN);

        $this->assertCount(1, $found);
        $this->assertSame('import', $found[0]->id);
    }

    public function testAChildRuleCoversExactlyOneExtraSegment(): void
    {
        // '/members/*' covers '/members/12' (a parametered route's real
        // path) but neither '/members' itself nor '/members/12/emails/5'.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'membre', ['paths' => '/members/*']);
        $service = $this->service($dir);

        $this->assertCount(1, $service->findForPath('/members/12', Role::IDENTIFIED));
        $this->assertCount(0, $service->findForPath('/members', Role::IDENTIFIED));
        $this->assertCount(0, $service->findForPath('/members/12/emails/5', Role::IDENTIFIED));
    }

    public function testASegmentPatternCoversAPageHangingOffAnId(): void
    {
        // The form the deep screens of `rental` and `camps` need: their
        // pages are /mes-locations/{slug}/reglages and
        // /chefs/camps/sejours/{id}/documents, which neither an exact
        // rule nor a direct-child rule can ever name.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'reglages', ['paths' => '/mes-locations/*/reglages']);
        $service = $this->service($dir);

        $this->assertCount(1, $service->findForPath('/mes-locations/le-chalet/reglages', Role::IDENTIFIED));
        $this->assertCount(1, $service->findForPath('/mes-locations/la-ferme/reglages', Role::IDENTIFIED));
        // Same number of segments on both sides: a rule for a page must
        // not also claim the pages under it.
        $this->assertCount(0, $service->findForPath('/mes-locations/le-chalet', Role::IDENTIFIED));
        $this->assertCount(0, $service->findForPath('/mes-locations/le-chalet/reglages/tarif', Role::IDENTIFIED));
        $this->assertCount(0, $service->findForPath('/mes-locations/le-chalet/gabarits', Role::IDENTIFIED));
    }

    public function testASegmentPatternTakesSeveralStars(): void
    {
        // The renter's tracking page is /locations/suivi/{id}/{token}.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'suivi', ['paths' => '/locations/suivi/*/*']);
        $service = $this->service($dir);

        $this->assertCount(1, $service->findForPath('/locations/suivi/42/' . str_repeat('a', 64), Role::PUBLIC));
        $this->assertCount(0, $service->findForPath('/locations/suivi/42', Role::PUBLIC));
    }

    public function testAnUnrelatedPathMatchesNothing(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'import', ['paths' => '/admin/import']);

        $this->assertSame([], $this->service($dir)->findForPath('/admin/journal', Role::SUPERADMIN));
    }

    public function testExactMatchesSortBeforeChildMatchesThenAlphabetically(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'zz-exact', ['paths' => '/config/maintenance']);
        $this->writeTopic($dir, 'aa-enfant', ['paths' => '/config/*']);
        $this->writeTopic($dir, 'mm-exact', ['paths' => '/config/maintenance']);

        $found = $this->service($dir)->findForPath('/config/maintenance', Role::SUPERADMIN);

        $this->assertSame(['mm-exact', 'zz-exact', 'aa-enfant'], array_map(fn ($t) => $t->id, $found));
    }

    // --- Role filtering: the single gate, on every surface ---

    public function testAChiefTopicIsInvisibleToAnIntendantEverywhere(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'pour-chefs', ['role_min' => 'chief', 'paths' => '/chefs/calendar']);

        $service = $this->service($dir);

        // Not in the index…
        $this->assertSame([], $service->listForRole(Role::INTENDANT));
        // …not on the page it covers…
        $this->assertSame([], $service->findForPath('/chefs/calendar', Role::INTENDANT));
        // …not in search…
        $this->assertSame([], $service->search('pour', Role::INTENDANT));
        // …and not by direct id (HelpController turns this null into 404).
        $this->assertNull($service->findById('pour-chefs', Role::INTENDANT));

        // While the role that clears the floor sees it everywhere.
        $this->assertNotNull($service->findById('pour-chefs', Role::CHIEF));
        $this->assertCount(1, $service->findForPath('/chefs/calendar', Role::CHIEF));
    }

    public function testFindByIdReturnsNullForAnUnknownIdToo(): void
    {
        $dir = $this->makeTopicDir();

        $this->assertNull($this->service($dir)->findById('inconnu', Role::SUPERADMIN));
    }

    // --- Index grouping and search ---

    public function testListForRoleGroupsByCategoryWithKnownCategoriesFirst(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'b-sujet', ['category' => 'Premiers pas']);
        $this->writeTopic($dir, 'a-sujet', ['category' => 'Autre chose']);
        $this->writeTopic($dir, 'c-sujet', ['category' => 'Espace animateurs']);

        $grouped = $this->service($dir)->listForRole(Role::PUBLIC);

        // Lexicon categories keep their canonical order, unknown ones
        // follow alphabetically — a new category never needs a code change.
        $this->assertSame(['Premiers pas', 'Espace animateurs', 'Autre chose'], array_keys($grouped));
    }

    public function testSearchIsAccentAndCaseInsensitiveOverTitleSummaryAndCategory(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'annee', ['title' => "Préparer l'année scoute"]);
        $this->writeTopic($dir, 'autre', ['title' => 'Sans rapport', 'summary' => 'Rien ici.']);

        $service = $this->service($dir);

        $this->assertCount(1, $service->search('ANNEE', Role::PUBLIC));
        $this->assertCount(1, $service->search('préparer', Role::PUBLIC));
        $this->assertSame([], $service->search('inexistant', Role::PUBLIC));
        // Summary and category are searched too.
        $this->assertCount(1, $service->search('rien', Role::PUBLIC));
        $this->assertCount(2, array_merge(...array_values($service->search('test', Role::PUBLIC))));
    }

    public function testSearchAlsoLooksInTheDeclaredQuestions(): void
    {
        // The whole point of the field: it carries the words people
        // actually type when the title uses different ones.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'publipostage', [
            'title' => 'Publipostage',
            'summary' => 'Un envoi personnalisé.',
            'question' => ['Comment envoyer un mail personnalisé depuis un fichier Excel ?'],
        ]);
        $this->writeTopic($dir, 'autre', ['title' => 'Sans rapport', 'summary' => 'Rien ici.']);

        $service = $this->service($dir);

        $this->assertCount(1, $service->search('excel', Role::PUBLIC));
        // Accent folding applies to a question exactly as to a title.
        $this->assertCount(1, $service->search('PERSONNALISE', Role::PUBLIC));
        $this->assertSame([], $service->search('tableur', Role::PUBLIC));
    }

    public function testAQuestionOnAnOutOfRoleTopicIsNeverSearchable(): void
    {
        // The role filter comes first, as it does for every other field:
        // a question is content, not an index that escapes the gate.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'confidentiel', [
            'role_min' => 'admin',
            'question' => ['Comment consulter le journal du site ?'],
        ]);

        $service = $this->service($dir);

        $this->assertSame([], $service->search('journal', Role::CHIEF));
        $this->assertCount(1, $service->search('journal', Role::ADMIN));
    }

    // --- Related topics ---

    public function testRelatedIgnoresUnknownIdsAndFiltersByRole(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'principal', ['related' => 'visible, pour-admins, inconnu']);
        $this->writeTopic($dir, 'visible');
        $this->writeTopic($dir, 'pour-admins', ['role_min' => 'admin']);

        $service = $this->service($dir);
        $principal = $service->findById('principal', Role::IDENTIFIED);
        $this->assertNotNull($principal);

        $related = $service->relatedTopics($principal, Role::IDENTIFIED);
        $this->assertSame(['visible'], array_map(fn ($t) => $t->id, $related));

        $relatedForAdmin = $service->relatedTopics($principal, Role::ADMIN);
        $this->assertSame(['visible', 'pour-admins'], array_map(fn ($t) => $t->id, $relatedForAdmin));
    }

    public function testAPurelyDocumentaryTopicAppearsOnlyInTheIndex(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'documentaire'); // no paths

        $service = $this->service($dir);

        $this->assertSame([], $service->findForPath('/', Role::PUBLIC));
        $this->assertNotNull($service->findById('documentaire', Role::PUBLIC));
        $this->assertCount(1, array_merge(...array_values($service->listForRole(Role::PUBLIC))));
    }
}
