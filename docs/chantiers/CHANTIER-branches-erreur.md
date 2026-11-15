# Chantier — Les branches d'erreur jamais exécutées (#449)

Document de chantier. Il complète `AGENTS.md` (§ Tests), `CONTRIBUTING.md` et
`docs/quality-pipeline.md`, qui restent la source de vérité des règles. Il
prend la suite des itérations 9 à 16 de
`docs/chantiers/CHANTIER-revue-des-tests.md`, qui ont ouvert le sujet et livré
les huit premiers lots, fichier par fichier ; leur journal y reste.

**L'objet** : chaque bloc `catch` de `core/` et `modules/` dont la suite de
tests n'exécute jamais le corps. Une branche d'erreur jamais exécutée est un
message d'erreur jamais relu, et sa première exécution aura lieu en
production. Le ticket #449 en comptait 328 sur 775 au départ (42,3 %).

**La fin** : zéro bloc `catch` jamais exécuté, en dehors de ceux qui portent un
marqueur `@catch-unreachable` avec sa raison (§ 2). Seule la PR qui atteint ce
zéro porte « Corrige #449 » ; toutes les autres portent « Pour #449 », que
`issue-fixed-comment.yml` ne lit pas comme une fermeture.

---

## 1. La mesure

```
vendor/bin/phpunit --coverage-php /tmp/cov.php
php scripts/catch-coverage.php /tmp/cov.php           # le tableau
php scripts/catch-coverage.php /tmp/cov.php --list    # et chaque fichier:ligne
php scripts/catch-coverage.php /tmp/cov.php --json    # pour une machine
```

La suite complète, avec PCOV, et sur MariaDB : une suite qui saute ses tests
de base de données mesure moins que la production n'exécute.

`scripts/catch-coverage.php` trouve chaque `catch` avec le tokenizer de PHP —
jamais une expression régulière, qu'un commentaire ou une chaîne contenant le
mot tromperait — et le juge sur la couverture de ligne :

| Verdict | Ce qu'il veut dire |
|---|---|
| `executed` | la première ligne exécutable du corps a tourné au moins une fois |
| `never` | elle n'a jamais tourné |
| `never-empty` | corps sans instruction (un commentaire, ou rien) : la ligne du `catch` est jugée |
| `unreachable` | le corps porte le marqueur, avec une raison (§ 2) |
| `unknown` | le rapport ne permet pas de trancher ; listé, jamais rangé ailleurs |

La méthode d'origine du ticket — « la première ligne exécutable du corps » —
ne voyait pas les `catch` vides : un corps sans instruction n'a pas de
première ligne. Ils sont comptés depuis la décision du 2026-09-28, et passent
**en tête** de chaque lot.

Les `never-empty` sont ceux qui **avalent** une erreur exprès.

Un fichier absent du rapport (jamais chargé par aucun test) a tous ses `catch`
jamais exécutés : `phpunit.xml` déclare `core/` et `modules/` en `<source>`.

La mesure **n'est pas un cliquet**. Le mainteneur a écarté, pour le moment,
une base par fichier que le nombre ne pourrait que faire baisser : un `catch`
neuf non couvert peut encore arriver sur `main`. C'est la remesure au début
de chaque lot qui le fait apparaître.

## 2. Le marqueur `@catch-unreachable`

Certaines branches ne s'atteignent pas honnêtement. L'exemple type est la
**fenêtre TOCTOU** : le contrôleur vient de vérifier, deux lignes plus haut et
dans la même requête, ce que le service relit ; seule une doublure qui ment
atteindrait la branche, et un test qui ment ne vaut pas mieux qu'aucun.

Une telle branche porte, **dans son corps**, un commentaire :

```php
} catch (MassMailException) {
    // @catch-unreachable findVisibleEmail() read the same row two lines up,
    // in this request; only a delete between the two reads lands here.
    return $this->notFound();
}
```

Les règles :

- **Une raison, toujours.** Un marqueur sans texte après lui est refusé : la
  mesure le compte `never` et le signale comme invalide.
- **La raison dit pourquoi la branche est inatteignable**, pas ce qu'elle
  fait. « Defensive » n'est pas une raison.
- **Le marqueur ne remplace pas un test possible.** Une branche qu'un état
  réel atteint — une saisie, une ligne en base, un module désactivé — se
  couvre. Avant de marquer, lire ce que l'appel entouré peut lever, toutes
  ses raisons : `resendDelivery()` lève aussi « Ce message ne peut pas être
  renvoyé. », que n'importe quel envoi déjà parti atteint.
- **Inatteignable parce que mal écrit n'est pas inatteignable.** Un `catch`
  qui nomme la mauvaise exception (lot 1 : `MassMailException` au lieu de
  `MailingListException`) est un défaut, à corriger.

## 3. Les lots, par domaine

Un lot = un domaine du tableau de la mesure (`core/<Dossier>` ou
`modules/<nom>`), pas un fichier : la stratégie « le fichier le plus chargé
d'abord » est épuisée, 366 branches réparties sur 211 fichiers au
2026-09-28. Un domaine trop gros pour une PR relisable se coupe en deux lots,
dits comme tels.

Pour chaque lot :

1. **Remesurer** sur `main` à jour, avant de commencer — jamais déduire du lot
   précédent.
2. **Les `catch` vides d'abord**, puis les autres.
3. **Atteindre la branche par un état réel** chaque fois qu'il existe : ce
   qu'un utilisateur tape, une ligne en base, une configuration. Une doublure
   seulement quand la panne d'un autre composant **est** le sujet (un
   transport SMTP qui refuse, un disque plein).
4. **Asserter l'effet**, pas seulement le passage : le message montré, le
   statut HTTP, la ligne journalisée, ce qui n'a **pas** été écrit. Un test qui
   traverse le `catch` sans rien vérifier fait baisser le chiffre et ne
   protège rien.
5. **Demander pourquoi une branche ne s'exécute jamais.** C'est là que les
   huit premiers lots ont trouvé leurs vrais défauts (exception sœur,
   comptage avalé, page qui affiche zéro au lieu d'« indisponible »). Un
   défaut trouvé se corrige dans le lot s'il tient dans le fichier et son
   test (la règle « Corriger tout de suite » de `CHANTIER-revue-des-tests.md`) ; sinon, un ticket à part.
6. **Mutation** : retirer le `catch`, ou changer ce qu'il fait, doit faire
   échouer un test.
7. **Journaliser** le lot ci-dessous : la mesure avant et après, ce qui a été
   trouvé, ce qui a été marqué et pourquoi.

## 4. Le tableau

Mesuré le 2026-11-14 sur `main` `fc8b1df1` — 28 996 tests, PCOV, MariaDB.

| | Nombre |
|---|---|
| Blocs `catch` dans `core/` et `modules/` | 1101 |
| Exécutés | 672 |
| **Jamais exécutés** | **429** (39,0 %) |
| dont corps vide | 72 |
| Marqués `@catch-unreachable` | 0 (lot 0 en ajoute un) |

Ils se répartissent sur 254 fichiers. Les domaines où il en reste, dans
l'ordre des lots (ceux à zéro, 11 domaines, ne figurent pas) :

| Domaine | Jamais exécutés | dont vides | Marqués | Blocs |
|---|---|---|---|---|
| `modules/rental` | ~~42~~ **0** (lots 3a-3b) | ~~6~~ 0 | 1 | 102 |
| `core/Http` | ~~36~~ **1** (lot 4, voir le journal) | ~~4~~ 0 | 1 | 133 |
| `core/Mail` | ~~35~~ **0** (lots 1-2) | ~~16~~ 0 | 2 | 77 |
| `core/Storage` | ~~31~~ **0** (lot 5) | ~~9~~ 0 | 1 | 67 |
| `modules/finance` | ~~23~~ **0** (lot 6) | 0 | 3 | 54 |
| `core/Maintenance` | ~~21~~ **0** (lot 7) | ~~3~~ 0 | 1 | 55 |
| `modules/inbound_mail` | 21 | 4 | 0 | 40 |
| `core/Support` | ~~20~~ **0** (lot 9) | ~~4~~ 0 | 1 | 29 |
| `modules/registration` | ~~17~~ **0** (lot 10) | ~~4~~ 0 | 1 | 47 |
| `modules/camps` | 15 | 1 | 0 | 34 |
| `core/Member` | 11 | 2 | 0 | 19 |
| `modules/attestations` | 11 | 1 | 0 | 20 |
| `modules/data_migration` | 11 | 0 | 0 | 28 |
| `modules/groups` | 10 | 2 | 0 | 24 |
| `modules/news` | 9 | 0 | 0 | 25 |
| `modules/sos_staff` | 9 | 0 | 0 | 23 |
| `modules/support_dashboard` | 9 | 2 | 0 | 13 |
| `modules/gallery` | 8 | 0 | 0 | 35 |
| `modules/social` | 8 | 0 | 0 | 23 |
| `core/Photo` | 6 | 0 | 0 | 8 |
| `core/Security` | 6 | 3 | 0 | 14 |
| `modules/documents` | 6 | 1 | 0 | 8 |
| `modules/calendar` | 5 | 0 | 0 | 16 |
| `modules/llm_connector` | 5 | 2 | 0 | 14 |
| `modules/mass_mail` | 5 | 0 | 0 | 47 |
| `modules/official_documents` | 5 | 0 | 0 | 9 |
| `core/File` | 4 | 0 | 0 | 9 |
| `core/Import` | 4 | 1 | 0 | 5 |
| `modules/covoiturage` | 4 | 0 | 0 | 9 |
| `core/Alert` | 3 | 1 | 0 | 9 |
| `core/Database` | 3 | 2 | 0 | 14 |
| `core/Statistics` | 3 | 1 | 0 | 7 |
| `core/Contact` | 2 | 0 | 0 | 3 |
| `core/Help` | 2 | 0 | 0 | 3 |
| `core/Image` | 2 | 0 | 0 | 4 |
| `core/Notification` | 2 | 1 | 0 | 8 |
| `core/Page` | 2 | 0 | 0 | 4 |
| `core/System` | 2 | 0 | 0 | 3 |
| `modules/fees` | 2 | 0 | 0 | 8 |
| `modules/retro` | 2 | 0 | 0 | 20 |
| `modules/test_tools` | 2 | 1 | 0 | 6 |
| `core/Scheduler` | 1 | 1 | 0 | 2 |
| `core/Service` | 1 | 0 | 0 | 1 |
| `core/Signature` | 1 | 0 | 0 | 3 |
| `core/View` | 1 | 0 | 0 | 3 |
| `modules/leadership` | 1 | 0 | 0 | 2 |

## 5. Journal

### Lot 0 — L'outillage — 2026-11-14

`scripts/catch-coverage.php` et son test (`tests/Core/System/CatchCoverageTest.php`),
le marqueur `@catch-unreachable`, ce document. Mutations vérifiées : juger un
`catch` vide sur son corps plutôt que sur sa ligne, accepter un marqueur sans
raison, ignorer le `basePath` du rapport, ne pas suivre une accolade `${`
d'interpolation, ranger `unknown` avec `never` — chacune fait échouer un test.

**La mesure, refaite** (§ 4) : 1 101 blocs, 429 jamais exécutés (39,0 %), dont
72 vides. Contre 853 blocs et 366 jamais exécutés au 2026-09-28 : `main` a
gagné près de 250 `catch`, et la part non exécutée a peu bougé. Les fichiers
des huit premiers lots tiennent (`CampaignController`,
`MemberEmailAddressController` : zéro) ; les cinq `catch` vides de
`MailTransportChain` relevés le 2026-09-28 y sont toujours.

**Le premier marqueur, et un « inatteignable » qui ne l'était pas.** Le lot 1
tenait pour fenêtres TOCTOU les deux branches restantes de
`MassMailController`. Celle de `tracking()` l'est : `getTrackingData()` ne lève
que sur un email absent, que `findVisibleEmail()` vient de lire dans la même
requête — elle porte le marqueur. Celle de `resend()` ne l'était pas :
`resendDelivery()` lève aussi « Ce message ne peut pas être renvoyé. », et la
mesure la trouve désormais exécutée. C'est la raison de la règle « lire toutes
les raisons de l'appel entouré » (§ 2).

### Lots 1 et 2 — `core/Mail` — 2026-11-14

**35 → 0** jamais exécutés, dont les 16 vides ; deux marqueurs. Remesuré sur
`tests/Core/Mail` avant et après (PCOV, MariaDB).

**Lot 1, les seize `catch` vides** — ceux qui avalent une erreur exprès :
quinze sont atteints par un état réel (journal indisponible : `DROP TABLE
event_log` ; écriture refusée : trigger SQLite `RAISE(FAIL)` ; table absente),
et chaque test asserte ce que l'avalement préserve — l'envoi part, le blocage
tient, le message va en file, le compteur ne bouge pas. Remplacer le `catch`
par un rethrow fait échouer chacun.

**Lot 2, les dix-neuf autres** : réglage tronqué, archive DMARC forgée (un
membre qui déclare 100 octets et en déploie 4 Mo), **course réelle** à deux
connexions sur MariaDB (index unique, instantané REPEATABLE READ), trigger
`SIGNAL 45000` sur MariaDB là où SQLite rend un refus de trigger sous le même
code 23000 qu'une collision de clé — et que le code lirait donc comme « déjà
là ». Un module doublé seulement quand sa panne est le sujet (le courrier
entrant qui ne répond pas).

**Les deux marqueurs.**
- `MailTransportChain::hasSpentItsQuota()`, la réserve : elle ne relit que les
  deux tables que `candidates()` vient de lire dans le même envoi, sans E/S
  entre les deux. Seule une connexion perdue dans cet intervalle y mène.
- `DrainDeferredMailHandler`, l'écriture des pièces jointes dans le répertoire
  temporaire : seul un disque plein ou qui refuse l'atteint. **C'est un
  compromis, pas une impossibilité** : la branche est réelle en production,
  mais la classe ne prend aucun répertoire qu'un test pourrait rediriger, et
  une suite qui tourne en root ne peut pas faire refuser `/tmp`. La couvrir
  demanderait d'injecter le répertoire temporaire dans le gestionnaire — un
  changement d'API hors du périmètre d'un lot de tests, suivi par #1217.

**Trouvé en chemin.**
- Un test de `PinnedProviderTransport` croyait la table des compteurs absente
  du jeu de test (elle y est depuis longtemps) : il ne traversait jamais la
  branche qu'il disait couvrir. Il force désormais l'échec par un trigger.
- Un docblock de `MailService`, orphelin au-dessus de la méthode voisine, a
  retrouvé `journalDeferral()`.
- `SeedMailboxes` : un `catch` est une couverture redondante (chaque appelant
  attrape déjà) — son mutant rethrow survit, celui qui change ce qu'il fait
  échoue. Gardé.
- Ouverts à part : #1209 (un échec en base est imputé au fichier des secrets),
  #1210 (une panne du courrier entrant présentée comme une boîte à ouvrir),
  #1211 (la page des boîtes témoins tombe si les réglages sont illisibles et
  qu'un fournisseur a été mesuré ; un cache de réglages vide mémorisé après un
  échec).

### Lots 3a et 3b — `modules/rental` — 2026-11-14

**42 → 0** jamais exécutés, dont les 6 vides ; un marqueur. Remesuré sur
`tests/Modules/Rental` avant et après (PCOV, MariaDB).

**Lot 3a — les vides, les contrôleurs et les dépôts (25).** Saisie refusée
(fichier non autorisé, copie signée en texte), bien supprimé entre-temps,
réservation dont le maintien a expiré pendant qu'une autre tient les dates,
table absente, trigger qui refuse une écriture, identifiants de boîte
illisibles (clé changée sans rechiffrer), PDF à tables de références en flux
(ce qu'écrit le scanner d'un téléphone), course réelle à deux sessions sur
MariaDB pour les marques et positions de lecture. Le transport d'e-mail n'est
doublé que là où sa panne est le sujet.

**Lot 3b — les services (18).** Photo d'état des lieux illisible, ligne de
facture antérieure à #980, catégorie Finances archivée, trop-perçu refusé par
les Finances, réservation sans prix confirmée, `SIGNAL 45000` sur MariaDB pour
distinguer un refus d'une collision de clé (SQLite les confond sous 23000).
Aucun changement de code produit : chaque branche n'avait simplement jamais
reçu sa panne.

**Le marqueur** : `RentalSignedContractService`, la reprise du PDF que
Ghostscript vient de réécrire. `rewriteForImport()` demande un PDF 1.4, que
FPDI lit ; seuls un Ghostscript qui écrirait un fichier illisible ou un disque
plein y mènent, et un test devrait truquer la sortie de Ghostscript.

**Trouvé en chemin, ouvert à part.**
- **#1212 — perte de données** : supprimer un bien efface ses réservations
  malgré l'avertissement « Possible seulement pour un bien qui n'a encore
  aucune réservation » (`hasHistory()` répond toujours `false`, et
  `rental_bookings.asset_id` est en `ON DELETE CASCADE`).
- **#1213** : sur une copie signée illisible, Ghostscript « réussit » en
  écrivant une page blanche, contresignée comme un contrat.

**Et #1221**, deux échecs silencieux : `RentalBookingRepository::addRenterEmail()` prend tout
`PDOException` pour « déjà là » (le message du service reste juste, mais
l'apprentissage d'adresse échoue en silence) ; les deux `catch` de
notification des gestionnaires n'écrivent rien au journal quand la recherche
des destinataires échoue (le journal du service de notification ne couvre que
l'envoi).

### Lot 4 — `core/Http` — 2026-11-15

**36 → 1** jamais exécuté au rapport, dont les 4 vides ; un marqueur, un
`catch` retiré avec le défaut qu'il cachait. Remesuré sur `tests/Core/Http`
avant et après (PCOV, MariaDB).

**34 branches atteintes** par un état réel : restauration déjà en attente,
table ou ligne de réglage absente, badge supprimé, fichier chiffré perdu ou
corrompu, fichier des secrets illisible, archive d'une autre version, dossier
de clés occupé par un fichier, vue sur une table supprimée, fichier texte nommé
`.jpg`, trigger qui refuse une écriture. GitHub et le transport d'e-mail ne
sont doublés que là où leur panne est le sujet.

**Celle que le rapport ne voit pas** : `ErrorHandler::guard()` (≈ l. 168).
Son test la traverse, dans un processus PHP à part puisque `guard()` termine
la requête par `exit` ; ce même `exit` empêche ce processus de rendre sa
couverture. La branche est atteinte et son effet asserté (page générique,
détail au seul journal du serveur, rien après), mais elle reste comptée
« jamais exécutée ». Elle sera traitée avec la mesure, avant le dernier lot :
ce n'est pas une branche inatteignable, et le marqueur ne doit pas servir à
la masquer.

**Le défaut corrigé** : `SetupController::handleConfigUpdate()` enveloppait
l'écriture de `site_name`, `short_name` et `base_url` dans un `catch` vide,
puis affichait « Configuration enregistrée avec succès ». Le `catch` est
retiré : un refus atteint le message d'erreur qui existait déjà.

**Le marqueur** : la suppression d'un document de section, dont la seule
exception métier relit une ligne lue deux lignes plus haut dans la même
requête.

### Lot 5 — `core/Storage` — 2026-11-15

**31 → 0** jamais exécutés, dont les 9 vides ; un marqueur. Remesuré sur
`tests/Core/Storage` et `tests/Core/Maintenance` avant et après (PCOV,
MariaDB).

**30 branches atteintes** par un état réel : panne de Google Drive (statut
503 ou 500 sur une requête précise, PHP sans cURL), de S3 (`NoSuchKey`) ou
de WebDAV (404, 401), disque qui refuse l'écriture d'un fichier témoin,
horloge qui épuise le budget d'un contrôle, ligne `../public` parmi les
emplacements, table absente, trigger qui refuse une écriture, secret
illisible, dossier de destination occupé par un fichier. `FakeDrive` gagne
un crochet `$intercept` qui répond un statut ou lève une exception pour une
requête choisie.

**Le marqueur** : la marche tolérante de `DirectorySize`. La racine est
ouverte par le constructeur, `CATCH_GET_CHILD` avale chaque enfant
illisible, et lire les entrées d'un dossier déjà ouvert ne lève rien — même
la racine supprimée en cours de marche (vérifié).

**Trouvé en chemin, ouvert à part.**
- **#1223** : une copie de protection peut être enregistrée comme protégée
  sans aucun contrôle, quand la destination ne répond ni sa somme ni sa
  taille pendant une panne.
- **#1224** : un refus de Google Drive n'affiche jamais le « rien n'a été
  changé » prévu pour lui.
- **#1230** (trouvé au lot de la maintenance) : un sous-dossier dont le
  chemin dépasse PATH_MAX est omis sans le dire.

### Lot 6 — `modules/finance` — 2026-11-15

**23 → 0** jamais exécutés ; trois marqueurs. Remesuré sur
`tests/Modules/Finance` avant et après (PCOV, MariaDB).

**20 branches atteintes** par un état réel : fichier refusé (un vrai fichier
texte déposé comme reçu), catégorie encore utilisée, créance dépassée,
débit déclaré « sans créance », trigger qui refuse une écriture (rattachement
d'un reçu, campagne, import de relevé, purge), fichier disparu du disque, JPEG
annonçant plus de pixels que le plafond, répertoire des membres illisible,
clé étrangère réelle sur MariaDB.

**Les marqueurs** : trois relectures, dans la même requête, d'une ligne lue
juste au-dessus — supprimer un reçu, changer son compte, ouvrir le
rapprochement d'un compte visible. Seule une purge ou une modification
concurrente entre les deux lectures y mène.

**Trouvé en chemin, ouvert à part.**
- **#1225 — perte de données** : sur MySQL/MariaDB, la purge des reçus
  orphelins efface le fichier chiffré puis échoue sur la clé étrangère ; le
  reçu reste listé et ne se télécharge plus. La suite SQLite, sans clés
  étrangères, ne pouvait pas le voir.
- **#1226** : remplacer un reçu de la pile à trier répond « Reçu introuvable. ».
- **#1227** : un reçu reconnu dont le classement échoue est abandonné sans
  trace.

### Lot 7 — `core/Maintenance` — 2026-11-15

**21 → 0** jamais exécutés ; un marqueur. Remesuré sur `tests/Core/Maintenance`
(PCOV, MariaDB) : il en reste un, `Remote/RemoteCopies.php:63`, que
`RemoteCopiesControllerTest` (sous `tests/Core/Http`) exécute déjà — zéro sur
la suite entière.

**20 branches atteintes** par un état réel : secrets illisibles, table
absente, trigger qui refuse une écriture, `SIGNAL` sur MariaDB, archive ou
sauvegarde de sécurité abîmée, quota dépassé, lien symbolique refusé. Chaque
test vérifie l'effet et échoue quand le `catch` relance.

**Le marqueur** : la lecture de `ATTR_SERVER_VERSION` dans `HostHealth`.
`pdo_mysql` la sert depuis la poignée de main mise en cache, même après que le
serveur a coupé la connexion (mesuré sur MariaDB 10.11), et `pdo_sqlite` comme
`pdo_pgsql` la répondent localement. Seul un pilote que l'application n'utilise
jamais la refuse.

En chemin, `FullResetHandlerTest` et `RestoreBackupHandlerTest` ne laissent
plus de dossiers temporaires derrière eux.

### Lot 9 — `core/Support` — 2026-11-15

**20 → 0** jamais exécutés ; un marqueur. Remesuré sur `tests/Core/Support`
(PCOV), après fusion de `main` : #1251 y a ajouté deux `catch` (29 blocs au
lieu de 27), atteints eux aussi.

**21 branches atteintes** par un état réel : ligne de réglage absente, table
absente ou amputée d'une colonne, secrets illisibles, archive disparue du
disque, trigger qui refuse une écriture, serveur de base injoignable, journal
impossible à écrire ; le transport n'est doublé que là où sa panne est le
sujet. Chaque test vérifie l'effet et échoue quand le `catch` relance ou
change ce qu'il fait.

**Le marqueur** : la lecture de version d'une commande dans
`CommandsCollector`. `run()` et l'échappement autour ne lèvent que sur un octet
nul, un argument au-delà d'`ARG_MAX` ou un délai sous une seconde ; le délai
est celui par défaut, l'option une constante, et le chemin un exécutable trouvé
par `command -v` ou dans une liste fixe.

**Un test retiré à la fusion** : celui qui exigeait qu'un paquet précédent dont
la fiche ne se relit pas soit oublié quand même. C'est exactement ce que #1251
a corrigé (les octets restaient sur le disque sans rien pour les retrouver) ;
son propre test couvre le même `catch` avec le comportement voulu.

### Lot 10 — `modules/registration` — 2026-11-15

**17 → 0** jamais exécutés ; un marqueur. Remesuré sur `tests/Modules/Registration`
(PCOV), après fusion de `main`.

**16 branches atteintes** par un état réel : ligne de réglage absente, trigger
qui refuse une écriture, adresse qui ne se déchiffre plus, jeton chiffré sous
une autre clé, course perdue, transport qui refuse l'envoi. Chaque test vérifie
l'effet et échoue quand le `catch` relance ou change ce qu'il fait.

**Le marqueur** : la boucle du retrait ou du refus groupé. Rien n'y lève
l'exception attrapée. `findNonFinalForYear()` ne rend que des demandes en
attente ou acceptées, qui peuvent toutes deux passer à « refusée » et à
« retirée ». La raison est vérifiée avant la boucle. Un refus ne prend jamais le
verrou de place, et la planification d'un e-mail répond `null` plutôt que de
lever.

**Trouvé en chemin, ouvert à part.**
- **#1235** : une demande encodée pendant un retrait ou un refus groupé est
  écrasée, pas sautée. La transition juge le statut lu avant la boucle, et
  l'`UPDATE` ne pose aucune condition sur le statut courant.
  `testABulkWithdrawalOverwritesARequestEncodedMeanwhile` fige ce comportement
  et dit ce qui changera quand il sera corrigé.
- **#1236** : un souhait d'ami perdu ou une confirmation d'adresse non envoyée
  ne laissent aucune trace.

`MigrationServiceTest::testFailedMigrationDoesNotPartiallyApply` échouait avant
d'ouvrir la transaction qu'il disait tester ; il la traverse désormais.
