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
 *
 * **This script writes massively into a database and creates login accounts
 * whose passwords are published in README.md.** It refuses to run on an
 * installation that already has members, financial movements or more than one
 * user account — it is destructive by design and merges into nothing. See
 * README.md §8; "idempotent or frankly destructive, never in between", and
 * this one is destructive.
 *
 * Everything it does goes through the application's own services: the Desk
 * import, the Config Desk role confirmation, the finance import, the upload
 * pipeline. Nothing is written to a table by hand, which is why a schema
 * change is absorbed instead of breaking a frozen artefact — and why
 * `vendor/bin/phpstan analyse` catches a signature drift here before anybody
 * runs it (`phpstan.neon` lists this directory in its `paths`).
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
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;

$options = getopt('', ['yes', 'root::']);
$datasetRoot = __DIR__;
$instanceRoot = is_string($options['root'] ?? null) && $options['root'] !== ''
    ? $options['root']
    : dirname(__DIR__, 3);

if (!array_key_exists('yes', $options)) {
    fwrite(STDERR, <<<TXT
    Ce script écrit massivement dans la base de données de l'installation
    ciblée et y crée des comptes de démonstration dont les mots de passe sont
    publiés dans README.md.

    Installation ciblée : {$instanceRoot}

    Relancez avec --yes si c'est bien une instance de test.

    TXT);
    exit(1);
}

$context = InstanceContext::at($instanceRoot);

try {
    $refusals = $context->refusalReasons();
} catch (\RuntimeException $exception) {
    fwrite(STDERR, 'Impossible d\'ouvrir l\'installation : ' . $exception->getMessage() . "\n");
    exit(1);
}

if ($refusals !== []) {
    fwrite(STDERR, "Construction refusée — cette installation a déjà servi :\n");
    foreach ($refusals as $refusal) {
        fwrite(STDERR, '  - ' . $refusal . "\n");
    }
    fwrite(STDERR, "\nRepartez d'une installation vierge : ce builder ne fusionne rien.\n");
    exit(1);
}

$pdo = $context->pdo();
$encryption = $context->encryption();
$generator = new DatasetGenerator($datasetRoot);

echo "Construction du jeu de données de référence\n";
echo "Installation : {$instanceRoot}\n\n";

// 1. Le superadministrateur d'abord : c'est lui qui est crédité des imports,
//    et import_journal.user_account_id porte une clé étrangère.
$demoAccounts = new DemoAccounts($pdo, $encryption, $generator->people());
$superadminId = $demoAccounts->ensureSuperadmin();
printf("Superadministrateur : %s (id %d)\n\n", DemoAccounts::SUPERADMIN_EMAIL, $superadminId);

// 2. Les trois années scoutes, puis les trois imports Desk dans l'ordre.
$replay = new DeskImportReplay($pdo, $encryption, $datasetRoot);
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

// 3. La confirmation des rôles — le seul endroit d'où Staff d'U peut naître.
$unconfirmed = $replay->confirmFunctionRoles($yearIds);
printf(
    "\nFonctions confirmées : %d ; laissées non confirmées : %d%s\n",
    count(UnitBlueprint::FUNCTIONS),
    count($unconfirmed),
    $unconfirmed !== [] ? ' (' . implode(', ', $unconfirmed) . ')' : '',
);

// 4. Les finances : catégories et comptes par défaut, puis les six relevés.
$finance = new FinanceSeeder($pdo, $encryption, $datasetRoot, $superadminId);
$finance->ensureModuleDefaults();
$financeCounts = $finance->seed();
printf(
    "\nFinances : %d comptes d'unité, %d mouvements importés, %d doublons reconnus\n",
    $financeCounts['accounts'],
    $financeCounts['imported'],
    $financeCounts['duplicates'],
);

// 5. Les extras : tout ce que Desk ne connaît pas, appliqué par les vrais
//    services — photos par le pipeline de téléversement, décalages d'année,
//    départs, badges, évènements, créances attendues.
$extras = new ExtrasApplier($pdo, $encryption, $context->storagePath(), $datasetRoot, $superadminId);
$extraResult = $extras->apply($yearIds, $finance->accountIds()['unite'] ?? 0);
echo "\nExtras appliqués :\n";
foreach ($extraResult['counts'] as $label => $count) {
    $skippedModule = $extraResult['skipped'][$label] ?? null;
    printf(
        "  %-26s %3d%s\n",
        $label,
        $count,
        $skippedModule !== null ? "   (ignoré : module « {$skippedModule} » désactivé)" : '',
    );
}

// 6. Les comptes de démonstration adossés à des membres — après les imports,
//    puisque c'est l'import Desk qui les crée.
$accounts = $demoAccounts->seedMemberAccounts();
echo "\nComptes de démonstration (mot de passe : " . DemoAccounts::PASSWORD . ")\n";
foreach ($accounts as $handle => $email) {
    printf("  %-12s %s\n", $handle, $email);
}

// 7. Le rapport final, par année et par section.
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

echo "\nTerminé. Les extras non couverts — documents de section, articles avec\n";
echo "formulaire, groupes de discussion, demandes d'inscription, locations —\n";
echo "sont listés dans README.md §8.3 avec la raison.\n";
echo "\nPensez à prendre une sauvegarde Maintenance : elle sert de point de\n";
echo "restauration jetable entre deux essais (README.md §11).\n";
