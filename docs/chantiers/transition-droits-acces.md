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
plus `tests/Core/ScoutYear/AuthorizationYearServiceTest` et
`tests/Architecture/AuthorizationYearsAreReadOnlyTest` (2 règles). Service
pur : câblé nulle part, aucun changement de comportement.

Trois sources alimentent le jeu — l'année publique (`current_scout_year_id`),
l'année date-calculée (`ScoutYearService::labelForDate()`, la bascule du
1er septembre) et l'année staff (`staff_scout_year_id`) — dédoublonnées,
puis bornées.

> **La borne a été corrigée pendant IT-02**, en écrivant le scénario A/B :
> telle que le document la décrivait (« un an d'écart, dans les deux
> sens »), l'état 3 du tableau de la situation ne tenait pas. Elle est
> maintenant « l'année publique, et celle qui la suit », et la raison est
> écrite dans la section IT-02 § Divergences, point 1. Les deux tests de
> borne du document restent verts : deux ans d'écart est écarté dans les
> deux sens.

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

---

## IT-02 — La résolution de rôle, la porte de connexion et la revalidation

**Livré.** `RoleResolver::resolveAcrossYears()` et
`isEmailAuthorizedToLoginAcrossYears()`, `SessionRevalidator::revalidate()`
qui reçoit désormais le jeu, `AuthController` (rôle, porte, membres liés),
le câblage dans `public/index.php`,
`tests/Integration/ScoutYearTransitionAccessTest` (13 cas, le scénario A/B
en entier), l'extension du test d'architecture d'IT-01 à `RoleResolver` et
`SessionRevalidator`, et la documentation (`ARCHITECTURE.md` §4,
`specifications.md` §16.5).

**Décisions autonomes.**

1. **Des méthodes parallèles plutôt qu'un changement de signature.** Le
   document laissait le choix « à toi de voir ce qui casse le moins
   d'appelants » : `resolve()` et `isEmailAuthorizedToLogin()` ont neuf
   appelants, dont six qui tiennent déjà un dossier rattaché à une année
   (`PersonalFeedService` ×3, `NotificationService`, `GroupRecipientResolver`,
   `NotificationPreferenceController`) et pour qui poser la question sur un
   jeu serait un bug, pas une amélioration (D6). La forme mono-année reste
   donc la primitive — c'est d'ailleurs elle qu'IT-03 utilise pour tester
   l'éligibilité *dans* l'année staff — et la forme « jeu » est ajoutée à
   côté. Zéro appelant cassé.
2. **La porte de connexion n'applique aucun seuil**, contrairement à la
   résolution de rôle. Le seuil décide de *ce qu'on peut faire* ; la porte
   décide si l'on entre. Un animé inscrit pour l'année préparée et pour
   aucune autre est un membre de l'unité : il se connecte, et son espace
   reste vide jusqu'à la bascule (D4 le dit en toutes lettres). Le refuser
   reviendrait à dire à une famille déjà inscrite que le site ne la connaît
   pas.
3. **L'ordre « compte désactivé » puis « super-admin » est conservé** dans
   la nouvelle méthode, avec le commentaire d'origine : dans l'autre sens,
   un super-admin désactivé — précisément le compte qu'un exploitant veut
   pouvoir fermer — continuerait de se connecter.

### `getLinkedMemberYears()` — conclusion et raison

**Non touché, et c'est la conclusion, pas un oubli.** Le mémo de la
tentative précédente le listait comme à modifier, mais sous une conception
où tout le monde recevait la même année élargie. Ici, il relève de la
**portée des données** (D5) : il répond « quels dossiers de membre cette
adresse atteint », une question sur des lignes. L'élargir à deux années
mettrait côte à côte le dossier d'un même enfant dans l'année qui se termine
et dans celle qu'on prépare — deux lignes pour une personne dans la
navigation « ses membres », et un sélecteur proposant une section morte.
C'est exactement ce que D5 ferme.

Ce qui change est **l'année qu'on lui passe**, et c'est D6 appliqué :
`AuthController::storeLinkedMembers()` la demandait à l'année publique, il
la demande maintenant à l'**année effective** de la personne — celle qu'elle
est réellement servie, et sous cette conception la seule où elle a vraiment
des membres. Sans ce changement, l'animateur entrant se connectait sur un
site qui venait de l'accepter et repartait avec une liste vide.

Ce que cette liste sert, vérifié plutôt que supposé : elle est écrite une
seule fois (`AuthSession::setLinkedMembers()`, à la connexion) et lue à un
seul endroit, `Modules\Camps\Controller\CampsAttachmentController`, pour
attribuer un avis de camp à un membre. L'en-tête du site, lui, ne s'en sert
pas : `public/index.php` recalcule `getLinkedMembers()` sur l'année
effective à chaque requête.

### Divergences constatées avec le document de chantier

1. **La borne n'est pas symétrique, et le tableau du document l'exige.**
   IT-01 disait « écarter toute année à plus d'un an d'écart de l'année
   publique, dans les deux sens ». Appliqué littéralement, l'état 3 du
   tableau de la situation (« Année publique 2026-2027 → A : plus d'accès
   chef ») **ne tient pas** : une unité qui bascule le site en août a, du
   jour de la bascule au 31 août, une année date-calculée en *retard* d'un
   an sur l'année publique. Cette année-là est celle où A est chef ; à un an
   d'écart, elle serait retenue, et A garderait son accès chef pendant les
   semaines qui suivent une bascule que l'unité a faite exprès pour le lui
   retirer.

   La borne implémentée est donc : **l'année publique, et celle qui la suit
   immédiatement**. Deux ans d'écart reste écarté dans les deux sens (le
   test que le document demande est vert), et une année *derrière* l'année
   publique est écartée aussi. La raison tient en une phrase : les deux
   mécanismes qui mettent légitimement une année en jeu la poussent en
   **avant** (l'activation de l'année staff, et le 1er septembre) ; une
   année en retard signifie que quelqu'un a basculé le site en avance, ce
   qui est une décision explicite. `testYearOneBehindThePublicYearIsDiscardedToo`
   et `testDateComputedYearBehindThePublicYearIsDiscarded` la tiennent.

2. **« B peut se connecter » dans l'état 1 du tableau est optimiste.** Le
   document affirme que rien n'est à faire de ce côté parce que
   `DeskImportService` crée la ligne `user_accounts` et que
   `AuthService::requestMagicLink()` ne regarde aucune année. C'est vrai de
   la *demande* de lien : le mail part. Mais `AuthController` appelle
   ensuite `isMemberAuthorized()`, qui exige une adhésion dans une année
   candidate — et dans l'état 1 l'année de B n'en est pas une. B reçoit donc
   son lien et se voit refuser la session, exactement comme avant ce
   chantier. Ce n'est pas une régression et ce n'est pas non plus corrigé
   ici : élargir la porte au-delà du jeu d'années serait inventer une
   portée que le document ne demande pas. `testPublicYearOnly` fige le
   comportement réel.

3. **Le seuil `intendant` ne change aucun résultat aujourd'hui**, et il est
   quand même écrit. Sous l'échelle de rôles actuelle (`public` 0,
   `identified` 1, `intendant` 2…), tout rôle en dessous du seuil vaut au
   plus le plancher `identified` qu'une année apporte de toute façon : le
   retirer ne rend aucun test rouge. Il est écrit parce que D4 énonce la
   règle comme « intendant » et non comme « ce que l'échelle rend
   inoffensif », et il devient porteur dès qu'un rôle est inséré entre les
   deux. `testTheThresholdIsPinnedToTheRoleLadderThatMakesItHarmlessToday`
   dit cela à voix haute et échoue le jour de cette insertion, en nommant
   le cas à ajouter.

### Vérification que les tests mordent

| Mutation | Tests devenus rouges |
|---|---|
| La revalidation juge sur l'année publique seule (divergence porte/revalidation) | `testBSurvivesTheRequestAfterSigningIn` |
| Seule l'année publique compte dans `resolveAcrossYears()` | `testStaffYearOpenGivesBothOfThemChiefAccess`, `testIntendantInTheStaffYearDoesElevate`, `testBSurvivesTheRequestAfterSigningIn`, `testBsGrantedRoleClearsChiefAndStopsBelowAdmin` |
| Seuil supprimé | **aucun** — voir la divergence 3 ci-dessus, c'est le constat, pas un trou de couverture |
| Borne asymétrique supprimée | `testYearOneBehindThePublicYearIsDiscardedToo`, `testDateComputedYearBehindThePublicYearIsDiscarded`, `testPublicYearSwitchedOverEndsAsAccessAndKeepsBs` |

---

## IT-03 — L'année servie

**Livré.** `ScoutYearResolver` : l'éligibilité à l'année staff se teste sur
le rôle résolu **dans l'année staff elle-même**
(`setStaffYearEligibility()`, mémoïsé, câblé par `public/index.php`), le
docblock de la classe est réécrit, et une nouvelle méthode
`getAuthorizationYear()` sépare « quelle année afficher » de « dans quelle
année juger qui est l'appelant ». Six appelants en requête basculent
dessus. Tests : quatre nouveaux cas dans `ScoutYearResolverTest`, six dans
`ScoutYearTransitionAccessTest`, `ScoutYearTransitionTest` mis à jour.
Documentation : `ARCHITECTURE.md` §8.26, `specifications.md` §16.2, un
sujet d'aide.

**Décisions autonomes.**

1. **Un `callable` injecté par un setter plutôt qu'un paramètre de plus sur
   `getEffectiveYear()`.** La méthode a une quinzaine d'appelants, tous des
   contrôleurs ; leur ajouter un argument aurait été quinze occasions d'en
   oublier un, et un oubli est silencieux — la personne retombe sur l'année
   publique. `ScoutYearResolver` ne touche jamais la session et ne connaît
   aucune adresse, donc seul le root de composition peut répondre. Non
   câblé, aucune année staff n'est servie : c'est le sens fermé, et
   `testStaffYearIgnoredWhenNoEligibilityIsWired` le fige.
2. **Mémoïsation par année.** Une page résout l'année effective une dizaine
   de fois, et la réponse coûte une résolution de rôle, donc plusieurs
   requêtes SQL. `testEligibilityIsResolvedOncePerYear` le tient.
3. **Le seuil global disparaît.** `getEffectiveYear()` ne teste plus
   `$role->hasAccess(Role::INTENDANT)` du tout : c'est le rôle *dans
   l'année visée* qui décide, et le rôle global n'ajoute rien qu'il ne
   dirait déjà (un rôle atteignant `intendant` dans l'année staff élève
   déjà le rôle global, par IT-02).
4. **`getAuthorizationYear()` ne prend aucun paramètre.** L'aperçu était la
   seule branche de `getEffectiveYear()` à lire un rôle, et cette méthode
   ne la prend pas. Zéro plomberie chez les appelants.

### Revue des appelants en requête — le point où le document se trompe

Le document affirme que « les onze sites du module locations continuent de
poser leur question dans une seule année — la bonne pour celui qui la
pose », et demande de le vérifier plutôt que de le croire. **Vérifié, et
c'est faux** : aucun de ces sites ne posait sa question dans l'année
effective. Ils la posaient dans l'année **date-calculée**
(`ScoutYearService::getCurrentYear()`), qui n'est ni l'année publique ni
l'année servie, et qui en plus **crée une ligne** en passant.

| Appelant | Année utilisée avant | Verdict |
|---|---|---|
| `RentalManagementController` (259, 500, 531, 2460, 755) | `getCurrentYear()`, via un `scoutYearId()` privé | **Exception réelle, traitée** → `getAuthorizationYear()` |
| `RentalPricingController:360` | `getCurrentYear()` | **Traitée** → `getAuthorizationYear()` |
| `RentalPublicController:97, 165` | `getCurrentYear()` | **Traitée** → `getAuthorizationYear()` |
| `RentalMenuHookService:82` | `getCurrentYear()`, passée par le root | **Traitée** → le root passe `getAuthorizationYear()` |
| `BannerConfigController:248` | `getCurrentYear()` | **Traitée** → `getAuthorizationYear()` |
| `RetroConfigController:188`, `RetroBoardController:97` | année effective, **aperçu compris** | **Traitée** → `getAuthorizationYear()` (D1) |
| `retro` (contenu du board) | année effective | Inchangé : c'est de la portée de **données**, pas d'accès |
| `SectionStaffAuthorizationService::getStaffedSections` | l'année qu'on lui passe | **Inchangé, et c'est la démonstration** : non vide pour A comme pour B, chacun dans son année, sans une ligne modifiée |
| `MemberService::isUnitChief` / `getLinkedMembers` | l'année qu'on leur passe | **Inchangés**, même raison |
| `MemberSearchController:206`, `MemberController:105` | dérivée du `member_year` jugé | **Inchangés** (D6). Les élargir serait un bug |

Pourquoi c'était une exception réelle et non un détail : sans ce
changement, la promesse du chantier — « B franchit les portes » — n'était
pas tenue pour le module locations ni pour la bannière. B, chef d'unité de
l'année préparée, se serait vu répondre `Forbidden` par la configuration de
la bannière et n'aurait eu aucune entrée « Mes locations ». Et le
comportement bascule tout entier le 1er septembre : avant cette date
l'année date-calculée reconnaît A et pas B, après elle reconnaît B et pas
A, sans que rien sur le site n'ait changé.

**Un trou d'autorisation trouvé en passant, et refermé.** `retro` posait
déjà sa question dans l'année effective — le module avait identifié le
problème du 1er septembre avant nous — mais **aperçu compris**. Or
`isUnitChief()` ouvre les commentaires masqués et la modération : c'est
une décision d'autorisation, et D1 l'interdit. Un `chief` qui fut Staff
d'U en 2019-2020 pouvait prévisualiser cette année-là et récupérer la
configuration de la rétro et de la bannière. `getAuthorizationYear()`
ferme cela pour les deux modules à la fois, sans rien coûter au cas qui
avait motivé le passage à l'année effective : l'année staff et l'année
publique y entrent toujours, seul l'aperçu en sort.

### Vérification que les tests mordent

| Mutation | Tests devenus rouges |
|---|---|
| L'éligibilité redevient `$role->hasAccess(Role::INTENDANT)` | `testAIsServedTheYearThatIsEnding`, `testStaffedSectionsAreNonEmptyForBothOfThemInTheirOwnYear`, `testStaffYearRefusedToAnAdminWhoIsNotIntendantInThatYear`, `testStaffYearIgnoredWhenNoEligibilityIsWired`, `testEligibilityIsAskedAboutTheStaffYearItself`, `testEligibilityIsResolvedOncePerYear` |

---

## IT-04 — Les appelants hors requête

**Livré.** `RentalAuthorizationService` gagne trois méthodes en forme de
jeu (`isUnitStaffInAnyYear`, `canManageAssetIdInAnyYear`,
`listManageableAssetsInAnyYear`), `VirtualEventViewer` porte désormais les
années d'autorisation de son lecteur, `PersonalFeedService` les lui donne,
et `RentalVirtualEventProvider` s'en sert. Tests : cinq cas dans
`RentalAuthorizationServiceTest` (dont la non-régression explicite), un
dans `RentalVirtualEventProviderTest`, un dans
`ScoutYearTransitionAccessTest` pour l'appelant qui dérive son année.

### Les cinq appelants, un par un — et le second point où le document se trompe

Le document les décrit comme « n'ayant ni session ni année effective ».
**Quatre des cinq en ont une** : ils tournent à l'intérieur d'une requête,
avec `AuthSession::getEmail()` dans les mains. Ce qu'ils n'avaient pas,
c'était la *bonne* année — ils prenaient l'année date-calculée, ce qui est
le défaut d'IT-03 et non un cas hors requête.

| Appelant | Dérive-t-il une année du dossier ? | Verdict |
|---|---|---|
| `RentalComplianceOwnershipChecker:58` | Non — un bien ne porte pas d'année scoute | **En requête** (`/files/{id}`, session présente) → année d'autorisation d'IT-03 |
| `RentalDocumentOwnershipChecker:85` | Non — une réservation non plus | **En requête** → année d'autorisation |
| `RentalMessageConsumer:653` | Non | **En requête** sur le chemin web ; sur le chemin planifié, les trois dépendances sont nulles et `canRead()` répond `false` sans poser de question |
| `RentalCommunicationService:284, 349` | Non | **En requête** : l'année leur est passée par `RentalManagementController` |
| `public/index.php` (audit de réservation) | Non | **En requête** : une fermeture qui lit `AuthSession::getEmail()` |
| `RentalVirtualEventProvider:200` | Non | **Le seul vrai cas hors requête**, et seulement par un chemin : le jeton ICS personnel (`PersonalFeedService`), qui n'a aucune session. Par `CalendarPublicController`, le lecteur est anonyme et n'obtient jamais le détail |

**Aucun des cinq ne peut dériver une année, et ce n'est pas un hasard** :
`modules/rental/schema.sql` refuse délibérément `scout_year_id` sur ses
tables, en écrivant pourquoi — « une réservation du 28 août au 2 septembre
est à cheval sur deux années scoutes ». L'exception D6 n'a donc aucun cas
dans ce module, par construction. Elle en a un ailleurs, et il est testé :
`MemberController`/`MemberSearchController` lisent l'année sur le
`member_year` qu'ils s'apprêtent à écrire
(`testACallerDerivingItsYearFromTheRecordIsUnaffectedByTheStaffYear`).

**Le seul élargissement livré est donc le jeton ICS personnel**, et il est
étroit : `VirtualEventViewer` porte un jeu d'années *d'autorisation*
distinct de l'année dont il affiche le contenu. Le contenu du flux reste
sur une seule année, comme toute liste (D5) ; le jeu ne dit que dans
quelles années un fournisseur peut établir un droit. Sans lui, un
gestionnaire de bien recruté pour l'année préparée recevrait dans son
agenda une plage occupée anonyme là où son collègue reçoit la réservation
— exactement le mode de panne asymétrique que le document décrit.

**La conclusion à retenir**, et elle contredit l'intuition du document :
sous cette conception, l'élargissement au jeu d'années n'est presque jamais
nécessaire, parce que presque personne n'est réellement hors requête. Ce
qui manquait n'était pas de l'ampleur, c'était la bonne année.

### Un sixième appelant, trouvé et traité

Le document en listait cinq. Il en existe un sixième, du même genre et sur
un chemin plus visible : `Core\Notification\NotificationService::dispatch()`
revérifie le `role_min` de **chaque destinataire** en résolvant son rôle,
et le faisait dans l'année date-calculée. Il tourne aussi bien en requête
que depuis le vrai crontab, où il n'y a strictement aucune session.

Conséquence pendant une transition : l'animateur recruté pour l'année
préparée est filtré hors de **toutes** les notifications que son nouveau
rôle lui vaut, sur des écrans que le site lui ouvre par ailleurs. C'est mot
pour mot le mode de panne que le document invoque pour justifier
l'élargissement — « un chef jamais notifié rate une demande de réservation
ou une échéance de conformité » — donc il est traité ici plutôt que
signalé. `dispatch()` et `recipientsForType()` jugent désormais sur le jeu,
dans les deux points d'entrée : `NotificationRoleWiringTest` fige les deux,
parce que le câblage dans un seul des deux est précisément la panne que ce
fichier de test existe déjà pour attraper (ARCHITECTURE.md §8.17), et
`testTheStaffOfTheYearBeingPreparedStaysAmongTheRecipients` fige le
comportement — il redevient rouge dès qu'on retire le jeu.

### Un défaut de cette itération, trouvé en relisant son propre diff

La mémoïsation de l'éligibilité vivait d'abord dans `ScoutYearResolver`,
**clé sur la seule année**. C'est faux, et d'une façon qui n'aurait
échoué nulle part dans la suite : une requête résout l'année effective une
première fois dans le front controller — où une requête de connexion est
encore anonyme — puis une seconde fois dans le contrôleur, après
`AuthSession::login()`. La seconde question recevait la réponse de la
première, donc l'animateur entrant se connectait et le site enregistrait
ses membres liés dans l'année publique, où il n'en a aucun. L'identité
change au milieu de la requête, donc l'identité fait partie de la clé.

Le cache est descendu dans `Core\ScoutYear\StaffYearEligibility`, la
seule couche qui connaît l'adresse ; `ScoutYearResolver` ne cache plus
rien et son test dit désormais qu'il redemande.
`StaffYearEligibilityTest::testTheAnswerFollowsTheAddressWhenAnIdentityAppearsMidRequest`
fige le cas.

---

## Récapitulatif

**Ce qui a été livré**, en quatre commits sur la branche du chantier :

| # | Livré |
|---|---|
| IT-01 | `AuthorizationYearService` + `AuthorizationYears`, le test d'architecture (lecture seule, pas d'aperçu), 14 cas unitaires |
| IT-02 | `RoleResolver::resolveAcrossYears()` / `isEmailAuthorizedToLoginAcrossYears()`, `SessionRevalidator`, `AuthController`, le scénario A/B complet |
| IT-03 | L'éligibilité à l'année staff testée dans l'année staff, `getAuthorizationYear()`, six appelants en requête corrigés, un trou d'aperçu refermé |
| IT-04 | Les méthodes en forme de jeu de `RentalAuthorizationService`, le jeton ICS personnel, la revérification de `role_min` des notifications |

**Aucun `schema.sql` n'a été touché** : aucune table, aucune colonne, aucun
bump de version de module. Le chantier n'en a pas eu besoin.

**Les deux constats qui contredisent le document**, et qu'il faut retenir
si quelqu'un rouvre ce sujet :

1. **La borne d'années n'est pas symétrique.** Une année *derrière* l'année
   publique ne compte pas, quelle que soit la distance. Sans cela, l'état 3
   du tableau de la situation ne tient pas.
2. **Presque personne n'est réellement hors requête.** Sur les cinq
   appelants que le document décrit ainsi, quatre tournent dans une requête
   avec une session. Ce qui leur manquait n'était pas de l'ampleur, c'était
   la bonne année : ils interrogeaient l'année date-calculée, qui
   n'appartient à personne entre le 1er septembre et la bascule — et qui
   crée une ligne au passage.

**Rien n'a été reporté** au-delà de ce que le document reporte lui-même
(l'élargissement de la portée des données, et la tolérance des écrans chefs
à un chef sans section — qui ne se produit plus sous cette conception).
