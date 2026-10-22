# Chantier — Signature personnelle dans le cœur (#926)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§4, §7.5,
§8.10, §8.55, §8.125), `SECURITY.md` (§5, §6, §25, §26), `design.md` (§7.5,
§7.11) et `specifications.md` (§4.6, §22.6), qui restent la source de vérité
des règles. Il ne les recopie pas.

L'issue #926 est **Ready** : elle s'implémente telle qu'elle est écrite, avec
les réponses du §2 aux questions de planification. Là où le texte de l'issue
diffère de ce document, **ce document l'emporte** — en particulier pour le
§6b de l'issue (la copie au signataire), que le mainteneur a remplacé par une
notification (Q1).

**Pas de maquette** : chaque lot décrit ce qu'il affiche, phrase comprise. Ce
qui n'est pas décrit ne change pas.

---

## 0. Règles de conduite

- **Un lot = une branche = une PR**, empilées dans l'ordre du §3. Suite
  complète avant chaque PR (PHPUnit sur MariaDB : `tests/Core`,
  `tests/Architecture`, `tests/Security`, `tests/Modules/Rental` entiers ;
  Vitest par `npm run test:coverage` ; `vendor/bin/phpstan analyse` ;
  `npm run typecheck` dès qu'un fichier de `public/assets/js/` change ;
  Playwright quand un parcours existant change). **Jamais de fusion rouge,
  jamais de baseline élargie.**
- **Documentation dans le même lot** que le code qu'elle décrit
  (`ARCHITECTURE.md`, `SECURITY.md`, `specifications.md`, `design.md`, aide
  `docs/help/*` et `modules/rental/help/*`, `core/View/rgpd_default.html` et
  `RgpdContentService`). Le lot E consolide, il ne rattrape pas.
- **Journal** : une ligne par lot au §6.
- **Schéma déclaratif** (`schema/core.sql`, `modules/rental/schema.sql`,
  `modules/rental/drops.sql`). **Aucune migration ni reprise** des signatures
  de l'ancienne table : ScoutMagic est en test (issue, « Périmètre des
  données »).
- **Les documents déjà signés ne sont jamais modifiés** : la signature est
  incorporée au document au moment de la signature.
- Code en anglais, interface en français. **Aucun texte d'interface ne parle
  d'un état antérieur** (« désormais », « a changé », « nouveau »…). Tout
  bouton qui agit immédiatement demande d'abord, en disant la conséquence
  (`data-confirm` / `ScoutMagicConfirm.ask()`, `design.md` §7.5).
- **Le compte vient de la session, jamais du formulaire** :
  `AuthSession::getUserAccountId()` partout ; un `user_account_id` posté est
  ignoré, et un test le prouve à chaque route.
- Locations ne nomme du cœur que des classes publiques du cœur
  (`docs/module-development.md`) ; le cœur ne nomme jamais Locations.

---

## 1. État des lieux (vérifié dans le code au commit `64e97906`)

**Stockage.** `modules/rental/src/Repository/RentalManagerSignatureRepository.php`
est clé par `user_account_id` (contexte de chiffrement
`rental_manager_signature`, L28) : `findPng()` L37, `has()` L50, `save()` L71
(un upsert par dialecte, couvert par
`RentalManagerSignatureRepositoryOnMysqlTest`), `delete()` L91. Table
`rental_manager_signatures` (`modules/rental/schema.sql` L1464–1479) :
`image_encrypted MEDIUMBLOB`, clé étrangère vers `user_accounts` en
`ON DELETE CASCADE`.

**Image.** `modules/rental/src/Document/SignatureImage.php` : `fromDataUrl()`
L53 (seulement `data:image/png;base64`), `normalize()` L72 (PNG, JPEG, WebP ;
5 Mo L32 ; 25 mégapixels L46, son propre plafond plutôt que
`Core\Image\ImageDimensionGuard` à 50 ; ajusté à 1200 × 400 ; transparence
gardée). Lève `RentalException`.

**Contresignature.** `RentalSignedContractService::countersign()` (L475) lit
d'abord la signature enregistrée (L484) et refuse sans elle ; le travail se
fait sous `documentRepository->withBookingLocked()` (L500) dans le verrou du
bien ; à l'intérieur : la copie en attente, l'assemblage (`assemble()` L681,
avec le nom du signataire), `attachPdf`, `bookingAudit->record()` (L548) ;
après le verrou : `mail->sendSignedContract()` (L580), l'échec journalisé
`rental_signed_contract_send_failed` (L595).
`CountersignedContract::addCountersignaturePage()` (L104) produit un PDF
distinct : changer ou supprimer la signature enregistrée ne le touche pas.

**Contrôleur et écrans.** `RentalManagementController` : dépendance
`$signatureRepository` (L260) ; `has_signature` dans l'étape du contrat
(L1709) et dans la page Documents (L4318) ; `countersignContract()` L1752
(compte depuis la session) ; `mySignature()` L1835 (403 pour qui ne gère
aucun bien), `saveSignature()` L1858, `deleteSignature()` L1907,
`signatureImage()` L1931 (`private, no-store`, `nosniff`),
`signatureReturn()` (liste blanche de retour) ; **`bookingAction()` (L4552)
n'attrape que `RentalException` (L4579)**. Vues : `my_signature.html.twig` ;
**deux points d'entrée de contresignature** — `_countersign_step.html.twig`
(L26–39 : formulaire `data-confirm` ou lien « Enregistrer ma signature » vers
`/mes-locations/ma-signature?retour=…`) et le contrat de remplacement
`_contracts.html.twig` (L88–102, son propre texte `data-confirm`) ; lien
« Ma signature » dans `my_rentals.html.twig` (L65). Quatre routes
`identified` dans `module.json` (L366–411) ; version du module 1.48.0
(`ModuleManifestTest`).

**Interface.** `public/assets/js/rental-signature.js` (cadre de dessin ; ne
remplit le champ caché `signature_data` que si quelque chose a été tracé),
chargé par `my_signature.html.twig` seulement.

**Composition.** `public/index.php` : dépôt Locations L12364, injecté dans le
service et le contrôleur ; `$rentalBookingAudit` L12149 ;
`SuperAdminService` L2579.

**Aide.** `modules/rental/help/locations-signatures.md` : questions « Où
enregistrer ma signature pour les contrats ? » et « Qui peut voir ma
signature ? » (L9–10), `paths` avec `/mes-locations/ma-signature` (L11),
section « Ma signature » (L60).

**Cœur.** Pas de capacité de signature. « Mon compte » :
`core/Http/Controller/AccountController.php`, routes vers L4197, sous-page
modèle `/account/devices` (L4237, ancêtre « Mon compte ») ;
`/account` est **mis en cache hors ligne** (`core/Offline/OfflineWhitelist.php`
L97). Adresses de retour : `Core\Http\SafeRedirect::internalPath()`
(SECURITY §26). Un compte n'est **jamais supprimé** : seulement désactivé
(`UserAccountRepository::deactivate()` L573), appelé par
`SuperAdminService::deactivate()` seulement. Notifications :
`Core\Notification\NotificationService::dispatch()` sur des types déclarés
dans `module.json` (`rental.new_request` L1424).

**RGPD.** `core/View/rgpd_default.html` §2.1 (comptes), §3.1 (durées) ; le
paragraphe « signature d'un gestionnaire » du module Locations (§2.4) ;
`RgpdContentService::buildSystemPrompt()` (règle 36 la dernière) ;
`EncryptedCoreColumnsAreAccountedForTest::ACCOUNTED_FOR`.

**Sans rapport** : `Modules\InboundMail\…\AttachmentPolicy::isSignatureImage()`
filtre les logos de signature des e-mails reçus. Ne pas y toucher.

---

## 2. Décisions verrouillées

### 2.1 Ce que demande l'issue

- La signature appartient au **compte** (`user_account_id`, l'adresse de
  connexion), **jamais à un membre** ; le nom qui l'accompagne est celui du
  compte.
- Le stockage, la normalisation de l'image, la lecture et la suppression
  passent dans le **cœur** et servent à tous les modules.
- « Mon compte » permet de **dessiner ou importer**, prévisualiser,
  **remplacer** et **supprimer** sa signature, pour tout compte `identified`,
  sans fiche membre ni rôle Locations.
- Protections conservées : image normalisée (PNG interne ; import PNG, JPEG,
  WebP), chiffrement au repos, accès réservé au titulaire, routes
  authentifiées, CSRF, jamais sous une adresse publique.
- **Signature ponctuelle** sans signature enregistrée : un dialogue dans le
  parcours du document, dessin ou import, case « Mémoriser ma signature dans
  mon compte pour une utilisation future » **décochée par défaut** ; décochée,
  rien n'est gardé ; cochée, la signature est gardée — jamais implicitement.
  Annuler ne signe rien et ne garde rien.
- **Avec une signature enregistrée** : toujours un dialogue « Ce document sera
  signé avec la signature enregistrée dans votre compte. Vérifiez son contenu
  avant de confirmer. », boutons « Annuler » et « Signer le document » ;
  jamais d'apposition automatique.
- **Locations** : la contresignature (deux points d'entrée) passe par la
  capacité du cœur et ces deux dialogues ; toutes ses règles restent ;
  l'ancien stockage et ses écrans disparaissent.
- **Sécurité et audit** : identité depuis la session ; aucune lecture par un
  autre compte ou un administrateur ; jamais de pixels dans les journaux ;
  l'acte de signature est tracé (identifiants, compte, date) ; la signature
  disparaît avec le compte, pas les documents signés.
- **§6a** : la même brique sert à un **signataire sans compte** (le locataire
  signe au doigt le jour de l'état des lieux, #989) : dessin seulement, nom
  tapé, jamais gardé à part.
- **§6c** : l'**image** seule, jamais la dynamique du tracé ; page RGPD
  complétée (finalité, base légale, suppression, conservation des documents).

### 2.2 Décisions du mainteneur (21/10/2026)

- **Q1 — Ce que reçoit le signataire (§6b de l'issue).** Ni copie (Cc) de
  l'e-mail au locataire, ni e-mail à part : une **notification** par le
  mécanisme ordinaire (`NotificationService::dispatch()`, un type déclaré par
  le module), au compte signataire, **menant à la page « Documents » de la
  réservation**, qui porte le document signé. Elle nomme le document, la date
  et l'heure. Ni pièce jointe, ni lien de suivi, ni donnée du locataire
  au-delà de ce que la page Documents montre déjà au gestionnaire. Pour
  Locations : un type `rental.document_signed`, au seul compte signataire.
  Son échec ne bloque jamais la contresignature (journalisé).
- **Q3 — Un autre tracé quand une signature est enregistrée.** Non : le mode
  « signature enregistrée » seulement, avec un lien « Gérer ma signature »
  vers `/account/signature?retour=…` (`SafeRedirect`).
- **Q4 — Effacement.** La signature enregistrée est effacée à la
  **désactivation** du compte (`UserAccountRepository::deactivate()`, par
  `SuperAdminService::deactivate()`) et, par la cascade de la clé étrangère,
  à un effacement manuel du compte. Les documents déjà signés gardent leur
  copie.
- **Q5 — Aperçu.** Un petit aperçu de la signature enregistrée dans le
  dialogue de confirmation, chargé depuis la route réservée au titulaire
  `/account/signature/image` (`no-store`) **à l'ouverture du dialogue
  seulement**, jamais incorporé au HTML de la page.

### 2.3 Décisions de planification (le ticket ou le code les tranchent)

- **Q6 — Anciennes adresses `/mes-locations/ma-signature…`** : retirées
  (404). Rien n'y renvoie, le site est en test, le ticket refuse le code de
  compatibilité. « Gérer mes locations » renvoie à Mon compte › Ma signature.
- **Import** d'une image dans le dialogue ponctuel : oui pour un titulaire de
  compte (§2 de l'issue) ; non pour un signataire sans compte (§6a : dessin
  seulement).
- **Table** `user_account_signatures` dans `schema/core.sql` ;
  `rental_manager_signatures` supprimée par `modules/rental/drops.sql`
  (`DROP TABLE IF EXISTS`), sans reprise.
- **Placement** : `Core\Signature` (+ `Core\Signature\Controller`), sur le
  modèle de `Core\Contact\Controller\DeviceCredentialController`. Page du
  titulaire `/account/signature`, **distincte** de `/account` qui est mise en
  cache hors ligne et ne doit jamais porter les octets d'une signature ; une
  carte sur `/account` ne montre que l'état et « Gérer ma signature ».
- **Atomicité** : l'image est normalisée **avant** tout verrou du module ;
  « Mémoriser » est un upsert exécuté **dans la transaction du module** (dans
  `withBookingLocked`), si bien qu'une signature qui échoue annule la
  mémorisation.
- **Audit** : l'acte est tracé avec le compte de la **session**
  (`BookingAudit::record()` gagne un identifiant de compte acteur facultatif
  qui remplace la résolution membre → e-mail → compte), plus une ligne de
  journal du cœur sans pixels.
- **§6c** : seule l'image PNG est gardée ; le JavaScript ne collecte jamais
  de points ni de temps.
- **Effacement à la désactivation** : dans `SuperAdminService::deactivate()`,
  juste après le retrait de l'accès, par
  `PersonalSignatureService::eraseOnDeactivation()` (dépendance obligatoire :
  pas d'oubli silencieux) ; une réactivation ne la rend pas.
  `AccountDeactivationErasesTheSignatureTest` échoue sur un second appelant de
  `UserAccountRepository::deactivate()`.

### 2.4 Écarté

- Une copie e-mail au signataire (§6b de l'issue) : remplacée par la
  notification (Q1).
- Une signature électronique qualifiée, un horodatage tiers : hors du
  périmètre de l'issue.
- Une reprise des signatures de `rental_manager_signatures` : refusée par
  l'issue.

---

## 3. Lots et ordre d'exécution

```
Vague 1 : [0 Document de chantier + A Stockage et image]   (une PR)
Vague 2 : [B « Ma signature » dans Mon compte ◄ A]
Vague 3 : [C1 API de signature et dialogue commun ◄ B]    (le dialogue mène à /account/signature et en lit l'aperçu)
Vague 4 : [C2 Locations sur le cœur ◄ C1]
Vague 5 : [D Notification au signataire ◄ C2]
Vague 6 : [E Fin du §6a et clôture ◄ tous]
```

### Lot 0 — Document de chantier

- Ce document : état des lieux, décisions (l'issue et les réponses aux
  questions), lots, pièges, critères d'acceptation, journal. Livré avec A.

### Lot A — Stockage et image dans le cœur

- **Schéma** `schema/core.sql` : `user_account_signatures`
  (`user_account_id INT UNSIGNED` clé primaire, `image_encrypted MEDIUMBLOB
  NOT NULL`, `updated_at DATETIME NOT NULL`, clé étrangère vers
  `user_accounts` en `ON DELETE CASCADE`), à côté de `user_account_photos`.
- **`Core\Signature`** : `SignatureImage` (`fromDataUrl()`,
  `fromUploadedFile()`, `fromBytes()` → `SignaturePng` ; logique reprise de
  Locations, `ImageDimensionGuard` en plus des 25 mégapixels) ;
  `SignaturePng` (valeur immuable, en-tête PNG vérifié, `__debugInfo()` sans
  octets) ; `SignatureException` (`UserFacingException`) ;
  `PersonalSignatureRepository` (contexte `user_account_signature`, upsert
  par dialecte, sans transaction propre) ; `PersonalSignatureService`
  (titulaire seulement : `has()`, `savedAt()`, `ownImage()`, `save()`,
  `delete()`, plus `eraseOnDeactivation()`), journal `security` sans image.
- **Désactivation** : `SuperAdminService::deactivate()` efface la signature
  (Q4).
- **Tests** : `SignatureImageTest`, `SignaturePngTest`,
  `PersonalSignatureRepositoryTest` et `…OnMysqlTest` (`UsesProductionEngine`),
  `PersonalSignatureServiceTest`, effacement à la désactivation
  (`SuperAdminServiceTest`, `AccountDeactivationErasesTheSignatureTest`),
  `PersonalSignatureRgpdCoverageTest` ; table SQLite dans
  `tests/DatabaseTestHelper.php` ; entrée de
  `EncryptedCoreColumnsAreAccountedForTest`.
- **Documentation** : ARCHITECTURE §8.125 « Personal signature
  (`Core\Signature`) » ; SECURITY §5 (liste des données personnelles, règle
  du titulaire seul) ; RGPD §2.1 et §3.1, règle 37 du prompt.
- Locations n'est pas touché : deux stockages pendant un lot sont sans
  danger, l'ancien est retiré en C2.

### Lot B — « Ma signature » dans Mon compte

- **Contrôleur** `Core\Signature\Controller\AccountSignatureController` :
  `GET /account/signature`, `POST /account/signature` (enregistrer ou
  remplacer ; `retour` par `SafeRedirect::internalPath()`),
  `POST /account/signature/delete` (`data-confirm`),
  `GET /account/signature/image` (titulaire seul, `private, no-store`,
  `nosniff`, 404 sinon) ; toutes `identified` ; compte depuis la session
  seulement.
- **Routes** dans `public/index.php` (fil d'Ariane : ancêtre « Mon compte »),
  câblage du contrôleur.
- **Écrans** : `core/View/templates/account/signature.html.twig` (dessiner ou
  importer, aperçu, remplacer, supprimer ; textes de la page Locations,
  généralisés) ; une carte sur `account/index.html.twig` qui ne montre que
  l'état et « Gérer ma signature » (aucune image : `/account` est en cache
  hors ligne).
- **JavaScript** `public/assets/js/signature-pad.js`, généralisé depuis
  `rental-signature.js` (qui reste jusqu'à C2).
- **Tests** : contrôleur (identified sans fiche membre ni rôle Locations,
  anonyme refusé, CSRF, image 404 pour qui n'en a pas, retour étranger
  refusé, `user_account_id` posté ignoré), gabarits par `TestTwig`, Vitest,
  `UxConventionsTest` (route sans page).
- **Documentation** : aide `docs/help/ma-signature.md` (les questions sur
  « ma signature » y passent depuis `locations-signatures.md`) ;
  `mon-compte.md` (`related`) ; spécifications §4.6 ; `design.md` §1.6 si la
  liste des pages du compte y figure ; SECURITY §6 (exception de la route
  d'image) ; RGPD (« depuis Mon compte »).

### Lot C1 — API de signature et dialogue commun

- **Valeurs** : `SignatureOrigin` (`Stored`, `DrawnNow`, `ImportedNow`) ;
  `SignatureSubmission::fromRequest(Request, allowImport, allowRemember)`
  (lit `signature_source`, `signature_data`, `signature_file` par
  `is_uploaded_file()`, `remember_signature` ; **ne lit jamais un
  identifiant de compte**) ; `AccountSignature` (compte, PNG, origine,
  mémorisation demandée, nom du compte) ; `WitnessSignature` (PNG, nom tapé ;
  ni compte ni stockage).
- **Service** : `forSigning(sessionAccountId, submission)` (signature
  enregistrée lue, ou « Votre signature enregistrée n'existe plus… » ;
  nouvelle signature normalisée, ou « Tracez votre signature ou importez-en
  l'image. ») — **avant tout verrou** ; `rememberIfAsked(AccountSignature,
  at)` — un upsert, sans transaction propre, rien quand l'origine est
  `Stored` ou la case décochée ; `witness(submission, typedName)` — dessin
  seulement, nom de 1 à 120 caractères ; `recordSigning(…)` — journal
  `signature/document_signed`, sans pixels.
- **Dialogue** `core/View/templates/partials/signature_dialog.html.twig` dans
  `partials/modal.html.twig` (fond statique) : mode enregistré (texte de
  l'issue, conséquence fournie par le module, aperçu chargé à l'ouverture
  (Q5), « Gérer ma signature » (Q3), « Annuler » / « Signer le document ») ;
  mode nouveau (cadre de dessin, import facultatif, case « Mémoriser… »
  jamais cochée au rendu, mêmes boutons) ; mode témoin (§6a : nom requis,
  dessin seulement, pas de case). `multipart/form-data`. Le dialogue est la
  confirmation : pas de `data-confirm` en plus.
- **JavaScript** `signature-dialog.js` : efface le cadre et le champ caché à
  la fermeture, après l'envoi et au retour par le cache arrière (`pageshow`
  persistant) ; seulement `toDataURL()`.
- **Tests** : service, gabarits dans les trois modes, Vitest. **Docs** :
  ARCHITECTURE §8.125, `design.md` §7.5.

### Lot C2 — Locations sur le cœur, ancien stockage retiré

- Le contrôleur construit la soumission et appelle `forSigning()` avant
  `bookingAction()`, hors verrou ; `SignatureException` traduite pour
  `bookingAction()` (qui n'attrape que `RentalException`).
- `countersign(…, AccountSignature $signature, …)` : `rememberIfAsked()` dans
  `withBookingLocked`, juste après la copie en attente et avant
  l'assemblage ; `bookingAudit->record(…, actorAccountId:)` ; après la
  transaction : `recordSigning()`, l'e-mail au locataire comme aujourd'hui.
- Les deux points d'entrée (étape du contrat, contrat de remplacement)
  ouvrent le dialogue commun.
- Retirés : les quatre routes `/mes-locations/ma-signature…` (version du
  module 1.49.0), `RentalManagerSignatureRepository` et ses tests,
  `Document\SignatureImage` et son test, `my_signature.html.twig`,
  `rental-signature.js` et son test, la table dans `RentalTestHelper`, le
  lien « Ma signature » de `my_rentals.html.twig` (remplacé par « Gérer ma
  signature » vers Mon compte) ; `DROP TABLE IF EXISTS
  rental_manager_signatures;` dans `modules/rental/drops.sql`.
- **Tests** : service (ponctuel décoché → rien de gardé ; coché → gardé ;
  mémorisation puis assemblage en échec → annulée ; jamais la signature d'un
  autre compte ; journal sans pixels), contrôleur (deux modes sur les deux
  points d'entrée ; annuler laisse la copie en attente ; non-gestionnaire
  refusé), E2E `rental-journey.spec.js` (dialogue, import, case décochée →
  « Aucune signature » dans Mon compte ; deuxième fois en mode enregistré).
- **Docs** : `locations-signatures.md`, spécifications §22.6,
  `docs/rental-guide.md`, paragraphe RGPD du module Locations,
  ARCHITECTURE §8.55.

### Lot D — Notification au signataire (§6b, réécrit par Q1)

- Type `rental.document_signed` déclaré dans `modules/rental/module.json`
  (groupe « Locations », `role_min: identified`).
- Après la transaction de la contresignature, indépendamment de l'e-mail au
  locataire : `NotificationService::dispatch('rental.document_signed',
  [compte signataire], payload)` — le document, la date et l'heure de la
  signature, une URL vers la page **Documents** de la réservation
  (`/mes-locations/{bien}/reservations/{id}/documents`). Ni pièce jointe, ni
  lien de suivi, ni donnée du locataire.
- Son échec ne bloque jamais la contresignature : il est journalisé.
- **Tests** : le destinataire est le seul compte de la session, l'URL est
  celle de Documents, l'échec est journalisé et la contresignature tient.
- **Docs** : RGPD (centre de notifications), ARCHITECTURE §8.55, aide
  `locations-signatures.md`.

### Lot E — Fin du §6a et clôture

- Mode témoin terminé (gabarit et Vitest), note ARCHITECTURE pour #989.
- Relecture finale de SECURITY, ARCHITECTURE, spécifications, RGPD ; journal
  du chantier ; PR « Corrige #926 », #989 débloquée.

---

## 4. Pièges

- **Deux points d'entrée de contresignature** dans Locations (l'étape du
  contrat et le contrat de remplacement) : les deux, toujours.
- **`bookingAction()` n'attrape que `RentalException`** : une
  `SignatureException` qui y remonte est une 500.
- **La mémorisation hors de la transaction** casse l'atomicité : « signer et
  mémoriser » est un seul acte, ou rien.
- **Le décodage de l'image sous le verrou du bien** bloque les autres
  gestionnaires pendant un travail CPU : normaliser avant.
- **`/account` est en cache hors ligne** (`OfflineWhitelist`) : jamais
  d'octets de signature dans cette page ; l'image a sa propre route
  `no-store`.
- **Le cache arrière du navigateur** garde un cadre tracé : l'effacer au
  `pageshow` persistant.
- **CSP** : aucun script en ligne ; le dialogue est piloté par un fichier de
  `public/assets/js/`.
- **`ExplicitDropsTargetRetiredColumnsTest`** (le nom de la table retirée),
  **`HelpInvariantsTest`** (questions en double entre deux sujets, chemins
  morts dans `paths`), **`UxConventionsTest`** (action destructive sans
  `data-confirm`, route sans page), **`TwigCommentsAreEnglishTest`**.
- **La matrice d'autorisations** (`scripts/authz-support.php`) lit les
  routes littérales de `public/index.php` : une route du cœur y figure.
- **Aucun texte « désormais »** : le message après une mémorisation dit
  « Votre signature est enregistrée dans Mon compte. », pas « désormais
  enregistrée ».
- **Un compte n'est jamais supprimé** : sans l'effacement à la désactivation,
  la cascade ne joue jamais.
- `rowCount()` après un UPDATE : lignes CHANGÉES sur MySQL et MariaDB,
  TROUVÉES sur SQLite. Une requête que le moteur décide a son test `OnMysql`.

---

## 5. Critères d'acceptation

1. `/account` permet à son propriétaire (rôle `identified`) de voir
   l'existence de sa signature, l'ajouter, la remplacer et la supprimer, sans
   fiche membre ni rôle de gestionnaire Locations.
2. Deux comptes différents, même s'ils correspondent à des membres
   partageant des coordonnées, ne voient et n'utilisent jamais les signatures
   de l'autre.
3. Sans signature enregistrée, la signature ponctuelle du contrat Locations
   fonctionne ; aucune signature n'est conservée si la case « Mémoriser »
   reste décochée.
4. La case « Mémoriser » est décochée par défaut ; cochée, la signature est
   conservée dans Mon compte et réutilisable par les modules autorisés.
5. Avec une signature enregistrée, le dialogue explicite précède chaque
   contresignature ; annuler laisse le document inchangé.
6. Les droits de contresignature, la validation de la copie reçue et l'envoi
   du contrat final dans Locations restent inchangés.
7. La modification ou la suppression d'une signature personnelle n'affecte
   aucun document déjà signé.
8. Tests unitaires et d'intégration du cœur (stockage, chiffrement,
   autorisation, import, suppression), tests du service de contresignature
   Locations (avec et sans enregistrement), tests d'interface et E2E des
   dialogues, de la confirmation et du consentement.
9. Documentation technique et utilisateur à jour (`ARCHITECTURE.md`,
   `specifications.md`, `SECURITY.md`, aide « Mon compte » et Locations,
   contenu RGPD).
10. Le signataire reçoit la notification du §2.2 (Q1) ; la signature est
    effacée à la désactivation du compte (Q4).

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| 0 — Document de chantier | #1003 | Ce document : état des lieux au commit `64e97906`, décisions de l'issue, du mainteneur (Q1, Q3, Q4, Q5, 21/10/2026) et de planification, lots, pièges, critères d'acceptation. | Lot D réécrit selon Q1 (notification, pas d'e-mail). |
| A — Stockage et image dans le cœur | #1003 | Table `user_account_signatures` (`schema/core.sql`, SQLite de `DatabaseTestHelper`) ; `Core\Signature\SignatureImage` (`fromDataUrl()`, `fromUploadedFile()`, `fromBytes()`), `SignaturePng`, `SignatureException`, `PersonalSignatureRepository`, `PersonalSignatureService` (`has()`, `savedAt()`, `ownImage()`, `save()`, `delete()`, `eraseOnDeactivation()`) câblé dans `public/index.php` ; `SuperAdminService::deactivate()` efface la signature ; RGPD §2.1 et §3.1 (« Signature personnelle »), règle 37 ; ARCHITECTURE §8.125 ; SECURITY §5. Tests : `SignatureImageTest`, `SignaturePngTest`, `PersonalSignatureRepositoryTest`, `PersonalSignatureRepositoryOnMysqlTest`, `PersonalSignatureServiceTest`, `AccountDeactivationErasesTheSignatureTest`, `PersonalSignatureRgpdCoverageTest`, `SuperAdminServiceTest`, inventaire des colonnes chiffrées. | `SignatureImage::normalize()` devient `fromBytes()` et rend un `SignaturePng` ; `fromUploadedFile()` juge la taille sur le disque avant de lire. `PersonalSignatureService` dépendance **obligatoire** de `SuperAdminService` (troisième paramètre, avant le courrier facultatif) : une désactivation qui oublierait la signature serait silencieuse. Le journal (`personal_signature_saved`, `_deleted`, `_erased`) est en plus du plan. Le fichier Locations reste en place jusqu'à C2. |
| B — « Ma signature » dans Mon compte | #1003 | `Core\Signature\Controller\AccountSignatureController` : `GET` et `POST /account/signature`, `POST /account/signature/delete` (`data-confirm`), `GET /account/signature/image` (titulaire seul, `private, no-store`, `nosniff`, 404 sinon), toutes `identified`, compte de la session seulement ; routes et câblage dans `public/index.php` (fil d'Ariane « Mon compte » › « Ma signature ») ; gabarit `account/signature.html.twig` (tracer ou importer, signature enregistrée et sa date, « Remplacer ma signature », « Supprimer ma signature ») ; carte « Ma signature » sur `account/index.html.twig` (état, date, « Gérer ma signature », jamais l'image ; `AccountController` gagne `?PersonalSignatureService` en dernier paramètre) ; `public/assets/js/signature-pad.js`. Aide `docs/help/ma-signature.md` (nouveau sujet), `mon-compte.md` (`related`) ; spécifications §4.6 ; `design.md` §1.6 ; SECURITY §6 (septième exception : l'image) ; ARCHITECTURE §8.125 ; RGPD §2.1 (« depuis Mon compte »). Tests : `AccountSignatureControllerTest`, `AccountSignatureRbacTest` (planchers lus dans `public/index.php`), `AccountSignaturePageTest` (`TestTwig`), `AccountControllerTest` (la carte), `tests/js/signature-pad.test.js` ; vérification Playwright ponctuelle (dessiner, importer, afficher, supprimer), non ajoutée à la suite. | Les questions de `locations-signatures.md` restent où elles sont : celles de `ma-signature.md` sont formulées autrement (aucun doublon) et la page Locations reste en service jusqu'à C2, qui fusionnera les deux. Entre B et C2, la contresignature lit encore l'ancienne signature Locations : un gestionnaire qui n'enregistre sa signature que dans Mon compte doit encore l'enregistrer dans « Gérer mes locations » — c'est pourquoi l'aide et la page de B ne parlent pas des contrats. `UxConventionsTest::NON_PAGE_MODULE_ROUTES` ne concerne que les routes de modules : la route d'image, du cœur, n'y figure pas (elle ne déclare pas de fil d'Ariane, ce qui suffit à `HelpMenuCoverageTest`). Le fil d'Ariane déclare l'ancêtre « Mon compte » comme `/account/devices`. Retour : « Continuer sans modifier ma signature » (pas de « Retour », `design.md` §7.3) ; l'adresse de retour ne peut pas être la page elle-même. Le cadre est vidé au retour par le cache arrière (`pageshow` persistant). |
| C1 — API de signature et dialogue commun | #? | `Core\Signature\SignatureOrigin` (`Stored`, `DrawnNow`, `ImportedNow`), `SignatureSubmission` (`fromRequest()` lit `signature_source`, `signature_data`, `signature_file` par `is_uploaded_file()`, `remember_signature` ; jamais un identifiant de compte), `AccountSignature`, `WitnessSignature` ; `PersonalSignatureService::forSigning()`, `rememberIfAsked()` (un upsert, sans transaction propre, rien pour `Stored` ou une case décochée), `witness()` (dessin seulement, nom de 1 à 120 caractères), `recordSigning()` ; partiel `partials/signature_dialog.html.twig` (modes enregistré, nouveau, témoin ; « Annuler » / « Signer le document » ; pas de `data-confirm`) ; `public/assets/js/signature-dialog.js` (aperçu créé à l'ouverture depuis `/account/signature/image`, retiré à la fermeture ; tout est vidé à la fermeture, après l'envoi et au retour par le cache arrière) ; `signature-pad.js` se vide sur l'évènement `signature-pad:reset`. ARCHITECTURE §8.125 (API et règle de transaction), `design.md` §7.5. Tests : `PersonalSignatureSigningTest`, `SignatureDialogTest` (`TestTwig`), `tests/js/signature-dialog.test.js`, `signature-pad.test.js`. | Le journal de l'acte suit le nommage du lot A : catégorie `core`, type `personal_signature_document_signed`, niveau `security` (plutôt que `signature/document_signed` en `info`) ; son contexte porte aussi l'heure. Le nom du compte vient d'un `UserAccountRepository` **facultatif**, dernier paramètre de `PersonalSignatureService` (câblé dans `public/index.php`) : sans lui, `signerName` est null et le module nomme le signataire à sa façon. Le constructeur de `SignatureSubmission` reste public pour les tests ; seul `fromRequest()` lit un fichier, et seulement après `is_uploaded_file()`. Un dialogue envoyé vide le dit sur place (le serveur refuse de même). Option `form_attributes` du partiel, en plus du plan : une page dont le script transforme les formulaires en `fetch` (Locations, `data-async="off"`) le laisse poster normalement, un dialogue ouvert ne survivant pas au rechargement des panneaux. Le mode témoin refuse aussi une image importée ou le mode enregistré, même si la soumission en porte. Aucun module ne s'en sert encore : C2 branche Locations. |
| C2 — Locations sur le cœur, ancien stockage retiré | #? | `RentalSignedContractService::countersign(…, AccountSignature, ?int $actorMemberId, $now)` (dépendance `PersonalSignatureService` à la place du dépôt Locations) : `rememberIfAsked()` dans `withBookingLocked()`, juste après `pendingCopyOf()`, avant la lecture du fichier et l'assemblage ; page ajoutée au nom du compte (« un gestionnaire » sans nom) ; `BookingAudit::record(…, $actorAccountId)` ; `recordSigning()` après la transaction. `RentalManagementController::countersignContract()` : soumission et `forSigning()` dans la fermeture de `bookingAction()` (après CSRF et contrôle du gestionnaire, avant tout verrou), `SignatureException` traduite en `RentalException` ; message « Votre signature est enregistrée dans Mon compte. » quand la case est cochée ; `mySignature()`, `saveSignature()`, `deleteSignature()`, `signatureImage()`, `signatureReturn()` et la dépendance au dépôt retirés ; `has_signature` lu du cœur. Les deux points d'entrée (`_countersign_step.html.twig`, `_contracts.html.twig`) ouvrent le dialogue commun, avec les textes de conséquence de leurs anciens `data-confirm` ; `booking.html.twig` et `booking_documents.html.twig` chargent `signature-pad.js` et `signature-dialog.js` ; `my_rentals.html.twig` mène à « Gérer ma signature » (Mon compte). Retirés : les quatre routes (`module.json` 1.49.0, `ModuleManifestTest`), `rental_manager_signatures` (`schema.sql` ; `DROP TABLE IF EXISTS` dans `drops.sql`, sans reprise), `RentalManagerSignatureRepository` et ses deux tests, `Document\SignatureImage` et son test, `my_signature.html.twig`, `rental-signature.js` et son test, la table de `RentalTestHelper`, l'entrée de `UxConventionsTest`. Docs : aide `locations-signatures.md` (section « Avec ou sans signature enregistrée », plus de chemin mort ni de question sur « ma signature ») et `ma-signature.md` (« Où elle sert »), page « Ma signature » (les contrats de location), spécifications §4.6 et §22.6, RGPD (paragraphe Locations : base légale, rien de gardé sans la case), SECURITY §5, ARCHITECTURE §8.55 et §8.125. Tests : `RentalSignedContractServiceTest` (ponctuel décoché → rien ; coché → gardé ; mémorisation puis fichier introuvable → annulée, rien au journal ; signature d'un autre compte jamais lue ; journal et historique au compte signataire, sans pixels ; nom du compte sur la page), `RentalManagementControllerTest` (dialogue dans les deux modes sur le tableau de bord et sur Documents, rien d'embarqué, case jamais cochée ; envoi vide refusé en français, copie toujours en attente ; non-gestionnaire refusé ; `user_account_id` posté ignoré), `BookingAuditTest`, E2E `rental-journey.spec.js`. | `forSigning()` est appelé dans la fermeture de `bookingAction()` plutôt qu'avant : il suit ainsi le CSRF et le contrôle du gestionnaire (une image n'est jamais décodée pour qui ne gère pas le bien) et reste hors de tout verrou, ceux-ci n'étant pris que par `countersign()`. Le dialogue poste sans `rental-booking.js` (`data-async="off"`, comme les autres dialogues de Locations) : la page se recharge après la signature. `docs/rental-guide.md` ne parlait pas de la page retirée : inchangé. Dans l'E2E, la première contresignature importe une image, case décochée, et Mon compte dit « Aucune signature enregistrée. » ; la seconde trace au cadre et coche « Mémoriser ». |
