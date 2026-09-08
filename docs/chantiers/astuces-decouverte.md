# Chantier — Astuces de découverte (« Le saviez-vous ? »)

Journal d'implémentation du document de chantier « Astuces de découverte »
(itérations IT-01 à IT-03). Une section par itération : ce qui a été
livré, les décisions prises en autonomie, les divergences constatées entre
le document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier lui-même n'est pas dans le dépôt et ne s'y ajoute
pas : ce journal est ce qui en reste.

---

## IT-01 — Le socle : la clé `discovery` et l'état « vu »

**Livré.** Aucune interface, comme demandé — le parseur et le schéma sont
ce qui peut casser les ~120 fichiers du corpus, ils passent seuls.

- `Core\Help\DiscoveryPriority` — enum backed string (`High='1'`,
  `Normal='2'`, `Low='3'`, `Off='off'`) avec un `rank()` de tri.
- `HelpFrontMatterParser` : la clé `discovery` ajoutée à `OPTIONAL_KEYS`,
  lue par `tryFrom()`, **jetant une `HelpException` nommant le fichier**
  sur une valeur inconnue — et aussi sur une valeur vide (voir les
  décisions autonomes).
- `HelpTopic::$discovery`, champ en lecture seule, `Normal` par défaut.
- **Le piège du cache**, traité ici : `HelpRegistry::CACHE_FORMAT`, une
  marque de format intégrée à la clé du cache sérialisé. Sans elle, une
  installation déployée par artefact puis mise à jour sur place garde une
  version qui ne bouge qu'à la release — et désérialiserait indéfiniment
  un index écrit avant que `discovery` n'existe. `DiscoveryPriority` a
  aussi été ajouté à la liste des classes autorisées de
  `SerializedFileCache` : un enum n'est pas dégradable en
  `__PHP_Incomplete_Class`, une classe manquante y serait un fatal et non
  le « miss » que ce cache est écrit pour survivre.
- `schema/core.sql` : la table `help_topics_seen` et la colonne
  `user_accounts.help_discovery_snoozed_until`, avec leurs commentaires.
- `Core\Help\Discovery\SeenTopicRepository` — seule couche à toucher PDO :
  `findSeenIds`, `countSeen`, `markSeen`, `clear`, `snoozedUntil`,
  `snooze`.
- `Core\Help\Discovery\DiscoveryService` — `nextTopics()`, la graine
  reproductible, le tri éditorial, les quatre réglages.
- Les quatre réglages `core` enregistrés dans `public/index.php`
  (`help_discovery_enabled`, `_interval_hours`, `_snooze_days`,
  `_batch_size`, tris 297 à 300).
- Tests : parseur (défaut, chaque valeur, valeur inconnue, valeur vide),
  cache (clé sans marque de format = miss, `discovery` qui survit à
  l'aller-retour), repository sur SQLite **et sur MySQL**, service (17
  cas : commutateur, report en cours, report échu, rôle, module non
  enregistré, `off`, déjà vu, troncature, priorité contre graine,
  reproductibilité, changement d'état, deux comptes, délais, défauts).
- `ARCHITECTURE.md` §8.64 : la clé `discovery` documentée dans le
  paragraphe qui décrit le format du front matter.

**Décisions autonomes.**

1. **`markSeen()` s'écrit en deux dialectes, pas en un.** Le document
   demande « un `INSERT … ON DUPLICATE KEY UPDATE`, jamais une boucle de
   requêtes ». La contrainte réelle est la boucle, pas la syntaxe : la
   base de test par défaut est SQLite en mémoire, qui ne connaît pas cette
   clause. C'est l'appariement portable que ce dépôt utilise déjà
   (`Modules\Presences\Repository\PresenceRepository`,
   `Modules\UsageStats\Repository\PageViewRepository`) —
   `ON CONFLICT … DO NOTHING` sur SQLite, `ON DUPLICATE KEY UPDATE` sur
   MySQL/MariaDB — dans **une** requête, quelle que soit la taille du lot.
2. **Une seconde classe de test, sur le moteur réel.**
   `SeenTopicUpsertOnMysqlTest` rejoue l'upsert et l'aller-retour de la
   colonne de report contre MySQL/MariaDB, dans sa propre base jetable. Le
   test SQLite seul aurait laissé la clause MySQL non exercée — la forme
   exacte du défaut qui a valu son existence à
   `Tests\Core\Scheduler\LivePrefixGuardOnMysqlTest`.
3. **`snoozedUntil()` lit par `Core\Service\DateInput::fromStorage()`**,
   pas par `createFromFormat()`. `Tests\Security\DateParsingConvergenceTest`
   interdit le second partout sauf dans `DateInput`, et c'est de toute
   façon le bon outil : une colonne vide doit se lire « rien ne retient »
   et jamais « maintenant ».
4. **`discovery:` sans valeur est une erreur**, pas un défaut implicite.
   Le document ne tranche que la valeur inconnue ; une clé écrite puis
   laissée vide est la même faute de frappe avec le même symptôme
   indébogable.
5. **`eligibleTopics()` existe à côté de `nextTopics()`.** Le document ne
   nomme que le second, mais IT-02 a besoin de l'ensemble éligible complet
   à deux endroits : revalider les ids qu'un navigateur prétend avoir lus,
   et consommer tout le reliquat pour « Ne plus me proposer ». Il est
   délibérément **non** filtré par le commutateur ni par le report — ses
   deux appelants agissent sur un dialogue déjà légitimement affiché —
   tandis que `nextTopics()` applique les deux barrières puis tronque.
6. **`countSeen()` est conservé** bien que la graine puisse se contenter
   de `count(findSeenIds())` : c'est lui qui décidera, en IT-02, si
   « Revoir les astuces » a quelque chose à défaire sur `/account`. La
   graine, elle, réutilise le comptage déjà en main plutôt que de
   redemander une requête.
7. **Un réglage à zéro ou négatif retombe sur son défaut.** Un lot de
   taille 0 serait une fenêtre vide et un intervalle négatif une fenêtre
   qui ne part jamais ; ni l'un ni l'autre n'est ce qu'un administrateur
   veut dire en vidant un champ.

**Divergences avec le dépôt réel.**

- Le document décrit `HelpService::CATEGORY_ORDER` comme un départage à
  retirer. Il est **déjà** `private` et ne sert qu'au groupement de
  `/aide` : rien à retirer, rien à toucher — `DiscoveryService` n'y touche
  pas et trie sa propre liste à plat.
- `HelpService::listForRole()` rend un tableau **groupé par catégorie**
  (`array<string, HelpTopic[]>`), pas une liste plate ; le service
  l'aplatit.
- La numérotation des réglages `core` s'arrête à 296 dans
  `public/index.php` : les quatre nouveaux prennent 297 à 300.
- `tests/Core/Database/SqlParserTest` compte les tables de
  `schema/core.sql` en dur (48 → 49) ; il fallait le mettre à jour, ce que
  le document ne pouvait pas prévoir.
- Aucune entrée `<testsuite>` à ajouter : `tests/Core/Help/Discovery/` vit
  sous `tests/Core`, que `phpunit.xml` déclare déjà récursivement.

**Reporté.** Rien. Le câblage du service dans le root de composition part
avec IT-02, qui est ce qui le consomme.
