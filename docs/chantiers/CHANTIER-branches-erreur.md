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
| `modules/rental` | 42 | 6 | 0 | 102 |
| `core/Http` | 36 | 4 | 0 | 134 |
| `core/Mail` | ~~35~~ **0** (lots 1-2) | ~~16~~ 0 | 2 | 77 |
| `core/Storage` | 31 | 9 | 0 | 67 |
| `modules/finance` | 23 | 0 | 0 | 54 |
| `core/Maintenance` | 21 | 3 | 0 | 55 |
| `modules/inbound_mail` | 21 | 4 | 0 | 40 |
| `core/Support` | 20 | 4 | 0 | 27 |
| `modules/registration` | 17 | 4 | 0 | 47 |
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
  changement d'API hors du périmètre d'un lot de tests.

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

