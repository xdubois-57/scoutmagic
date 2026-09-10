# Chantier — Droits d'accès pendant la transition d'année

Journal d'implémentation du document de chantier « Droits d'accès pendant la
transition d'année » (itérations IT-01 à IT-04). Une section par itération :
ce qui a été fait, les décisions prises en autonomie, les divergences
constatées entre le document de chantier et le dépôt réel, et ce qui a été
reporté. Même format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier lui-même n'est pas dans le dépôt : il a été fourni en
pièce jointe. Ce journal en est la seule trace durable, il doit donc se lire
sans lui.

---

## Le problème, en deux phrases

Pendant la transition d'année, l'animateur qui quitte l'unité et celui qui
arrive ont besoin de réponses opposées le même jour. Résoudre le rôle contre
la seule année publique ne sert que le premier ; le résoudre contre la seule
année staff ne sert que le second — d'où un **jeu** d'années plutôt qu'une
année, et une année **servie personne par personne** plutôt qu'un
élargissement de chaque contrôle en aval.

---

## IT-01 — Le jeu d'années d'autorisation

**Livré.** `Core\ScoutYear\AuthorizationYearService` (le service) et
`Core\ScoutYear\AuthorizationYears` (l'objet-valeur immuable qu'il rend),
plus `tests/Core/ScoutYear/AuthorizationYearServiceTest` (12 cas) et
`tests/Architecture/AuthorizationYearsAreReadOnlyTest` (2 règles). Service
pur : câblé nulle part, aucun changement de comportement.

Trois sources alimentent le jeu — l'année publique (`current_scout_year_id`),
l'année date-calculée (`ScoutYearService::labelForDate()`, la bascule du
1er septembre) et l'année staff (`staff_scout_year_id`) — dédoublonnées,
puis bornées à un an d'écart de l'année publique **dans les deux sens**.

**Décisions autonomes.**

1. **Un objet-valeur plutôt qu'un simple `int[]`.** La règle d'IT-02 n'est
   pas symétrique : un rôle obtenu dans l'année publique compte tel quel, un
   rôle obtenu ailleurs ne compte qu'à partir d'`intendant`. Le consommateur
   doit donc savoir *laquelle* des années du jeu est l'année publique.
   `AuthorizationYears` porte les deux (`ids()` et `isPublicYear()`) ; rendre
   un tableau nu aurait obligé chaque appelant à redemander l'année publique
   et à la comparer lui-même, c'est-à-dire à réimplémenter le seuil.
2. **Dépendance sur `SettingService`, pas sur `ScoutYearResolver`.**
   `ScoutYearResolver::getPublicYearId()`/`getStaffYearId()` auraient fait
   l'affaire, mais `ScoutYearResolver` porte aussi `getCurrentPublicYear()`,
   qui appelle `getCurrentYear()`, qui `INSERT`. Lire les deux réglages
   directement (avec les constantes `ScoutYearResolver::SETTING_*`, qui
   restent la seule définition des clés) enlève l'occasion de se tromper, et
   garde le service utilisable sans session comme IT-04 l'exige.
3. **L'aperçu n'est pas un paramètre.** Le document l'exige et la classe le
   documente : il n'y a aucun moyen d'en passer un, même par inadvertance.
4. **Le repli « pas de réglage → l'année date-calculée est l'année
   publique »** est repris de `ScoutYearResolver::getCurrentPublicYear()`,
   moins son `INSERT`. Sans lui, le seuil d'IT-02 ne voudrait pas dire la
   même chose sur un site fraîchement installé que sur un site configuré.
5. **L'horloge s'injecte** (`?\DateTimeImmutable $now`, l'idiome déjà utilisé
   par `Core\Alert\Check\*`) : c'est ce qui permet de tester la frontière du
   1er septembre des deux côtés sans écrire une seule date en dur.

**Les trois exigences de forme du document.**

- *Libellés dérivés de la date du test.* Aucun libellé n'est écrit en dur :
  les années de fixture viennent de `Tests\DatabaseTestHelper::scoutYear()`
  (±2 ans autour de l'année courante), et les deux dates injectées sont
  calculées comme le 31 août et le 1er septembre de l'année calendaire dans
  laquelle l'année scoute courante commence.
- *Un cas où la bonne année est la seconde.*
  `testTheStaffYearIsNotFirstInTheSet` assère que l'année staff est
  `$ids[1]`, qu'elle n'est pas `$ids[0]`, et qu'elle n'est pas l'année
  publique. Une implémentation qui lirait `$ids[0]` reste rouge.
- *Vérifier que chaque test mord.* Cinq mutations ont été appliquées au
  service, chacune rendant la suite rouge, puis retirées :

  | Mutation | Tests devenus rouges |
  |---|---|
  | Borne d'un an supprimée | `testYearTwoAheadOfThePublicYearIsDiscarded`, `testYearTwoBehindThePublicYearIsDiscarded`, `testDateComputedYearTwoAheadOfThePublicYearIsDiscarded` |
  | `findByLabel()` remplacé par `getCurrentYear()` | `testDateComputedYearIsAbsentOnThirtyFirstOfAugust`, `testResolvingCreatesNoScoutYearRow`, `testEmptySetWhenTheInstallationHasNoScoutYearAtAll` |
  | Année staff ignorée | `testStaffYearIsIncludedWhenConfigured`, `testTheStaffYearIsNotFirstInTheSet`, `testUnsetPublicSettingFallsBackToTheDateComputedYear` |
  | Repli sur l'année date-calculée supprimé | `testUnsetPublicSettingFallsBackToTheDateComputedYear` |
  | Seul le premier identifiant conservé | `testStaffYearIsIncludedWhenConfigured`, `testTheStaffYearIsNotFirstInTheSet`, `testUnsetPublicSettingFallsBackToTheDateComputedYear` |

  Le test d'architecture a été vérifié de la même façon : faire appeler
  `getCurrentYear()` au service rend `testNoAuthorizationYearFileCreatesAScoutYear`
  rouge, y référencer `ScoutYearSession::getPreviewId()` rend
  `testNoAuthorizationYearFileReadsTheSessionPreview` rouge. Le test lit les
  fichiers au **tokenizer** et non au `grep` : les docblocks de ces mêmes
  fichiers expliquent en toutes lettres pourquoi ils n'appellent pas
  `getCurrentYear()` ni ne lisent l'aperçu, et cette documentation ne doit
  jamais être ce qui fait échouer le test.

**Divergence constatée.** Le document décrit l'année date-calculée comme
« l'arithmétique de bascule automatique du 30 septembre ». Le dépôt bascule
le **1er septembre** : `ScoutYearService::labelForDate()` teste `month >= 9`,
et `DatabaseTestHelper::scoutYear()` documente la journée de tests rouges
que cette bascule a déjà coûtée. Les tests suivent le code, pas le document.

**Aucune documentation projet modifiée à cette itération**, délibérément :
le service n'est câblé nulle part, et documenter dans `ARCHITECTURE.md` une
règle que le code n'applique pas encore serait exactement la dérive que
`AGENTS.md` reproche à une documentation écrite après coup. La règle, son
seuil, sa borne et l'exclusion de l'aperçu sont documentés en IT-02, où ils
deviennent vrais.
