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
tout en gardant celui qui n'en a pas.

**Reporté.** Rien.
