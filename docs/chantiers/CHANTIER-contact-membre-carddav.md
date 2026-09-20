# Chantier — Contact d'un membre : vCard, QR et CardDAV

Roadmap d'exécution en **3 itérations séquentielles**, issue #398. Traite-les dans l'ordre.
N'entame pas la suivante avant que la précédente soit fusionnée sur `main`.

IT-01 est utile seule et se livre seule. IT-02 et IT-03 forment ensemble la synchronisation : IT-02
n'a aucun effet visible sans IT-03, mais elle se teste et se fusionne séparément.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. Couverture RBAC explicite sur chaque frontière de rôle touchée.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- Chaque écran ajouté ou modifié ship son sujet d'aide dans la même PR (charte `design.md` §7.11).
  `tests/Core/Help/` échoue sinon.
- **Aucune donnée personnelle dans le journal, les messages d'erreur ou les traces.** Ce chantier
  manipule des coordonnées de mineurs de bout en bout : la règle n'a jamais été aussi littérale.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR. Jamais une issue publique pour une faille de sécurité.
- **L'issue #398 reste unique.** IT-01 et IT-02 la référencent sans la fermer (« Refs #398 ») ;
  **la PR d'IT-03 porte « Closes #398 »**. Vérifie avant de la fusionner que tout ce que tu aurais
  reporté a sa propre issue ouverte.

---

## La fiche de contact : le contenu exact — **Décidé**

Une seule définition, deux variantes de charge. Ne l'invente pas, ne l'enrichis pas.

| Propriété | Contenu |
|---|---|
| `FN` | Prénom et nom complets. **Jamais le totem seul**, jamais `display_name`. |
| `N` | Nom, prénom, décomposés. |
| `NICKNAME` | Le totem, s'il existe. |
| `PHOTO` | **Fichier téléchargé et CardDAV uniquement.** Jamais dans le QR. |
| `ORG` | Nom de l'unité, puis la section. |
| `TITLE` | La fonction principale de l'année en cours. |
| `EMAIL` | L'adresse Desk, puis **toutes** les adresses actives configurées par le membre. |
| `TEL` ×2 | Le fixe et le GSM de Desk, **sans étiquette particulière**. |
| `ADR` | **Toutes** les adresses postales, principale et secondaires. |
| `NOTE` | L'année scoute en cours, puis les affiliations (voir ci-dessous). |
| `UID` | Stable, dérivé de l'identité persistante du membre (`members.id`), identique dans les deux variantes. |
| `REV` | Horodatage de dernière modification, identique dans les deux variantes. |

**Les affiliations dans `NOTE`** : une ligne par année, du plus récent au plus ancien, au format
`année · fonction · section`. La section est **nommée**, avec le nom configuré dans Config Desk et
jamais le code Desk — une section renommée se lit sous son nom, y compris pour les années passées.
Une fonction sans section (Trésorier, Infirmier) s'arrête à la fonction, sans séparateur orphelin.
Plusieurs fonctions la même année tiennent sur la même ligne.

**Cinq affiliations au maximum dans le QR ; toutes dans le fichier et en CardDAV.** Le QR ne
signale pas qu'il est tronqué : décision prise, ne rajoute pas de mention.

### Ce qui ne sort jamais

Handicap, assurance complémentaire, sexe, date de naissance, identifiant Desk, patrouille, niveau
de formation, consentements de communication (fédération et unité).

Le champ **Handicap est une donnée de santé**. Il est sur le même écran, dans le même objet, à une
ligne de distance du reste. C'est l'erreur la plus facile à commettre dans tout ce chantier.

### Deux incohérences assumées, à ne pas « corriger »

1. **Les deux numéros sont étiquetés « Tél. parent 1 » et « Tél. parent 2 » à l'écran** — Desk
   fournit un fixe et un GSM qui, pour un animé, sont ceux du ménage. La vCard les exporte **sans
   cette étiquette**. C'est une décision explicite du demandeur, pas un oubli.
2. **Le QR tronque l'historique sans le dire.** Également décidé.

---

## IT-01 — La vCard et le QR

### À faire

- `Core\Contact\VCardBuilder` (ou équivalent) : construit la fiche depuis un membre et une année
  scoute, avec un drapeau pour la variante — avec ou sans photo, historique complet ou cinq lignes.
  **Une seule implémentation du contenu** : le QR et le fichier ne doivent jamais diverger sur un
  champ.
- La lecture de l'historique d'affiliations : **une requête**, sur toutes les lignes `member_years`
  du même `members.id`, jamais une par année.
- Le téléchargement : `Content-Type: text/vcard`, nom de fichier dérivé du nom du membre,
  **généré à la volée et diffusé, jamais écrit sur disque** (`SECURITY.md` §5 — les données
  personnelles sur disque sont chiffrées ou strictement temporaires ; ici elles ne touchent pas le
  disque du tout).
- Le QR : `endroid/qr-code` est **déjà une dépendance justifiée** du projet (affiches, QR SEPA).
  `Core\Pdf\PosterPdfService::buildQrCodeDataUri()` montre le geste exact à reprendre. Aucune
  dépendance nouvelle, aucun appel à une API externe.
- Le bouton « Ajouter à mes contacts » et son dialogue : **uniquement** sur la fiche membre de la
  recherche en Espace admin (`/admin/members`, `role_min: admin`). Ni trombinoscope, ni page du
  membre, ni Staffs. Le QR et le fichier sont offerts ensemble, **sans détection d'appareil**.
- Le dialogue énonce ce que la fiche contient, en une phrase, avec l'année scoute.
- La journalisation via `JournalService` : un export de coordonnées est une action sensible.
  Identifiant du membre et rien d'autre — ni nom, ni email, ni numéro.

### Piège

`Core\Member\MemberProfile` ne porte que **les fonctions d'une seule année** : c'est un instantané
de `member_years`. L'historique n'y est pas. Ne le reconstitue pas en bouclant sur les années.

### Documentation

`specifications.md` (§ Espace admin > Membres), et **la page RGPD** : `AGENTS.md` impose de
reprendre `RgpdContentService::getDefaultContent()` quand un nouveau traitement apparaît. Un canal
d'export de données personnelles vers un appareil personnel en est un.

---

## IT-02 — Les identifiants d'appareil

Aucun effet visible sans IT-03, mais entièrement testable seule : c'est le socle
d'authentification, et c'est le gros du travail de la synchronisation — pas le XML.

### Pourquoi c'est nécessaire — **Décidé**

Les clients CardDAV ne savent faire que du HTTP Basic (ou Digest). **Aucune des trois méthodes du
site** — lien magique, mot de passe, clé numérique — ne convient à un client qui synchronise en
arrière-plan sans interface. Il faut donc des identifiants dédiés.

### À faire

- Une table d'identifiants d'appareil : compte, libellé donné par l'utilisateur, secret **haché**,
  date de création, date de dernière synchronisation, date de révocation.
- Le secret est `random_bytes()`, **affiché exactement une fois** à la création et jamais
  récupérable ensuite — même traitement que le secret du webhook GitHub
  (`MaintenanceController::generateWebhookSecret()`, `ARCHITECTURE.md` §8.17). Ne le range pas
  dans `SettingService` : une ligne de réglage le rendrait lisible sur la page Paramètres.
- L'écran dans **Mon compte**, visible **uniquement pour `admin` et `superadmin`** : la liste des
  appareils, la création, la révocation. Un appareil déclaré et jamais synchronisé se signale,
  sans être supprimé tout seul — c'est un identifiant valide qui traîne.
- L'écran dit la vérité désagréable : la copie descendue reste sur l'appareil, suit ses
  sauvegardes, et révoquer n'efface rien.
- Un **interrupteur superadmin** coupant la synchronisation pour tout le site, et une vue listant
  **tous** les appareils connectés, tous comptes confondus, avec révocation. Une fonctionnalité qui
  réplique des données personnelles doit avoir son coupe-circuit.
- La journalisation : création, révocation, **échecs d'authentification**. Jamais les
  synchronisations réussies — elles arrivent toutes les quelques minutes et noieraient le journal.

### La règle qui ne se négocie pas — **Décidé**

**Le rôle est re-résolu à chaque requête.** Le jeton ne se croit jamais sur parole : si le compte
n'est plus `admin` ou `superadmin`, la synchronisation s'arrête immédiatement, sans attendre une
révocation manuelle. Aujourd'hui, un chef qui quitte le staff perd son accès au prochain import
Desk ; un appareil synchronisé doit mourir exactement de la même façon.

---

## IT-03 — Le serveur CardDAV, en lecture seule

### Ce que le carnet contient — **Décidé**

**Les animateurs et le Staff d'U, et rien d'autre.** Aucun animé, aucun numéro de parent. C'est ce
qui rend ce chantier acceptable : le jeu de données est celui que tout membre identifié voit déjà
sur `/trombinoscope`, exactement le raisonnement que `SECURITY.md` §6 a déjà tenu pour autoriser sa
seule exception existante. Reprends `SectionService::getSectionStaff()`, qui filtre déjà sur les
fonctions de rôle chef et chef d'unité, plus la section `STAFFDU`.

Les fiches y sont celles d'IT-01, variante complète : photo, historique entier.

### Le protocole, en lecture seule

`OPTIONS` annonçant `DAV: 1, 3, addressbook` ; `/.well-known/carddav` pour l'autodécouverte ;
`PROPFIND` résolvant `current-user-principal` puis `addressbook-home-set` ; la collection avec son
`getctag` ; un `PROPFIND Depth:1` donnant un `getetag` par fiche ; les `REPORT`
`addressbook-multiget` et `addressbook-query`. Du XML construit à la main, **aucune dépendance
nouvelle**.

**`PUT` et `DELETE` répondent 403.** La source de vérité est Desk ; rien ne remonte jamais.

### Ce que le dépôt permet déjà, et qu'il faut savoir avant de commencer

- **Le routeur accepte n'importe quelle méthode** : il la stocke comme une chaîne libre et la
  compare telle quelle à `REQUEST_METHOD`. `PROPFIND` et `REPORT` se routent sans le modifier.
- **Le CSRF n'est pas global** : il est appelé contrôleur par contrôleur via
  `AbstractController::guardCsrf()`. Une route machine ne l'appelle simplement pas.
- **`Core\Http\Request` ne porte que `$_POST`** : le corps XML d'un `PROPFIND` se lit sur
  `php://input`, dans le contrôleur — jamais dans un Service, qui n'accède pas aux superglobales.

### Les deux exceptions à documenter — **Décidé**

Les routes CardDAV sont `role_min: public` et s'authentifient par identifiant d'appareil dans le
contrôleur, sans CSRF. C'est **exactement** le motif du webhook GitHub, que `SECURITY.md` §4
documente comme « l'unique exception délibérée ». Il y en aura deux.

**Écris-le dans `SECURITY.md`** : la deuxième exception, son périmètre, pourquoi elle est
inévitable (un client CardDAV n'a pas de session à laquelle lier un jeton), et pourquoi elle est
étroite (lecture seule, contacts de staff uniquement, rôle re-résolu à chaque requête). Une
exception non écrite devient un précédent ; une exception écrite reste une exception.

### Deux pièges qui mordent

1. **Les clients interrogent en permanence.** Toutes les données personnelles sont `BLOB`
   chiffrées : répondre à chaque sondage en déchiffrant tout le staff est intenable. Le `getctag`
   doit pouvoir répondre « rien n'a changé » **sans** déchiffrer quoi que ce soit — appuie-le sur
   un horodatage agrégé, pas sur le contenu des fiches.
2. **L'hébergement mutualisé est la cible du projet.** Certaines configurations Apache rejettent
   `PROPFIND` et `REPORT` avant d'atteindre PHP, ou les détournent vers leur propre module WebDAV ;
   et la disposition « arbre unique » du bootstrap pose un `.htaccess` à la racine qu'il faut
   vérifier. Ajoute une **sonde** : un bouton qui vérifie que ces méthodes arrivent bien jusqu'à
   PHP sur cet hébergeur, pour que l'échec soit expliqué au lieu d'être mystérieux.

### Documentation

`ARCHITECTURE.md` (une section §8 pour le service, et la mention des méthodes HTTP non
conventionnelles), `SECURITY.md` (la seconde exception CSRF/`role_min: public`, et la section
authentification pour les identifiants d'appareil), `specifications.md`, la page RGPD, et le sujet
d'aide de l'écran Mon compte.

---

## Écarté, explicitement

- **Le bouton ailleurs que dans la recherche de membres** — pas sur le trombinoscope, où il
  donnerait à tout membre identifié le moyen d'aspirer les coordonnées des animateurs une par une.
- **La détection d'appareil** pour choisir entre QR et fichier.
- **La photo dans le QR.**
- **La date de naissance** dans la fiche.
- **Les animés dans le carnet synchronisé.**
- **L'écriture depuis un client CardDAV.**
- **Une mention « historique tronqué » dans le QR.**
- **Promettre que la fiche se met à jour toute seule dans un carnet d'adresses après un scan.**
  `UID` et `REV` y aident, certains clients les honorent, d'autres dupliquent en se fiant au nom.
  Aucune interface ne doit affirmer le contraire.
