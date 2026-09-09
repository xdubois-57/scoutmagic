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
