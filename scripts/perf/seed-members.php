<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Performance fixture: a large unit across many scout years.
 *
 * Usage: php scripts/perf/seed-members.php <instance-dir> [years=8] [scale=1.0]
 *
 * <instance-dir> is a throwaway install provisioned by
 * `scripts/e2e-support.php provision` (its storage/keys and secrets are what
 * the seeder encrypts with). Never run this against a real installation: it
 * writes thousands of rows straight into member tables.
 *
 * Shape (scale 1.0): ~630 distinct members, ~3 000 member-years, 330–430
 * active members per year, 12 sections in 4 branches plus Staff d'U, one
 * address (30 % two), one function and one section period per member-year —
 * the same rows a Desk import writes. scale 2.5 with 12 years gives ~1 950
 * members and ~11 800 member-years. Deterministic (mt_srand(42)).
 *
 * Complements tests/fixtures/reference-dataset/ (3 realistic years replayed
 * through the real import) with volume, which is what this measures.
 * See docs/chantiers/CHANTIER-performance.md.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "seed-members.php is a CLI script.\n");
    exit(1);
}
if (!isset($argv[1]) || !is_dir($argv[1] . '/storage/keys')) {
    fwrite(STDERR, "Usage: php scripts/perf/seed-members.php <instance-dir> [years] [scale]\n");
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Accents are folded through the core helper, never through
// iconv('ASCII//TRANSLIT'): its output depends on the C library, so the
// seeded addresses would differ between a glibc box and a macOS one and
// a performance run would not be comparing the same data.
// tests/Architecture/AccentFoldingTest.php enforces this.
Core\Config\AppClock::apply();
$inst = $argv[1];
$years = (int) ($argv[2] ?? 8);
$scale = (float) ($argv[3] ?? 1.0);
mt_srand(42);

$secrets = (new Core\Security\SecretManager(
    $inst . '/storage/keys/master.key',
    $inst . '/storage/config/secrets.enc'
))->readSecrets();
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $secrets['db_host'],
        $secrets['db_port'],
        $secrets['db_name']
    ),
    $secrets['db_user'],
    $secrets['db_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$enc = Core\Security\EncryptionService::fromEncodedKeys($secrets['encryption_key'], $secrets['blind_index_key']);
$e = fn(?string $v, string $ctx) => $v === null ? null : $enc->encrypt($v, $ctx);

// --- scout years: current exists; add past ones ---
$current = $pdo->query('SELECT * FROM scout_years WHERE is_current = 1')->fetch(PDO::FETCH_ASSOC);
$curStart = (int) substr($current['label'], 0, 4);
$yearIds = [$curStart => (int) $current['id']];
for ($y = $curStart - 1; $y > $curStart - $years; $y--) {
    $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)')
        ->execute([sprintf('%d-%d', $y, $y + 1), sprintf('%d-09-01', $y), sprintf('%d-08-31', $y + 1)]);
    $yearIds[$y] = (int) $pdo->lastInsertId();
}
ksort($yearIds);

// --- branches & sections ---
$branchDefs = [
    10 => ['Baladins', 3, 24, 6, 7],
    20 => ['Louveteaux', 4, 30, 8, 11],
    30 => ['Éclaireurs', 3, 35, 12, 15],
    40 => ['Pionniers', 2, 20, 16, 17],
];
$sectionNames = [
    10 => ['Ribambelle', 'Farandole', 'Clairière'],
    20 => ['Meute Seeonee', 'Meute Waingunga', 'Meute Akela', 'Meute Baloo'],
    30 => ['Troupe Orion', 'Troupe Pégase', 'Troupe Cassiopée'],
    40 => ['Poste Everest', 'Poste Kilimandjaro'],
];
$branches = []; // sort_order => branch id
$sections = []; // branch sort => [section ids]
foreach ($branchDefs as $sort => [$label]) {
    $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)')
        ->execute(['BR' . $sort, $label, $sort]);
    $branches[$sort] = (int) $pdo->lastInsertId();
    foreach ($sectionNames[$sort] as $i => $name) {
        $pdo->prepare(
            'INSERT INTO sections (age_branch_id, desk_code, name, email, is_visible, is_active, color) '
            . 'VALUES (?, ?, ?, ?, 1, 1, NULL)'
        )->execute([
            $branches[$sort],
            'SEC' . $sort . $i,
            $name,
            str_replace(' ', '.', Core\Service\TextNormalizerService::fold($name)) . '@example.invalid',
        ]);
        $sections[$sort][] = (int) $pdo->lastInsertId();
    }
}
$staffBranch = (int) $pdo->query("SELECT id FROM age_branches WHERE desk_code = 'STAFFDU'")->fetchColumn();
$staffSection = (int) $pdo->query("SELECT id FROM sections WHERE desk_code = 'STAFFDU'")->fetchColumn();

// --- functions & fee categories ---
$fn = [];
$functionDefs = [
    ['ANIME', 'Animé', 'identified'],
    ['ANIMATEUR', 'Animateur', 'chief'],
    ['CDU', "Animateur d'unité", 'admin'],
    ['INTENDANT', 'Intendant', 'intendant'],
];
foreach ($functionDefs as [$code, $label, $role]) {
    $pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
        ->execute([$code, $label, $role]);
    $fn[$code] = (int) $pdo->lastInsertId();
}
$fees = [];
foreach (['Tarif normal', 'Tarif réduit', 'Tarif minimum'] as $i => $label) {
    $pdo->prepare('INSERT INTO fee_categories (desk_code, label) VALUES (?, ?)')
        ->execute(['TARIF' . $i, $label]);
    $fees[] = (int) $pdo->lastInsertId();
}

$firstNames = [
    'Louis', 'Emma', 'Gabriel', 'Olivia', 'Arthur', 'Alice', 'Jules', 'Chloé', 'Lucas', 'Léa', 'Adam', 'Inès',
    'Raphaël', 'Jade', 'Léo', 'Louise', 'Hugo', 'Mila', 'Noah', 'Camille', 'Nathan', 'Zoé', 'Liam', 'Manon',
    'Maël', 'Juliette', 'Victor', 'Lina', 'Eliott', 'Rose', 'Sacha', 'Anna', 'Tom', 'Elena', 'Oscar', 'Nina',
    'Théo', 'Lucie', 'Simon', 'Clara',
];
$lastNames = [
    'Dubois', 'Lambert', 'Martin', 'Peeters', 'Janssens', 'Maes', 'Jacobs', 'Mertens', 'Willems', 'Claes',
    'Goossens', 'Wouters', 'De Smet', 'Dupont', 'Lemaire', 'Renard', 'Leroy', 'Simon', 'Laurent', 'Michel',
    'Fontaine', 'Dumont', 'Vandenberghe', 'Van den Broeck', 'Declercq', 'Hermans', 'Aerts', 'Pauwels', 'Smets',
    'Wijns',
];
$streets = [
    'Rue de la Station', 'Avenue des Tilleuls', 'Chaussée de Wavre', 'Rue du Moulin', 'Clos des Bouleaux',
    'Rue Haute', 'Avenue Louise', 'Rue de l\'Église', 'Drève du Bois', 'Rue des Combattants',
];
$cities = [
    ['1300', 'Wavre'], ['1340', 'Ottignies'], ['1348', 'Louvain-la-Neuve'], ['1330', 'Rixensart'], ['1310', 'La Hulpe'],
    ['1380', 'Lasne'], ['1150', 'Woluwe-Saint-Pierre'], ['1160', 'Auderghem'],
];
$totems = [
    'Chamois', 'Springbok', 'Loutre', 'Ocelot', 'Fennec', 'Colibri', 'Sitatunga', 'Genette', 'Markhor', 'Ouistiti',
    'Tamia', 'Koudou', 'Pika', 'Saïmiri', 'Vigogne',
];
$pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];

// --- member pool: cohorts by birth year, plus animateurs & staff ---
// Each entry: desk_id, first, last, gender, birth, family email, address,
// sectionSlot (int), joined (first scout year start), left (exclusive).
$members = [];
$idx = 0;
$mk = function (int $birthYear, string $kind) use (
    &$idx,
    $pick,
    $firstNames,
    $lastNames,
    $streets,
    $cities,
    $curStart,
    $years,
    $totems
) {
    $idx++;
    $first = $pick($firstNames);
    $last = $pick($lastNames);
    $joinAgeMin = $kind === 'staff' ? 25 : 6;
    $join = max($curStart - $years + 1 - mt_rand(0, 3), $birthYear + $joinAgeMin);

    if ($kind === 'staff') {
        $left = $curStart + 1;
    } elseif ($kind === 'animateur') {
        $left = $birthYear + 18 + mt_rand(2, 7);
    } elseif (mt_rand(1, 100) <= 20) {
        $left = $join + mt_rand(1, 6);
    } else {
        $left = $birthYear + 18 + mt_rand(0, 6);
    }

    [$pc, $city] = $pick($cities);
    $email = str_replace(' ', '', Core\Service\TextNormalizerService::fold($first . '.' . $last));

    return [
        'desk_id' => sprintf('DESK-%06d', $idx),
        'first' => $first,
        'last' => $last,
        'gender' => mt_rand(0, 1) ? 'M' : 'F',
        'birth' => sprintf('%d-%02d-%02d', $birthYear, mt_rand(1, 12), mt_rand(1, 28)),
        'email' => $email . $idx . '@example.invalid',
        'phone' => mt_rand(0, 2) ? sprintf('+3247%07d', mt_rand(0, 9999999)) : null,
        'street' => $pick($streets),
        'number' => (string) mt_rand(1, 250),
        'pc' => $pc,
        'city' => $city,
        'slot' => mt_rand(0, 1000),
        'join' => $join,
        'left' => $left,
        'kind' => $kind,
        'totem' => $pick($totems),
        'fee' => mt_rand(0, 2),
        'second_address' => mt_rand(1, 100) <= 30,
    ];
};
$oldestYear = $curStart - $years + 1;
for ($birth = $oldestYear - 17; $birth <= $curStart - 6; $birth++) {
    $n = (int) round(28 * $scale);
    for ($i = 0; $i < $n; $i++) {
        $members[] = $mk($birth, 'child');
    }
}
for ($birth = $oldestYear - 26; $birth <= $curStart - 18; $birth++) {
    $n = (int) round(9 * $scale);
    for ($i = 0; $i < $n; $i++) {
        $members[] = $mk($birth, 'animateur');
    }
}
for ($i = 0; $i < (int) round(8 * $scale); $i++) {
    $members[] = $mk($curStart - mt_rand(28, 45), 'staff');
}

$branchForAge = function (int $age) use ($branchDefs): ?int {
    foreach ($branchDefs as $sort => [, , , $min, $max]) {
        if ($age >= $min && $age <= $max) {
            return $sort;
        }
    }

    return null;
};

$pdo->beginTransaction();
$insMember = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
$insMy = $pdo->prepare(
    'INSERT INTO member_years ('
    . 'member_id, scout_year_id, first_name_encrypted, last_name_encrypted, gender_encrypted, '
    . 'birth_date_encrypted, phone_encrypted, mobile_encrypted, email_encrypted, email_blind_index, '
    . 'totem_encrypted, quali_encrypted, patrol_encrypted, formation_level, federation_mail_consent, '
    . 'unit_mail_consent, fee_category_id, unit_code, handicap_encrypted, supplementary_insurance, is_active'
    . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
);
$insAddr = $pdo->prepare(
    'INSERT INTO member_addresses ('
    . 'member_year_id, address_type, street_encrypted, number_encrypted, box_encrypted, '
    . 'complement_encrypted, postal_code_encrypted, city_encrypted, country_encrypted, '
    . 'address_normalized_blind_index'
    . ') VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$insFn = $pdo->prepare(
    'INSERT INTO member_functions ('
    . 'member_year_id, function_id, section_id, age_branch_id, start_date, end_date, '
    . 'mandate_end, is_main_function'
    . ') VALUES (?,?,?,?,?,?,?,1)'
);
$insPeriod = $pdo->prepare(
    'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date) '
    . 'VALUES (?,?,?,?,?)'
);
$stats = ['members' => 0, 'member_years' => 0, 'per_year' => []];
foreach ($members as &$m) {
    $m['id'] = null;
    foreach ($yearIds as $y => $yid) {
        if ($y < $m['join'] || $y >= $m['left']) {
            continue;
        }
        $age = $y - (int) substr($m['birth'], 0, 4);

        if ($m['kind'] === 'child' || ($m['kind'] === 'animateur' && $age < 18)) {
            $sort = $branchForAge($age);
            if ($sort === null) {
                continue;
            }
            $secs = $sections[$sort];
            $sectionId = $secs[$m['slot'] % count($secs)];
            $branchId = $branches[$sort];
            $fnId = $fn['ANIME'];
        } elseif ($m['kind'] === 'animateur') {
            $all = array_merge(...array_values($sections));
            $sectionId = $all[$m['slot'] % count($all)];
            $branchId = (int) $pdo
                ->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)
                ->fetchColumn();
            $fnId = $fn['ANIMATEUR'];
        } else {
            $sectionId = $staffSection;
            $branchId = $staffBranch;
            $fnId = ($m['slot'] % 5 === 0) ? $fn['INTENDANT'] : $fn['CDU'];
        }

        if ($m['id'] === null) {
            $insMember->execute([$m['desk_id']]);
            $m['id'] = (int) $pdo->lastInsertId();
            $stats['members']++;
        }
        $isAdult = $fnId !== $fn['ANIME'];
        $email = $isAdult ? $m['email'] : 'parents.' . $m['email'];
        $mobile = $isAdult
            ? $e(sprintf('+3249%07d', $m['slot'] * 977 % 9999999), 'member_years.mobile')
            : null;
        $patrol = ($fnId === $fn['ANIME'] && $age >= 12)
            ? $e('Patrouille ' . ['Aigle', 'Loup', 'Renard', 'Castor'][$m['slot'] % 4], 'member_years.patrol')
            : null;
        $insMy->execute([
            $m['id'],
            $yid,
            $e($m['first'], 'member_years.first_name'),
            $e($m['last'], 'member_years.last_name'),
            $e($m['gender'], 'member_years.gender'),
            $e($m['birth'], 'member_years.birth_date'),
            $e($m['phone'], 'member_years.phone'),
            $mobile,
            $e($email, 'member_years.email'),
            $enc->blindIndex($email, 'email'),
            $isAdult ? $e($m['totem'], 'member_years.totem') : null,
            $isAdult ? $e('Joyeux', 'member_years.quali') : null,
            $patrol,
            $isAdult ? ['', 'Formation 1', 'Formation 2', 'Brevet'][$m['slot'] % 4] : null,
            $m['slot'] % 3 === 0 ? 1 : 0,
            1,
            $fees[$m['fee']],
            'BW021',
            null,
            $m['slot'] % 10 === 0 ? 'Oui' : null,
        ]);
        $myId = (int) $pdo->lastInsertId();
        $stats['member_years']++;
        $stats['per_year'][$y] = ($stats['per_year'][$y] ?? 0) + 1;
        $norm = Core\Member\AddressNormalizer::normalize($m['street'], $m['number'], null, $m['pc']);
        $insAddr->execute([
            $myId,
            'Domicile',
            $e($m['street'], 'member_addresses.street'),
            $e($m['number'], 'member_addresses.number'),
            null,
            null,
            $e($m['pc'], 'member_addresses.postal_code'),
            $e($m['city'], 'member_addresses.city'),
            $e('Belgique', 'member_addresses.country'),
            $norm !== '' ? $enc->blindIndex($norm, 'address') : null,
        ]);
        if ($m['second_address']) {
            $insAddr->execute([
                $myId,
                'Parent 2',
                $e('Rue Neuve', 'member_addresses.street'),
                $e('12', 'member_addresses.number'),
                null,
                null,
                $e('1000', 'member_addresses.postal_code'),
                $e('Bruxelles', 'member_addresses.city'),
                $e('Belgique', 'member_addresses.country'),
                null,
            ]);
        }
        $start = sprintf('%d-09-01', $y);
        $end = $y === $curStart ? null : sprintf('%d-08-31', $y + 1);
        $insFn->execute([
            $myId,
            $fnId,
            $sectionId,
            $branchId,
            $start,
            null,
            $isAdult ? sprintf('%d-08-31', $y + 1) : null,
        ]);
        $insPeriod->execute([$m['id'], $sectionId, $yid, $start, $end]);
    }
}
unset($m);
$pdo->commit();
echo json_encode($stats, JSON_PRETTY_PRINT), "\n";
