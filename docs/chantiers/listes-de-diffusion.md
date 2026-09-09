# Chantier — Listes de diffusion

Journal d'implémentation du document de chantier « Listes de diffusion »
(itérations IT-01 à IT-05). Une section par itération : ce qui a été
livré, les décisions prises en autonomie, les divergences constatées
entre le document de chantier et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier lui-même n'est pas dans le dépôt et ne s'y ajoute
pas : ce journal est la seule trace qui lui survit.

---

## Divergence transverse — le bump de version n'est plus ce que le document décrit

Le document impose, en convention de travail, que « toute modification de
`modules/mass_mail/schema.sql` impose de monter `version` dans
`modules/mass_mail/module.json`, dans la même PR », au motif que
`ModuleManager` ne réapplique le schéma que si la version déclarée dépasse
celle enregistrée.

**Ce n'est plus vrai dans le dépôt.** `AGENTS.md` § Database :

> **A module's `schema.sql` no longer needs a `module.json` version bump to
> take effect.** […] The whole declared schema — `schema/core.sql` plus
> every `modules/*/schema.sql`, enabled or not — is now migrated as one set
> by whatever deploys the code (`Core\Database\SchemaFiles`,
> ARCHITECTURE.md §10). Editing a module's `schema.sql` is enough.

La règle qui reste est différente : on monte `version` quand le module
change d'une façon que ses utilisateurs doivent voir, ou quand le nouveau
manifeste cesse de déclarer un réglage que l'ancien déclarait — c'est cette
purge-là que la comparaison de versions pilote encore.

**Décision.** La version est montée à chaque itération de ce chantier,
comme le document le demande — mais pour la raison que `AGENTS.md` donne
(le module change visiblement) et pas pour celle que le document donne (le
schéma ne serait pas réappliqué). Le résultat sur disque est le même ; le
commentaire qui l'accompagne n'aurait pas été le même.

---

## IT-01 — La bascule vers l'Espace admin

**Livré.** Le déplacement, et rien d'autre.

| Avant | Après |
|---|---|
| `/config/mass-mail` | `/admin/listes-de-diffusion` |
| `/config/mass-mail/lists[…]` | `/admin/listes-de-diffusion/lists[…]` |
| `menu: configuration`, `role_min: superadmin` | `menu: espace_admin`, `role_min: admin` |
| libellé « Envoi de mails » | libellé « Listes de diffusion » |
| `Controller\ConfigController` | `Controller\MailingListController` |
| `views/config.html.twig` | `views/mailing_lists.html.twig` |
| `public/assets/js/mass-mail-config.js` | `public/assets/js/mass-mail-lists.js` |
| `help/config-envoi-mails.md` | `help/listes-de-diffusion.md` |

`POST /config/mass-mail/settings`, l'action `saveSettings()`, la carte
« Vitesse d'envoi » du gabarit et le bloc JavaScript correspondant sont
supprimés. Les quatre réglages (`batch_size`, `batch_interval_minutes`,
`merge_retention_months`, `previous_year_active_cutoff`) sont intacts :
vérifié, aucun ne figure dans
`Core\Http\Controller\SettingsController::EXCLUDED_FROM_GENERIC_PAGE`, donc
tous les quatre remontent seuls dans Configuration > Réglages.

Mis à jour dans la même PR : `tests/dast/authz-fixtures.json`,
`scripts/perf/urls-admin.txt`, les deux points de câblage de
`public/index.php`, `specifications.md` (la ligne descend du tableau §4.5
vers celui du §4.4), `ARCHITECTURE.md` (§8.71sexies), le sujet d'aide et
l'entrée `listes-de-diffusion` de `HelpLabelDriftTest::ALLOWLIST`.

**Décisions prises en autonomie.**

- **`menu_group: "services"`.** Les quatre colonnes d'`espace_admin` sont
  « Membres & année », « Contenu du site », « Services » et « Suivi »
  (`Core\View\MenuBuilder::MENU_GROUPS`). Une liste de diffusion n'est ni
  un membre, ni du contenu de site, ni un suivi ; « Services » est la
  colonne où vivent déjà Inscriptions, Rétrospectives, Locations et SOS
  Staff d'U.
- **Le contrôleur s'appelle `MailingListController`**, pas
  `MailingListsController` ni `ListsController` : il pilote
  `MailingListService` et `MailingListRepository`, qui existaient déjà.
- **Les fichiers de vue, de script et de test suivent le renommage.** Le
  document ne le demandait que pour le contrôleur, mais un gabarit nommé
  `config.html.twig` sur une page qui n'est plus dans Configuration est
  exactement le genre de nom qui ment.
- **Le sujet d'aide est réécrit, pas seulement renommé.** Son `id` devient
  `listes-de-diffusion`, sa catégorie passe de « Configuration » à « Espace
  chefs d'U », son `role_min` de `superadmin` à `admin`, et sa section
  « La vitesse d'envoi » devient une phrase de renvoi vers Configuration >
  Réglages — la page ne porte plus ce réglage. Une troisième `question:`
  a été ajoutée (« Pourquoi ne puis-je pas supprimer une liste de
  diffusion ? »), la règle « désactiver plutôt que supprimer » étant ce
  qu'un lecteur vient chercher.

**Tests.** `Tests\Modules\MassMail\Controller\MailingListRbacTest`
(nouveau) rejoue chaque route sous `/admin/listes-de-diffusion` à travers
le vrai `Router`/`RbacGuard` : `admin` passe, `chief` reçoit 403. Ses cas
sont lus **dans `module.json`**, pas retapés — une route ajoutée plus tard
arrive avec sa couverture ou pas du tout. `ModuleManifestTest` remplace son
assertion « les routes `configuration` sont `superadmin` » par « le module
ne déclare plus aucune route `configuration` » plus les floors
d'`espace_admin`, et gagne une assertion sur la survie des quatre réglages.
`ErrorMessageLeakTest` perd son test de `saveSettings()`, dont la méthode
n'existe plus. La suite Vitest est renommée avec le script et perd son
bloc « sending speed ».

**Reporté.** Rien.

---

## IT-02 — Le critère badge, et les sélecteurs

**Livré.** Le troisième axe, la sémantique D5, la refonte des sélecteurs,
la phrase en direct et le compteur en direct.

- `mass_mail_list_badges (list_id, badge_id)`, sur le modèle exact des deux
  autres jonctions. `badges` est une table **core**, donc cette clé
  étrangère ne traverse aucune frontière de module (AGENTS.md § Database).
  La résolution joint `member_badges` sur le `member_year` de l'année
  résolue — les badges sont déjà historisés par année (§8.11), il n'y avait
  rien à inventer. Version du module montée à 1.11.0.
- `MemberResolutionRepository::resolveCustomList()` prend un troisième
  tableau d'identifiants et applique D5 : ET entre axes, OU dedans, **un
  axe vide ne contraint pas**, les trois vides ne donnent **aucun membre**.
- `partials/select_bar.html.twig` en `mode: 'multi'` pour les trois axes.
- La phrase et le compteur, sous les sélecteurs, dans
  `public/assets/js/mass-mail-lists.js`, plus la route
  `POST /admin/listes-de-diffusion/preview-count`.

**Divergence — les chips n'existent plus dans ce dépôt.**

Le document de chantier demande de passer les trois axes en `btn-check` +
`btn btn-outline-primary`, en citant
`modules/inbound_mail/views/config/mailbox_scopes.html.twig` « plus huit
autres écrans » comme précédent, et prévient à juste titre des 44 px de
cible tactile et du défilement horizontal sur mobile.

**Le dépôt a déjà tranché cette question, dans l'autre sens.** `design.md`
§1.4 et §7.10.1 :

> Le site a exactement deux composants de sélection […] **Select bar** —
> choisir une **donnée** : une section, un calendrier, un compte, un bien
> louable, **des badges**. La liste est ouverte, vient de la base, et ses
> libellés sont longs. […] **Nav rail** — se déplacer entre les sous-pages
> ou les vues **d'une même page**. […] *ensemble fixe, déclaré dans le
> code, libellés courts → nav rail. Ensemble ouvert, venant de la base →
> select bar.*
>
> Aucun des deux ne cache quoi que ce soit : pas de `+N`, pas de repli
> côté client, pas de mesure du DOM après rendu.

Et §7.6 rappelle que le composant à chips a été **retiré** précisément
parce que son repli « +N » cachait des pages entières derrière un contrôle
de débordement.

Les huit précédents cités sont tous des **`btn-check` radio dans un
`btn-group`** — un ensemble fixe de deux à cinq options déclarées dans le
code (`shared`/`dedicated`, `local`/`s3`, les cinq visibilités d'un
article). Aucun n'est une multi-sélection venant de la base. Les fonctions,
les sections et les badges le sont tous les trois, et le document le dit
lui-même : « une unité a facilement vingt fonctions Desk distinctes ».

**Décision.** Le select bar en `mode: 'multi'`, conformément à `design.md`,
qui prime sur ce document sur toute règle générale. Il apporte gratuitement
les trois choses que le document voulait obtenir des chips : la cible
tactile de 44 px (par `.tap-target`, dans le seul endroit où vit le
dimensionnement tactile), un panneau qui défile **lui-même** plutôt que la
page, et rien de caché. Il ne demande aucun CSS non plus. Un test
(`UxConventionsTest`) défend déjà cette convention ; y contrevenir aurait
été un troisième composant de sélection dans un dépôt qui vient d'en
supprimer un.

**Autres décisions prises en autonomie.**

- **La validation passe de « au moins une fonction ET une section » à « au
  moins un critère, sur n'importe quel axe ».** Sans cela, un axe vide
  n'est pas exprimable et la sémantique D5 n'a nulle part où s'appliquer.
  Une liste sans aucun critère reste refusée en IT-02 : elle ne
  contiendrait personne et rien ne permet encore d'y ajouter quoi que ce
  soit — c'est IT-03 qui la rendra légitime en lui donnant des adresses.
- **Ce que faisait le code avec un axe vide, et pourquoi c'était à
  corriger** (le document demande de le vérifier et de le noter) :
  `resolveCustomList()` renvoyait l'ensemble vide dès qu'**un seul** des
  deux axes était vide, pas seulement les deux. Rien de stocké ne change de
  sens — la validation interdisait cet état — mais la convention était
  fausse dans une direction dangereuse : une liste qui aurait perdu sa
  dernière section (section supprimée par un import Desk, `ON DELETE
  CASCADE` sur `mass_mail_list_sections`) se serait mise à ne résoudre
  personne, silencieusement, au lieu de s'élargir.
- **`BadgeService` est injecté en cinquième position et **nullable**.** Le
  service n'en a besoin que pour proposer le vocabulaire des badges au
  formulaire ; résoudre une liste lit `mass_mail_list_badges` directement.
  `SendBatchHandler`, qui ne fait que résoudre, n'en passe donc pas, et le
  commentaire au point d'appel dit pourquoi.
- **Le compteur compte pour l'année scoute *effective* et la nomme.** Une
  liste ne porte aucune année (la page le dit) ; un nombre sans année se
  lirait comme une promesse sur celle que l'e-mail visera plus tard.
- **Le compteur est séquencé sur un jeton, pas « debouncé ».** Trois clics
  rapides laissent sinon à l'écran la réponse qui revient en dernier.

**Corrigé après revue (revue Claude sur la PR).** Un sélecteur ne
proposait que le vocabulaire *actif* — badges actifs, sections actives et
visibles. Or les trois sélecteurs sont le seul endroit où les critères
d'une liste font l'aller-retour : le formulaire soumet ce qu'ils
contiennent, et `replaceCriteria()` efface puis réinsère à partir de cette
soumission. Un identifiant sans élément où être sélectionné est donc un
identifiant que **n'importe quelle** modification — même celle de la seule
description — supprimait en silence. Et sous la sémantique que cette
itération introduit, une liste perdant ainsi son dernier badge ne se
serait pas rétrécie : son axe badge aurait cessé de contraindre quoi que
ce soit et la liste se serait **élargie** à tous les membres que les
autres axes désignent. `getAllBadges()` et `getAllSections()` ajoutent
donc au vocabulaire actif tout ce qu'une liste nomme encore, suffixé
« (désactivé) » / « (retirée) » : un critère que personne ne voit est un
critère que personne ne peut retirer.

**Deux autres corrections après revue, même famille.**

- **Un badge encore croisé par une liste ne peut plus être supprimé.**
  `mass_mail_list_badges.badge_id` est une clé étrangère en `ON DELETE
  CASCADE`, et une cascade est silencieuse par construction : sous la
  sémantique de cette itération, perdre la ligne n'annule pas la liste,
  elle cesse de contraindre — « la meute ET le badge X » devient « la
  meute ». `BadgeService::delete()` refusait déjà un badge attribué à un
  membre ; il interroge désormais `Core\Module\BadgeUsageProvider`
  (§7.4), que `Service\MailingListBadgeUsageService` implémente. Le hook est
  **défini par le cœur et implémenté par le module**, jamais l'inverse :
  la cascade vit dans le schéma de ce module, donc la connaissance aussi.
  `getUndeletableBadgeReasons()` donne à la page « Configuration >
  Badges » la phrase même que le serveur lèverait, pour que le bouton
  désactivé et le refus ne puissent pas se contredire. **Désactiver reste
  permis** — c'est l'opération qui veut dire « on n'utilise plus ça » —
  et l'élément grisé du sélecteur est précisément ce qui permet de
  retirer le critère à la main d'abord.
- **Le compteur en direct dédoublonne comme l'envoi.** Il comptait les
  lignes brutes de `resolveCustomList()` alors que l'envoi dédoublonne
  sur l'adresse en minuscules. Deux frères et sœurs sur une adresse
  familiale sont deux membres et **un** destinataire : le compteur
  promettait un nombre que l'envoi contredisait, et précisément dans les
  unités où l'adresse partagée est la règle. Il passe par
  `deduplicateByMemberAndAddress()`, la méthode que l'envoi emprunte
  déjà. Un membre sans adresse continue d'être compté : la question posée
  est « qui cette liste désigne », et l'exclure ferait diverger le nombre
  de la liste elle-même.

**Et une troisième, plus petite.** Le message de zéro destinataire
expliquait le piège du badge (« un badge n'est porté que par le Staff d'U
et les animateurs ») **quel que soit** le critère choisi. Dit à quelqu'un
qui n'a sélectionné qu'une section, il désigne un axe qu'il n'a jamais
touché : ça se lit comme un défaut de la page, pas comme un conseil sur
sa liste. L'explication n'apparaît plus que si un badge fait partie des
critères ; sinon, zéro est simplement zéro.

**Tests.** Résolution : ET entre axes, OU dedans, axe vide non contraignant
dans les deux sens, trois axes vides → ensemble vide, badge d'une année
passée non compté, liste par badge seul n'exigeant aucune fonction, membre
inactif jamais résolu. Contrôleur : `MailingListPreviewCountTest` (le
compte, le croisement badge × section d'animés à zéro, aucun critère à
zéro, jeton CSRF invalide, corps non-JSON). Vitest : la phrase (singulier,
pluriel, axe absent, retour à zéro critère) et le compteur (l'appel, le
singulier, le rouge à zéro, aucun appel sans critère, la réponse périmée
ignorée, l'échec annoncé). `npm run typecheck`. Plus, après revue : un
badge désactivé qu'une liste nomme encore reste proposé et grisé, un badge
désactivé que personne ne nomme reste absent, et la même paire pour les
sections ; la suppression d'un badge refusée tant qu'une liste le croise
et permise sinon, la même sans registre de hooks, la phrase de refus
identique des deux côtés, le fournisseur du module qui nomme les badges
croisés, et le compteur qui replie deux membres sur une adresse commune
tout en gardant celui qui n'en a pas ; et le zéro sans badge qui ne parle
pas de badge.

**Reporté.** Rien.

---

## IT-03 — Les adresses propres à la liste

**Livré.** La table, la résolution en union, la désinscription globale,
l'écran, le plafond, la RGPD et l'aide.

- `mass_mail_list_addresses` telle que le document la décrit, au commentaire
  près. `Repository\ListAddressRepository` est le seul endroit qui chiffre
  et déchiffre (SECURITY §5), `Repository\ListAddress` est l'objet valeur,
  `Service\ListAddressService` porte les règles. Version du module montée à
  1.12.0.
- `MailingListService::resolveMembersForYears()` renvoie désormais des
  entrées à `member_id` et `scout_year_id` **nullables** : c'est la forme
  qu'avait déjà un destinataire externe de publipostage. Les adresses
  passent par le `$seenAddresses` existant, donc le dédoublonnage se fait
  sur l'adresse normalisée — vérifié, c'était bien le cas, et c'est la
  seule chose qui puisse marcher : les deux moitiés de l'union ne
  partagent aucun identifiant.
- `MassMailService::freezeListRecipients()` gèle une adresse comme un
  destinataire à part entière : `member_id`, `scout_year_id`,
  `member_email_id` et `audience_row_id` tous nuls.
- Quatre routes JSON (`GET`/`POST .../lists/{id}/addresses`,
  `PATCH`/`DELETE .../addresses/{id}`), réglage
  `mass_mail_list_addresses_max` à 2000 avec sa description obligatoire,
  section repliée dans le gabarit,
  `public/assets/js/mass-mail-list-addresses.js`.

**Décisions prises en autonomie.**

- **La désinscription est appliquée sur toutes les branches, et elle
  atteint aussi la liste de suppression du publipostage.** Le document ne
  demandait que le `UPDATE … WHERE email_blind_index = ?`. Pris à la
  lettre, quelqu'un qui se désinscrit depuis une adresse de liste
  continuait de recevoir les publipostages, et réciproquement une adresse
  supprimée par un publipostage restait « active » à l'écran tout en
  n'étant jamais écrite. D2 dit exactement le contraire : « Quelqu'un qui
  demande qu'on cesse de lui écrire s'adresse à l'unité, pas à une liste. »
  Donc : toute désinscription marque les lignes `mass_mail_list_addresses`
  portant le même index aveugle, **et** un destinataire sans membre est
  aussi ajouté à `mass_mail_suppressed_addresses`. Au gel, une adresse
  supprimée devient une ligne `error` explicite plutôt qu'une omission
  silencieuse — sans quoi le suivi ne dirait pas pourquoi la personne n'a
  rien reçu.
- **Le distinguo dans `mass_mail_recipients` est le couple
  (`member_id` NULL, `audience_row_id`).** Le commentaire du schéma disait
  « NULL only for a mail-merge row » ; il en existe désormais deux sortes,
  et c'est `audience_row_id` qui les sépare. Le commentaire est corrigé
  dans la même PR, puisque c'est sur lui que le contrôleur de
  désinscription s'appuie.
- **La validation d'IT-02 n'a pas été assouplie.** D5 dit qu'une liste
  peut légitimement n'être qu'un carnet d'adresses, mais une liste se crée
  avant de recevoir ses adresses : autoriser zéro critère à la création
  aurait laissé créer des listes vides sans rien pour s'en apercevoir. Le
  plancher reste « au moins un critère », et une liste purement carnet
  s'obtient en croisant un critère qui ne désigne personne. **C'est une
  limitation réelle, notée ici plutôt que passée sous silence** ; la lever
  proprement demanderait de distinguer « liste vide par erreur » de
  « liste volontairement sans membres », ce qui est une question d'écran
  et non de schéma.
- **`ListAddressService` est un service à part**, pas une extension de
  `MailingListService` : un service par sujet (AGENTS, « Single file per
  concern »), et celui-ci porte le plafond, le journal et la validation.
- **Le nom est borné à 150 caractères** côté service. La colonne est un
  BLOB chiffré, donc rien ne borne à sa place, et un nom de dix kilo-octets
  est un coût de déchiffrement que personne n'a demandé.
- **`ListAddressRepository::findForList()` trie en PHP**, par nom puis
  adresse. Il n'y a pas d'`ORDER BY` possible sur du chiffré, et c'est la
  raison pour laquelle l'écran charge tout en une fois.

**Divergence — le rendu par tranches et la recherche.** Le document décrit
« rendu par tranches de 50 avec un afficher plus » et une recherche
« même normalisation que `TextNormalizerService` ». Les deux sont livrés
tels quels ; la normalisation est une **seconde implémentation de
l'algorithme** en JavaScript, ce que le dépôt admet explicitement pour
`OfflineWhitelist::matches()` — il n'y a pas d'exécution partagée entre PHP
et le navigateur. Ce n'est pas une seconde copie de la *donnée*.

**Corrigé après revue (revue Claude sur la PR).** Deux trous dans
l'invariant que cette itération énonce elle-même — « une demande, honorée
sur toutes les tables qui pourraient réécrire à cette adresse ».

- **La désinscription d'un membre n'écrivait pas sur la liste de
  suppression.** Elle ne désactivait que sa ligne `member_emails`, ce qui
  arrête le courrier aux membres et rien d'autre : un chef ajoutant la
  même adresse à une liste le lendemain, ou un import Excel la portant,
  passait à travers — aucun de ces deux chemins ne lit `member_emails`.
  Ce que la personne demande d'arrêter, c'est **l'adresse** ; la trace de
  sa demande doit donc vivre ailleurs que dans son adhésion. La
  suppression est désormais écrite **quel que soit** le demandeur. Elle
  ne restreint rien en retour : le gel des membres est gouverné par
  `member_emails.is_active` et ne lit pas cette table, donc quelqu'un qui
  réencode son adresse dans son compte est de nouveau joint comme membre.
  Seuls restent fermés les deux chemins adressés par l'adresse — les
  adresses propres d'une liste et le publipostage — c'est-à-dire
  exactement les deux que personne d'autre qu'un chef ne peut rouvrir.
  Et `ListAddressService::add()`/`edit()` écrivent désormais une ligne
  **déjà désinscrite** pour une adresse supprimée : refuser au chef (qui
  n'est pas la personne qui s'est désinscrite) l'aurait fait recommencer
  par Excel, et accepter en silence aurait affiché un compte d'adresses
  que l'envoi refuse ensuite.
- **Le dédoublonnage ne voyait que l'adresse Desk.** `MailingListService`
  écarte une adresse de liste égale à l'adresse Desk d'un membre — la
  seule que ses critères portent — mais le gel écrit une ligne par membre
  et par adresse **valide** (`member_emails` comprises). Une adresse de
  liste égale à la *deuxième* adresse d'un membre était donc un second
  e-mail à la même personne, ce que la page d'aide de cette itération
  promet précisément qui n'arrive pas. Le gel se fait maintenant en deux
  passes — les membres, puis les adresses de la liste — et saute une
  adresse déjà écrite : aucune ligne plutôt qu'une ligne `error`, parce
  que rien n'a échoué et personne n'a été oublié.

**Tests.** `ListAddressRepositoryTest` (chiffrement vérifié sur les octets
bruts, index aveugle insensible à la casse, unicité par liste, même adresse
dans deux listes, comptage sans déchiffrement, tri, désinscription
atteignant toutes les listes et idempotente). `ListAddressServiceTest`
(normalisation, adresse invalide, doublon, liste inconnue, ligne
désinscrite ni modifiable ni supprimable, plafond refusé avant écriture,
défaut du réglage). `ListAddressFlowTest` (adresses seules, critères seuls,
les deux, dédoublonnage membre ↔ adresse, exclusion des désinscrites, forme
du destinataire gelé, adresse supprimée ailleurs, désinscription propagée à
deux listes). `MailingListControllerTest` gagne les quatre routes.
`MailingListRbacTest` les couvre automatiquement — ses cas viennent de
`module.json`. Vitest : chargement unique, recherche insensible aux
accents, filtre, tranches de 50, ligne désinscrite sans boutons, ajout,
édition en place, annulation, suppression confirmée, et un nom porteur de
balises rendu en texte. Plus, après revue : une adresse de liste égale à
la *deuxième* adresse d'un membre ne produit pas un second envoi, et la
désinscription d'un membre ferme aussi le chemin des adresses de liste —
la ligne qu'un chef ajoute ensuite naissant désinscrite.

**Reporté.** L'aller-retour Excel, qui est IT-04 : le dépôt ne contient de
cette itération ni export, ni import, ni `replaceForList()`.

---

## IT-04 — L'aller-retour Excel

**Livré.** L'export en flux, l'import en deux temps, et rien d'autre.

- `Service\ListAddressImportService` porte les trois moitiés : `export()`,
  `analyse()` (n'écrit rien) et `apply()` (la seule qui écrit).
  `Service\ListAddressImportPreview` est ce que l'analyse renvoie,
  `Service\ListAddressImportException` ce qu'elle lève sur un problème de
  structure. `Repository\ListAddressRepository::replaceForList()` fait le
  remplacement. Version du module montée à 1.13.0.
- Trois routes : `GET .../addresses/export`, `POST .../addresses/import`
  (analyse), `POST .../addresses/import/confirm` (application).
- L'export passe par `Core\Http\SpreadsheetResponse`, comme l'export des
  réponses de formulaire du module `news` : généré dans la requête,
  diffusé, jamais écrit là où quelque chose pourrait le resservir. Rien
  n'étant stocké, il n'y a rien à faire passer par `FileAccessGuard`.
  `phpoffice/phpspreadsheet` était déjà une dépendance ; aucune n'a été
  ajoutée.

**Décisions prises en autonomie.**

- **Les deux temps sont deux requêtes, et la confirmation reporte les
  lignes.** Le document demande « téléversement → analyse → aperçu chiffré
  → confirmation explicite » et, séparément, que le fichier soit supprimé
  dès l'analyse. Les deux ensemble impliquent que la confirmation ne peut
  pas relire le fichier : elle porte donc les lignes que l'analyse lui a
  renvoyées. Conséquence assumée et traitée : ce que la confirmation porte
  est **revalidé et redédoublonné**, et le plafond est **redemandé**, dans
  une requête qui ne peut rien tenir pour acquis d'une précédente. Rien
  n'y est escaladé — un chef d'unité peut de toute façon ajouter
  l'adresse de son choix par la route d'ajout.
- **Le plafond est demandé à l'analyse *et* à la confirmation.** À
  l'analyse pour que le refus arrive avant qu'on propose de confirmer un
  remplacement inapplicable ; à la confirmation parce que c'est là qu'on
  écrit. Il compte les désinscrites comme survivantes, un remplacement ne
  les retirant jamais.
- **Les alias d'en-têtes sont tolérants dans un seul sens.** `Adresse`,
  `Adresse email`, `Email`, `Courriel`, `Mail` pour la colonne d'adresses ;
  `Nom`, `Nom complet`, `Contact` pour celle des noms. C'est ce que
  l'import Desk et l'import de publipostage font déjà, et cela n'affaiblit
  rien : un en-tête inconnu refuse toujours le fichier entier.
- **Un problème de structure refuse tout ; une mauvaise ligne, non.** Le
  document demande les deux comportements (« En-tête manquant ou mal
  orthographié → refus explicite, rien d'écrit. Adresse invalide →
  signalée, les autres passent »), qui sont deux mécanismes distincts :
  `ListAddressImportException` d'un côté, la liste `errors` de l'aperçu de
  l'autre.
- **Une ligne entièrement vide n'est pas une erreur.** Un bloc séparé d'un
  autre par une ligne blanche est une habitude de tableur, pas une faute.

**Tests.** `ListAddressImportServiceTest` écrit de **vrais fichiers
`.xlsx`** avec la bibliothèque que l'importeur relit — la seule façon de
savoir que les deux sont d'accord : en-tête mal orthographié refusé, aucune
colonne d'adresses refusée, fichier vide refusé, fichier qui n'est pas un
classeur refusé, colonnes trouvées dans le désordre et sous d'autres
orthographes, adresse invalide signalée sans bloquer les autres, doublon
réduit à une ligne, ligne blanche tolérée, compteurs justes sans rien
écrire, plafond refusé à l'analyse, remplacement appliqué, désinscrite
survivant dans les deux sens, nom corrigé sans doublon, confirmation
revalidée et redédoublonnée, plafond redemandé.
`ListAddressImportRoutesTest` couvre le `finally` — **le fichier est
supprimé après une analyse réussie, après un refus de structure et après
un fichier qui n'est pas un classeur du tout** — plus l'export, le refus
CSRF, l'extension refusée, et la confirmation. Vitest : l'export est un
lien, l'analyse envoie du `FormData` avec le jeton, la phrase de
compteurs, la clause « désinscrite » absente quand il n'y en a pas, les
erreurs de ligne rendues en texte, le refus structurel sans rien à
confirmer, « Annuler » qui n'envoie rien, la confirmation qui recharge
l'ensemble depuis le serveur, et l'échec qui laisse l'écran intact.

**Corrigé après revue (revue Claude sur la PR).**

- **Un en-tête en double était accepté en silence**, alors que
  `ARCHITECTURE.md` promettait le contraire dans la même PR. Lire la
  colonne arrivée en premier *est* exactement le remplacement silencieux
  par la mauvaise colonne que la reconnaissance par en-tête existe pour
  éviter. Le fichier est donc refusé, un message par rôle quel que soit
  le nombre de colonnes en trop, et joint aux autres problèmes
  structurels — « tous les problèmes listés d'un coup » reste vrai.
- **Le plafond comptait deux fois les désinscrites que le fichier porte
  déjà.** `export()` les écrit dans le fichier et `replaceForList()`
  compte une telle ligne comme *inchangée*, jamais comme un ajout :
  additionner toutes les désinscrites au compte du fichier refusait
  l'aller-retour d'une liste proche du plafond — c'est-à-dire exactement
  la taille de liste pour laquelle cette itération existe.
  `countUnsubscribedNotIn()` répond sur les index aveugles, sans rien
  déchiffrer, la question étant un nombre.

- **Le remplacement n'était pas transactionnel.** N insertions puis M
  suppressions : une panne entre les deux moitiés laissait la liste
  porter à la fois ce que le fichier apportait et ce qu'il retirait —
  au-delà du plafond qu'on venait de vérifier, et sans entrée au journal
  puisque l'appelant ne journalise qu'au retour. Enveloppé, annulé sur
  `\Throwable`, comme le fait déjà `BatchResetService`.
- **Le compte de doublons du fichier n'était affiché nulle part.**
  `analyse()` le calcule et la route le renvoie, mais l'aperçu ne le
  lisait pas : un fichier de 300 lignes annonçant « 280 ajoutées » sans
  rien dire des vingt autres se lit comme une perte. La phrase de résumé
  le nomme désormais.

- **Une confirmation sans champ `addresses` effaçait la liste.** Un
  corps tronqué, un bug de client ou une requête retouchée à la main
  arrivaient à `apply()` comme « remplacer par rien » — avec un 200, et
  sans rien de la confirmation en deux temps autour de laquelle toute
  l'itération est construite. Un remplacement par **rien** reste
  légitime (un fichier réduit à son en-tête) : ce qui est refusé, c'est
  l'absence du tableau, et une entrée qui n'est pas un objet — refusée
  au lieu d'être écartée, écarter transformant une charge utile abîmée
  en une liste plus courte, c'est-à-dire en suppressions que personne
  n'a confirmées.

**Complété dans la foulée de la revue d'IT-03.** `replaceForList()` ne
protège que les lignes désinscrites **de la liste qu'il remplace** — les
seules qu'il voie. Une adresse désinscrite sur une *autre* liste arrive
comme une ligne neuve et aurait été créée active, ce qui contredit D2.
`apply()` interroge donc la table de suppression sur le fichier entier en
une requête (`filterSuppressed()`) et marque ce qu'elle nomme : c'est la
seule table qui sache, toute désinscription y écrivant depuis IT-03, quel
que soit le demandeur. Test : un fichier portant une adresse supprimée
crée bien sa ligne, mais désinscrite, et elle ne rejoint jamais la liste
des adresses joignables.

**Reporté.** Rien.

---

## IT-05 — La liste par défaut « Anciens »

**Livré.** Une cinquième liste par défaut, calculée à la volée, et rien
d'autre.

- `Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS`, l'ENUM `list_type` étendue,
  version du module montée à 1.14.0. **Ni table, ni jonction, ni tâche
  planifiée** (D4).
- `MemberResolutionRepository::resolveFormerMembers()` : actif sur une
  année scoute *passée*, inactif sur l'année effective, présent dans au
  moins `former_members_min_scout_years` années scoutes distinctes, parti depuis
  moins de `former_members_max_years_since_departure` années scoutes.
- Les deux réglages, avec la description française obligatoire qui
  explique noir sur blanc pourquoi le seuil est en **années**.
- La description de la liste, **calculée** : « Anciens connus depuis
  2019-2020 · au moins 2 années scoutes · partis depuis moins de 10
  ans. »
- L'écran de composition masque le choix des années pour cette liste et
  met une note à sa place.

**Les comparaisons de dates se font sur `start_date`, jamais sur l'id.**
Une ligne `scout_years` est créée dès que quelque chose en a besoin, donc
son identifiant ne dit rien de la chronologie. La borne haute se compte
de la même façon en **années scoutes que l'unité a réellement connues**
(`scoutYearStartDateOffsetBy()`), pas en arithmétique de calendrier : une
unité qui a sauté une année n'a pas de ligne pour elle, et soustraire dix
à une date inclurait silencieusement une année qu'il fallait exclure.

**Le point à trancher : `unit_mail_consent` est appliqué, sur cette liste
uniquement.** Le reste du repository l'ignore délibérément — la colonne
Desk « Courrier d'unité » a été jugée peu fiable — et pour des membres
présents cette année, ça se défend : on leur écrit à propos de ce à quoi
ils participent. Quelqu'un parti depuis cinq ans est un autre cas : rien
dans la vie ordinaire de l'unité ne justifie de lui écrire, le seul
signal positif que quiconque ait jamais enregistré est cette colonne, et
un rebond ou une plainte venant d'une adresse que personne n'utilise plus
coûte la réputation d'envoi de tout le domaine — les mails aux familles
compris. Un oui peu fiable reste le seul oui qui existe. La raison est
écrite dans le code, comme le commentaire existant le fait pour la
décision inverse.

**Conclusion demandée : `member_years.leaving` n'est pas lu, et ce n'est
pas un oubli.** C'est un indicateur *prospectif*, posé sur la ligne de
l'année en cours — un chef, ou la réponse d'une famille, qui dit « ne
reviendra pas l'an prochain ». Il ne peut donc pas servir de critère :

- **son absence ne dit rien.** La plupart des départs sont silencieux —
  personne ne coche quoi que ce soit, la famille ne se réinscrit tout
  simplement pas. L'exiger réduirait « Anciens » aux départs que
  quelqu'un a pris la peine de saisir, c'est-à-dire à une minorité.
- **sa présence n'exclut personne non plus.** Un membre marqué partant
  puis revenu a une ligne active cette année, et la deuxième condition
  l'écarte déjà. Le drapeau n'ajoute rien là où il pourrait s'appliquer.
- **et il voisine avec des données qui n'ont rien à faire ici.**
  `leaving_comment_encrypted` est un texte libre souvent sensible
  (conflit, situation familiale, santé), déchiffré uniquement dans
  `Core\Member\DepartureRepository`. Une liste de diffusion n'a aucune
  raison de s'en approcher, même pour n'en lire que le drapeau voisin.

Son seul usage honnête serait décoratif — annoter « départ annoncé » à
côté d'un nom — et cette liste n'affiche pas de noms.

**Conclusion demandée : `previous_year_active_cutoff` ne recoupe pas ce
besoin.** C'est un repère **MM-JJ** (`07-31` par défaut), de type texte,
affiché dans la fenêtre d'envoi pour expliquer ce que représente la liste
de l'année scoute précédente. Sa propre description dit qu'il
« n'affecte pas la résolution réelle de la liste » : il ne filtre rien,
ne se compte pas en durée, et répond à une autre question (à quelle date
l'instantané de l'année précédente correspond à peu près). Les deux
réglages ajoutés ici sont donc bien deux, pas un troisième par accident.

**Autres décisions prises en autonomie.**

- **Chaque ancien garde son année, et la liste n'en a donc aucune.**
  `resolveMembersForYears()` traite cette liste à part, comme la liste
  externe : l'année de chaque destinataire est celle de sa dernière année
  active, la seule pour laquelle son profil existe et donc la seule que
  `mass_mail_recipients.scout_year_id` puisse porter. L'année *stockée*
  de l'e-mail ne sert qu'à une chose — l'année dont il faut être absent —
  et c'est délibérément celle-là, plutôt qu'une année que le service
  irait chercher lui-même, pour que le compteur avant l'envoi et le gel
  répondent à la même question.
- **La liste est acceptée par `validateAndSanitize()` et nommée dans la
  page « Envoi de mails ».** Une liste par défaut que le formulaire
  propose et que l'enregistrement refuse n'aurait pas été une liste ; et
  la colonne « Année » affiche `—` pour elle, comme pour le publipostage
  et la liste externe, puisque l'année qu'elle stocke n'est celle de
  personne.
- **La recherche par libellé passe d'un booléen par liste par défaut à
  la liste des types dont le libellé correspond.** Deux booléens qui
  seraient devenus trois puis quatre ; « Anciens » se cherche comme
  « Membres actifs » se cherche déjà.
- **Le plancher reste `admin`.** `MassMailAccessService::canUseList()`
  renvoie déjà `false` par défaut : un animateur de section ne propose
  pas cette liste, exactement comme « Membres actifs ».

**Tests.** Résolution : un membre d'une seule année n'est pas un ancien
au défaut de 2 (et en est un à 1), un membre actif cette année ne l'est
jamais même avec dix années derrière lui, un ancien revenu disparaît sans
rien d'autre qu'un import, la borne haute exclut au-delà du seuil et `0`
la désactive, l'adresse et l'année retenues sont celles de la dernière
année active, une ligne passée inactive n'est pas une adhésion, le
consentement est exigé ici et nulle part ailleurs, et la plus ancienne
année connue est celle où quelqu'un a été importé — pas celle qui existe
sans personne. Service : la liste figure parmi les listes par défaut, la
description reflète l'année la plus ancienne et les deux seuils, un
minimum à `0` retombe sur le défaut, deux anciens partageant une adresse
sont dédoublonnés, et sans année de référence la liste est vide. Envoi :
le gel étiquette chaque ancien avec sa propre dernière année active.
Vitest : les années masquées et la note affichée pour cette liste, et le
retour à la normale pour une liste qui, elle, vise une année. Plus, après
revue : le consentement lu sur la dernière année active et non sur une
plus ancienne (dans les deux sens), et une année soumise en douce qui ne
change rien à ce que la liste contient.

**Corrigé après revue (revue Claude sur la PR).**

- **Les deux clés de réglage étaient en français** (`anciens_min_...`).
  `AGENTS.md` ne laisse aucune marge : « All code, comments, variable
  names, function names, class names, table names, column names… :
  English. No exceptions. » Une clé de réglage est un identifiant de
  code — stockée telle quelle dans `settings.key`, reflétée en constantes
  PHP, écrite en dur dans un test — et c'est le `label` et la
  `description` à côté qui portent le français. Renommées en
  `former_members_min_scout_years` et
  `former_members_max_years_since_departure`. **Divergence avec le
  document de chantier**, qui nommait explicitement ces deux clés : les
  documents du dépôt priment sur lui pour toute règle générale, et
  celle-ci en est une.
- **`unit_mail_consent` était lu sur la mauvaise ligne.** Il filtrait
  dans le `WHERE`, donc *avant* la réduction qui élit la dernière année
  active : le gagnant devenait « la dernière année active qui, en plus,
  consentait ». Quelqu'un dont l'instantané le plus récent dit non était
  donc quand même retenu — à l'adresse périmée d'une année plus
  ancienne, étiqueté avec l'identifiant de cette année-là (celui dont la
  page de suivi ne trouve pas le profil), et mesuré contre la borne de
  départ depuis la mauvaise date. Il est désormais **sélectionné** puis
  lu sur la ligne gagnante : une seule ligne répond aux quatre
  questions, ou aucune n'est fiable.
- **Les cases d'années masquées pilotaient encore l'année de
  référence.** La page les cache par une classe CSS et écrit « il n'y a
  donc pas d'année scoute à choisir » — mais les `input` restent dans le
  formulaire, et une case cochée avant de changer de type de liste est
  toujours envoyée. Cocher « Année suivante » puis passer sur
  « Anciens » résolvait « tous ceux qui sont absents de l'an prochain »,
  c'est-à-dire une bonne partie de l'unité. Les années soumises sont
  maintenant **ignorées**, pas seulement rétrécies : l'année de
  référence est l'année publique courante, résolue dans le service. Une
  année que personne ne voit n'est pas une année que quelqu'un a
  choisie. Le compteur et le gel continuent de s'accorder, tous deux
  passant par la même méthode.

- **Et le garde de vacuité s'est retrouvé du mauvais côté du filtre**
  que la correction précédente venait d'ajouter. « Des candidats, mais
  aucun qui consente » est le cas **ordinaire** — la colonne est peu
  fiable et souvent vide — et il atteignait `countDistinctScoutYears()`
  sans aucun identifiant, donc `IN ()`, que MySQL et MariaDB refusent.
  SQLite le tolère, et `DatabaseTestHelper` construit une base SQLite :
  les tests ne pouvaient pas le dire. Le garde est passé après le
  filtre, l'aide se garde elle-même comme les trois autres appels à
  `placeholders()` de ce fichier, et le test de non-régression **compte
  les requêtes** (`InstrumentedPdo`) plutôt que d'observer un résultat —
  il dit donc la même chose quel que soit le moteur qui l'exécute.

**Reporté.** Rien de cette itération. Restent hors périmètre, comme le
document le prévoit : la portée en années comme propriété d'une liste
personnalisée, l'inscription publique, et la sélection multiple, les
étiquettes, les filtres avancés et la suppression en masse (D6).

---

## Après le chantier — les adresses passent dans la fenêtre d'édition

Les sections ci-dessus disent ce que chaque itération a livré, et restent
telles quelles. Une demande ultérieure a déplacé l'écran qu'IT-03 et IT-04
avaient construit, sans rien changer à ce qu'ils avaient décidé (D1, D2,
D3, D6 tiennent tous).

**Les adresses d'une liste se gèrent dans la fenêtre qui modifie la
liste**, plus dans un panneau replié sur la page. Elles font partie de ce
que la liste contient, au même titre que ses critères, et c'est cette
fenêtre qui décide du contenu d'une liste. La page **résume** : ce que les
critères désignent, en les nommant (`MailingListService::describeCriteria()`
— une phrase distincte de celle du dialogue, qui les compte parce que les
sélecteurs sont juste au-dessus), et combien d'adresses propres la liste
porte. Un seul panneau, pointé sur la liste ouverte ; une « Nouvelle
liste » le voit remplacé par une phrase, une adresse n'ayant rien à quoi
s'attacher tant que la liste n'existe pas.

**Une adresse s'ajoute et se retire, elle ne se corrige plus en place.**
Une ligne ne porte qu'un nom et une adresse : une faute de frappe se
répare en la retirant et en la retapant. La route `PATCH
/admin/listes-de-diffusion/addresses/{id}` et `ListAddressService::edit()`
sont parties avec le bouton plutôt que de rester joignables sans appelant ;
l'aller-retour Excel corrige toujours un nom en place, parce qu'il
réconcilie trois cents lignes au lieu d'en réparer une. Le geste restant
est la corbeille que le reste de la page utilise déjà — le mot à côté de
cinquante lignes, c'est cinquante fois le même mot — nommée d'après
l'adresse qu'elle retire pour qu'un lecteur d'écran annonce laquelle.

**Ajouter et retirer restent immédiats**, alors qu'enregistrer la liste ne
l'est pas : le panneau réécrit donc le compte de la page au passage, sinon
« Annuler » laisserait le résumé annoncer un nombre que la base ne porte
plus.

