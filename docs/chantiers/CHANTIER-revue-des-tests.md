# Chantier — Revue des tests automatisés et des spécifications

Document de chantier. Il complète `AGENTS.md`, `CONTRIBUTING.md` et
`docs/quality-pipeline.md`, qui restent la source de vérité des règles. Il ne
les recopie pas.

Son objet n'est pas le code produit : c'est **le filet**. Au moment où ce
chantier s'ouvre, le dépôt porte 1 179 fichiers de test, 14 848 méthodes,
369 733 lignes — plus de code de test que de code produit. Personne n'a jamais
relu cet ensemble comme un tout, et il a grossi commit par commit, chacun
ajoutant ses tests sous la pression de la règle « tests obligatoires » de
`AGENTS.md`.

La question posée est donc précise, et ce n'est pas « y a-t-il assez de
tests ? » : **ces 14 848 méthodes échoueraient-elles si le produit cassait ?**
Un test qui ne peut pas échouer coûte son temps d'exécution, occupe la place
d'un vrai test, et fait pire que rien — il rend le vert menteur. C'est
exactement le mode de défaillance que `docs/quality-pipeline.md` documente
déjà, appliqué cette fois au filet lui-même.

Le second objet est les **spécifications** : `specifications.md` (2 555
lignes), `design.md` (1 050), `ARCHITECTURE.md` (4 777), `SECURITY.md` (809),
`README.md` (366). Ils décrivent un produit qui a beaucoup bougé. Ce qu'ils
décrivent faux est plus dangereux que ce qu'ils omettent, parce que le code
s'écrit contre eux.

---

## 0. Règles communes à toutes les itérations

### 0.1 Ce chantier corrige — mais pas n'importe quoi

À la différence de la revue de code, celui-ci **commite**. Deux régimes, et la
frontière entre les deux n'est pas une question de goût :

**Corriger tout de suite** — les quatre conditions doivent être réunies :

1. le défaut **et** sa correction tiennent dans un seul fichier, ou dans un
   fichier et son test ;
2. ce qui est faux l'est **au regard d'une règle déjà écrite** (une section de
   `ARCHITECTURE.md`, un commentaire du code, une assertion voisine) — pas au
   regard d'un jugement formé pendant la lecture ;
3. la correction est prouvée par un test **qui échoue avant et passe après** ;
4. elle ne touche ni le schéma, ni une frontière d'autorisation, ni le
   composition root, ni un service du cœur utilisé par plus de trois modules.

**Ouvrir une issue** — dès qu'une seule condition manque, et systématiquement
quand le correctif suppose un arbitrage produit, quand il change ce qu'un
utilisateur voit, ou quand il faudrait d'abord décider ce que le
comportement *devrait* être. Dans le doute, l'issue : une correction hâtive
dans un filet qu'on est en train d'auditer est la pire des séquences.

Les issues sont en français, portent le seul label `triage:pending`, décrivent
le défaut sans proposer le correctif, et ne contiennent aucune donnée
personnelle. Le triage automatique fait le reste — ne jamais poser `triage:done`
ni un label `bug:*` à la main. Une même faiblesse répétée dans N fichiers fait
**une** issue qui les liste.

Le site n'est en production nulle part : une faille s'ouvre en issue publique
comme le reste. Décision à rouvrir le jour où une unité tourne pour de vrai.

### 0.2 Le cas particulier du test renforcé qui devient rouge

C'est la situation centrale de ce chantier, et elle se produira souvent :
renforcer un test faible révèle un vrai bug dans le produit.

La règle, dans l'ordre :

1. Le renforcement est bon — il a fait son travail.
2. Si le bug tombe sous §0.1 « corriger tout de suite », corriger le produit et
   garder le test renforcé. C'est le cas idéal, et le seul où les deux se
   commitent ensemble.
3. Sinon : **retirer le renforcement de la PR**, ouvrir l'issue, et coller le
   test renforcé dans le corps de l'issue. Il devient la reproduction, et le
   futur correctif n'aura qu'à le reprendre.

Ne jamais affaiblir le test pour le faire passer, ne jamais le marquer
`markTestSkipped` avec un commentaire, ne jamais fusionner rouge. Un test
désactivé pour cause de bug est une dette invisible : c'est précisément ce que
ce chantier cherche.

### 0.3 Comment on prouve qu'un test sert à quelque chose

Par **mutation ciblée**, jamais par lecture :

> On casse délibérément la ligne de production que le test prétend couvrir —
> inverser une condition, retirer un `where`, retourner une constante, sauter
> un appel — on relance ce seul test, et on regarde s'il devient rouge.

Un test qui reste vert sur une mutation évidente est un constat. La mutation
est ensuite **annulée** (`git checkout --` sur le fichier produit) : aucune
mutation ne part dans une PR.

Pas d'outil de mutation automatique sur 370 000 lignes — le coût serait
absurde. Chaque itération fixe son échantillon, dit lequel elle a muté, et le
journal le consigne.

### 0.4 Ce qu'une itération livre

Ce fichier n'est pas dans le dépôt : il arrive en pièce jointe. **La PR de
l'itération 1 le dépose en `docs/chantiers/CHANTIER-revue-des-tests.md`**, tel
quel, avant d'y écrire sa propre entrée de journal.

Ensuite, une PR par itération contenant :

- les corrections relevant de §0.1, chacune avec son test ;
- les tests renforcés qui passent ;
- la suppression des tests morts (§1) ;
- l'entrée de journal, ajoutée au §9 de ce fichier.

Le journal dit **ce qui a été muté et a tenu**, pas seulement ce qui a cassé.
Sans cette moitié, une session ultérieure ne saura pas distinguer « vérifié,
solide » de « pas regardé ».

Rebase sur `main`, suite complète, auto-merge armé une fois `All checks` vert
sur le head courant, puis l'itération suivante depuis `main` à jour.

### 0.5 Ne jamais confondre couverture et preuve

Le rapport Clover produit par `checks.yml` mesure les lignes **exécutées**. Un
test qui appelle une méthode sans rien vérifier de son résultat la couvre à
100 %. Aucun chiffre de couverture n'est une conclusion dans ce chantier ; il
sert seulement à choisir où regarder.

### 0.6 Ne pas refaire ce qui tourne déjà

32 gardes dans `tests/Architecture`, 17 dans `tests/Security`, 16 dans
`tests/Integration` surveillent déjà une partie de ces questions —
`ScheduledTasksAreTestedTest`, `BackgroundWorkIsNotSilentTest`,
`ModuleSpecificationCoverageTest`, `AuthorizationMatrixInventoryTest`,
`QueryBudgetTest`. Les lire avant de chercher : plusieurs itérations
ci-dessous consistent à **étendre un garde existant**, pas à en écrire un
nouveau à côté.

---

## 1. Les tests qui ne peuvent pas échouer

**Question** : combien des 14 848 méthodes passeraient encore si la
fonctionnalité qu'elles nomment était supprimée ?

**Pistes déjà mesurées, à traiter en premier parce qu'elles sont certaines** :

- **20 `assertTrue(true)` littéraux** — `RentalPaymentServiceTest`,
  `AutoCreateRetroHandlerTest` (deux fois), `StoredFileCleanerTest` (deux
  fois), `ProcessPhotoHandlerTest`, `MigrateAlbumStorageHandlerTest`,
  `ProcessVideoHandlerTest`, et le reste. Certains sont un « ça ne lève pas
  d'exception » légitime — mais alors l'assertion honnête est sur l'état
  observable après l'appel, pas sur `true`. Pour chacun : soit une vraie
  assertion, soit le test disparaît.
- **Deux fichiers sans une seule assertion** :
  `tests/Modules/Camps/Mail/CampsMailNotifierTest.php` et
  `tests/Modules/Finance/Mail/FinanceMailNotifierTest.php`. Ils exercent
  l'envoi de mail de deux modules et ne vérifient rien. Ce sont deux
  notificateurs qui peuvent être cassés depuis longtemps sans que rien ne
  l'indique.

**Ce qui doit être vérifié, au-delà de ces deux listes** :

- Les tests dont **toutes** les assertions sont `assertNotNull`,
  `assertIsArray`, `assertInstanceOf` ou `assertCount` : ils vérifient la forme,
  jamais la valeur. Muter la valeur retournée et voir.
- Les tests qui **construisent l'attendu depuis le code testé** — appeler le
  service pour fabriquer la valeur de référence, puis la comparer à elle-même.
- Les tests dont le nom promet plus que le corps : `test…IsEncrypted` qui
  vérifie seulement que la colonne n'est pas vide, `test…IsRejected` qui
  vérifie un code HTTP sans vérifier que rien n'a été écrit.
- Les `try/catch` qui avalent l'échec attendu sans `fail()` dans la branche
  qui ne devrait pas être atteinte.

**Échantillon de mutation imposé** : au moins un test par module (23), choisi
parmi ceux qui nomment la fonctionnalité la plus centrale du module.

**Régime** : supprimer un test mort et corriger une assertion molle relèvent de
§0.1 — c'est l'objet même du chantier. Un notificateur réellement cassé
découvert au passage relève de §0.2.

---

## 2. Le vert qui saute

**Question** : que ne s'exécute-t-il pas, et qui le saurait ?

**Ce qui est mesuré** : 75 `markTestSkipped`. Les motifs se répartissent entre
des contraintes d'environnement légitimes (pas de chiffrement AES dans le zip,
pas de liens symboliques, pas d'`imagick`, pas de Ghostscript, pas de
libsodium) et **une quinzaine conditionnés à la disponibilité d'une base**
(`Database not available`, `No MySQL server configured (TEST_DB_HOST unset)`).

`checks.yml` fournit bien MySQL 8 et MariaDB 10.11 avec les `TEST_DB_*`. Mais
**rien n'assure que ces tests s'exécutent vraiment** : si une variable
disparaissait d'un job, ils sauteraient tous, PHPUnit resterait vert, et le
job aussi. Un pan entier d'intégration cesserait d'être vérifié sans le moindre
signal — dans un dépôt qui fait tourner deux moteurs de base *exprès*.

**Ce qui doit être vérifié** :

- **Chaque motif de saut** : est-il encore vrai dans l'environnement CI
  d'aujourd'hui ? Un saut écrit pour une contrainte levée depuis est un test
  qu'on croit avoir.
- **Le compte de sauts en CI** : le rapport JUnit
  (`evidence/phpunit-mysql8.xml`) le contient déjà. Combien de `skipped` sur
  le job MySQL, combien sur MariaDB, et lesquels ?
- **Le garde qui manque** : un test d'architecture qui échoue si un saut
  motivé par la base survient alors que `TEST_DB_HOST` est défini. C'est la
  forme exacte de `TimedFeaturesWarnAboutCronTest` ou
  `BackgroundWorkIsNotSilentTest` — le dépôt a déjà ce réflexe, il ne l'a pas
  appliqué ici.
- **Les tests qui ne tournent que sur un moteur** : le job MariaDB exclut-il
  des groupes ? Si oui, lesquels, et la divergence MySQL/MariaDB
  est-elle réellement couverte là où elle compte (types de dates, collation,
  mots réservés) ?
- **Les `@group` et les exclusions de `phpunit.xml`** : un groupe exclu par
  défaut qu'aucun job n'inclut jamais ne s'exécute nulle part.

---

## 3. Les doublures qui remplacent le sujet

**Question** : parmi les 291 fichiers qui montent une doublure, combien
testent le système et combien testent le montage ?

Une doublure est légitime pour **sortir du périmètre** — le réseau, l'horloge,
un fournisseur tiers. Elle devient un problème quand elle remplace ce que le
test prétend vérifier : simuler le Repository puis vérifier que le Service l'a
appelé avec tel argument ne prouve que le câblage. Le SQL, le chiffrement, la
contrainte d'unicité, la sérialisation — tout ce qui casse vraiment — n'est
jamais exercé.

**Ce qui doit être vérifié** :

- Pour chaque doublure : **que remplace-t-elle ?** Un tiers hors périmètre
  (`PhoneProviderInterface`, `GitHubReleaseClientInterface`,
  `LlmConnectorInterface`, backends S3) : légitime. Un Repository, un service
  de chiffrement, un sanitizer, `MailService` : suspect.
- Les tests dont **toutes** les assertions portent sur la doublure
  (`expects($this->once())`) et aucune sur l'état résultant.
- Les doublures qui **acceptent tout** : une méthode simulée sans contrainte
  d'argument laisse passer un appel avec les mauvais paramètres.
- Le cas inverse, plus discret : une doublure dont le comportement **ne
  correspond plus** à l'objet réel — un retour `null` là où le vrai service
  lève désormais, un tableau dont les clés ont changé. Le test passe, le
  produit casse. Chercher en confrontant la doublure à la signature réelle.
- **Les tests qui touchent vraiment la base** : combien, dans quels modules, et
  les modules à faible ratio (`presences` 23/10, `fees` 42/22, `rental`
  111/53) en ont-ils ?

---

## 4. La couverture fonctionnelle, lue depuis les spécifications

**Question** : chaque comportement promis par les specs est-il vérifié ?

**Pourquoi ce sens de lecture** : partir du code pour chercher ses tests ne
trouve jamais ce qui manque — un comportement jamais implémenté n'a pas de
ligne à couvrir, et n'apparaît dans aucun rapport. Seule la descente depuis
`specifications.md` trouve les trous. `ModuleSpecificationCoverageTest` fait
déjà ce trajet ; cette itération regarde **ce qu'il laisse passer** et
l'étend.

**Ce qui doit être vérifié, section par section de `specifications.md`** :

- Les **règles chiffrées** : fenêtre de bascule 1er août – 29 septembre,
  expiration à 15 minutes des liens magiques et des confirmations d'e-mail,
  rétention 13 mois du consentement, 90 jours des notifications lues, quota de
  5 sauvegardes, budget de votes en rétrospective. Chacune est un test à
  écrire ou à retrouver — et chaque **borne** compte : la veille, le jour même,
  le lendemain.
- Les **transitions d'état** : les trois états d'un e-mail secondaire
  (pending/active/deactivated) et les passages interdits entre eux ; les quatre
  étapes de la transition d'année ; les statuts d'une mise à jour
  (`backing_up` → … → `rolled_back`).
- Les **règles négatives**, les moins testées partout : « jamais de
  contournement chef/admin », « une section masquée disparaît de tous les
  sélecteurs », « un badge attribué ne peut plus être supprimé », « un article
  réservé n'expose pas sa couverture ». Une règle négative ne se teste que par
  une tentative qui doit échouer.
- Les **modules à faible ratio** : `presences`, `fees`, `rental`, `gallery`,
  `inbound_mail`, `mass_mail`. Pour chacun, lister depuis la spec les
  comportements promis et pointer ceux sans test.

**Régime** : écrire un test manquant qui passe du premier coup relève de §0.1.
Un test manquant qui, une fois écrit, révèle que le comportement promis n'est
pas implémenté relève de §0.2 — c'est un écart produit, pas un défaut de test.

---

## 5. Les frontières de rôle et d'appartenance

**Question** : `AGENTS.md` exige, pour chaque route, un test d'intégration
vérifiant l'accès **au `role_min` et le refus un cran en dessous**. Sur ~520
routes déclarées, où en est-on réellement ?

**Ce qui est déjà couvert** : `AuthorizationMatrixInventoryTest` et le profil
standard de `dast.sh` rejouent chaque route sous chaque rôle et comparent au
`role_min` **déclaré**.

**Ce qui doit être vérifié** :

- **L'inventaire est-il complet ?** Une route ajoutée et absente de la matrice
  n'est refusée par personne. Le garde échoue-t-il, ou se contente-t-il de ce
  qu'on lui donne ?
- **Le cran en dessous est-il vraiment testé**, route par route, ou seulement
  le cran d'accès ? Le second seul ne prouve rien — une route ouverte à tous
  passe le test d'accès.
- **L'appartenance, pas seulement le rôle** : deux `chief` de sections
  différentes, deux membres d'une même famille, deux `intendant` face au compte
  d'un autre. `MemberService::canAccess()`,
  `MemberEmailService::isOwnMember()`, `files.owner_member_id` via
  `FileAccessGuard` portent des règles explicitement sans contournement — un
  test par règle, avec la tentative qui doit échouer.
- **Le POST autant que le GET** : une route d'écriture protégée uniquement par
  l'écran qui y mène.
- **Ce que voit un `public`** : les routes `role_min: public` (44 déclarées
  côté modules) exposent-elles ce que la spec dit qu'elles exposent, et rien de
  plus ?

---

## 6. Les chemins d'échec

**Question** : les tests ne décrivent-ils que ce qui se passe quand tout va
bien ?

C'est le biais le plus universel d'une suite écrite sous obligation : on teste
ce qu'on vient d'implémenter, c'est-à-dire le cas nominal.

**Ce qui doit être vérifié** :

- **Chaque `catch` du produit a-t-il un test qui l'atteint ?** Une branche
  d'erreur jamais exécutée en test est une branche jamais exécutée, point — sa
  première exécution sera en production.
- **Les retours en arrière** : `InstallUpdateHandler` (statut `rolled_back`),
  `RestoreBackupHandler`, les transactions. Un rollback non testé est un
  rollback qui n'existe pas.
- **Les écritures partielles** : fichier écrit sans ligne en base, ligne sans
  fichier. Que vérifie le test après l'échec simulé — le message, ou l'état ?
- **Les entrées refusées** : MIME invalide, PDF non conforme, CSV mal formé,
  montant négatif, date hors bornes, champ trop long. Pour chacune, le test
  vérifie-t-il **aussi** que rien n'a été écrit ?
- **Le nettoyage** : les fichiers temporaires supprimés « succès ou échec »
  (`SECURITY.md` §5, CSV Desk et relevés bancaires) — testés sur la branche
  échec, ou seulement sur la branche succès ?
- **La concurrence** : double soumission, deux tâches sur la même ressource,
  reprise après dépassement de budget.

---

## 7. Le navigateur : Vitest et Playwright

**Question** : les 124 fichiers Vitest et les parcours Playwright
vérifient-ils ce que voit un utilisateur, ou la forme interne du code ?

**Ce qui doit être vérifié** :

- **Vitest teste-t-il la logique réellement embarquée** dans les 110 fichiers
  de `public/assets/js`, ou une version recopiée dans le test ? Une fonction
  dupliquée dans le fichier de test est un test de lui-même.
- **Les assertions Playwright portent-elles sur du visible** (un texte, un état
  de bouton, une ligne apparue) ou sur un sélecteur interne qui changera à la
  prochaine retouche CSS ?
- **Les attentes** : `waitForTimeout` fixe plutôt qu'attente d'une condition —
  source de fragilité, et de faux vert quand le délai suffit par chance.
- **Les parcours qui s'arrêtent avant la fin** : une inscription testée
  jusqu'au formulaire mais pas jusqu'à la ligne créée.
- **Ce qu'aucun des deux ne regarde** : l'état après réveil de l'application
  installée, le cache hors ligne, le changement de compte sur le même appareil.
  Le constater, pas le combler ici.

---

## 8. Les spécifications elles-mêmes

**Question** : `specifications.md`, `design.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `README.md` décrivent-ils le produit d'aujourd'hui ?

**Ordre de lecture imposé** : cette itération vient **en dernier**, parce que
les sept précédentes auront accumulé les écarts entre ce qui est écrit et ce
qui est fait. Elle les consolide au lieu de les redécouvrir.

**Ce qui doit être vérifié** :

- **Ce qui est décrit et n'existe plus** : une page, un réglage, une route, un
  comportement retiré depuis. C'est le plus dangereux — un agent écrit du code
  contre cette description.
- **Ce qui existe et n'est décrit nulle part** : 23 modules aujourd'hui.
  Chacun a-t-il sa section, ses pages, ses rôles, ses réglages ?
- **Les contradictions entre fichiers** : le même comportement décrit
  différemment dans `specifications.md` et `ARCHITECTURE.md`, ou un `role_min`
  qui diverge entre la spec et `module.json`.
- **Les nombres** : chaque durée, seuil, quota, fenêtre cité dans une spec
  correspond-il à la valeur du code ? Ce sont eux que l'itération 4 a testés —
  les écarts trouvés là remontent ici.
- **`README.md`** : les prérequis, la procédure d'installation et la liste de
  fonctionnalités correspondent-ils à la version 1.0.42 ?
- **Le vocabulaire** : un même objet nommé de deux façons selon le fichier est
  une source d'erreur pour tout agent qui lit les deux.

**Régime** : corriger une spec est presque toujours §0.1 — un seul fichier,
aucun risque d'exécution. L'exception est l'écart qui pose la question « lequel
des deux a raison, le code ou la spec ? » : celui-là est un arbitrage produit
et devient une issue.

---

## 9. Journal

Une entrée par itération, ajoutée par la PR de fin d'itération (§0.4).

```
### Itération N — <titre> — <date>

**Périmètre parcouru** : …
**Mutations tentées** : <fichier produit muté> → <test> → rouge / VERT
**Corrigé dans cette PR** : … (une ligne par correction, avec son test)
**Issues ouvertes** : #… (une ligne chacune)
**Vérifié et tenu** : …
**Non vérifiable, et pourquoi** : …
```

### Itération 1 — Les tests qui ne peuvent pas échouer — 2026-09-20

**Périmètre parcouru** : les trois populations que §1 donne pour certaines
(les `assertTrue(true)` littéraux, les deux fichiers dits sans assertion) ;
puis, sur l'ensemble des 1 179 fichiers, quatre recherches : les méthodes
dont toutes les assertions sont de forme, les `try/catch` qui avalent
l'échec attendu sans `fail()`, les noms promettant un refus, et les
messages d'assertion écrits en français. Enfin l'échantillon de mutation
imposé : un test par module, 23 modules.

**Mutations tentées** :

*L'échantillon imposé — une méthode centrale par module, remplacée par un
retour constant, suite du module rejouée, mutation annulée. Les 23 sont
rouges :*

- `attestations/Service/BatchDistributionService::sendSlice → true` → `tests/Modules/Attestations` → rouge
- `banner/Service/BannerService::getRandomBannerHtml → null` → `tests/Modules/Banner` → rouge
- `calendar/Service/CalendarEventService::getViewableCalendars → []` → `tests/Modules/Calendar` → rouge
- `camps/Service/CampService::validate → []` → `tests/Modules/Camps` → rouge
- `fees/Service/HouseholdTariffService::differenceCents → 0` → `tests/Modules/Fees` → rouge
- `finance/Service/FinanceService::isAccountVisibleTo → true` → `tests/Modules/Finance` → rouge
- `gallery/Service/AlbumService::findVisibleForMember → []` → `tests/Modules/Gallery` → rouge
- `groups/Service/GroupAccessService::canRead → true` → `tests/Modules/Groups` → rouge
- `inbound_mail/Service/InboundMailService::findForReference → []` → `tests/Modules/InboundMail` → rouge
- `leadership/Service/ObligationsService::candidates → []` → `tests/Modules/Leadership` → rouge
- `llm_connector/Service/LlmConnectorService::isTierAvailable → true` → `tests/Modules/LlmConnector` → rouge
- `mass_mail/Service/ListAddressService::findActiveForList → []` → `tests/Modules/MassMail` → rouge
- `member_stats/Service/MemberStatsService::getStatistics → []` → `tests/Modules/MemberStats` → rouge
- `news/Service/ArticleService::canView → true` → `tests/Modules/News` → rouge
- `presences/Service/PresenceAuthorizationService::maySeeSection → true` → `tests/Modules/Presences` → rouge
- `registration/Service/ForecastService::countDeparturesForYear → 0` → `tests/Modules/Registration` → rouge
- `rental/Service/RentalBookingService::findByTrackingToken → null` → `tests/Modules/Rental` → rouge
- `retro/Service/BoardService::isUnitChief → true` → `tests/Modules/Retro` → rouge
- `sos_staff/Service/OnCallService::resolveTargetForDate → null` → `tests/Modules/SosStaff` → rouge
- `support_dashboard/Service/StatisticsIntakeService::receive → succès constant` → `tests/Modules/SupportDashboard` → rouge
- `test_tools/Service/MailSandboxService::armed → false` → `tests/Modules/TestTools` → rouge
- `trombinoscope/Service/TrombinoscopeService::getResponsable → null` → `tests/Modules/Trombinoscope` → rouge
- `usage_stats/Service/UsageStatsService::resolveMonth → '2000-01'` → `tests/Modules/UsageStats` → rouge

*Les deux fichiers que §1 annonçait « sans une seule assertion » :*

- `camps/Mail/CampsMailNotifier` : garde « aucun destinataire » retiré de `proposed()` → `CampsMailNotifierTest` → rouge
- `camps/Mail/CampsMailNotifier` : `url` de `stayCreated()` remplacée par `/chefs/camps` → `CampsMailNotifierTest` → rouge
- `finance/Mail/FinanceMailNotifier` : année scoute ignorée dans la résolution des trésoriers → `FinanceMailNotifierTest` → rouge
- `finance/Mail/FinanceMailNotifier` : garde « aucun destinataire » retiré → `FinanceMailNotifierTest` → rouge

*Les renforcements, chacun prouvé vert avant / rouge après :*

- `core/File/FileRepository::findById` privé de son `WHERE id = ?` → `StoredFileCleanerTest` (deux no-op) → **VERT avant renforcement**, rouge après
- `modules/groups/src/Service/RateLimitService` : `>= $limit` remplacé par `>= PHP_INT_MAX` → `Groups\Service\RateLimitServiceTest` → 1 rouge sur 6 avant, 3 sur 6 après
- `modules/finance/src/Service/ImportService::verifyIban` ne refuse plus → `testDeletesTemporaryFileEvenOnFailure` → **VERT avant**, rouge après
- `modules/sos_staff/src/Service/RedirectService` : le refus « aucun fournisseur » rend au lieu de lever → `testApplySendsAdminAlertEmailOnFailure` → **VERT avant**, rouge après
- `core/Help/Assistant/AssistantService::ask` avale l'`LlmException` du fournisseur → `testAFailedCallStillSpendsItsAllowance` → **VERT avant**, rouge après
- `modules/retro/src/Controller/RetroChiefController::close` clôture le tableau **puis** rend son 403 → `testCloseForbiddenBelowChiefEvenIfCreateThresholdIsLower` → **VERT**, et non corrigé ici (issue #387)

**Corrigé dans cette PR** :

- Les 25 `assertTrue(true)` littéraux ont disparu — §1 en annonçait 20, il y en avait 25. Chacun remplacé par une assertion sur l'état observable après l'appel : la ligne et les octets du voisin survivent à un `delete(null)` (`StoredFileCleanerTest`), aucun tableau n'est créé pour un événement supprimé (`AutoCreateRetroHandlerTest`), aucun autre tableau n'est clôturé ni journalisé (`AutoCloseHandlerTest`), le média voisin reste `pending` (`ProcessPhotoHandlerTest`, `ProcessVideoHandlerTest`), l'album en migration n'est pas déplacé (`MigrateAlbumStorageHandlerTest`), le document voisin reste `pending` (`CompressSectionDocumentHandlerTest`), aucune variante n'est dérivée d'un autre fichier (`ImageVariantServiceTest`), les créances de finance sont intactes (`RentalPaymentServiceTest`), les actions autorisées sont aussi *enregistrées* (`Retro\Service\RateLimitServiceTest`), l'appel vide est le seul émis (`ObjectStorageBackendTest`), le voisin reste sur le partage (`WebDavBackendTest`), rien n'est supprimé ailleurs ni créé pour le préfixe absent (`LocalStorageBackendTest`), la requête `DELETE` a bien été émise malgré le 404 (`GoogleDriveClientTest`).
- Trois cas n'avaient pas d'état observable et ont été retournés autrement : `DiskBudgetTest::testAWriteThatFitsIsAccepted` affirme désormais sa propre prémisse (la place disponible dépasse l'écriture plus la marge) ; `VolumeInventoryTest` oppose au silence sur le NAS le refus, sur le volume primaire, de la même écriture ; `TwigCacheFreshnessTest` n'a plus de retour anticipé — la liste des gabarits périmés est vide dans les deux cas, et la seule assertion reste vraie.
- `PdfCompressorTest` : la branche `if/else` qui acceptait les deux résultats est devenue une assertion unique sur le contrat (« `null`, ou un PDF valide plus petit »), doublée du nettoyage du répertoire temporaire (`SECURITY.md` §5).
- `CsrfGuardCoverageTest` : la branche qui validait les quatre contrôleurs exemptés sur parole vérifie maintenant la prémisse de l'exemption — un POST exempté de jeton CSRF ne peut être qu'à `role_min: public`, une route derrière un rôle ayant une session, donc un jeton à lier.
- Cinq `try/catch` qui avalaient l'échec attendu affirment désormais que l'échec a bien eu lieu (`Groups\Service\RateLimitServiceTest` ×2, `Finance\Service\ImportServiceTest`, `SosStaff\Service\RedirectServiceTest`, `Core\Help\Assistant\AssistantServiceTest`) — les cinq sont prouvés ci-dessus.
- Deux messages d'assertion en français traduits dans `WebDavBackendTest`, fichier que cette PR modifiait déjà.

**Issues ouvertes** :

- #387 — quinze tests de refus sur route d'écriture n'attestent que du code HTTP ; une écriture faite avant le refus passe inaperçue (constat établi par mutation sur `RetroChiefController::close()`).
- #388 — quarante-trois messages d'assertion en français dans dix-neuf fichiers, et rien qui tienne le compte.

**Vérifié et tenu** :

- **Les deux fichiers « sans une seule assertion » ont des assertions.** `CampsMailNotifierTest` et `FinanceMailNotifierTest` vérifient par attentes de doublure (`expects($this->once())->method('dispatch')->with(…, $this->callback(…))`), que PHPUnit compte comme assertions : « OK (6 tests, 18 assertions) ». Les quatre mutations ci-dessus les rendent rouges. Le relevé de §1 était mécanique — il cherchait `$this->assert…` et une attente s'écrit `$mock->expects(…)`. Les deux notificateurs sont solides ; ne pas les rouvrir.
- **Les assertions « de forme » ne sont pas le gisement annoncé.** 137 méthodes n'ont que des `assertNotNull`/`assertIsArray`/`assertInstanceOf`/`assertNotEmpty`. La plus dense, `Finance\File\FinanceAccountOwnershipCheckerTest` (7), les emploie par paires `assertNotNull`/`assertNull` autour de `FileAccessGuard::check()` : le verdict d'accès *est* la valeur, et la mutation `FinanceService::isAccountVisibleTo → true` de l'échantillon est rouge. Le critère « toutes les assertions sont de forme » produit surtout des faux positifs ; une itération ultérieure qui voudrait y revenir devra partir d'autre chose.
- **Les `try/catch` sans `fail()` : 24 sites, 19 tiennent.** Dans ces 19, la disparition de l'exception se voit ailleurs — aucune entrée de journal à lire (`MailFailureJournalTest`), un envoi compté au lieu de zéro (`MailTransportSeamTest`, `MailProbeSenderTest`), un disjoncteur resté fermé (`MailTransportChainTest`), un `$caught`/`$failed` déjà affirmé (`StoredFileReaderTest`, `ErrorHandlerTest`, `Groups…::testPostingPastTheLimitIsRefusedWithATypedException`). Deux autres sont volontairement tolérants et le disent : les purges de `usage_stats` et de `support_dashboard` vérifient qu'un échec ne coûte pas le réarmement, et un handler qui cesserait de lever ne les invaliderait pas.
- **Suite complète verte après les renforcements** : 18 551 tests, 67 899 assertions, 3 sautés, 0 échec ; `vendor/bin/phpstan analyse` : aucune erreur. Aucun fichier de production n'est modifié par cette PR.

**Non vérifiable, et pourquoi** :

- **Les 14 autres tests de #387.** Un seul (`RetroChiefController::close()`) a été mué. La mutation qui prouverait les autres n'est pas la même d'un contrôleur à l'autre — sur `MemberEmailAddressController::add()`, par exemple, la vérification d'appartenance *produit* l'identifiant qui sert à écrire, si bien que « refuser après avoir écrit » ne s'exprime pas en une ligne. Chacun demande son propre montage.
- **La moitié MySQL.** Tout ce qui précède a tourné sur la MariaDB du conteneur (`CLAUDE.md` § This container) ; le verdict du job `test` sur MySQL 8 n'est connu qu'en CI.
- **Le nombre réel de sauts.** La suite complète n'a sauté que 3 tests ici, là où le dépôt porte 75 `markTestSkipped`. L'écart est l'objet de l'itération 2 ; il n'est pas mesurable depuis ce seul environnement.

### Itération 2 — Le vert qui saute — 2026-09-20

**Périmètre parcouru** : les 75 `markTestSkipped`, motif par motif, mesurés
plutôt que lus — sur les deux environnements accessibles (MariaDB 10.11 du
conteneur, MySQL 8 de CI, dont le rapport JUnit a été récupéré depuis
l'artefact `phpunit-reports` de la PR #389). Puis les `@group` et les
exclusions de `phpunit.xml`, le périmètre du job MariaDB, et la couverture
réelle de la divergence entre les deux moteurs.

**Mutations tentées** :

*Sur le garde livré :*

- `TEST_DB_PORT=1`, base promise et absente → `DatabaseBackedTestsReallyRunTest` → rouge, en nommant l'hôte, le port et le refus du pilote
- les cinq `TEST_DB_*` retirées et `CI=true`, la régression exacte de §2 → rouge, « TEST_DB_HOST unset or empty, CI set »
- les cinq retirées et pas de `CI` — un portable → sauté, avec son motif
- `TEST_DB_HOST=` exporté vide et pas de `CI` → sauté, comme les vingt-quatre classes qui retombent alors sur `127.0.0.1` ; la revue de la PR #394 a relevé qu'une première version rougissait ici, et elle avait raison
- `TEST_DB_HOST=` vide, `CI=true`, serveur présent → vert, pour la même raison
- un `markTestSkipped('Database not available')` posé dans `Core\View\FormatFiltersTest`, qui n'ouvre aucune connexion → rouge, en citant le fichier et le message
- le même, écrit `markTestSkipped("Database not available: " . __FILE__)` → **VERT** tant que le garde ne lisait qu'un littéral entre apostrophes ; rouge depuis qu'il lit l'argument entier. Constat de la revue de la PR #394, reproduit avant correction
- le même en `sprintf('No %s server (port %d)', 'MySQL', 3306)` → rouge
- une parenthèse à l'intérieur du message → l'argument est extrait entier et l'analyse continue
- `markTestSkipped ("…")`, avec l'espace que PHP accepte avant la parenthèse → **VERT** tant que le garde cherchait le nom collé à la parenthèse ; rouge depuis. Second constat de la revue CodeRabbit sur la PR #394
- `TEST_DB_HOST` cité dans un commentaire d'un fichier qui n'ouvre aucune connexion → **VERT** tant que l'exemption reposait sur la simple présence du nom ; rouge depuis qu'elle exige `getenv('TEST_DB_HOST')`. Premier constat de la même revue
- un fichier qui lit vraiment `getenv(...)`, guillemets simples ou doubles → toujours exempté : le resserrement ne crée pas de faux positif

*Sur un garde écrit puis retiré (voir plus bas) :*

- une colonne `rows` (mot réservé de MariaDB) ajoutée à `modules/banner/schema.sql` → **VERT** : `MigrationRunner` entoure chaque identifiant de backticks, un mot réservé ne passe donc jamais par là
- un type inconnu (`NOTATYPE`) dans le même fichier → rouge — mais `Tests\Integration\ReferenceDatasetBuildTest` rougit aussi, sur la suite complète, pour la même raison
- `SchemaIntrospector::decodeDefault()` privé de sa branche MariaDB → **VERT** sur le garde, rouge sur `tests/Core/Database` (3 échecs) : déjà couvert
- `SchemaComparator` rendu incapable de conclure à l'égalité d'une valeur par défaut → **VERT** sur le garde, rouge 21 fois ailleurs

**Corrigé dans cette PR** :

- `tests/Architecture/DatabaseBackedTestsReallyRunTest.php` — le garde que §2
  demandait. Il n'essaie pas de compter les sauts du run, ce qu'un test ne
  peut pas lire : les vingt-quatre classes concernées sautent toutes sur la
  même chose, une connexion refusée, donc « un saut motivé par la base
  a-t-il eu lieu ? » est « la connexion était-elle possible ? ». Un second
  test tient cette équivalence : un saut qui nomme la base depuis un fichier
  qui n'ouvre aucune connexion serait hors de portée du premier, et il n'y
  en a pas. Il lit `CI` autant que `TEST_DB_HOST`, précisément parce qu'un
  job qui a *perdu* ses variables n'a plus de `TEST_DB_HOST` à lire.

**Issues ouvertes** :

- #393 — vingt et une des vingt-quatre classes sautent en silence là où
  trois refusent de le faire, avec la règle qu'elles enfreignent citée
  depuis leur propre code et la mesure des 139 tests concernés.

**Vérifié et tenu** :

- **Rien ne saute, ou presque.** CI, job `test` sur MySQL 8, rapport JUnit du
  run 35497103931 : **2 tests sautés sur 18 551** — un nom NSS en IPv6 seul
  introuvable, et un décodage d'en-tête IMAP que `ext-imap` rend
  inobservable. Conteneur, MariaDB 10.11 : **3 sur 18 551** — le même nom
  NSS, et deux tests de permissions que le compte `root` invalide. Aucun
  saut motivé par la base, nulle part. Soixante et onze des soixante-quinze
  sites ne se déclenchent dans aucun des deux environnements : leurs
  contraintes (chiffrement AES du zip, liens symboliques, `imagick`,
  libsodium, Ghostscript) sont satisfaites des deux côtés, CI installant
  Ghostscript et `imagick` explicitement.
- **Les deux tests de permissions ne tournent que hors du conteneur.**
  `Core\Storage\DirectorySizeTest` et `Core\Storage\Volume\VolumeInventoryTest`
  sautent ici parce que la session distante est `root`, et tournent en CI, où
  le runner ne l'est pas. Une session distante ne les voit donc jamais : à
  savoir avant de conclure quoi que ce soit sur eux depuis ce conteneur.
- **Aucun groupe n'est exclu nulle part.** `phpunit.xml` ne porte aucun
  `<groups><exclude>` et le commentaire qui l'explique est à jour ; le seul
  groupe du dépôt est `database` (587 attributs, 548 docblocs, les deux
  ensemble dans presque tous les fichiers) ; le job `database-mariadb` lance
  `vendor/bin/phpunit` sans filtre, la suite entière. La question « le job
  MariaDB exclut-il des groupes ? » a pour réponse : non.
- **Le schéma des modules atteint bien les deux moteurs.** L'hypothèse
  inverse a été formulée puis réfutée par mutation : un type inconnu glissé
  dans `modules/banner/schema.sql` rougit `Integration\ReferenceDatasetBuildTest`,
  qui provisionne par `scripts/e2e-support.php provision` — le chemin de
  production — dans sa propre base, donc applique `SchemaFiles::all()`, core
  et 23 modules, sur le moteur que `TEST_DB_*` désigne. Il tourne dans les
  deux jobs PHP. Il n'y a pas de trou de ce côté.
- **Un mot réservé dans un `schema.sql` n'est pas un risque.**
  `MigrationRunner` entoure chaque identifiant de backticks : une colonne
  `rows` — que MariaDB refuse en SQL nu, vérifié — est créée sans incident.
  L'incident `last_value` que cite `docs/quality-pipeline.md` venait de
  requêtes écrites à la main, pas du DDL.

**Non vérifiable, et pourquoi** :

- **Le compte de sauts côté MariaDB en CI.** `.github/workflows/checks.yml`
  produit `phpunit-mariadb.xml` à chaque exécution et ne le téléverse que
  sous `if: ${{ inputs.evidence }}`, donc sur un tag de release. Les trois
  exécutions de `release.yml` du dépôt ont toutes échoué et datent du
  2026-09-09 : leurs artefacts sont expirés, et `evidence/` n'existe pas
  dans le dépôt. Le chiffre de cette entrée pour MariaDB vient du conteneur,
  qui tourne `root` et n'a pas `ext-imap` — deux écarts connus avec le
  runner. Noté en #393.
- **Un garde écrit, prouvé inutile, retiré.**
  `tests/Core/Database/DeclaredSchemaAppliesToTheRealEngineTest` appliquait
  tout le schéma déclaré au moteur réel et vérifiait qu'une seconde
  migration ne fasse rien. Les quatre mutations ci-dessus l'ont démonté :
  sa première moitié double `ReferenceDatasetBuildTest`, et sa seconde ne
  pouvait pas échouer — `MigrationRunner::migrate()` sort à son étape 0
  quand le hash du schéma n'a pas bougé, si bien que « la seconde migration
  n'exécute rien » affirmait qu'un court-circuit court-circuite. C'est très
  exactement ce que ce chantier cherche, écrit par lui ; il est supprimé
  plutôt que livré, et consigné ici pour que personne ne le réécrive.

### Itération 3 — Les doublures qui remplacent le sujet — 2026-09-21

**Périmètre parcouru** : les 308 fichiers qui montent une doublure, puis le
recensement des classes doublées. `MailService` arrive en tête avec 107
fichiers — deux fois la suivante — et c'est l'une des quatre que §3 nomme
comme suspectes. De là, deux questions posées à la doublure la plus
répandue du dépôt : sait-elle refuser, comme l'objet réel ? et lui
demande-t-on ce qu'elle a reçu, ou seulement si on l'a appelée ?

**Mutations tentées** :

Sur `tests/Modules/News/Task/SendPendingTicketsHandlerTest`, avant et après
le renforcement de cette PR :

| Mutation | Avant | Après |
|---|---|---|
| `TicketMailService` poste chaque billet à une adresse fixe étrangère | 1 rouge sur 8 | **8 rouges** |
| le `catch (MailException)` du gestionnaire resserré sur `SuppressedRecipientException` | VERT | **3 erreurs** |
| l'événement journalisé `ticket_email_failed` renommé | VERT | **1 rouge** |
| le journal d'échec nomme aussi l'adresse de la famille | — | **1 rouge** |
| la réclamation `sent_email_claims` prise après l'envoi au lieu d'avant | 2 rouges | 3 rouges |

Les deux « VERT » sont le cœur du sujet : la doublure ne pouvait pas lever,
alors que `MailService::send()` est documentée `@throws MailException on
failure`, si bien que la moitié du gestionnaire — journaliser l'échec et
continuer le lot — n'était atteinte par aucun test. Le « 1 rouge sur 8 »
est l'autre moitié : sept tests sur huit acceptaient que les billets
partent chez un inconnu.

Hors de ce fichier, la même mutation du destinataire, sur deux services
qui ne sont pas corrigés ici :

| Fichier muté | Tests | Verdict |
|---|---|---|
| `core/Member/MemberEmailService.php` | 46 | **VERT** |
| `modules/registration/src/Service/RequestEmailService.php` | 10 | **VERT** |

**Corrigé dans cette PR** :

- `tests/Modules/News/Task/SendPendingTicketsHandlerTest` — la doublure
  devient un transport qui garde ce qu'on lui confie et refuse ce que le
  vrai refuse. Les huit tests existants lisent désormais les destinataires
  au lieu de compter les appels ; trois tests s'ajoutent sur le chemin
  d'échec : un billet refusé ne coûte pas les leurs aux autres, l'échec est
  journalisé par identifiants seuls, et un billet refusé n'est pas renvoyé
  au tour suivant. 8 tests → 11, 14 assertions → 21. Aucun fichier de
  production touché.

**Issues ouvertes** :

- #439 — les doublures de `MailService` vérifient qu'un envoi a eu lieu,
  presque jamais à qui, avec les trois mutations ci-dessus. Une centaine de
  fichiers sur le cœur et une quinzaine de modules : hors des quatre
  conditions de §0.1.

**Vérifié et tenu** :

- **La réclamation est prise avant le transport, et c'est testé.** La
  mutation qui la déplace après l'envoi rougissait déjà avant cette PR.
  Le commentaire du gestionnaire — « Before the transport, never after » —
  n'était donc pas une intention non gardée.
- **`SuppressedRecipientException` étend `MailException`.** Hypothèse
  inverse formulée — un destinataire suspendu ferait tomber tout le lot,
  puisque le gestionnaire ne rattrape que `MailException` — puis réfutée en
  lisant la hiérarchie : la classe en hérite, le `catch` couvre les deux.
  Rien à signaler.
- **Les chemins d'échec ne sont pas tous morts.** Deux gardes sondés par
  mutation — `core/Notification/NotificationMailer` et
  `modules/registration/src/Task/SendReenrollmentEmailsHandler` — rougissent
  quand on resserre leur `catch` : leurs tests font bien lever la doublure,
  par `willReturnCallback` plutôt que `willThrowException`. Le défaut de
  #439 porte sur le destinataire, pas sur l'absence générale de chemin
  d'échec, et l'issue le dit ainsi.

**Non vérifiable, et pourquoi** :

- **Le compte exact des doublures « qui acceptent tout ».** Une sonde en
  lot, mutant chaque site d'envoi puis relançant les tests miroirs, est le
  seul moyen de le chiffrer ; elle écrit successivement dans une vingtaine
  de fichiers de production et a été refusée par le garde-fou de la
  session. Les trois mesures ci-dessus sont donc des sondages, pas un
  recensement : #439 annonce trois fichiers mesurés sur 107 candidats, et
  ne prétend pas davantage.
- **Une mesure d'abord fausse, corrigée.** Le premier comptage cherchait
  `willThrowException(new MailException…)` et concluait « 8 fichiers sur 107
  laissent la doublure échouer ». La sonde de
  `SendReenrollmentEmailsHandler` a rougi alors que son test n'était pas
  dans les huit : il lève depuis un `willReturnCallback`, que le motif ne
  voyait pas. Le compte réel est d'une vingtaine, et le constat de #439 a
  été reformulé avant ouverture — le destinataire non lu, qui lui tient.

**Une assertion à moi, inefficace, trouvée par la revue** : le test du
journal d'échec vérifiait d'abord que le contexte JSON *contenait* la
chaîne de l'identifiant. `claude[bot]` a relevé que dans une base neuve
l'article, le formulaire et la réponse portent tous l'identifiant 1, si
bien que « le contexte contient "1" » tient encore quand `response_id` a
disparu. Reproduit : en retirant la clé, le test restait vert. Corrigé en
décodant le JSON et en comparant la clé — mais la variante profonde
restait ouverte, car journaliser `article_id` *à la place* de
`response_id` passait toujours, 1 valant 1. Le montage crée donc deux
réponses que personne ne nomme, pour que l'identifiant attendu ne vaille
plus 1 ; les deux mutations rougissent désormais. Une assertion qui ne
peut pas échouer, écrite dans l'itération qui les traque : c'est le
troisième garde de ce chantier démonté par sa propre règle, après celui
de l'itération 1 et les deux de l'itération 2.

**Incident de sonde, consigné** : la sonde en lot, avant d'être refusée, a
laissé `modules/mass_mail/src/Task/SendBatchHandler.php` vide — 653 lignes.
`open(f, 'w')` tronque le fichier avant que l'argument de `write()` soit
évalué, l'expression a levé entre les deux, et le script est mort sans
atteindre son `git checkout`. Rien ne l'a signalé : la suite venait de
passer, et l'outillage de mutation ne vérifie que l'application de la
mutation, pas sa réversion. C'est le `git diff --stat` d'avant commit qui
l'a vu. Un outil qui écrit dans le code de production doit préparer son
contenu avant d'ouvrir le fichier, et vérifier l'arbre propre en sortie.

**Piège du conteneur, consigné** : `vendor/bin/phpstan analyse` a rapporté
24 erreurs dans `modules/official_documents/src/Pdf/OverlayPdf.php` après
la remise de la branche sur `main`. Aucune ne venait du changement :
`composer.lock` avait gagné `setasign/tfpdf` entre-temps et le `vendor/` du
conteneur datait d'avant. `composer install` les fait toutes disparaître.
Un `phpstan` rouge sur un fichier que l'on n'a pas touché se vérifie
d'abord contre `composer.lock`.

### Itération 4 — La couverture fonctionnelle, lue depuis les spécifications — 2026-09-21

**Périmètre parcouru** : d'abord ce que `ModuleSpecificationCoverageTest`
laisse passer, puisque §4 le désigne ; ensuite les règles chiffrées que §4
énumère, reprises une à une depuis `specifications.md` vers le code, puis
mises à l'épreuve par mutation.

**Ce que le garde existant ne regarde pas** :
`Integration\ModuleSpecificationCoverageTest` vérifie **l'index** de
`specifications.md` §1.1 — chaque module y a une ligne, chaque section
pointée existe, aucune ligne ne désigne un module mort. Il ne lit jamais le
**contenu** d'une section. Un module peut donc être parfaitement indexé et
promettre n'importe quoi : le trajet spec → test s'arrête au sommaire.

**Mutations tentées** :

| Mutation | Tests exécutés | Avant | Après |
|---|---|---|---|
| `AuthService::TOKEN_EXPIRY_MINUTES` 15 → 1440 | **la suite entière, 19 878** | **VERT** | **rouge** |
| idem 15 → 14 | `AuthServiceTest` | VERT | **rouge** |
| idem 15 → 16 | `AuthServiceTest` | VERT | **rouge** |
| `if ($now > $record->expiresAt)` → `if (false)` | `AuthServiceTest` | 1 rouge | **2 rouges** |
| idem, avec une heure de grâce après l'expiration | `AuthServiceTest` | VERT | **rouge** |
| `CookieConsentService::CONSENT_DURATION_DAYS` 395 → 30 | `tests/Core/Cookie/` | VERT | VERT |
| idem 395 → 3650 (dix ans) | + `tests/Core/View/`, 638 | VERT | VERT |

Le premier est le constat de l'itération : un lien magique valable **vingt-
quatre heures** au lieu de quinze minutes laissait la suite complète verte.
La promesse est écrite deux fois — `specifications.md` ligne 66, « Token:
single-use, 15-minute expiry », et `core/View/rgpd_default.html`, qui en
fait une durée de conservation — et rien ne la tenait, parce que chaque
test qui mentionne la fenêtre écrit son propre `expires_at` au lieu de lire
celui que le service a écrit.

La mutation « une heure de grâce » sépare les deux tests ajoutés :
`testVerifyMagicLinkExpired` existait déjà, mais il date son lien de 2020,
ce qui reste rouge pour une implémentation qui comparerait les années. La
borne à la seconde est ce qui manquait.

**Corrigé dans cette PR** :

- `tests/Core/Security/AuthServiceTest` — deux tests. Le premier lit le
  `expires_at` que `requestMagicLink()` a réellement écrit et le compare à
  quinze minutes, à la seconde près : 14, 16 et 1440 rougissent. Le second
  vérifie la borne — refusé une seconde après l'expiration, accepté trente
  secondes avant. Aucun fichier de production touché.

**Issues ouvertes** :

- #444 — la durée du consentement aux cookies (395 jours, « 13 mois per
  ePrivacy directive ») n'est tenue par rien, et ne peut pas l'être : le
  banc d'essai remplace `setcookie()` par un bocal qui garde le nom et la
  valeur et **jette l'expiration**, c'est-à-dire l'attribut que la règle
  encadre. Dix ans passent aussi bien que treize mois.

**Vérifié et tenu** :

- **Deux prémisses de §4 sont inexactes, et il vaut mieux le savoir avant
  de les chasser.** La « fenêtre de bascule 1er août – 29 septembre » n'est
  écrite nulle part dans `specifications.md`, qui place la coupure au
  1er septembre. Et les « 90 jours des notifications lues » comme le
  « quota de 5 sauvegardes » ne sont pas des règles mais des **réglages
  avec valeur par défaut** — `notifications_retention_days` lu par
  `PurgeNotificationsHandler` avec `?: 90`, le quota de sauvegardes résolu
  par `BackupRetention::quotaFor()`. Un test qui figerait ces chiffres
  figerait une valeur par défaut, pas une promesse.

**Non vérifiable, et pourquoi** :

- **Une mutation qui ne s'applique pas se lit exactement comme un test qui
  tient.** Deux mutations du `if` d'expiration ont d'abord été annoncées
  vertes ; elles n'avaient simplement pas été appliquées, l'échappement de
  l'expression `perl` ne correspondant à rien. Le test d'expiration
  préexistant restait vert lui aussi, ce qui a mis la puce à l'oreille —
  sans lui, deux fausses preuves de robustesse entraient dans ce journal.
  L'outil de mutation utilisé au début de ce chantier vérifiait
  `git diff` après écriture ; la boucle qui l'a contourné ne le faisait
  pas. Toute mutation doit prouver qu'elle a modifié le fichier avant que
  son verdict compte.

### Itération 5 — Les frontières de rôle et d'appartenance — 2026-09-21

**Périmètre parcouru** : les cinq questions de §5, prises une à une, et
chacune posée au code par mutation plutôt que par lecture — l'inventaire
des routes, le garde du routeur, les trois règles d'appartenance nommées,
et une règle négative de §4 restée sans réponse.

**Mutations tentées** :

| Mutation | Tests exécutés | Verdict |
|---|---|---|
| `MemberService::canAccess()` accorde tout | 789 (`Core/Member` + `Security`) | **2 rouges** |
| `FileAccessGuard::isOwnerScopedAgainst()` → `false` | 870 (+ `Core/File`) | **3 rouges** |
| le garde RBAC du routeur ne s'exécute plus | **suite entière, 19 891** | **258 rouges** |
| une route déclarée par variables, invisible au parseur | `AuthorizationMatrixInventoryTest` | **refuse de s'exécuter** |
| `ArticleService::coverImageRoleMin()` → toujours `'public'` | 609 (`Modules/News` + `Security`) | **5 rouges** |

**Corrigé dans cette PR** : rien, et c'est le résultat. Les cinq frontières
que §5 soupçonne sont tenues. Ajouter un garde de plus là où 258 tests
rougissent déjà serait écrire le doublon que l'itération 2 a supprimé après
l'avoir écrit.

**Issues ouvertes** : aucune. Rien de ce qui a été sondé ne constitue un
défaut au regard d'une règle écrite.

**Vérifié et tenu** :

- **L'inventaire ne se contente pas de ce qu'on lui donne.** Une route
  ajoutée à `public/index.php` avec un verbe et un chemin passés par
  variables — une forme que le parseur ne comprend pas — ne rend pas
  l'audit incomplet : `authzCoreRoutes()` sort en erreur, avec ses propres
  mots, « A route written in an unfamiliar shape would be invisible to the
  matrix, so this refuses to run rather than audit an incomplete list ».
  Le test meurt, donc la CI rougit. C'est la réponse à la première question
  de §5, et elle est bonne.
- **Les trois règles d'appartenance résistent à leur annulation complète.**
  Rendre `canAccess()` toujours vrai, désarmer la portée par propriétaire de
  `FileAccessGuard`, ouvrir toutes les couvertures d'articles : chacune
  rougit, sans que rien n'ait eu besoin d'être ajouté.

**Le fait structurel de cette itération** — et il mérite d'être écrit, parce
qu'il déplace où se trouve le filet : **`tests/Security/`, 238 tests, la
suite qui porte le nom de la frontière, n'exerce pas le garde RBAC du
routeur.** Retirer `if (!$skipRbac)` la laisse entièrement verte. Les 258
tests qui attrapent ce retrait sont les tests RBAC des modules —
`NewsRbacTest`, `RentalRbacTest`, `MemberSearchRbacTest` et leurs pairs.
La couverture existe donc, et elle est large, mais elle n'est pas là où §5
la suppose : ni `AuthorizationMatrixInventoryTest`, qui raisonne sur des
déclarations, ni le profil DAST, qui tourne en CI seulement, ne la portent.
Une personne cherchant « le test qui garantit le refus » dans
`tests/Security/` ne le trouverait pas.

**Non vérifiable, et pourquoi** :

- **« Le cran en dessous, route par route »** ne se mesure pas depuis
  PHPUnit. Les 258 rouges prouvent que le refus est exercé largement, pas
  qu'il l'est pour chacune des ~520 routes déclarées ; établir la couverture
  route par route demanderait de muter le `role_min` de chaque route
  séparément et de relancer la suite à chaque fois — plusieurs centaines
  d'exécutions de treize minutes. Le job `Authorization matrix` fait ce
  trajet en CI, par le navigateur, et c'est lui qu'il faudrait lire pour
  répondre ; son artefact n'est publié que sur un tag de release, la même
  limite que l'itération 1 a rencontrée et que #393 consigne.

### Itération 6 — Les chemins d'échec — 2026-09-21

**Périmètre parcouru** : la première question de §6 — « chaque `catch` du
produit a-t-il un test qui l'atteint ? » — posée à l'ensemble du produit
plutôt qu'à un échantillon, puis deux des retours en arrière que §6 nomme.

**L'instrument, et pourquoi il est légitime ici.** Muter 775 blocs un par un
demanderait 775 exécutions de treize minutes. La couverture répond
directement, à condition de ne lui demander que ce qu'elle mesure :
l'exécution. « Une branche jamais exécutée en test » est littéralement un
corps de `catch` dont aucune ligne n'a de hit. Ce n'est pas un score de
qualité, et rien ici ne s'en sert comme tel.

> **775 blocs `catch` dans `core/` et `modules/`. 328 — 42,3 % — n'ont
> jamais été exécutés par la suite.**

La concentration est dans les contrôleurs : `MassMailController` 12,
`CampaignController` 8, `MemberEmailAddressController` 7,
`OutboundMailController` 7, `GalleryChiefController` 7. C'est-à-dire là où
se décide ce qu'un utilisateur voit quand quelque chose échoue — une
branche jamais exécutée est aussi un message d'erreur jamais relu.

**Mutations tentées** :

| Mutation | Tests exécutés | Verdict |
|---|---|---|
| `InstallUpdateHandler::rollbackToSafetyBackup()` ne fait plus rien | 519 (`Core/Maintenance`) | **7 rouges** |
| le `try`/`catch` du compteur de `MailTransportChain` retiré | 1 149 (`Core/Mail` + `Modules/MassMail`) | **VERT** |
| idem, après le test de cette PR | 1 | **erreur** |
| l'échec du compteur avalé sans être journalisé | 1 | **rouge** |

**Corrigé dans cette PR** :

- `tests/Core/Mail/Transport/MailTransportChainTest` — un test. Le
  `try`/`catch` qui entoure l'incrémentation du compteur porte une règle
  écrite à côté de lui : laisser remonter l'échec ferait rapporter en échec
  un envoi qui a bien eu lieu, et `mass_mail` le rejouerait — **un second
  exemplaire dans la boîte de quelqu'un**. Retirer la garde laissait
  1 149 tests verts.

  L'échec est **réel et non simulé** : un déclencheur SQLite refuse les
  écritures sur `mail_send_counters` tout en laissant les lectures
  fonctionner, de sorte que le dépôt lève la `PDOException` qu'il lèverait
  en production. `SendCounterRepository` est `final`, et une doublure ici
  serait précisément ce que §3 reproche — une doublure qui ne ressemble
  plus au sujet. La première tentative retirait la table entière : la
  lecture du quota, qui se fait *avant* l'envoi, échouait aussi et la voie
  était sautée, si bien que le test ne prouvait plus rien sur le compteur.
  Le déclencheur sépare les deux.

**Issues ouvertes** :

- #449 — les 327 autres branches, avec la mesure complète et sa méthode.

**Vérifié et tenu** :

- **Le retour en arrière d'une mise à jour est testé.** Vider
  `rollbackToSafetyBackup()` rougit sept fois. §6 cite ce cas comme
  suspect (« un rollback non testé est un rollback qui n'existe pas ») ;
  ici il existe.

**Non vérifiable, et pourquoi** :

- **La mesure porte sur l'exécution, pas sur la vérification.** Les 447
  blocs exécutés ne sont pas pour autant vérifiés : un `catch` traversé par
  un test qui n'assure rien de son effet compte comme exécuté. Le chiffre
  est donc une **borne supérieure de la couverture réelle** des chemins
  d'échec, et le vrai total des branches non tenues est plus élevé que 328.
  Le distinguer demanderait de muter chacune des 447, ce que la même
  arithmétique interdit.

### Itération 7 — Le navigateur : Vitest et Playwright — 2026-09-21

**Périmètre parcouru** : les **quatre** soupçons que §7 formule — une logique
recopiée plutôt qu'importée, des assertions sur des sélecteurs internes, des
attentes à délai fixe, des parcours qui s'arrêtent avant la fin — puis le
cinquième point, que §7 demande explicitement de **constater sans combler**.

**Deux des quatre soupçons ne tiennent pas — la logique recopiée et les
parcours inachevés ; celui des sélecteurs tient à sa mesure ; celui des
attentes n'est ni confirmé ni levé, faute de mutation** :

- **127 fichiers Vitest sur 128 importent le fichier de production**, et le
  cent-vingt-huitième ne le recopie pas davantage. Un premier comptage en
  annonçait cinq sans import ; le motif ratait la forme par effet de bord
  (`import '../../public/assets/js/x.js';`, sans `from`), qui explique
  quatre d'entre eux. Le cinquième,
  `escape-html-attribute-safety.test.js`, lit délibérément le source comme
  **texte**, par `readFileSync` : c'est un garde de source, pas un test
  recopié, mais ce n'est pas un import non plus, et écrire « 128 sur 128 »
  était une facilité que la phrase suivante contredisait.
- **Quatre `waitForTimeout` dans toute la suite Playwright**, et ce que
  chacun attend est établi. Que chacun soit *juste* ne l'est pas : ce
  paragraphe a d'abord conclu qu'ils l'étaient, en lisant, et cette
  conclusion s'est révélée fausse sur l'un d'eux. Les emplacements sont
  nommés ci-dessous plutôt que numérotés, parce qu'ils ne forment pas une
  série — trois appartiennent à une même barrière, le dernier à rien du
  tout.

  Un premier comptage limité à `tests/e2e/specs/` n'en voyait que trois : le
  quatrième vit dans `tests/e2e/support/`, qui fait partie de la suite et
  que j'avais exclu sans le décider.

  | Emplacement | Ce qu'il attend |
  |---|---|
  | `support/human-check.js:48` | le reliquat avant que `HumanCheck` accepte, calculé depuis l'horodatage du jeton — zéro compris |
  | `specs/rental-management.spec.js:226` | 4 000 ms à plat, même barrière |
  | `specs/rental-request.spec.js:211` | 4 000 ms à plat, même barrière |
  | `specs/pwa-prefetch-once.spec.js:52` | 2 s sur chacune de trois pages, pour établir qu'**aucune** requête supplémentaire n'est partie |

  Le dernier est d'une autre nature et il est hors de cause : on ne peut pas
  attendre la condition « rien ne se produit », et une borne temporelle est
  la seule forme que cette assertion puisse prendre. Les trois premiers
  contournent la même règle — `Core\Security\HumanCheck` refuse un
  formulaire soumis plus vite qu'un humain ne l'aurait rempli — et attendre
  comme un visiteur plutôt que désactiver la garde est le bon choix, que
  leurs commentaires expliquent.

  **Ce que j'ai écrit à tort**, et qu'il faut lire comme l'erreur type de ce
  chantier : que `support/human-check.js` « calculait la valeur exacte » là
  où les deux scénarios la codaient en dur. Je l'ai conclu **en lisant** le
  helper. Le jeton `human_check_token` ne porte que `{f, t, s}` —
  champ-piège, horodatage, signature — et **aucun délai** ; le helper écrit
  son propre `const DEFAULT_MIN_DELAY_SECONDS = 3` (ligne 21), pendant que
  le serveur lit `human_check_min_delay_seconds`, par défaut 3
  (`HumanCheckService.php:108`). **Les trois emplacements redisent le même
  réglage, aucun ne le lit.**

  Ce qui survit à la correction : le helper mesure depuis l'émission du
  jeton, donc n'attend que le reliquat, là où les deux scénarios attendent
  4 000 ms depuis le clic. C'est une meilleure façon d'attendre, pas une
  façon de ne pas recopier — et les trois cassent dès que le réglage dépasse
  leur constante. Le helper est importé par cinq scénarios de plus
  (`rental-lifecycle`, `news-form-payment`, `password-reset`,
  `registration-flow`, `auth-methods`) : la barrière est franchie bien plus
  largement que par les deux scénarios qui écrivent 4 000 ms.
  Non corrigé ici — trois fichiers de test, et la preuve demande de faire
  varier le réglage côté serveur pendant que la suite tourne. Déposé en
  #453, dont le corps porte la même correction et le protocole de preuve.
- **Les parcours ne s'arrêtent pas avant la fin.** Le soupçon que §7 formule
  — « une inscription testée jusqu'au formulaire mais pas jusqu'à la ligne
  créée » — est démenti, et par son propre exemple : `registration-flow.spec.js`
  ne s'arrête pas à « Demande envoyée ». Il suit le lien de suivi reçu par
  courriel, lit « En attente d'examen » sur la page de la famille, retrouve
  le nom de l'enfant et son unité précédente dans la gestion côté staff,
  fait prendre la décision, et revient vérifier qu'elle est devenue
  « Retirée » côté famille. La ligne créée est vue des trois côtés.
  Sur toute la suite : **27 scénarios cliquent un bouton de création, et les
  27 en vérifient la conséquence**. Vingt-six relisent le serveur après une
  navigation ; le vingt-septième, `mass-mail-merge.spec.js`, va plus loin
  qu'une relecture — il ouvre les messages **réellement remis** dans le bac
  à sable, exige exactement deux envois et finit sur la ligne de suivi rendue
  par le serveur. S'il n'a aucun `goto` après la création, ce n'est pas que
  la page se mette à jour d'elle-même : chaque étape est une **navigation
  provoquée par un clic** et attendue comme telle — `waitForURL(/\/mass-mail\/\d+$/,
  { waitUntil: 'load' })` après « Lancer l'envoi », puis
  `waitForURL(/\/mass-mail\/\d+\/tracking/)` après « Suivi ».
  Le premier comptage annonçait 28 et rangeait `login-page.spec.js` parmi
  eux : il cherchait le **nom** d'un bouton de création, et ce scénario
  écrit « Envoyer le lien de connexion » dans un `expect(...).toBeVisible()`
  sans jamais le cliquer. Compter un clic plutôt qu'une mention le sort de
  la population — où il n'avait rien à faire, puisqu'il ne crée rien.

- **Les sélecteurs : le soupçon tient, à sa mesure.** Le premier
  recensement publié ici ne se reproduisait sous **aucune** portée
  cohérente : il mêlait un comptage de `tests/e2e/` entier et un comptage de
  `tests/e2e/specs/` seul, et deux de ses chiffres n'étaient atteignables
  ni sous l'une ni sous l'autre. La revue l'a refait et a eu raison.
  Re-dérivé, portée et règle énoncées — **l'arbre `tests/e2e/` entier**,
  `support/` compris, en comptant les **occurrences** et non les lignes :

  | Ce que le sélecteur adresse | Occurrences |
  |---|---|
  | ce qu'un utilisateur voit | **839** — `getByRole` 500, `getByLabel` 169, `getByText` 165, `getByPlaceholder` 5 |
  | un identifiant (`locator('#…')`) | **258** |
  | une classe CSS (`locator('.…')`) | **127** |

  Restreint à `specs/` seul, ce serait 823 / 250 / 120 — c'est de ce second
  comptage que venaient les 250 et 120 publiés à tort « sur toute la suite »,
  exactement l'exclusion silencieuse de `support/` qui avait déjà fait
  manquer le `waitForTimeout` de `support/`. Aucun `getByTestId` nulle part.

  Ces 127 sont la part exposée à une retouche de feuille de style. Elles
  sont moins fragiles qu'il n'y paraît — les classes visées sont des noms de
  composants (`groups-reply-bubble`, `retro-comment`, `calendar-event-bar`,
  `sos-day-row`) et non des utilitaires de présentation — mais renommer un
  composant casserait le test sans que rien n'ait changé pour
  l'utilisateur. Ce n'est pas rien, et ce n'est pas ce que §7 redoutait.

**Le constat, lui, tient — et il est plus étroit et plus net que §7 ne le
formule** :

> `public/sw.js` n'est exécuté comme service worker par **aucune** couche.

Chaque couche documente sa propre moitié, et aucune ne peut nommer l'autre :

- `tests/js/sw.test.js` exerce le vrai fichier, et écrit en tête qu'il n'y a
  « no real Service Worker runtime: fetch, Response and the Cache Storage
  API are all mocked below » — ce qui est le bon choix sous jsdom ;
- `tests/e2e/playwright.config.js` pose `serviceWorkers: 'block'` pour toute
  la suite, avec sa raison : un worker qui met en cache en arrière-plan
  rendrait le journal des requêtes non déterministe, et « registering it is
  its own feature with its own future scenario ».

Les deux affirmations sont vraies et bien raisonnées. Ce qu'aucune ne dit,
c'est que l'autre existe. La logique *dans* `sw.js` est réellement testée ;
son **cycle de vie** — install, activate, une requête servie depuis un vrai
Cache Storage, une navigation hors ligne, le réveil de l'application
installée — ne l'est par rien, et aucun rouge ne l'annoncerait.

**Corrigé dans cette PR** : `docs/quality-pipeline.md`, section « The
failure mode this repository keeps meeting ». `AGENTS.md` désigne cet
endroit sans ambiguïté — « A check that can be green without having run
belongs in its last section. […] When you find another, write it down
there ». Le constat y est donc écrit, avec les deux citations qui
l'établissent. Aucun test n'est ajouté : §7 dit de constater, pas de
combler, et écrire un scénario de service worker est le « own future
scenario » que la configuration annonce déjà.

**Mutations tentées** : une seule, et son absence était un manquement.

Cette itération ne renforce aucun test — elle en mesure. §0.3 exige
pourtant qu'une qualité de test soit établie **par mutation ciblée, jamais
par lecture**, et §0.4 que le journal dise ce qui a été muté et a tenu. Les
deux affirmations centrales de cette entrée — « 127 fichiers sur 128
importent le fichier de production » et « les quatre `waitForTimeout` sont
justes » — ont d'abord été écrites sur la foi d'un comptage et d'une
lecture. La revue l'a relevé, et elle avait raison.

La seconde a été **retirée** plutôt que corrigée : lire un `waitForTimeout`
établit ce qu'il attend, jamais qu'il tombera le jour où la règle qu'il
contourne change. C'est en la relisant qu'une affirmation voisine s'est
révélée **fausse** — celle sur le helper, corrigée ci-dessus —, et c'est
exactement ce que la lecture ne pouvait pas montrer. La justesse des quatre
attentes reste donc ouverte, et figure sous « Non vérifiable » plutôt que
sous un constat.

- **Muter `nextSelection()` dans `public/assets/js/rental-calendar.js`**
  (intervertir l'arrivée et le départ à la reprise d'une sélection),
  mutation prouvée appliquée par comparaison de fichiers → **4 tests sur 23
  rouges** dans `tests/js/rental-calendar.test.js`, 23 verts après
  restauration. Le fichier Vitest exerce donc bien le code de production
  qu'il importe, et ne le recopie pas : l'affirmation « 127 sur 128 »
  cesse d'être un comptage d'`import` pour devenir une propriété observée,
  au moins sur cet exemplaire.

- **Ce qui n'a pas été muté**, et il faut le dire plutôt que le taire : la
  barrière `HumanCheck`. La mutation juste demande **deux** valeurs, pas
  une, parce que les trois emplacements qui redisent ce réglage ne
  l'écrivent pas à la même hauteur — 3 secondes dans le helper, 4 000 ms
  dans les deux scénarios. Porter `human_check_min_delay_seconds` à **4**
  ne doit faire tomber que ce qui passe par le helper ; le porter à **6**
  doit tout faire tomber. C'est cette asymétrie qui est la preuve : un seuil
  unique au-dessus de 4 les fait tomber tous les trois et ne distingue rien.
  Le `waitForTimeout` de `pwa-prefetch-once.spec.js` n'a rien à voir avec
  cette barrière et ne doit bouger dans aucun des deux cas — c'est le
  témoin. Deux exécutions Playwright complètes, donc : c'est la reproduction
  que #453 attend, et elle y est consignée avec ce protocole, pas ici.

**Issues ouvertes** :

- #452 — le cycle de vie du service worker, exercé par aucune couche.

  Cette entrée disait d'abord « aucune », au motif que le constat était
  désormais écrit dans `docs/quality-pipeline.md` et qu'une issue redirait
  ce que deux commentaires disent déjà. La revue a relevé que c'est une
  confusion entre deux règles distinctes d'`AGENTS.md` : consigner un angle
  mort dans la carte du pipeline est l'une, et « the moment a real problem
  is identified and the decision is taken **not** to fix it in the change at
  hand, open an issue » est l'autre, qui vise explicitement « a trap you
  documented in a comment rather than removed ». La seconde ne se déduit pas
  de la première. Constat juste, issue déposée.

- #453 — **trois** emplacements redisent le délai minimum que
  `Core\Security\HumanCheck` impose, et aucun ne le lit :
  `tests/e2e/support/human-check.js` avec son `DEFAULT_MIN_DELAY_SECONDS = 3`,
  `rental-management.spec.js` et `rental-request.spec.js` avec leurs
  4 000 ms. Le helper attend mieux — il mesure depuis l'émission du jeton et
  n'attend que le reliquat — mais il recopie la valeur comme les deux autres.
  Déposée pour la même raison que #452 : le constat est réel, il n'est pas
  corrigé ici, et la règle ne se satisfait pas de l'avoir écrit dans un
  journal.

  Non corrigeable sous §0.1 : trois fichiers de test, et surtout aucune preuve
  possible d'ici — établir qu'un test suit le réglage au lieu de le recopier
  demande de faire varier ce réglage côté serveur pendant que la suite
  tourne, ce que l'outillage E2E ne permet pas depuis un scénario.

  Le corps de l'issue portait d'abord la formulation fausse corrigée plus
  haut ; il est réécrit, la rétractation restant visible plutôt que
  l'ancienne version remplacée en silence.

**Une règle perdue en route, et retrouvée par la revue** : `AGENTS.md`
demande que toute issue porte `**Type: bug**` ou `**Type: enhancement**` sur
sa **première ligne**, verbatim. Les issues des itérations 1 et 2 la portent
— #391, #393 et #395 relues et conformes. Les quatre ouvertes ensuite ne
l'avaient pas : #439, #444, #449 et #452, toutes corrigées. #453, ouverte
plus tard dans la même passe de revue, la porte dès l'écriture — ce qui est
le seul effet durable du constat. La frontière est
nette, et c'est celle d'une reprise de session : l'habitude a été perdue au
moment où le contexte l'a été, et rien dans le dépôt ne la rappelle au
moment d'écrire — le workflow de triage accepte l'issue et rend son verdict
sans elle. #452 proposait en outre son correctif, ce que §0.1 de ce chantier
interdit aux issues qu'il ouvre ; la section nomme désormais l'endroit du
test, que `AGENTS.md` exige, sans prescrire comment lever le blocage des
service workers, qui est une question de conception.

**Vérifié et tenu** :

- **La règle « importer le vrai fichier, jamais le recopier » est
  respectée partout.** Aucun des 128 fichiers ne réimplémente la logique
  qu'il teste : 127 importent le fichier de production, le dernier le lit
  comme source pour en vérifier la forme.
- **Un piège déjà consigné par l'auteur.** `tests/js/sw.test.js` raconte en
  commentaire qu'un nettoyage supprimait `self`, ce qui faisait lever une
  `ReferenceError` attrapée par le `catch` du code : les tests passaient
  sans jamais exercer la reprise. C'est exactement le sujet de ce chantier,
  trouvé et écrit avant lui.

**Non vérifiable, et pourquoi** :

- **La justesse des quatre `waitForTimeout`**, au sens du chantier — et
  c'est la seule affirmation de cette entrée qui a été **retirée** plutôt
  que corrigée. Ce qui est établi : ce que chacun attend, d'où il tire sa
  durée, et que celui de `pwa-prefetch-once.spec.js` est d'une autre nature.
  Ce qui ne l'est pas : qu'un test tombe le jour où la règle qu'il contourne
  change. La mutation qui le montrerait demande deux seuils et deux
  exécutions Playwright, et elle appartient à #453.
- **La fragilité des 127 sélecteurs de classe.** Le chiffre est une mesure,
  pas un verdict : établir qu'un renommage de composant casse un test sans
  que rien n'ait changé pour l'utilisateur demanderait de renommer
  réellement une classe dans la feuille de style et de rejouer la suite
  navigateur, 127 fois pour en faire une propriété plutôt qu'un exemple.
  Le constat est donc énoncé comme un risque dimensionné, jamais comme un
  défaut constaté.
- **Le cycle de vie du service worker**, que §7 demande explicitement de
  constater sans combler. C'est l'objet de #452.
---

### Itération 8 — Les spécifications elles-mêmes — 2026-09-21

**Périmètre parcouru** : les six vérifications que §8 énumère, menées
mécaniquement plutôt qu'à la lecture — un document de 3 061 lignes ne se
relit pas, il se confronte.

**Ce que §8 redoutait le plus n'a pas été trouvé.** « Ce qui est décrit et
n'existe plus » est le danger que §8 place en tête, parce qu'un agent écrit
du code contre cette description. Rien de tel :

- **Les 23 chemins cités en toutes lettres dans `specifications.md` ont été
  confrontés à `public/index.php` et aux 24 `module.json` : les 22 qui sont
  des routes existent toutes.** Le vingt-troisième, `/members/42`, ne résout
  pas — c'est un exemple d'adresse, pas une route.
- **Les 23 clés de réglage citées existent toutes** dans le code.
- **Aucune ligne de l'index §1.1 ne désigne un module absent**, et aucun
  module n'y manque — mais cela, un test le tenait déjà (voir plus bas).

**Les nombres tiennent, tous.** Chaque durée, seuil et quota cité a été
confronté à la constante qui le porte, et pas une ne diverge :

| Spec | Valeur | Constante |
|---|---|---|
| §20 message 5 000 / réponse 2 000 caractères | 5000 / 2000 | `PostService::MAX_BODY_LENGTH`, `ReplyService::MAX_BODY_LENGTH` |
| §20 fenêtre d'édition 15 minutes | 15 | `PostService::EDIT_WINDOW_MINUTES` |
| §24 purge des audiences 18 mois / 7 jours | 18 / 7 | `PurgeMergeAudiencesHandler::DEFAULT_RETENTION_MONTHS`, `::ORPHAN_RETENTION_DAYS` |
| §23 rétention courrier non rattaché 90 jours | 90 | `PurgeUnlinkedMessagesHandler::DEFAULT_RETENTION_DAYS` |
| §33 archive d'album 512 Mo | 512×1024×1024 | `GalleryController::MAX_ZIP_BYTES` |
| §37 mot de rétro 120–200, défaut 140 | 120 / 200 / 140 | `BoardService::MIN_MAX_COMMENT_LENGTH`, `BoardService::MAX_MAX_COMMENT_LENGTH`, `AutoBoardCreationService::DEFAULT_MAX_COMMENT_LENGTH` |
| §43 commentaire de présence 500 | 500 | `PresenceRepository::MAX_COMMENT_LENGTH` |
| §44 fiche santé 18 mois | 18 | `PurgeHealthSheetsHandler::DEFAULT_RETENTION_MONTHS` |
| §21 archive 90 jours / 1 an, ticket 2 ans | 90 / 365 / 730 | `SupportTicketRepository::ARCHIVE_RETENTION_DAYS_AFTER_CLOSURE`, `::ARCHIVE_MAX_AGE_DAYS`, `::TICKET_RETENTION_DAYS` |
| §21 historique 12 mois par défaut | 12 | `SupportHistoryPeriod::DEFAULT_MONTHS` |

*(la première version de ce tableau citait « §852 », « §1157 », « §2526 »… :
c'étaient des **numéros de ligne** produits par le `grep -n` qui a servi à
les trouver, pas des sections — et `specifications.md` n'a que 44 sections.
Relevé par la revue. Les renvois ci-dessus sont des sections, chacune
vérifiée en cherchant la valeur citée à l'intérieur de son corps.)*

C'est le résultat de l'itération 4 qui remonte ici : là où une valeur est
testée, elle est aussi écrite juste.

**L'écart est ailleurs, et il était invisible parce qu'un test le rendait
invisible.** `Tests\Integration\ModuleSpecificationCoverageTest` tient
l'index §1.1 dans les deux sens — tout module a sa ligne, toute ligne a sa
section, toute section existe. C'est pour cela que les vérifications
ci-dessus passent. Or §1.1 promet **deux** choses, et la seconde n'était
tenue par rien :

> « the pages a module adds are also listed, per menu, in §4 »

**Cinq entrées de menu avaient quitté §4**, sans que rien ne le dise :

| Entrée | Module | Ce qu'elle était devenue |
|---|---|---|
| §4.3 « Présences » | `presences` | absente — alors que §43 écrit « une seule entrée de menu, dans l'Espace animateurs » |
| §4.4 « Courrier » | `inbound_mail` | absente — §4.5 ne décrit que la page de *configuration* des boîtes |
| §4.4 « Réinscription » | `registration` | rangée dans §4.5, alors que son manifeste dit `espace_admin` |
| §4.5 « Fréquentation » | `usage_stats` | absente |
| §4.5 « Supervision » | `support_dashboard` | décrite sous le nom du manifeste, jamais sous celui du menu |

La ligne « Réinscription » est la plus intéressante : §4.5 s'ouvre sur
« All pages in this menu require the `superadmin` role, except Maintenance
(`admin`) », et cette page est à `admin`. La contradiction était **dans la
même sous-section, à quatorze lignes d'écart**. La déplacer en §4.4 ne
corrige pas seulement le classement : elle rend vraie la phrase d'ouverture
de §4.5.

**Une carte que les clés de menu rendent piégeuse**, consignée dans le test
parce qu'elle se relit de travers : `espace_animes` est l'Espace **membres**
(§4.2, `identified`), `espace_chefs` l'Espace **animateurs** (§4.3,
`intendant`/`chief`), `espace_admin` l'Espace **chefs d'U** (§4.4, `admin`).
La correspondance a été établie sur le `role_min` de chaque route, puis
recoupée indépendamment par les `breadcrumb.parents` des manifestes — et non
sur l'orthographe des clés.

**Mutations tentées** — chacune prouvée appliquée par une comparaison de
fichiers avant verdict :

- **Retirer la ligne « Présences » de §4.3** → rouge, sur cette seule
  entrée. Le test voit une ligne manquante.
- **Renommer le libellé de menu dans `modules/usage_stats/module.json`**
  (« Fréquentation » → « Audience ») → rouge sur « Audience ». Le test lit
  le manifeste, pas une liste recopiée dans le test.
- **Déplacer la ligne « Présences » dans la mauvaise sous-section** (§4.4 au
  lieu de §4.3) → **rouge**, alors que la ligne est présente dans le
  document. C'est la mutation qui compte : elle prouve que le test cherche
  dans la sous-section du menu déclaré et non dans §4 tout entier. Sans
  elle, un test qui fouille le document entier aurait été vert et aurait eu
  l'air bon.

**Un piège du test, trouvé par le test.** La première version rapportait la
première entrée manquante et affichait, comme botte de foin, la sous-section
entière — plusieurs milliers de caractères pour un nom de page absent.
Réécrite pour collecter les cinq et n'en faire qu'un message. Le coût d'un
échec illisible se paie le jour où il tombe, pas le jour où il est écrit.

**Une erreur de manipulation, et ce qu'elle enseigne.** La sonde de mutation
restaurait le fichier par `git checkout specifications.md` — ce qui a effacé
les corrections non encore commitées et fait tourner la mutation suivante
sur le document d'origine. Le symptôme était lisible (cinq écarts au lieu
d'un), et c'est le test qui l'a signalé. Les sondes suivantes travaillent sur
une copie. Même famille que la sonde de l'itération 3 qui avait vidé un
fichier de production : un outil de vérification qui écrit dans l'arbre de
travail doit être tenu pour dangereux.

**Les autres documents, et ce que §8 redoutait le plus.** `specifications.md`
n'était que le premier des cinq. Les quatre autres ont subi les mêmes
confrontations mécaniques.

**« Ce qui est décrit et n'existe plus » : rien.** C'est le danger que §8
place en tête, au motif qu'un agent écrit du code contre cette description.
Sur **734 références de classe ou d'espace de noms** citées par les cinq
documents et `docs/quality-pipeline.md`, cinq ne résolvaient pas :

| Référence | Verdict |
|---|---|
| `Core\Photo\PwaIconService` | la phrase **raconte le renommage** et nomme `UnitLogoService` deux lignes plus loin |
| `Core\Photo\StaffThumbnailProcessor` | paragraphe explicitement marqué « retired » |
| `Modules\Gallery\Service\DiskSpace` | cité au passé, « already documented why » |
| `Tests\Architecture\PendingMigrationSelfDriveTest` | paragraphe « removed » |
| `Core\Http\Controller\SchedulerContinuationController` | « There used to be a seventh, and its removal is worth recording » |

Aucune n'est un renvoi périmé : les cinq sont la documentation faisant
exactement ce qu'on attend d'elle, consigner ce qui a disparu et pourquoi.
Le soupçon est levé par la vérification, et il est consigné ici parce qu'un
comptage brut aurait produit cinq « corrections » fausses.

**Les renvois entre sections, eux, ne sont pas tous bons.** Sur **766 renvois
à une section d'`ARCHITECTURE.md`**, 765 résolvent. Le seul qui ne résout pas
est `§7.9` — **64 occurrences dans 53 fichiers**, dont 27 dans `modules/`,
22 dans `tests/`, 6 dans `core/`, 6 dans `public/` et 2 dans
`ARCHITECTURE.md` lui-même. La section §7 s'arrête à §7.6.

Les 64 ne divaguent pas : ils désignent tous la même règle — rien de
personnel dans un journal ni dans ce qui s'exporte, et un contenu de
courriel n'est sûr qu'une fois assaini. La moitié « journal » de cette règle
*est* écrite, sous un autre numéro : §8.6 « Event journal », « No personal
data in entries ». Un lecteur qui suit un de ces 64 renvois ne trouve rien,
en conclut que la règle n'est pas écrite, et n'a aucune raison d'aller
chercher §8.6 — que rien ne lui désigne.

**Et §7.9 n'est pas seul.** La même confrontation appliquée aux renvois
**internes** de chaque document — un `§X` écrit sans nom de fichier — fait
apparaître un second foyer : **`§6.7` (33 occurrences) et `§6.14` (24)**, qui
ne résolvent dans aucun document non plus. Leur origine est plus lisible :
plusieurs citations disent « **module spec** §6.7 », c'est-à-dire une
spécification propre au module locations, avec sa propre numérotation. Ce
document n'existe plus : la spécification de `rental` est aujourd'hui
`specifications.md` **§22**, dont les sous-sections vont de §22.1 à §22.13.
La règle désignée s'y trouve bien — « A manual block and a letting are
deliberately [indistinguishables] », « A visitor cannot page into the past »
sont §22.2, et ce que le calendrier publié laisse voir est §22.8 — sous
d'autres numéros. `SECURITY.md:760` en hérite et écrit « the boundary §6.7
exists to enforce », dans un fichier dont le §6 s'intitule « File access » et
n'a aucune sous-section.

**121 renvois au total**, vers trois numérotations disparues, tous propagés
par copie du commentaire voisin. Tout le reste résout : 765 sur 766 vers
`ARCHITECTURE.md`, et les renvois internes des cinq documents une fois
retirés ceux-là et deux renvois à des normes externes (`RFC 7489 §6.6.2`,
`RGPD §2.10`) — que le comptage avait d'abord signalés, et qui ne sont pas
des défauts.

**Non corrigé, et déposé en #454**, parce que les deux issues possibles sont
des arbitrages : écrire §7.9 revient à rédiger la formulation canonique
d'une règle de protection des données dont 64 emplacements dépendent, et
réécrire les 64 renvois vers §8.6 suppose que §8.6 couvre les deux thèmes,
ce qu'il ne fait pas. Le test qui le détecte est écrit, rouge sur §7.9 et
sur rien d'autre, retiré de cette PR et collé dans l'issue — c'est la
procédure que §0.2 prévoit pour un test qui tombe sur un défaut qu'on n'a
pas le droit de corriger.

**Les chiffres de la matrice d'autorisation étaient faux partout.** Cinq
énoncés, deux documents, rien qui les vérifie :

| Où | Disait | Tient |
|---|---|---|
| `README.md` (tableau des profils) | 528 routes | **toutes** les routes |
| `README.md` (« rejoue les … routes ») | 528 routes | **toutes** les routes déclarées |
| `README.md` (liste des jobs) | 534 routes, 3 204 couples | **toutes**, un couple par combinaison |
| `SECURITY.md` | 528 routes × 6 rôles = 3 168 paires | **every route as every role** |
| `SECURITY.md` (paramètres nommés comme un identifiant) | 209 routes | **every** route enregistrée |

**La colonne de droite n'a pas toujours dit cela, et le revirement est le
constat.** Elle a d'abord porté les chiffres justes — 747 routes,
4 482 couples, 265 routes à paramètre identifiant — et un test les épinglait
à `authzRoutes()`, au motif que « le nombre dans la prose fait partie du
changement qui ajoute la route ». C'était un raisonnement, pas une mesure.

**La mesure le dément.** Sur trente jours, **50 des 94 commits de `main`
touchent une déclaration de route** — plus d'un sur deux. Un compte épinglé
dans la prose rend donc rouge la majorité des PR ouvertes, sans faute de
leur part ; celle-ci a vu le chiffre bouger **deux fois pendant qu'elle
était ouverte** — 746, puis 747, puis 750 — dont une fois en CI, après avoir
été vert en local une heure plus tôt. Un fil qui se déclenche sur plus de la
moitié des fusions n'est pas un garde, c'est un impôt.

La prose ne cite donc plus de taille. Elle énonce l'**invariant** — toutes
les routes, tous les rôles —, qui est ce dont a besoin le lecteur qui audite
la couverture, qui reste vrai quelle que soit la taille de la table, et que
les tests d'inventaire tiennent déjà. Le test garde cette affirmation
présente **et empêche un « 750 routes » bien intentionné d'être réécrit**.

C'est le seul endroit du chantier où une correction en a remplacé une autre.
La première n'était pas fausse — les chiffres publiés étaient bien faux et
il fallait les corriger — mais le dispositif qui devait les maintenir justes
coûtait plus qu'il ne rapportait, et seule la mesure pouvait le dire.

README se contredisait lui-même — 528 deux fois, 534 une fois — et aucun des
trois nombres n'était le bon. Ce n'est pas cosmétique, et c'est la panne que
`AuthorizationMatrixInventoryTest` existe déjà à détecter, un cran plus
haut : son propre docblock écrit qu'« a shorter green run reads exactly like
a complete one ». Le lecteur de ces phrases-là est précisément en train
d'auditer la couverture ; il compare le chiffre cité à la table des routes.
Un chiffre trop bas se lit exactement comme un trou dans la matrice.

**Et un job de CI avait quitté la liste du README.** `checks.yml` en définit
huit ; la section « Intégration continue » n'en nommait que sept.
L'absent était `database-mariadb` — celui-là même dont le rôle est de
séparer une divergence de moteur de tout le reste. Le job tournait sur
chaque PR pendant ce temps : c'est l'inventaire qui était court, ce qui est
la direction dangereuse.

**Mutations tentées** (second lot), chacune prouvée appliquée par
comparaison de fichiers avant verdict :

- **Retirer la puce `database-mariadb`** → rouge, sur elle seule. **Et
  cette mutation ne prouvait presque rien**, ce que la revue a établi et
  que j'avais manqué : voir ci-dessous.
- **Ajouter un job au workflow** → rouge, en le nommant.
- **Renommer une puce** (`security` → `securite`) → rouge.
- **Ajouter une puce pour un job inexistant** → rouge, par l'autre
  direction du test.

**La mutation qui passait pour la bonne raison, et qui ne prouvait rien.**
C'est le constat le plus utile de tout le chantier, et il porte sur mon
propre test. `EveryCiJobIsDocumentedTest` cherchait d'abord le nom du job
**dans tout le README** (`str_contains($readme, '`' . $job . '`')`). Or
quatre des huit jobs sont cités entre accents graves **ailleurs que dans
leur propre puce** — « la même suite complète que `test` », « indépendamment
du job `test` » :

| Job | Mentions dans README | Retirer sa puce le rendait-il rouge ? |
|---|---|---|
| `test` | 5 | non |
| `sonarqube` | 5 | non |
| `e2e-tests` | 2 | non |
| `javascript-tests` | 2 | non |
| `database-mariadb` | **1** | oui |
| `security`, `authorization-matrix`, `dast-passive` | 1 | oui |

**J'avais muté le seul job incapable de révéler la faiblesse.** Le rouge
obtenu était vrai, et il ne disait rien des quatre autres : le test était
inerte pour la moitié de ce qu'il prétendait tenir, et ma mutation
l'avait certifié bon. C'est exactement la panne que §0.3 décrit — un vert
qui ressemble à une garantie —, transposée d'un cran : **une mutation qui
passe pour la bonne raison sur le mauvais échantillon**.

Le test parcourt désormais la liste à puces de la section, comme le faisait
déjà son jumeau dans l'autre direction. Les quatre mutations qui ne
faisaient rien font chacune tomber le test, en le nommant :

    retirer la puce de `test`             → rouge : test
    retirer la puce de `sonarqube`        → rouge : sonarqube
    retirer la puce de `e2e-tests`        → rouge : e2e-tests
    retirer la puce de `javascript-tests` → rouge : javascript-tests

**La leçon se range à côté de §0.3** : choisir la cible d'une mutation dans
le cas le plus commode, c'est se répondre à soi-même. La cible doit être
celle qui a le plus de chances de survivre.

Les chiffres, eux, ont été rendus rouges d'un coup par la première version
du test — preuve qu'elle les lisait et ne les supposait pas. Ce sont
aujourd'hui trois mutations sur l'invariant qui tiennent sa remplaçante :
retirer l'affirmation « every route as every role » la rend rouge, et y
réécrire un compte la rend rouge par l'autre bout, que la phrase invariante
survive ou non.

**Ce que ces tests coûtent, et où passe vraiment la limite.** Trois taux,
mesurés sur les 96 commits des trente derniers jours de `main` :

| Ce que le test couple au document | Commits qui le changent | Test |
|---|---|---|
| un job de `checks.yml` | **2 sur 96** (2 %) | `EveryCiJobIsDocumentedTest` |
| un `label` ou un `name` de `module.json` | **18 sur 96** (19 %) | §4 et §1.1 |
| une déclaration de route | **50 sur 96** (52 %) | *retiré* |

J'ai d'abord énoncé la limite comme une fréquence : « quelques fois par
mois, oui ; plus d'une fois sur deux, non ». **C'est un mauvais critère**,
et les 19 % le montrent — une PR sur cinq, faut-il garder le test ou non ?
La fréquence ne répond pas.

Le bon critère est ailleurs : **le document devient-il faux, ou seulement
périmé sur un chiffre qui n'ajoutait rien ?** Quand une entrée de menu est
renommée, §4 *doit* suivre : un lecteur qui cherche « Départs » ne le
trouve plus, et le document ment. Quand une route est ajoutée, la prose ne
doit rien : « toutes les routes » était déjà vrai avant et le reste après.

La fréquence n'est que l'arbitre du cas douteux. À 52 % elle tranchait
seule ; à 19 %, ce qui tranche est que le renommage rend le document faux.

**Vérifié et tenu** (second lot) :

- **`design.md`** : `.rich-text img` a bien `max-width: 100%; height: auto`,
  plafonné à **420px** à partir de 992px, et c'est bien la même valeur que
  la grille média d'un groupe (`app.css:667`, `components.css:493`).
  Bootstrap est bien en **5.3.8**. La cible tactile de **44px** est bien
  posée sous `@media (pointer: coarse)`, et le CSS cite `design.md §7.2` en
  retour — un renvoi qui va dans les deux sens.
- **`README.md`** : `PHP >= 8.4` correspond au `^8.4` de `composer.json`,
  `Node.js >= 22` aux `engines` de `package.json` et au `node-version: '22'`
  des trois jobs, et « 6 niveaux » de rôles aux six cas de
  `Core\Security\Role`, dont l'échelle 0–5 est exactement celle de
  `specifications.md` §2.1. `VERSION` dit bien `1.0.42`.

**Les tests ont fait leur preuve avant même d'être fusionnés.** L'entrée a
été rédigée sur un `main` que cette PR a ensuite dû rattraper — de
cinquante-cinq commits, puis trois fois encore, la CI jugeant chaque fois
la branche contre un `main` déjà dépassé. À chaque contact, les tests
écrits ici sont devenus **rouges**, et sur des choses réelles :

- **`AuthorizationMatrixInventoryTest`** : une route ajoutée entre temps a
  fait passer les sept chiffres publiés de 746/4 476/264 à 747/4 482/265,
  et le test les a tous les sept dénoncés en nommant la phrase à corriger.
  Puis, quelques heures plus tard, **la CI les a dénoncés une seconde
  fois** — 750/4 500/268 — alors qu'ils venaient d'être corrigés et que le
  local était vert. C'est cette seconde fois qui a provoqué la mesure, puis
  le revirement décrit plus haut : le test tenait sa promesse, et sa
  promesse était le problème.
- **`ModuleSpecificationCoverageTest`** : **quatorze** entrées de menu
  avaient été renommées depuis (« Groupes » → « Discussions »,
  « Trombinoscope » → « Les animateurs », « Départs » → « Départs de
  l'unité », « Passage » → « Passages de branche »…) sans que §4 suive.
  Les quatorze sont des renommages de pages que §4 décrivait déjà, donc
  quatorze premières cellules à réécrire — et non du contenu à inventer.

Le cas le plus parlant est §4.4 « Téléphone d'urgence » : la ligne **avait**
été mise à jour depuis « SOS Staff d'U », mais vers un libellé que le menu
ne porte pas — il dit « Gérer le téléphone d'urgence ». Une mise à jour à la
main, faite de bonne foi, et fausse d'un mot. C'est précisément ce qu'un
test de présence attrape et qu'une relecture ne rattrape pas.

**Et le renommage en a fait tomber un troisième, qui n'était pas le mien.**
`Tests\Modules\Groups\DocumentationTest` garde depuis longtemps la
présence de la page des groupes dans le tableau de §4.2 — en écrivant le
libellé **en dur** : `assertStringContainsString('| Groupes (module) |')`.
Son intention est juste et son commentaire l'énonce bien ; sa mise en œuvre
recopie une valeur qui vit dans `module.json`, et elle est devenue fausse le
jour où l'entrée est devenue « Discussions ». C'est exactement le défaut de
#453, dans un test cette fois plutôt que dans un scénario.

Il aurait suffi d'y écrire le nouveau libellé. Il lit désormais le manifeste,
parce que remplacer un littéral périmé par un littéral frais, c'est
reconduire la panne en la datant d'aujourd'hui.

**Une règle qui n'était pas écrivable au début de l'itération l'est
devenue.** L'index §1.1 porte une colonne « Name in the interface », et elle
n'avait pas de règle unique : elle disait « Groupes » là où le manifeste
déclarait « Groupes de discussion », et « Intelligence artificielle » là où
`llm_connector` déclarait « Connecteur IA ». Les deux étaient défendables —
c'étaient les libellés de **menu** — donc la colonne avait deux lectures
possibles et rien à tester.

`main` a depuis renommé les deux modules, et l'ambiguïté est partie avec
eux : **les 24 lignes valent maintenant le `name` de leur manifeste**. La
ligne `groups` est alors devenue simplement périmée, et rien ne l'a vu — il
a fallu un relecteur. D'où un cinquième test, muté dans les deux
directions : remettre « Groupes » dans l'index le rend rouge, renommer le
module dans son manifeste en laissant l'index derrière aussi.

C'est le seul endroit du chantier où **attendre** a produit une règle :
elle n'était pas écrivable en début d'itération, elle l'est devenue parce
que le produit a tranché entre les deux lectures.

**Non vérifiable, et pourquoi** (second lot) :

- **« in about a minute »**, que `SECURITY.md` écrit à côté du nombre de
  paires. Le job `Authorization matrix` a mis cinq minutes sur la dernière
  exécution, mais il provisionne une instance avant de rejouer quoi que ce
  soit, et rien dans sa sortie ne sépare les deux. Le chiffre n'est pas
  corrigé faute de savoir ce qu'il mesure.
- **Le fond d'`ARCHITECTURE.md`**, 5 507 lignes. Ce qui a été confronté au
  code, ce sont ses renvois et les classes qu'il nomme — pas ce qu'il en
  dit.
- **Un troisième soupçon, levé lui aussi.** Les 24 manifestes déclarent
  **88 réglages** ; **77** ne sont cités par leur clé nulle part dans
  `specifications.md`. Le chiffre invite à conclure à 77 réglages non
  documentés — et ce serait faux. §4.5 décrit les réglages **en français et
  par ce qu'ils font** (« nombre maximum de médias par album »), jamais par
  leur clé, et c'est cohérent : la page « Paramètres » montre des libellés,
  pas des clés. Plusieurs des 77 ne sont d'ailleurs pas des réglages du tout
  mais de l'état (`inbound_mail_quota_alerted_at`,
  `inbound_mail_scopes_migrated`, `inbound_mail_refresh_started_at`). Aucune
  règle écrite n'exige la clé, donc il n'y a pas d'écart — seulement un
  comptage qui aurait produit 77 faux constats.

**Corrigé dans cette PR**, en **quatre** paires document + test, chacune
rouge avant et verte après — la troisième condition de §0.1 comprise. La
quatrième est arrivée en dernier, sur un constat de revue, et elle n'était
pas écrivable au début de l'itération (voir plus bas) :

| Document | Test qui le tient |
|---|---|
| `specifications.md` §4.2 à §4.5 — cinq lignes manquantes, dont un déplacement, et quatorze renommages | `ModuleSpecificationCoverageTest::testEveryMenuEntryAModuleAddsHasItsRowInSectionFour` |
| `README.md` et `SECURITY.md` — sept chiffres, remplacés par l'invariant qu'ils illustraient mal | `AuthorizationMatrixInventoryTest::testTheDocumentationClaimsEveryRouteRatherThanACountOfThem` |
| `README.md` — la puce `database-mariadb` | `EveryCiJobIsDocumentedTest` (deux directions) |
| `specifications.md` §1.1 — le nom de `groups`, resté « Groupes » | `ModuleSpecificationCoverageTest::testTheIndexNamesEachModuleAsItsManifestDoes` |

**Une issue du chantier déjà refermée par le produit.** En intégrant `main`
avant de fusionner cette itération, #453 — les trois emplacements qui
redisaient le délai minimum de `HumanCheck` — se trouve **traitée** par #469
(`89728cd`). Les deux `waitForTimeout(4000)` ont disparu au profit du
helper, il ne reste dans tout `tests/e2e/` que les deux attentes légitimes,
et `Tests\Core\System\E2eFixedWaitRatchetTest` interdit désormais d'en
réintroduire — dans les deux directions, et avec un motif qui tolère
l'espace, `waitForTimeout (4000)` ne passant donc pas à côté.

C'est la première fois de ce chantier qu'un constat déposé en issue revient
corrigé dans la branche avant même que l'itération qui l'a produit soit
fusionnée. Ce qui reste ouvert est étroit : une seule copie du réglage
subsiste, dans le helper, et prouver qu'elle suit le serveur plutôt qu'elle
ne le redit demande toujours les deux seuils et les deux exécutions
Playwright décrits dans l'issue.

**Issues ouvertes** :

- #454 — 121 renvois du code et des tests (`§7.9` ×64, `§6.7` ×33, `§6.14`
  ×24) pointent vers des sections qui n'existent dans aucun document. Les
  deux issues possibles — écrire ces sections, ou renuméroter les renvois —
  sont des arbitrages, et le test qui les détecte est collé dans l'issue.

Aucun des autres écarts de cette itération ne pose la question « lequel des
deux a raison, le code ou la spec ? » : le manifeste construit le menu, la
CI définit ses jobs, l'inventaire compte les routes, et le document seul
était en retard.

**Vérifié et tenu** :

- `test_tools` et `support_dashboard` déclarent bien le `visible_when` que
  §1.1 leur prête (`["reference_installation", "local_installation"]` et
  `["statistics_receiver"]`).
- Les 24 modules ont une section, et les 29 renvois portés par les lignes de
  l'index pointent tous vers une section qui existe.
- Les libellés « Groupes » et « Intelligence artificielle » de l'index §1.1
  semblaient contredire les manifestes (« Groupes de discussion »,
  « Connecteur IA ») : c'étaient leurs **libellés de menu**, exacts tous les
  deux au moment de la vérification. Soupçon levé par la mesure, pas par la
  lecture — il figure ici parce qu'il aurait fait une correction fausse.

  **Et il a changé de statut pendant la PR**, ce que la revue a relevé.
  `main` a depuis renommé les deux modules : `groups` porte maintenant le nom
  « Discussions » dans son manifeste **et** dans son menu, et
  `llm_connector` « Intelligence artificielle » dans les deux. L'index §1.1
  disait toujours « Groupes » : ce n'était plus un libellé de menu, c'était un
  libellé périmé, et il est corrigé. Le soupçon était faux quand il a été
  levé, et la correction qu'il aurait fait faire est devenue juste pour une
  autre raison — ce qui est une raison de plus de dater ce qu'on vérifie.

**Non vérifiable, et pourquoi** :

- **Le fond des sections**, par construction. Ce qui est tenu est que
  chaque page a sa ligne, pas que la ligne dise vrai. §4.5 décrit la page
  « Courrier sortant » sur 4 000 caractères ; rien ne confronte ces
  4 000 caractères à l'écran. Un test de couverture est un test de présence.
- **L'ordre des sous-sections de §18** (18.1, 18.2, 18.3, **18.5**, 18.4)
  est inversé dans le document. Non corrigé : renuméroter toucherait les
  renvois croisés qui citent §18.4 et §18.5 depuis d'autres sections, et une
  correction d'ordre ne se prouve par aucun test — et la corriger demande
  d'abord de trancher entre renuméroter (quatre renvois croisés à reprendre)
  et déplacer le bloc (les numéros restent justes, le texte bouge). Déposé
  en **#488**, la revue ayant relevé qu'un constat différé sans issue est
  précisément ce qu'`AGENTS.md` interdit.
