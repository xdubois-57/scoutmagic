# Chantier — Locations : équivalence superadmin et réglages d'un bien (#1355)

> **Sources**
> - Le ticket #1355 : sa description, puis la section « Analyse du 21/11/2026 », qui prime sur le texte d'origine.
> - La maquette **validée par le mainteneur le 21/11/2026** : `docs/chantiers/maquettes/locations-reglages.html`. Elle fait foi pour la hiérarchie des écrans, les libellés et les états. Son écran « Analyse et estimation » résume ce document.
> - Ce document fixe le découpage, l'ordre et les décisions.
>
> En cas de divergence, **ce document l'emporte sur le ticket**, puis la maquette.

## 0. Comment travailler

- **Lire d'abord** `AGENTS.md`, `ARCHITECTURE.md`, `SECURITY.md`, `CONTRIBUTING.md`, `design.md`, puis le ticket #1355 en entier.
- **Enchaîner les lots dans l'ordre des vagues, sans poser de question.** On ne s'arrête que pour un changement de design par rapport à la maquette, ou pour un manque de clarté fonctionnelle. Une décision technique ordinaire se prend et se note dans la PR.
- **Paralléliser** ce que le §3 désigne comme parallèle : un agent et une branche (ou un worktree) par lot.
- **Une PR par lot**, en français. Sa description dit « Partie de #1355 (lot X) », sauf **la dernière PR du chantier**, celle qui laisse les cinq lots fusionnés, qui dit « Corrige #1355 ». Jamais un mot-clé de fermeture : `issue-fixed-comment.yml` ferme le ticket dès la fusion d'une PR qui porte « Corrige #1355 ».
  - **Fusion automatique dès que tout est vert**, par l'auto-merge, sans demander de confirmation.
  - Pas de poussée directe sur `main`.
- **Avant chaque commit :**
  - `vendor/bin/phpstan analyse` si le commit touche du PHP, et `npm run typecheck` s'il touche `public/assets/js/`. Ne jamais mettre son propre constat dans une baseline.
  - Les tests s'écrivent avec le code. Chaque route ajoutée ou modifiée a son test RBAC : accordée au `role_min`, refusée un cran en dessous.
- **Code en anglais, interface en français.** Les tests et les fixtures n'utilisent que des données fictives.
- **Les constats du §2 datent du 21/11/2026, sur `main` (`e41bd2fe2`).** Les revérifier au début de chaque lot. Si l'un ne tient plus, s'adapter et le noter dans la PR.

## 1. Décisions verrouillées

| # | Sujet | Décision |
|---|---|---|
| D1 | Équivalence | Dans tout le module Locations, un compte **superadmin** a les mêmes capacités qu'un chef d'U, sur tous les biens. Il n'a besoin ni de rattachement au Staff d'U ni de désignation comme gestionnaire. Les règles des autres rôles ne changent pas, et le rôle `admin` seul n'ouvre rien de plus. |
| D2 | Reconnaissance | Le superadmin se reconnaît à l'**indicateur de son compte** (`user_accounts.is_super_admin`), lu à partir de l'adresse, **partout**, avec ou sans session : classement du courrier, flux ICS, description d'une créance. On n'utilise jamais le rôle de la session pour juger une adresse tierce. |
| D3 | Historique | L'auteur des actions d'un superadmin est **son compte, sans membre**. On ne prend jamais le premier animé lié à son adresse. |
| D4 | Droits ouverts | Le superadmin peut aussi **épingler le compte des paiements** et **saisir librement une autre adresse de locataire** (#1106). Le sous-titre de « Gérer mes locations » devient « En tant que superadministrateur du site, vous gérez tous les biens de l'unité. » |
| D5 | Notifications | L'équivalence n'ajoute **aucun destinataire** de notification. `ManagerRecipientResolver` ne change pas. |
| D6 | Lien existant | Le lien « Règles, tarif et acompte dans l'espace de gestion du bien » (`config/asset.html.twig:28`) garde son texte, son icône, son apparence et son adresse. Il doit simplement fonctionner. |
| D7 | Minimum | « Nombre de personnes minimum » n'est proposé qu'avec une unité par personne (`BillingUnit::isPersonBased()`), et le choix se met à jour dès qu'on change l'unité. Le contrôle serveur existant reste. |
| D8 | Grille | Le tarif par défaut s'affiche **en grisé dans les cases non personnalisées**, avec la mention « par défaut ». Dans le dialogue, c'est une valeur indicative (`placeholder`), **jamais une valeur écrite** dans la case. Une case remplie, même à 0, est un prix personnalisé. Sans tarif par défaut, rien n'est affiché à la place. |
| D9 | Périodes | Une période qui **chevauche** une autre est refusée **à l'ajout** : bornes incluses, périodes ponctuelles et annuelles, y compris une période annuelle qui passe le nouvel an. Le message nomme la période en conflit et les jours en commun, et rien n'est enregistré. Un conflit déjà enregistré est signalé dans l'encart, sans rien modifier automatiquement. Il n'existe pas de modification de période. |
| D10 | Frais | « Trois natures seulement. » est remplacé par « Ajoutez les frais complémentaires à la location, comme le nettoyage ou les consommations. » |
| D11 | Paiements | L'encart a une **case « Suivre les paiements via le site »**, enregistrée tout de suite avec le toast habituel, et deux boutons, **Acompte** et **Caution**. Le bouton « Modifier » et le dialogue global disparaissent. **Chaque action n'enregistre que ses propres réglages.** Si le compte ou la catégorie manque, l'activation est refusée et l'encart renvoie vers « Biens à louer › Compte des paiements ». En cas d'échec, la case revient à l'état réellement enregistré. |
| D12 | Sans Finances | Acompte et caution **restent réglables même sans le module Finances**. La case de suivi est alors remplacée par une explication. |
| D13 | Liens Finances | Dans « Compte des paiements » (Biens à louer), seul un superadmin voit les liens vers `/config/finance/accounts` et `/config/finance/categories`, ouverts dans un nouvel onglet. Les autres lisent « demandez-le au superadministrateur du site ». |
| D14 | Bailleur | Deux choix explicites : « Utiliser le bailleur par défaut : [nom], [adresse] » ou « Bailleur spécifique à ce bien ». Le bailleur par défaut est calculé **sans** tenir compte du bailleur du bien. La ligne « Origine » et l'expression « bailleur habituel » disparaissent. Une adresse manquante est dite clairement. |
| D15 | Rappels | L'encart devient un **résumé** : nombre de rappels actifs par destinataire, délais personnalisés, rappels désactivés. Le formulaire passe dans le dialogue « Modifier les rappels ». Chaque rappel affiche son destinataire, « Aux gestionnaires du bien » ou « Au locataire », tiré de `ReminderKind::isInternal()`. Sans gestionnaire joignable, les rappels internes partent **toujours au Staff d'U**, sans que l'écran le dise. La phrase « Ce que ce bien change aux rappels de l'unité. » disparaît. |

## 2. Ce que le code impose

**Pourquoi le superadmin est bloqué.** `RentalAuthorizationService` ne lit jamais le rôle. Tout passe par `isUnitStaff()` (l. 151-158, via `MemberService::isUnitChief()`) ou par la désignation comme gestionnaire. Sont concernés `managesAnyAsset`, `listManageableAssets`, `canManageAsset(Id)` et `listManageableAssetsInAnyYear`. Le service est **construit deux fois** dans `public/index.php` (l. 9185 pour Finances, l. 12586).

**Où l'équivalence doit s'appliquer**, au-delà des méthodes ci-dessus :
- les décisions qui appellent `isUnitStaff` directement, dans `RentalManagementController` : l. 353 (`can_pin_account`), l. 790 (sous-titre, et `my_rentals.html.twig:15`), l. 1483 et l. 1648 (adresse libre, #1106) ;
- les appels sans session : `RentalMessageConsumer:1003`, `RentalVirtualEventProvider:206` et `RentalReceivableDescriber:87`.

**Indicateur superadmin.** `RoleResolver::isSuperAdmin()` est privé : exposer l'indicateur, ou passer par `UserAccountRepository`.

**Auteur dans l'historique.** `actorMemberId()` (l. 5162-5172, 48 appels) renvoie le premier membre lié à l'adresse. Il faut un auteur « compte » pour un superadmin.

**Grille.** `saveGrid()` (`RentalPricingController.php:198-224`) enregistre toute case non vide. D'où la règle de D8. L'écran des rappels (`reminderRows`) utilise déjà ce mécanisme de valeur indicative.

**Périodes.** On ne peut qu'ajouter (`RentalPricingController.php:132`) ou supprimer (l. 152) une période. Les outils existants :
- `PricePeriod::covers()` compte les bornes incluses ;
- `RentalPricingEngine.php:278` produit le message actuel en cas de chevauchement ;
- `RentalPricingRepository.php:107` trie les périodes.

**Paiements.** `savePayments()` (l. 393-431) reconstruit un `PaymentSettings` complet : il faut trois enregistrements partiels. `saveManagedSettings()` préserve déjà le compte et la catégorie. Les conditions `finance_available` (`_payments.html.twig:31`, `:116`) et l'erreur « service absent » de `savePayments` sont à revoir pour D12. Pour la case, s'appuyer sur `ScoutMagicApi.postJson` et `toast.js`, sur le modèle de `notification-preferences.js`.

**Bailleur.** `RentalDocumentService` n'expose que `landlordFor()` (l. 1410). Il faut lui ajouter l'accès au bailleur par défaut (`Landlord::resolve()`).

**Rappels.** Il y a 13 types (`ReminderKind::cases()`) : le commentaire de `saveReminders` en annonce 12, à corriger. `isInternal()` est l. 86-88. Le repli vers le Staff d'U est assuré par `ManagerRecipientResolver`.

**Lignes du ticket à corriger.** `myRentals` est en l. 754, l'année d'autorisation en `index.php:12572`, `compte-edit` en l. 417 et l'aide du champ Compte en l. 440.

**À réutiliser sans créer de composant** : `partials/modal.html.twig` (`section_editor`), `toast.js`, `ScoutMagicApi`, `BillingUnit::isPersonBased()`, `PricePeriod::covers()`, `PaymentSettings::depositFor()` et `securityDepositAmount()`, `Landlord::resolve()`, `ReminderKind::isInternal()`.

## 3. Les lots

### Vague 1 — en parallèle

#### Lot A — Équivalence superadmin (D1 à D6)

- `RentalAuthorizationService` reconnaît le superadmin par l'indicateur de son compte, injecté via une petite dépendance (D2). Câbler les deux constructions de `index.php`.
- Les quatre décisions directes de `RentalManagementController` (D4) et l'auteur de l'historique (D3).
- **Tests :**
  - Un superadmin lié à deux animés fictifs, sans Staff d'U, et un superadmin sans aucune fiche membre. Pour les deux :
    - le menu « Gérer mes locations » est visible ;
    - la liste de tous les biens s'affiche ;
    - les réglages s'ouvrent par le lien existant de D6 ;
    - l'enregistrement du tarif fonctionne, et le badge « Tarif manquant » disparaît après un enregistrement réel ;
    - les fichiers, le courrier et le flux ICS restent accessibles ;
    - l'historique nomme le compte.
  - Un admin qui n'est pas superadmin et n'est pas au Staff d'U reste refusé.
  - Les gestionnaires désignés gardent leur périmètre.
  - Aucun nouveau destinataire de notification (D5).
- Mettre à jour l'aide des locations et `specifications.md` (droits).

#### Lot B — Liens Finances et textes (D10, D13)

- Dans `config/asset.html.twig`, fenêtre `compte-edit` : les liens ou la phrase alternative, selon le droit superadmin. Ce droit est transmis par `RentalConfigController`.
- Le texte des frais (`_pricing.html.twig:208`).
- **Tests de rendu** : superadmin (deux liens), admin (phrase, aucun lien), module Finances inactif (affichage inchangé).

### Vague 2 — après la fusion de A et B

#### Lot C — Tarification, grille, périodes (D7 à D9)

- Le minimum dynamique dans le dialogue de tarification (un petit script de module, compatible CSP).
- La grille de l'encart et du dialogue selon D8.
- Le refus des chevauchements dans `RentalPricingService::addPeriod()`, sans écriture partielle, et le signalement des conflits déjà enregistrés dans l'encart.
- **Tests :**
  - le minimum : unités par personne ou non, changement d'unité ;
  - la grille : défaut affiché, zéro explicite conservé, enregistrement sans changement qui ne fige rien, cas sans défaut ;
  - les chevauchements : ponctuel/ponctuel, annuel/annuel, ponctuel/annuel, bornes incluses, période décembre → janvier ; des périodes sans jour commun sont acceptées.

#### Lot E — Bailleur et rappels (D14, D15), en parallèle de C

- `RentalDocumentService` donne le bailleur par défaut. L'encart et le dialogue suivent D14.
- L'encart des rappels devient un résumé et le formulaire passe dans un dialogue, avec le destinataire de chaque rappel (D15). Le sens du délai vide, de 0 et de la case décochée ne change pas.
- **Tests :**
  - bailleur : défaut commun, repli sur l'unité, bailleur spécifique, adresse manquante ;
  - rappels : le résumé reflète l'état enregistré, Annuler n'enregistre rien, les destinataires viennent de `isInternal()`.

#### Lot D — Paiements (D11, D12), après C

- Une route JSON pour la case de suivi : RBAC, CSRF et refus explicite si le compte ou la catégorie manque.
- Trois enregistrements partiels :
  - le suivi ;
  - l'acompte avec les échéances de l'acompte et du solde ;
  - la caution.

  Chacun conserve tout le reste, compte et catégorie compris.
- L'encart et les deux dialogues selon la maquette, avec le toast. La restitution manuelle de la caution (`_finance_off.html.twig`) reste disponible.
- **Tests :**
  - chaque enregistrement préserve les deux autres, le compte et la catégorie ;
  - la case est refusée sans compte, et revient à l'état enregistré après un échec ;
  - sans le module Finances, l'acompte et la caution restent réglables et apparaissent dans le contrat ;
  - un parcours e2e : cocher, voir le toast, recharger la page, l'état est conservé.
- D et E modifient tous deux `RentalManagementController::settings()` : celui qui part en second rebase.

### Clôture — à la fin du dernier lot

- Mettre à jour :
  - l'aide (`modules/rental/help/` : réglages d'un bien, tarifs, paiements, rappels) ;
  - `specifications.md` (Locations) ;
  - `ARCHITECTURE.md`, pour l'équivalence superadmin dans la politique d'accès du module.
- Commenter #1355 avec ce qui a été livré, lot par lot.
- **Fermer le ticket #1355.**

## 4. Ordre et parallélisme

```
Vague 1 :  A   B          (en parallèle)
Vague 2 :  C   E          (en parallèle, après la fusion de A et B)
           D              (après C ; rebase sur E si besoin)
```

## 5. Estimation

| Lot | Taille |
|---|---|
| A | 2 à 3 jours |
| B | ½ jour |
| C | 2 à 3 jours |
| D | 3 à 4 jours |
| E | 1 à 2 jours |

Au total, 9 à 13 jours, tests et documentation compris.

## 6. Hors périmètre

- Un changement des droits des autres rôles.
- Les priorités entre périodes tarifaires.
- Les destinataires des notifications.
- Les écrans des Finances eux-mêmes.
