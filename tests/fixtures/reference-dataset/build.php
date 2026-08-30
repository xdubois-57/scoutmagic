<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Builds the reference dataset into a ScoutMagic installation.
 *
 *   php tests/fixtures/reference-dataset/build.php --yes
 *   php tests/fixtures/reference-dataset/build.php --yes --root=/chemin/vers/installation
 *   php tests/fixtures/reference-dataset/build.php --yes --reset
 *
 * **This script writes massively into a database and creates login accounts
 * whose passwords are published in README.md.** It refuses to run on an
 * installation that already has members, financial movements or more than one
 * user account — it is destructive by design and merges into nothing. See
 * README.md §8; "idempotent or frankly destructive, never in between", and
 * this one is destructive.
 *
 * `--reset` answers that refusal instead of merely reporting it: it empties
 * the target first (safety dump, TRUNCATE of every table but the two that
 * hold site configuration, removal of the uploaded files) and then builds into
 * the emptied instance. The installation itself survives — keys, secrets,
 * schema and enabled modules are left alone — which is what separates it from
 * the application's "Réinitialisation complète". It is its own flag, on top of
 * `--yes`, because "build into an empty instance" and "throw away whatever is
 * in this one" are two different decisions and only one of them can lose
 * someone's data. See InstanceReset and README.md §8.4.
 *
 * Everything the BUILD does goes through the application's own services: the
 * Desk import, the Config Desk role confirmation, the finance import, the
 * upload pipeline. Nothing is written to a table by hand, which is why a
 * schema change is absorbed instead of breaking a frozen artefact — and why
 * `vendor/bin/phpstan analyse` catches a signature drift here before anybody
 * runs it (`phpstan.neon` lists this directory in its `paths`). The one place
 * that rule cannot hold is the reset, which has no service to go through; see
 * InstanceReset for why, and for what the application itself does there.
 *
 * CLI only, and for the same reason as public/cron.php (SECURITY.md §24): it
 * runs privileged work with no session and no RBAC, so a web request must
 * never reach it. There is nothing to serve to a browser here.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../vendor/autoload.php';

use Tests\Fixtures\ReferenceDataset\DatasetGenerator;
use Tests\Fixtures\ReferenceDataset\DemoAccounts;
use Tests\Fixtures\ReferenceDataset\DeskImportReplay;
use Tests\Fixtures\ReferenceDataset\ExtrasApplier;
use Tests\Fixtures\ReferenceDataset\FinanceSeeder;
use Tests\Fixtures\ReferenceDataset\InstanceContext;
use Tests\Fixtures\ReferenceDataset\InstanceReset;
use Tests\Fixtures\ReferenceDataset\CampaignBlueprint;
use Tests\Fixtures\ReferenceDataset\CampaignSeeder;
use Tests\Fixtures\ReferenceDataset\ModuleActivator;
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;

$options = getopt('', ['yes', 'reset', 'no-backup', 'root::']);
$reset = array_key_exists('reset', $options);
$withBackup = !array_key_exists('no-backup', $options);
$datasetRoot = __DIR__;
$instanceRoot = is_string($options['root'] ?? null) && $options['root'] !== ''
    ? $options['root']
    : dirname(__DIR__, 3);

if (!array_key_exists('yes', $options)) {
    $resetWarning = $reset
        ? "--reset est demandé : les données de cette installation seront effacées\n"
            . "(tables vidées, fichiers téléversés supprimés) avant la construction.\n"
            . "Seuls les réglages, les modules activés et les clés sont conservés.\n\n"
        : '';

    fwrite(STDERR, <<<TXT
    Ce script écrit massivement dans la base de données de l'installation
    ciblée et y crée des comptes de démonstration dont les mots de passe sont
    publiés dans README.md.

    Installation ciblée : {$instanceRoot}

    {$resetWarning}Relancez avec --yes si c'est bien une instance de test.

    TXT);
    exit(1);
}

if (!$reset && !$withBackup) {
    fwrite(STDERR, "--no-backup ne veut rien dire sans --reset : sans réinitialisation, rien n'est écrasé.\n");
    exit(1);
}

$context = InstanceContext::at($instanceRoot);

try {
    $refusals = $context->refusalReasons();
} catch (\RuntimeException $exception) {
    fwrite(STDERR, 'Impossible d\'ouvrir l\'installation : ' . $exception->getMessage() . "\n");
    exit(1);
}

if ($refusals !== [] && !$reset) {
    fwrite(STDERR, "Construction refusée — cette installation a déjà servi :\n");
    foreach ($refusals as $refusal) {
        fwrite(STDERR, '  - ' . $refusal . "\n");
    }
    fwrite(
        STDERR,
        "\nCe builder ne fusionne rien. Deux sorties :\n"
        . "  - repartir d'une installation vierge ;\n"
        . "  - relancer avec --reset, qui vide d'abord cette installation-ci\n"
        . "    (sauvegarde de sécurité, puis vidage des tables de données et\n"
        . "    suppression des fichiers téléversés ; réglages, modules activés\n"
        . "    et clés sont conservés). Voir README.md §8.4.\n"
    );
    exit(1);
}

$pdo = $context->pdo();
$encryption = $context->encryption();
$generator = new DatasetGenerator($datasetRoot);

echo "Construction du jeu de données de référence\n";
echo "Installation : {$instanceRoot}\n\n";

// Le vidage a lieu dès que --reset est demandé, même quand rien ne motivait un
// refus : les motifs de refus ne comptent que les membres, les mouvements et
// les comptes, alors qu'une instance peut très bien porter des sections, des
// années scoutes ou un calendrier sans un seul membre. Construire par-dessus
// ces restes-là, c'est exactement le demi-mélange que ce builder refuse.
if ($reset) {
    echo $refusals === []
        ? "Réinitialisation demandée (aucun membre en base, mais le vidage a lieu quand même).\n"
        : "Réinitialisation demandée. Cette installation contenait :\n";
    foreach ($refusals as $refusal) {
        echo '  - ' . $refusal . "\n";
    }

    $resetter = new InstanceReset($context->connection(), $context->storagePath(), $instanceRoot);

    try {
        $resetResult = $resetter->run($withBackup);
    } catch (\RuntimeException $exception) {
        fwrite(STDERR, "\nRéinitialisation abandonnée : " . $exception->getMessage() . "\n");
        fwrite(STDERR, "Rien n'a été vidé.\n");
        exit(1);
    }

    printf(
        "  → %d table(s) vidée(s), %d fichier(s) supprimé(s)\n",
        $resetResult['tables'],
        $resetResult['files'],
    );
    echo $resetResult['backupPath'] !== null
        ? '  → sauvegarde de sécurité : ' . $resetResult['backupPath'] . "\n\n"
        : '  → ' . (string) $resetResult['backupError'] . "\n\n";

    // Le refus est la seule définition de « vierge » que ce script connaisse :
    // si elle ne tient plus après le vidage, c'est le vidage qui est
    // incomplet, et il vaut mieux s'arrêter là que construire à moitié.
    $remaining = $context->refusalReasons();
    if ($remaining !== []) {
        fwrite(STDERR, "La réinitialisation n'a pas suffi :\n");
        foreach ($remaining as $refusal) {
            fwrite(STDERR, '  - ' . $refusal . "\n");
        }
        exit(1);
    }
}

// 1. Le superadministrateur d'abord : c'est lui qui est crédité des imports,
//    et import_journal.user_account_id porte une clé étrangère.
$demoAccounts = new DemoAccounts($pdo, $encryption, $generator->people());
$superadminId = $demoAccounts->ensureSuperadmin();
printf("Superadministrateur : %s (id %d)\n\n", DemoAccounts::SUPERADMIN_EMAIL, $superadminId);

// 2. Tous les modules, activés par le vrai service de modules.
//
//    AVANT tout le reste, et pas à la fin : un module éteint n'a ni réglages
//    par défaut ni routes, et les extras qui en dépendent seraient sautés en
//    silence — ce qui laissait exactement le genre de trou que ce jeu de
//    données est censé rendre visible. L'état final demandé (tous les modules
//    actifs) est le même ; c'est l'ordre qui change ce que le build peut
//    écrire. Voir README.md §8.1.
$moduleActivator = new ModuleActivator(
    $pdo,
    new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo)),
    $instanceRoot . '/modules',
);
$moduleResult = $moduleActivator->activateAll();
printf("Modules activés : %d\n", count($moduleResult['activated']));
foreach ($moduleResult['failed'] as $moduleId => $reason) {
    fwrite(STDERR, "  ! module « {$moduleId} » non activé : {$reason}\n");
}
echo "\n";

// 3. Les trois années scoutes, puis les trois imports Desk dans l'ordre.
$replay = new DeskImportReplay($pdo, $encryption, $datasetRoot, $context->storagePath());
$yearIds = $replay->ensureYears();
echo "Années scoutes créées : " . implode(', ', UnitBlueprint::YEARS) . "\n";

$importResults = $replay->importAll($yearIds, $superadminId);
foreach ($importResults as $label => $result) {
    printf(
        "  %s : %3d membres, %3d lignes, %d fonction(s) inédite(s)\n",
        $label,
        $result->memberCount,
        $result->lineCount,
        $result->newFunctionsCount,
    );
}

// 4. La confirmation des rôles — le seul endroit d'où Staff d'U peut naître.
$unconfirmed = $replay->confirmFunctionRoles($yearIds);
printf(
    "\nFonctions confirmées : %d ; laissées non confirmées : %d%s\n",
    count(UnitBlueprint::FUNCTIONS),
    count($unconfirmed),
    $unconfirmed !== [] ? ' (' . implode(', ', $unconfirmed) . ')' : '',
);

// 5. Les finances : catégories et comptes par défaut, les IBAN des comptes de
//    section, puis les six relevés. L'ordre est porteur — voir README.md §8.1.
$finance = new FinanceSeeder($pdo, $encryption, $datasetRoot, $superadminId);
$finance->ensureModuleDefaults();
$sectionAccounts = $finance->completeSectionAccounts();
$financeCounts = $finance->seed();
printf(
    "\nFinances : %d comptes d'unité, %d comptes de section complétés,\n"
    . "           %d mouvements importés, %d doublons reconnus\n",
    $financeCounts['accounts'],
    $sectionAccounts,
    $financeCounts['imported'],
    $financeCounts['duplicates'],
);

// 6. Les extras : tout ce que Desk ne connaît pas, appliqué par les vrais
//    services — photos par le pipeline de téléversement, décalages d'année,
//    départs, badges, adresses de section, créances attendues, et les
//    semeurs par domaine (calendrier, actualités, camps, inscriptions,
//    bannières, galerie, locations, responsables de section).
$extras = new ExtrasApplier($pdo, $encryption, $context->storagePath(), $datasetRoot, $superadminId);
$extraResult = $extras->apply($yearIds, $finance->accountIds()['unite'] ?? 0);
echo "\nExtras appliqués :\n";
foreach ($extraResult['counts'] as $label => $count) {
    $skippedModule = $extraResult['skipped'][$label] ?? null;
    printf(
        "  %-26s %3d%s\n",
        $label,
        $count,
        $skippedModule !== null ? "   (ignoré : module « {$skippedModule} » absent)" : '',
    );
}
foreach ($extraResult['notes'] as $note) {
    echo '  → ' . $note . "\n";
}

// 7. La campagne de paiement, après les extras : elle a besoin des membres
//    (une ligne par membre, member_id obligatoire) et du compte d'unité, et
//    ses communications structurées — tirées au hasard à la création — ne
//    peuvent donc pas être dans les relevés commités. Le semeur écrit un
//    septième relevé et le fait entrer par le même ImportService.
$unitAccountId = $finance->accountIds()['unite'] ?? 0;
if ($unitAccountId > 0) {
    $campaign = new CampaignSeeder(
        $pdo,
        $encryption,
        $context->storagePath(),
        $datasetRoot,
        $extras->memberIds(),
        $superadminId,
    );
    $campaignCounts = $campaign->seed(
        $unitAccountId,
        static fn (string $path, string $name): array => $finance->importExtraStatement('unite', $path, $name),
    );
    printf(
        "\nCampagne « %s » : %d lignes, %d créances, %d paiements écrits, %d importés\n",
        CampaignBlueprint::LABEL,
        $campaignCounts['rows'],
        $campaignCounts['receivables'],
        $campaignCounts['payments'],
        $campaignCounts['imported'],
    );
}

// 8. Les comptes de démonstration adossés à des membres — après les imports,
//    puisque c'est l'import Desk qui les crée.
$accounts = $demoAccounts->seedMemberAccounts();
echo "\nComptes de démonstration (mot de passe : " . DemoAccounts::PASSWORD . ")\n";
foreach ($accounts as $handle => $email) {
    printf("  %-12s %s\n", $handle, $email);
}

// 9. Le rapport final, par année et par section.
echo "\nEffectifs constatés en base :\n";
foreach (UnitBlueprint::YEARS as $label) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM member_years WHERE scout_year_id = ? AND is_active = 1'
    );
    $statement->execute([$yearIds[$label]]);
    $row = $statement->fetch(\PDO::FETCH_ASSOC);
    printf("  %s : %3d membres actifs\n", $label, (int) ($row['n'] ?? 0));
}

$sections = $pdo->query('SELECT desk_code, is_active FROM sections ORDER BY desk_code');
echo "\nSections :\n";
foreach ($sections !== false ? $sections->fetchAll(\PDO::FETCH_ASSOC) : [] as $section) {
    printf(
        "  %-24s %s\n",
        (string) $section['desk_code'],
        (int) $section['is_active'] === 1 ? 'active' : 'inactive',
    );
}

echo "\nTerminé. Les deux extras non couverts — documents de section, groupes de\n";
echo "discussion et leurs messages — sont listés dans README.md §8.3 avec la\n";
echo "raison.\n";
echo "\nPensez à prendre une sauvegarde Maintenance : elle sert de point de\n";
echo "restauration jetable entre deux essais (README.md §11).\n";
