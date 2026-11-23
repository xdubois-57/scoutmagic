# Chantier — Courrier entrant : une configuration simple

> **Sources.**
> - La maquette `docs/chantiers/maquettes/courrier-entrant-simplifie.html` fait foi pour la hiérarchie des écrans, les libellés et les états. Son écran « Qui voit quoi » dessine deux règles côte à côte : **seule la règle A est retenue**, la colonne B n'est là que pour comparer. Son écran « la page Portée actuelle » montre ce qui disparaît.
> - Ce document fixe le découpage, l'ordre et les décisions du mainteneur. Là où la maquette et ce texte diffèrent, **le texte prime** : en « Échec de la vérification », le mot de passe est vidé (D10) ; pour un fournisseur inconnu, le serveur, le port et le chiffrement restent vides (lot B).
>
> **Ticket GitHub :** #1333 (le numéro est inscrit ici à la création du ticket).

## 0. Comment travailler

- **Lire d'abord** `AGENTS.md`, `ARCHITECTURE.md` (en particulier §8.58, le courrier entrant), `SECURITY.md`, `CONTRIBUTING.md`, `docs/inbound-mail-setup.md` et `design.md`.
- **Enchaîner les lots dans l'ordre des vagues, sans poser de question.** On ne s'arrête que pour un changement de design par rapport à la maquette, ou pour un manque de clarté fonctionnelle. Une décision technique ordinaire se prend et se note dans la PR.
- **Une PR par lot**, en français. Sa description dit « Partie de #1333 (lot X) », sauf **la dernière PR du chantier**, qui dit « Corrige #1333 » (voir le §4). Jamais un mot-clé de fermeture : `issue-fixed-comment.yml` ferme le ticket dès la fusion d'une PR qui porte « Corrige #1333 ».
  - **Fusion automatique dès que tout est vert**, par l'auto-merge, sans demander de confirmation.
  - Pas de poussée directe sur `main`.
- **Paralléliser** ce que le §3 désigne comme parallèle : un agent et une branche (ou un worktree) par lot.
- **Avant chaque commit :**
  - `vendor/bin/phpstan analyse` si le commit touche du PHP, et `npm run typecheck` s'il touche `public/assets/js/`. Ne jamais mettre son propre constat dans une baseline.
  - Les tests s'écrivent avec le code. Un nouveau répertoire de tests hors de `tests/Modules/` se déclare dans `phpunit.xml`.
  - Un test RBAC accompagne chaque route ajoutée ou modifiée.
- **Code en anglais, interface en français.**
- **ScoutMagic est en test : aucune reprise de données.** Les boîtes existantes gardent leur `purpose` et leur `dedicated_to`. Les réglages par module disparaissent simplement.

## 1. Décisions verrouillées

| # | Sujet | Décision |
|---|---|---|
| D1 | Deux usages, et seulement deux | Une boîte est soit le **courrier général de l'unité**, soit **réservée à un module**. Il n'y a plus de réglage par module : le « qui peut lire » (`ReadMode`) et l'interrupteur « analyse ce courrier » disparaissent, ainsi que la page « Portée ». |
| D2 | Qui analyse | Une boîte générale est analysée par **tous les modules actifs** qui lisent le courrier. Une boîte réservée n'est analysée **que par son module**, comme aujourd'hui (`Mailbox::impliedScopes`). Une boîte ajoutée fonctionne dès son enregistrement, alors qu'aujourd'hui elle reste inerte tant que sa portée n'est pas réglée. |
| D3 | Qui lit, sur une boîte générale (règle A) | **Le Staff d'U voit tout**, dans « Courrier reçu ». Les responsables d'un module ne voient **que les messages rattachés** à un dossier qu'ils gèrent. **Les propositions** (rattachement incertain) d'un message de la boîte générale **ne leur sont jamais montrées** : elles restent dans « Courrier reçu », où le Staff d'U les confirme ou les écarte. Une fois confirmé, le message est rattaché et devient visible du module. |
| D4 | Qui lit, sur une boîte réservée | **Les responsables du module voient tout ce qui arrive sur l'adresse**, propositions comprises, et le Staff d'U aussi. Les autres modules ne la lisent pas. |
| D5 | Une adresse réservée par module | Un module a **au plus une** adresse réservée. Le serveur le garantit, par exemple par un index unique sur `dedicated_to`. L'écran ne propose pas un module qui a déjà la sienne, et l'alerte « plusieurs boîtes dédiées à… » disparaît. |
| D6 | Usage proposé par défaut | Le formulaire coche **« Courrier général »** quand aucune boîte générale n'existe, et **« Réservée à un module »** sinon. Ce n'est qu'une valeur par défaut : plusieurs boîtes générales restent permises. |
| D7 | Plus de nom de boîte | **L'adresse e-mail du compte tient lieu de nom**, partout où le nom s'affichait : la configuration, « Courrier reçu », la configuration des Locations, le journal d'analyse. La colonne `name` est supprimée. |
| D8 | Réglages de connexion | Le serveur, le port et le chiffrement sont **visibles et obligatoires**, jamais repliés. Ils sont **pré-remplis** quand le fournisseur est reconnu (voir le lot B). |
| D9 | Dossiers lus | Par défaut : **la boîte de réception**, plus **les messages envoyés quand un module en a besoin** (aujourd'hui les Locations, via `HandlesOutboundMail`). Le dossier des envoyés reste détecté par le serveur. Dans une partie repliée « Dossiers lus (facultatif) » : les autres dossiers à cocher, **sans jamais proposer** les dossiers spéciaux (indésirables, corbeille, brouillons, « Tous les messages », « Important », « Suivis »), et le dossier des envoyés « trouvé automatiquement », modifiable seulement si le serveur ne l'indique pas. **Modifiée par #1343** : la partie « Dossiers lus » n'apparaît qu'une fois la connexion vérifiée, la boîte de réception y est cochée d'office, la zone de texte de secours sans JavaScript disparaît (sans script, seule la boîte de réception est lue, ou les dossiers enregistrés sont gardés), et le dossier des envoyés non désigné par le serveur se choisit dans la liste de ses dossiers. |
| D10 | Vérifier avant d'enregistrer | « Vérifier et enregistrer » ouvre la connexion. **Une adresse qui ne se connecte pas n'est pas enregistrée** : l'échec s'affiche en une phrase utile, et les champs restent remplis, sauf le mot de passe. La case « Synchroniser cette boîte » disparaît : une adresse ajoutée est relevée, et on la suspend avec « Désactiver » dans la liste. |
| D11 | Première relève limitée | La première lecture d'un dossier, c'est-à-dire sans curseur existant ou après un changement d'UIDVALIDITY, ne prend que **les messages des 12 derniers mois, et au plus les 5 000 plus récents par date de réception**, jamais par UID. Le curseur part ensuite de là, sans sauter aucun message retenu. |
| D12 | Textes | Les explications longues de la page (RGPD, absence de connecteur Gmail natif) **vont dans l'aide**. La page n'en garde qu'une phrase et un lien. L'aide sur le mot de passe d'application ne s'affiche que pour le fournisseur reconnu, sous son champ. |

## 2. Ce que le code impose

Constats établis le 16/11/2026 sur `main`. À revérifier au début de chaque lot.

**Le « qui peut lire » ne fait aujourd'hui qu'une seule chose.** Il alimente `InboundMailboxRepository::mailboxIdsReadableInFull()`, qui ne lit que la valeur `all`. `Personne` et `Messages concernés` se comportent exactement de la même façon, alors que l'écran promet pour `Personne` « n'ouvre aucune liste ». Supprimer le concept ne retire donc aucune protection réelle.

**Où agissent les réglages par module :**

| Réglage | Lu par |
|---|---|
| L'interrupteur « analyse » (`MailboxScope::$analyzes`) | `InboundMailService` (vers les lignes 738 et 923), `ReplyAddressService` (ligne 156), `MailboxScopeService` |
| Le « qui peut lire » (`read_mode`) | `mailboxIdsReadableInFull()` seulement, qui se réduit à « les boîtes réservées à ce module » |

**À supprimer :**
- la table `inbound_mailbox_consumers`, déclarée dans `drops.sql` ;
- les classes `Api\ReadMode`, `Api\MailboxScope`, `Service\MailboxScopeService` et `Mailbox::impliedScopes()` (à remplacer par une règle simple sur l'usage) ;
- `views/config/mailbox_scopes.html.twig` et `public/assets/js/inbound-mail-scopes.js` ;
- les routes `/config/courrier-entrant/boites/{id}/portee` ;
- le réglage interne `inbound_mail_scopes_migrated`.

`MailboxPurpose` reste, avec ses libellés à revoir (« Courrier général », « Réservée à … »).

**Propositions visibles des modules (D3).** Elles passent par trois chemins, et les trois doivent exclure les propositions portant sur un message d'une boîte générale :
- `InboundMessageRepository::triageScope()`, la clause sur `inbound_message_candidates` des listes de tri ;
- `findCandidatesForConsumer()`, utilisé par `TriageRowBuilder` et par `Finance\ReceiptController` ;
- `countCandidatesFor()`, utilisé par les attentions de Camps et de Finances.

Sur une boîte réservée, rien ne change : le module la lit en entier.

**Côté Staff d'U.** « Courrier reçu » (`GeneralMailboxService`, `/courrier`, `role_min: admin`) montre déjà tout, et sait confirmer une proposition (`/courrier/{id}/proposition/confirmation`). Il devient **le seul endroit** où se traitent les propositions d'une boîte générale. Son bouton « Rafraîchir » (`/courrier/rafraichir`) remplace celui de la configuration, qui disparaît.

**La première relève.** Le curseur d'un dossier neuf commence aujourd'hui à l'UID 0 (`FolderCursor`, `MailboxSyncService::fetchSince`) : tout l'historique est lu. Le dédoublonnage sur Message-ID par boîte (`idx_message_box_dedup`) reste la garantie contre les doublons quand plusieurs dossiers sont cochés.

**Nom de boîte (D7).** Il est lu dans `InboundMailboxController`, `GeneralMailboxService`, `AnalysisJournal`, `modules/rental/views/config/index.html.twig` et les trois vues de configuration. L'adresse est chiffrée en base (`username_encrypted`) : il faut la déchiffrer pour l'afficher, comme la liste de configuration le fait déjà.

## 3. Les lots

### Vague 1 — en parallèle

#### Lot A — Deux usages, une règle de lecture (modèle et visibilité)

- Appliquer D1 à D5 et D7 dans le module.
  - L'analyse découle de l'usage (D2).
  - `mailboxIdsReadableInFull($consumer)` devient « les boîtes réservées à ce module ».
  - Les propositions d'une boîte générale sont exclues des trois chemins du §2 (D3).
  - Un module a au plus une adresse réservée, garanti côté serveur (D5).
  - La colonne `name` disparaît, et l'adresse en tient lieu partout où le nom s'affichait (D7).
- Supprimer ce que le §2 liste. Les routes « Portée » sont retirées du `module.json`. Faire monter la version du module, puisque des réglages sont retirés.
- Supprimer **provisoirement** de `index.html.twig` le bouton « Portée » et l'alerte de conflit de dédicace : le lot B refait la page.
- **Tests :**
  - une boîte générale est analysée par tous les modules actifs, une boîte réservée par son seul module ;
  - un message de la boîte générale **proposé** aux Locations n'apparaît ni dans la liste de tri des Locations, ni dans le compteur d'attention, ni dans `findCandidatesFor` ;
  - le même message, une fois confirmé depuis « Courrier reçu », apparaît dans la réservation ;
  - sur une boîte réservée, les propositions restent visibles du module ;
  - une deuxième boîte réservée au même module est refusée ;
  - le Staff d'U voit tout dans « Courrier reçu » ;
  - `ReplyAddressService` suit l'usage ;
  - `ModuleBoundariesTest` reste vert.

#### Lot C — Première relève limitée (D11)

- Dans `MailboxSyncService` et le client IMAP : pour un dossier sans curseur, ou dont l'UIDVALIDITY a changé, ne lire que les messages des **12 derniers mois**, et au plus les **5 000 plus récents**.
  - « Plus récents » se juge **sur la date de réception du serveur (`INTERNALDATE`), jamais sur l'UID** : un vieux message importé dans la boîte reçoit un UID plus élevé que des messages récents. Une recherche `SINCE` donne les messages des 12 derniers mois ; on en garde les 5 000 dont la date de réception est la plus récente.
  - **Le curseur ne fait sauter aucun message retenu, et ne fait relire aucun message écarté.** Les messages retenus sont lus nommément, puis le curseur part du plus grand UID du dossier au moment de cette première lecture : tout ce qui arrive ensuite est relevé normalement.
- Cette règle s'applique **aussi aux envoyés**, et à un dossier coché plus tard.
- Le journal de la relève indique combien de messages anciens ont été laissés de côté. Rien n'est montré dans l'interface.
- **Tests**, avec le client factice (`FakeMailboxClient`) : une boîte de 7 000 messages sur trois ans, une boîte de 200 messages récents, un changement d'UIDVALIDITY, et une boîte où de vieux messages importés ont des UID plus élevés que des messages récents (les récents sont gardés, les vieux écartés, et aucun n'est relu à la relève suivante).
- Ce lot ne touche ni la configuration ni la visibilité, **il est indépendant du lot A**. En cas de conflit sur `MailboxSyncService`, on rebase et on garde les deux changements.

### Vague 2 — après la fusion du lot A

#### Lot B — L'écran de configuration (maquette)

- **La liste des adresses**, selon l'écran « Liste des adresses » de la maquette :
  - une carte par adresse, avec l'adresse en titre et l'usage en une phrase (« Courrier général de l'unité », « Réservée aux Locations ») ;
  - l'état et la dernière relève, et en cas d'échec, une phrase qui dit quoi faire ;
  - les actions Modifier, Désactiver et Supprimer ;
  - plus d'hôte ni de port sur la ligne, plus de badges par module, plus de « Rafraîchir maintenant » ;
  - en haut, une phrase et le lien vers l'aide. L'avertissement sur la tâche planifiée (`cron_warning`) reste.
- **Le formulaire d'ajout et de modification**, selon l'écran « Ajouter une adresse » :
  - les champs, dans cet ordre : adresse e-mail, mot de passe d'application, serveur, port, chiffrement, usage, puis module pour une adresse réservée ;
  - l'usage par défaut selon D6, et seuls les modules actifs qui lisent le courrier et n'ont pas encore d'adresse réservée sont proposés ;
  - « Dossiers lus (facultatif) » replié, selon D9 ; la liste des dossiers vient du serveur, filtrée sur ses attributs spéciaux (`\Junk`, `\Trash`, `\Drafts`, `\All`, `\Flagged`, `\Important`, et `\Sent` affiché à part) ;
  - « Vérifier et enregistrer », selon D10 ;
  - en modification, un mot de passe laissé vide conserve l'ancien.
- **Reconnaissance du fournisseur** (D8), par une petite classe testable du module. Elle cherche d'abord le domaine de l'adresse, puis, à défaut, l'enregistrement MX du domaine (`dns_get_record`), pour reconnaître un domaine propre hébergé chez Google Workspace ou Microsoft 365 :
  - Gmail et Google Workspace : `imap.gmail.com`, 993, SSL ;
  - Outlook, Hotmail, Live et Microsoft 365 : `outlook.office365.com`, 993, SSL ;
  - OVH : `ssl0.ovh.net`, 993, SSL ;
  - Infomaniak : `mail.infomaniak.com`, 993, SSL.

  Chaque valeur est à confirmer dans la documentation du fournisseur avant le commit. Inconnu : les champs restent vides, avec une phrase d'aide. Pour Gmail et Outlook, l'aide sur le mot de passe d'application s'affiche sous son champ, avec le lien `https://myaccount.google.com/apppasswords` pour Gmail.
- **Textes (D12).**
  - Récrire `docs/inbound-mail-setup.md` et les sujets d'aide du module : `courrier-unite.md`, `courrier-portee.md` (à supprimer ou à fondre dans un autre), `courrier-orienter.md`.
  - Mettre à jour `specifications.md` et `ARCHITECTURE.md` §8.58 : les deux usages, la règle A et la fin du réglage par module.
- **Tests :**
  - le formulaire et sa vérification : succès, échec sans enregistrement, mot de passe conservé ;
  - la reconnaissance du fournisseur par domaine et par MX, avec un résolveur DNS injecté ;
  - le filtrage des dossiers spéciaux ;
  - l'usage par défaut et les modules proposés ;
  - le RBAC des routes ;
  - un parcours e2e : ajouter une première adresse générale, puis une adresse réservée aux Locations, sans aucune page « Portée ».
- **Ajouter la maquette au tableau** de `docs/chantiers/maquettes/README.md`, si ce n'est pas déjà fait.
- **Si ce lot est le dernier fusionné**, la fin du chantier suit le §4.

## 4. Ordre et parallélisme

```
Vague 1 :  A   C     (en parallèle)
Vague 2 :  B         (après la fusion de A ; C peut être encore en cours)
```

**La fin du chantier.** Le dernier lot n'est pas forcément B : C peut finir après lui. La dernière PR, celle qui laisse **A, B et C tous fusionnés**, porte « Corrige #1333 » ; les autres disent « Partie de #1333 (lot X) ». Pour cette dernière PR :

1. **avant sa fusion**, commenter le ticket #1333 avec ce qui a été livré, lot par lot ;
2. **fermer le ticket** : `issue-fixed-comment.yml` le fait à la fusion. Vérifier qu'il est bien fermé, et le fermer à la main s'il est resté ouvert.

## 5. Hors périmètre

- Un connecteur natif Gmail ou Microsoft (OAuth).
- Un changement de la durée de conservation : 90 jours sans rattachement, comptés à partir de la date du message.
- Un changement de « Courrier reçu », au-delà du rôle qu'il prend pour les propositions de la boîte générale (D3).
