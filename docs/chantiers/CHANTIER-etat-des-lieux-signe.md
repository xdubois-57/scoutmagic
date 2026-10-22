# Chantier — État des lieux signé par les deux parties, photos et liste papier (#989)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§8.55,
§8.56, §8.125), `SECURITY.md` (§5, §6, §25), `design.md` (§7.5) et
`specifications.md` (§22.6, §22.7), qui restent la source de vérité des
règles. Il ne les recopie pas.

L'issue #989 s'implémente telle qu'elle est écrite, avec les décisions du §2.
Là où le texte de l'issue diffère de ce document, **ce document l'emporte** —
en particulier pour la « copie au signataire » de son §1, que le mainteneur a
remplacée par une notification (Q1), et pour le format HEIC (Q6).

L'état du code (§1) a été vérifié au commit **`371b4597`** (origin/main), avant
le lot A. Le journal (§6) dit ce que chaque lot a changé depuis.

Ce n'est pas un avis juridique.

---

## 0. Règles de conduite

Ce sont celles du chantier #926, §0, sans changement :
- un lot = une branche = une PR ;
- la suite complète passe avant chaque PR (PHPUnit sur MariaDB, Vitest, phpstan, `npm run typecheck`, Playwright quand un parcours change) ;
- la documentation va dans le même lot que le code ;
- le schéma est déclaratif (`modules/rental/schema.sql`, `modules/rental/drops.sql`, `schema/core.sql`), sans migration ni reprise (le site est en test) ;
- un document signé n'est jamais modifié ;
- le compte vient de la session, jamais du formulaire ;
- aucun texte d'interface ne parle d'un état antérieur.

---

## 1. État des lieux du code (vérifié au commit `371b4597`, avant le lot A)

### 1.1 Validation de l'état des lieux

**Service.** `modules/rental/src/Service/RentalInventoryValidationService.php::validate(booking, asset, phase, actorMemberId, actorName, now, arrivalTickedByHand)` fait les choses dans cet ordre :
1. `RentalStayService::assertPhaseOpen()` ;
2. refus si le bien n'a ni lignes ni compteurs (« l'état des lieux se coche à la main ») ;
3. `missingReadings()` ;
4. **gel par la clé unique** (`recordInventoryValidation()`, `uq_rental_inventory_validations (booking_id, phase)`), puis nouvelle lecture des lignes et des compteurs ;
5. `DocumentPdfService::generate()` avec le corps `Stay\InventoryReport::html()` ;
6. `RentalDocumentService::attachPdf(…, DocumentType::INVENTORY, …, isForRenter: true)` et `attachInventoryDocument()`.

En cas d'échec, le service **compense** : il supprime le document puis appelle `forgetInventoryValidation()`. **Il n'y a ni transaction ni `withBookingLocked`**, ce qui compte pour la règle de #926 (voir §4). Viennent ensuite `BookingAudit::record(STATUS_CHANGED, …, 'État des lieux validé')`, puis `RentalBookingMailService::sendDocument()` et `markSent()`.

**Contrôleur.** `RentalManagementController::validateInventory()` passe par `bookingAction()`, qui n'attrape que `RentalException`. Le nom du signataire vient de `signerDisplayName()` (`SignerName::forAccount()`).

**Gabarit.** `modules/rental/views/management/booking_inventory.html.twig` porte le formulaire `data-inventory-validate` avec un `data-confirm` (texte « Valider et envoyer… à {renterEmail} »). La page dit déjà « Cette page ne fonctionne qu'en ligne ». La carte « Déjà validé » donne le lien vers le PDF.

**Le PDF aujourd'hui.** `InventoryReport::html()` est une fonction pure qui échappe tout. Elle produit :
- les éléments, puis les écarts ou les différences ;
- les compteurs ;
- les incidents en simple liste à puces de descriptions, **sans date ni photo** ;
- la ligne finale « Validé par X le d/m/Y à H:i », formatée directement, **sans `AppClock::zone()`**.

**Le moteur PDF.** `core/Pdf/DocumentPdfService.php` utilise Dompdf avec `isRemoteEnabled=false` : une image ne peut arriver qu'en URI `data:`. Les règles CSS `.body td/th` ajoutent une bordure et `.body` est en `text-align: justify`. `generateBare()` existe aussi, sans en-tête ni pied de page. Il existe en plus `core/Pdf/PdfCompressor.php` (Ghostscript, qpdf ou pdftocairo en best effort).

### 1.2 Incidents, relevés et photos

**Schéma** (`modules/rental/schema.sql`) :
- `rental_incidents` a `file_id INT NULL` (**une seule photo**) et `created_at` (la date de constat existe déjà). `description_encrypted` et les trois textes de décision sont chiffrés.
- `rental_meter_readings` a `file_id NULL`, `read_at` et la clé unique `(booking_id, meter_id, phase)`.
- `rental_inventory_validations` a `validated_at`, `validated_by_member_id` et `document_id`.

**Value objects.** `Stay\Incident` (avec `createdAt`, `fileId`) et `Stay\MeterReading` (avec `readAt`, `fileId`).

**Envoi des photos.** `RentalManagementController::uploadOptionalPhoto()` et `withOptionalPhoto()` passent par `UploadHandler::handle()` avec `ALLOWED_PHOTO_MIMES` (jpeg, png, webp, **heic**) et 15 Mo au plus. Le fichier est enregistré avec `owner_type = RentalDocumentService::OWNER_TYPE` (`'rental_document'`) et `owner_id = booking id`, **sans ligne `rental_documents`**. `discardUnusedUpload()` intervient si l'écriture est refusée.

**Une photo à la création seulement.** Une photo ne s'ajoute qu'à la création de l'incident (`_incidents.html.twig`, champ `name="photo" accept="image/*"` dans une ligne `col-6 col-md-3`) ou à l'enregistrement d'un relevé (`_inventory_meters.html.twig`). Aucun ajout ni retrait ensuite.

**Le remplacement laisse un fichier orphelin.** Remplacer la photo d'un relevé change `file_id` dans `RentalStayRepository::saveReading()` (`$fileId ?? $existing->fileId`), mais **l'ancien fichier n'est jamais supprimé**.

**`UploadHandler`** (`core/File/UploadHandler.php`) :
- `stripExifAndSave()` ne réencode que jpeg, png, webp et gif, et corrige l'orientation (JPEG seulement) ;
- **HEIC est déplacé tel quel** : extension `.bin`, métadonnées EXIF et position GPS conservées ;
- **si GD ne sait pas décoder l'image, le fichier est aussi gardé tel quel** ;
- aucune réduction de taille.

Cela contredit `ARCHITECTURE.md` §8.56 (« `UploadHandler` strips them ») et la page RGPD (`core/View/rgpd_default.html`, l. ~392 : « Les photos éventuelles sont dépouillées de leurs métadonnées de localisation »).

**Conversion d'images côté serveur.** GD ne lit pas HEIC. Imagick est facultatif (`ExtensionsCollector` : « aperçu des PDF ») et ne sert aujourd'hui qu'à `PdfRasterizer`. Aucun `Imagick::queryFormats('HEIC')` n'existe. `Core\Photo\ImageVariantService::generate($fileId, 'thumb'|'md')` produit des dérivés WebP 192 px (carré) ou 1024 px, servis par `/files/{id}/{variant}` avec `private, max-age=31536000, immutable`. `public/sw.js` ne les met en cache **que pour une page de la liste hors ligne**, et Locations n'en déclare aucune. `Core\Image\ImageDimensionGuard` limite à 50 Mpx. **Aucun processeur générique ne produit un JPEG de grand côté borné** (`LandscapeImageProcessor` recadre, `ImageVariantProcessor` produit du WebP).

**La purge oublie les photos.** `RentalRetentionService::purgeBooking()` ne supprime que les fichiers des `rental_documents`. **Les photos d'incidents et de relevés ne sont pas effacées** (fichiers sur disque et lignes `files`). La page RGPD §3.1 dit pourtant « fichiers compris ».

### 1.3 Réduction des images dans le navigateur

**`public/assets/js/upload.js`** est une IIFE liée à `#drop-zone` et n'est pas réutilisable telle quelle. Il contient :
- `loadImage()` par `createImageBitmap(file, {imageOrientation:'from-image'})`, avec repli sur `<img>` ;
- `downscaleToWebp()` par canvas, avec un budget par contexte (`CONTENT_BUDGET` : 2 400 px, 0,85, **WebP**) ;
- `applyFile()`, qui remplace le contenu du champ fichier par `DataTransfer`.

**`public/assets/js/finance-receipt-form.js`** contient :
- `resizeImage()` (FileReader puis `<img>` puis canvas, **largeur** 1 600, JPEG 0,75 ; l'orientation n'y est pas explicite) ;
- `toJpegFile()`, `MAX_FILES` et le verrou du bouton d'envoi.

**EXIF.** Réencoder par canvas ne recopie jamais l'EXIF : la suppression, GPS compris, est structurelle.

**HEIC.** Seul Safari (iOS et macOS) décode HEIC dans `createImageBitmap` ou `<img>` ; Chrome et Firefox échouent, et on retombe sur l'envoi du fichier original.

**Tests existants.** `tests/js/upload.test.js` (simulation de createImageBitmap, canvas, toBlob et DataTransfer) et `tests/js/finance-receipt-form.test.js` sont des modèles.

**Envoi asynchrone.** `public/assets/js/rental-booking.js` envoie les formulaires du panneau par `fetch(new FormData(form))`, sauf `data-async="off"`, puis recharge les panneaux. Il faut donc remplacer les fichiers **au `change`** du champ (comme `applyFile()`), et non au `submit`.

### 1.4 L'état des lieux « coché à la main »

**Les clés.** `Booking\BookingMilestones::MARKABLE = [ARRIVAL_INVENTORY, DEPARTURE_INVENTORY]`.

**Quand l'étape est « hors site ».** `Booking\MilestoneEvidence` (vers la l. 248) les met dans `$offsite` quand le module séjour est absent ou que `!$assetKeepsInventory`. Sinon elles se cochent à la validation, **et une coche à la main compte aussi (IT-14)** sur un bien qui a un modèle.

**Le chemin de la coche.** `RentalManagementController::markMilestone()` appelle `operationsService->atomicallyOnAsset()`, puis `RentalMilestoneMarkService::set()`, puis `BookingAudit::STEP_MARKED`. La coche se défait (`canBeReopened()`).

**Les conséquences d'une entrée cochée.** `arrivalTickedByHand()` (contrôleur, vers la l. 3315) en dépend, ainsi que `assertPhaseOpen(…, arrivalTickedByHand)`, `missingReadings()` (relevé d'entrée exigé à la sortie) et `ReadingPhase::frozenBy()`.

### 1.5 Documents et envoi au locataire

- **Stockage du PDF.** `RentalDocumentService::attachPdf()` passe par `storePdf()` : `file_put_contents` sous un nom aléatoire, puis une ligne `files` avec `encrypted=false`, `owner_type 'rental_document'`, `owner_id booking`.
- **Envoi.** `RentalBookingMailService::sendDocument()` envoie le modèle `rental.document` avec le PDF en pièce jointe. **C'est le seul canal vers le locataire** : il n'a aucun téléchargement.
- **Suppression.** `RentalDocumentService::delete()` ne refuse **que** si `sentAt !== null`. Un PDF d'état des lieux non envoyé (envoi en échec) se supprime donc depuis Documents (`deleteDocument()`), et `rental_inventory_validations.document_id` pointe alors dans le vide.
- **Types.** `DocumentType` a `INVENTORY`, `PHOTO`, `METER_READING` et `EVIDENCE`.

### 1.6 Signature personnelle (#926), la référence

**API** (`core/Signature/PersonalSignatureService.php`) :
- `forSigning(sessionAccountId, SignatureSubmission)` est appelé **avant tout verrou** ;
- `rememberIfAsked()` fait un upsert **dans** la transaction du module ;
- `witness()` et `witnessFromRequest()` prennent un dessin seulement et un nom de 1 à 120 caractères (champ `signer_name`), et n'écrivent rien ;
- `recordSigning(signature, module, documentType, documentId, ownerEntityId, at)` écrit dans le journal du cœur `personal_signature_document_signed`, **sans paramètre d'empreinte**.

**Limite bloquante.** `SignatureSubmission::fromRequest()` lit des **noms de champs fixes** (`signature_source`, `signature_data`, `signature_file`, `remember_signature`). `signature-pad.js` (l. 69–70) prend **un** `[data-signature-pad]` et **un** `input[name="signature_data"]` par `form[data-signature-form]`. `signature_dialog.html.twig` est une modale qui contient son propre `<form>`. **Un même envoi ne peut donc pas porter la signature du locataire (témoin) et celle du gestionnaire.** C'est une extension du cœur nécessaire (lot D).

**Référence d'intégration.** `RentalSignedContractService::countersign()` (l. 498) : `withBookingLocked()` (transaction et `FOR UPDATE`), dans `occupancy->onAsset()` ; dans la transaction, `rememberIfAsked()`, l'assemblage, `attachPdf` et `bookingAudit->record(…, actorAccountId)` ; ensuite `recordSigning()`, `notifySigner()` (type `rental.document_signed`, libellé « Contrat signé avec votre signature », URL `BookingPage::DOCUMENTS`) et l'e-mail au locataire, dont l'échec est journalisé.

**Sauvegarde contre le cache arrière.** `signature-dialog.js` vide le cadre à la fermeture, à l'envoi et au `pageshow` persistant.

### 1.7 Empreinte et journal

**Aucune empreinte SHA-256 de document n'est journalisée nulle part dans Locations.** `rental_documents.fingerprint` est l'empreinte des **termes** d'un contrat (`ContractFingerprint`), et la péremption des contrats s'en sert : ne pas la réutiliser. `IncidentBasis::hashOf()` est l'empreinte des décisions.

**Historique de la réservation.** `BookingAudit::record()` écrit dans `entity_changes` du cœur, toutes valeurs chiffrées. **AuditService ne note pas d'IP.**

**Journal du cœur.** **`Core\Journal\JournalService::log()` enregistre `REMOTE_ADDR` sur chaque ligne** (`currentIp()`), y compris `personal_signature_document_signed` et `rental_incident_reported`.

### 1.8 Adresse du bien (#977)

`RentalAsset::$address` (`?string`, null tant que personne ne l'a saisie). Le nom est `$asset->name`. Les heures en heure belge s'obtiennent par `Core\Config\AppClock::zone()`, et `DateFilterExtension::datetimeFr()` sert d'exemple (lot D de #926).

### 1.9 Hors ligne

Locations ne déclare pas de section `offline`, et un test du manifeste l'épingle. `/files/{id}` est réseau seul dans `sw.js`. Les dérivés ne sont écrits en cache que pour une page de la liste. `/account` est en cache : il ne doit jamais porter une signature (#926).

### 1.10 Tests et aide existants

**Tests :**
- `tests/Modules/Rental/Stay/InventoryReportTest.php`, `Service/RentalStayServiceTest.php`, `Repository/RentalStayRepositoryTest.php` et `…OnMysqlTest.php`, `Controller/RentalManagementControllerTest.php` (la validation y est testée ; **pas de `RentalInventoryValidationServiceTest`**), `Booking/MilestoneEvidenceTest.php`, `Service/RentalMilestoneMarkServiceTest.php` ;
- Vitest : `tests/js/rental-inventory.test.js`, `signature-pad.test.js`, `signature-dialog.test.js` ;
- E2E : `tests/e2e/specs/rental-journey.spec.js` (incident l. 433–452, `validateInventory()` l. 928). **Il ne lit pas les e-mails** : l'envoi s'assert par la date d'envoi et la page du locataire.

**Aide : les trois sujets nommés par l'issue sont presque pleins.** Corps mesurés par rapport à `HelpInvariantsTest::MAX_BODY_WORDS = 500` :

| Sujet | Mots |
|---|---|
| `locations-etat-des-lieux.md` | 483 |
| `locations-suivi.md` | 497 |
| `locations-documents.md` | 485 |
| `locations-signatures.md` | 482 |
| `locations-incidents.md` | 291 |

Un sujet nouveau est nécessaire.

---

## 2. Décisions

### 2.1 Décisions du mainteneur (22/10/2026)

**Q1 — « Gestionnaire signataire en copie » (§1 de l'issue) : une notification,
pas une copie.** Comme pour #926 (Q1), le gestionnaire signataire reçoit une
notification du site, pas une copie de l'e-mail. Le type `rental.document_signed`
est repris et son libellé élargi (« Document signé avec votre signature »), avec
un corps « Vous avez signé l'état des lieux d'entrée/de sortie de {réf.} le
JJ/MM/AAAA à HH:MM… ». Elle va au seul compte de la session, mène à la page
Documents, ne porte ni donnée du locataire ni fichier, et son échec est
journalisé sans bloquer. Le critère e2e « e-mail reçu par le gestionnaire en
copie » devient « la notification apparaît et ouvre Documents ».

**Q3 — « Signé sur papier » : aucune signature électronique.** En mode papier,
ni le locataire ni le gestionnaire ne signent à l'écran : la feuille porte les
deux signatures. Le PDF dit « Signé sur papier par les deux parties le … : voir
la feuille signée, photo n ». Le compte de la session est quand même noté
(historique, journal).

**Q4 — La coche nue disparaît partout pour les deux étapes d'état des lieux.**
Sur un bien sans modèle ni compteurs, l'étape ouvre l'écran de signature
minimal ; sur un bien avec modèle, elle mène à la page État des lieux, qui
valide, même tard. **Une étape signée ne se rouvre jamais** : le PDF est parti.
Le code `arrivalTickedByHand` est simplifié en conséquence, sans reprise des
anciennes coches (site en test).

**Q6 — HEIC : converti quand le serveur le peut, refusé sinon, jamais gardé tel
quel.** Côté serveur, une photo HEIC est convertie en JPEG par Imagick quand
`Imagick::queryFormats('HEIC')` liste le format ; sinon elle est refusée avec la
phrase « Cette photo est au format HEIC, que ce serveur ne sait pas lire :
réglez l'appareil photo sur « Le plus compatible » ou choisissez une autre
photo. ». **Une photo n'est jamais gardée telle quelle avec sa position GPS.**
Côté client, `accept="image/jpeg,image/png,image/webp"`, sans HEIC, pour
qu'iOS transcode lui-même (comportement attendu d'iOS, à vérifier sur un
appareil).

### 2.2 Décisions par défaut (recommandations retenues, non posées au mainteneur)

**Q2 — « Pas d'adresse IP dans le journal ».** « Journal » se lit comme
l'historique de la réservation (`BookingAudit`, sans IP), qui ne reçoit rien de
l'appareil. Le journal de sécurité du cœur (`JournalService`) est inchangé : il
garde l'IP du gestionnaire comme pour toute action, et aucune donnée du
locataire n'y entre (ni nom tapé, ni choix nominatif).

**Q5 — Photos ajoutées et retirées jusqu'à la validation.** Une photo s'ajoute à
un incident déjà constaté, et s'en retire, jusqu'à la validation de l'état des
lieux de sortie ; celle d'un relevé se retire jusqu'à la validation de sa
phase. Ensuite tout est figé avec le PDF.

**Q7 — La remarque libre de l'état des lieux minimal (§3 de l'issue)** est
gardée chiffrée (`BLOB`, contexte de chiffrement propre, comme
`description_encrypted`) sur la ligne de validation, pour que la page puisse la
montrer.

**Q8 — Les miniatures** sont le dérivé `thumb` de `ImageVariantService`
(192 px, WebP, servi par `FileAccessGuard` à `/files/{id}/thumb`), produit à
l'envoi et supprimé avec la photo.

### 2.3 Tranché par le ticket ou par le code (pas des questions)

- En cas d'absence ou de refus du locataire, l'e-mail part quand même et la
  signature du gestionnaire reste exigée.
- La liste vierge de sortie reprend la valeur d'entrée, ou celle du modèle
  quand l'entrée était minimale.
- L'empreinte est celle des octets du fichier tel qu'il est enregistré et
  envoyé (`hash_file('sha256', $path)`).
- Jusqu'à 5 photos par incident, 1 par relevé de compteur ; pas de reprise des
  données (ScoutMagic est en test).

---

## 3. Lots

```
Vague 1 : [0 Document de chantier + A Photos : schéma, serveur, purge]
Vague 2 : [B Photos : tuile et réduction dans le navigateur ◄ A]   [C PDF : dates et annexe photos ◄ A]   [D Cœur : deux signataires dans un formulaire]
Vague 3 : [E Écran de signature et validation signée ◄ C, D]
Vague 4 : [F Liste vierge et « Signé sur papier » ◄ E]   [G État des lieux coché → minimal signé ◄ E]
Vague 5 : [H Clôture ◄ tous]
```

Les lots A à G sont « Partie de #989 », le lot H « Corrige #989 ».

### Lot 0 — Document de chantier
Ce document dans `docs/chantiers/CHANTIER-etat-des-lieux-signe.md`, livré avec A.

### Lot A — Photos : schéma, normalisation serveur, purge (environ 2 jours)

**Schéma** (`modules/rental/schema.sql`) :
- nouvelle table `rental_incident_photos` : `id`, `incident_id` (clé étrangère vers `rental_incidents`, `ON DELETE CASCADE`), `booking_id` (clé étrangère, cascade), `file_id`, `position SMALLINT`, `created_at`, `created_by_member_id`, et un index `(incident_id, position)` ;
- retrait de `rental_incidents.file_id`, avec `ALTER TABLE rental_incidents DROP COLUMN file_id;` dans `drops.sql` ;
- `rental_meter_readings.file_id` garde sa photo unique ;
- table SQLite correspondante dans `tests/Modules/Rental/RentalTestHelper.php`.

**Cœur** : `Core\Image\PhotoNormalizer`, nouvelle classe générique. Il :
- décode avec GD, et HEIC par Imagick quand le format est disponible ;
- applique l'orientation EXIF ;
- borne le grand côté à 2 400 px ;
- réencode en JPEG à 85 % (aucune métadonnée en sortie) ;
- passe par `ImageDimensionGuard` ;
- **lève une exception au lieu de garder le fichier tel quel**.

Il est appelé avant `UploadHandler::handle()`, sur le modèle de `PhotoIngestionService` (traiter puis enregistrer). `UploadHandler` lui-même ne change pas pour les autres appelants.

**Locations** :
- `RentalStayService` : `addIncidentPhoto()` (5 au plus, refusé après la validation de la sortie), `removeIncidentPhoto()` (même garde) et `removeReadingPhoto()` (refusé une fois la phase validée). Le remplacement de la photo d'un relevé supprime l'ancien fichier.
- `reportIncident()` accepte plusieurs fichiers.
- Contrôleur : `uploadOptionalPhoto()` passe par le normaliseur, puis `ImageVariantService::generate(…,'thumb')`.
- Nouvelles routes POST `/mes-locations/incident/photo`, `/mes-locations/incident/photo/retirer` et `/mes-locations/releve/photo/retirer`. Chacune déclare son `role_min` dans `module.json`, avec montée de version dans `ModuleManifestTest`.
- Un retrait supprime le fichier et ses dérivés (`ImageVariantService::resolvePath`).

**Purge** : `RentalRetentionService::purgeBooking()` supprime aussi les fichiers des photos d'incidents et de relevés, ce qui corrige l'écart du §1.2.

**Tests** :
- `PhotoNormalizerTest` : JPEG avec EXIF et GPS ressort sans, orientation 6, 4 000 px ramenés à 2 400, PNG vers JPEG, fichier non décodable refusé, HEIC refusé sans Imagick ;
- `RentalStayServiceTest` : 6e photo refusée, retrait avant et après validation, autre réservation refusée ;
- `RentalStayRepositoryTest` et `…OnMysqlTest` ;
- `RentalRetentionServiceTest` : photos purgées ;
- contrôleur : CSRF, non-gestionnaire refusé, `discardUnusedUpload` sur refus.

**Docs** :
- ARCHITECTURE §8.56 : la phrase « UploadHandler strips them » est corrigée (normaliseur, refus du HEIC illisible) ;
- SECURITY §5 et §6 si la règle des photos y figure ;
- RGPD : paragraphe Locations, l. ~392, et §3.1 (photos purgées) ;
- spécifications §22.7.

### Lot B — Photos : tuile et réduction dans le navigateur (environ 2 jours)

**JavaScript** :
- `public/assets/js/photo-reduce.js` (nouveau, exporté et testable) : `loadImage()` repris d'`upload.js` (`createImageBitmap` avec `imageOrientation:'from-image'`), réduction à 2 400 px sur le grand côté, JPEG 0,85, essai HEIC, et repli sur le fichier original si le navigateur échoue. Le serveur normalise de toute façon.
- `public/assets/js/rental-photo-tile.js` : un menu à deux choix, deux `<input type=file>` cachés (`capture="environment"` et galerie), le remplacement des fichiers au `change` par `DataTransfer` (compatible avec `rental-booking.js`), les miniatures avec croix (`data-confirm`, `design.md` §7.5) et la limite affichée (5 ou 1).
- `upload.js` et `finance-receipt-form.js` ne sont pas réécrits ; leur reprise peut faire l'objet d'une issue de suivi.

**Gabarits** : `_incidents.html.twig` (sur mobile : description, puis montant sur sa propre ligne, puis rangée de miniatures avec la tuile « + » en fin ; aide « Une vue d'ensemble, puis un gros plan » ; rappel « Photographiez le dégât, pas les personnes ») et `_inventory_meters.html.twig` (tuile, 1 photo).

**Tests** :
- Vitest `tests/js/photo-reduce.test.js` (dimensions, type `image/jpeg`, qualité 0,85, orientation demandée, repli quand le décodage échoue, nom en `.jpg`) et `tests/js/rental-photo-tile.test.js` (menu, `capture`, limite, croix) ;
- `TestTwig` pour les gabarits ;
- E2E `rental-journey.spec.js` : un incident avec deux photos (`setInputFiles`).

**Docs** : `locations-incidents.md` (291 mots), `design.md` si la tuile devient un motif.

### Lot C — PDF : dates des incidents et annexe photos (environ 2 jours)

**Nouveaux objets** :
- `Stay\InventoryPhoto` (value object) : numéro, objet (incident, ou compteur et phase), valeur d'un compteur, date, JPEG ;
- `Stay\InventoryPhotoAnnex`, pur : l'ordre (les compteurs d'abord, entrée et sortie du même compteur côte à côte, puis les incidents dans l'ordre de constat) et le HTML de l'annexe ;
- `Service\InventoryPhotoRenderer` : dérivés JPEG de 1 600 px, en mémoire, jamais stockés.

**`InventoryReport::html()`** :
- chaque incident devient « 12/07 à 18 h 40 — Porte du réfectoire forcée (photos 3 et 4) », heure en `AppClock::zone()` ;
- reçoit la liste `InventoryPhoto[]` ;
- la ligne « Validé par » passe aussi en `AppClock::zone()`.

Le corps reste échappé. Les images sont en URI `data:image/jpeg;base64` validée par une expression régulière, comme `paymentQrHtml()`.

**Mise en page de l'annexe** : une `<table class="photo-annex">` par page, de 3 lignes de 2 cellules à `width:50%`, avec `table-layout:fixed`, `page-break-after:always` entre les tableaux, et `width`/`height` calculés côté serveur (Dompdf respecte mal `max-height`). Le CSS de l'annexe neutralise `.body td` (bordure) et `text-align: justify`.

**Câblage** : branché dans la validation existante.

**Tests** :
- `InventoryReportTest` (dates en heure belge depuis l'UTC, renvois « photos 3 et 4 ») ;
- `InventoryPhotoAnnexTest` (ordre, légendes, 2 × 3 par page, échappement) ;
- `RentalInventoryValidationServiceTest`, nouveau : le PDF de 10 photos de 2 400 px reste sous un seuil défini dans le test (par exemple 4 Mo) et sa page compte se vérifie avec smalot/pdfparser.

**Docs** : ARCHITECTURE §8.56, spécifications §22.7, `locations-etat-des-lieux.md` (en restant sous 500 mots, quitte à déplacer des questions vers le sujet du lot E).

### Lot D — Cœur : deux signataires dans un même formulaire (environ 1,5 jour)

**Partiel** : `partials/signature_fields.html.twig` est extrait du corps de `signature_dialog.html.twig` (qui l'inclut). Il prend un paramètre `name_prefix` (par exemple `renter_`) et un conteneur `[data-signature-field]`.

**Serveur** :
- `SignatureSubmission::fromRequest(Request, allowImport, allowRemember, string $prefix = '')` ;
- `PersonalSignatureService::witnessFromRequest(Request, string $prefix = '')` ;
- `recordSigning(…, ?string $documentSha256 = null)`, ajouté au contexte du journal.

**JavaScript** : `signature-pad.js` se lie par conteneur `[data-signature-field]` (un cadre et un champ caché par conteneur) au lieu d'un par formulaire. Le vidage (fermeture, envoi, `pageshow` persistant) est généralisé à un formulaire **non modal** qui porte ces champs.

**Tests** :
- `PersonalSignatureSigningTest` (préfixe ; les champs de l'autre signataire ne sont jamais lus ; témoin préfixé) ;
- `SignatureDialogTest` (le dialogue est inchangé) et `SignatureFieldsTest` ;
- `signature-pad.test.js` (deux cadres dans un formulaire) et `signature-dialog.test.js` ;
- les tests de contresignature de #926 restent verts.

**Docs** : ARCHITECTURE §8.125 (« Two signers on one document »), `design.md` §7.5.

### Lot E — Écran de signature et validation signée (environ 3 jours)

**Schéma** (`rental_inventory_validations`) :
- `renter_signing VARCHAR(20) NOT NULL` (`on_site` | `absent` | `refused`, plus `paper` au lot F) ;
- `validated_by_account_id INT UNSIGNED NULL` ;
- `document_sha256 CHAR(64) NULL`.

**Rien du témoin n'est stocké** : ni nom, ni image.

**Route et page** : `GET /mes-locations/{slug}/reservations/{id}/etat-des-lieux/signer?phase=` (`no-store`, page absente de la liste hors ligne). La page montre :
- le résumé (écarts, relevés, et à la sortie les incidents avec leur date et leurs miniatures). **On n'y montre jamais un montant, une décision, « En attente de », une référence ni un motif** : le locataire lit l'écran ;
- le choix du locataire : « Le locataire signe ici », « Le locataire est absent », « Le locataire refuse de signer » ;
- les champs témoin `renter_` (nom et dessin) ;
- l'avertissement « Sans la signature du locataire, cet état des lieux prouve moins. Joignez des photos de ce qui compte. » ;
- la signature du gestionnaire (mode enregistré ou nouveau, « Mémoriser ») ;
- le bouton « Valider, signer et envoyer ».

Le bouton actuel de `booking_inventory.html.twig` devient un lien vers cette page (plus de `data-confirm` : l'écran est la confirmation).

**Contrôleur** : `validateInventory()` lit, dans la fermeture de `bookingAction()` (après le CSRF et le contrôle du gestionnaire, avant tout verrou) :
- `forSigning(AuthSession::getUserAccountId(), …)` ;
- `witnessFromRequest($request, 'renter_')`, seulement si `on_site` ;
- `SignatureException` traduite en `RentalException`.

**Service** : `validate(…, InventorySigning $renter, AccountSignature $manager)`.
- Les dérivés photo sont préparés **avant** le verrou.
- Dans `documentRepository->withBookingLocked()` : gel, nouvelle lecture (lignes, compteurs, incidents, photos), `rememberIfAsked()`, PDF, `attachPdf`, `document_sha256`, puis `bookingAudit->record(…, 'État des lieux signé', actorAccountId)`, avec en `to` le choix du locataire et l'empreinte.
- En cas d'échec : retour arrière, et suppression du fichier PDF écrit sur disque.
- Après le commit : `recordSigning(…, sha256)`, `notifySigner()` (Q1), puis `sendDocument()` dans tous les cas (y compris absent ou refus).

**Bloc de signatures du PDF**, deux cadres :
- côté locataire : « Accepté sur place par {nom tapé}, signé au doigt », ou « Le locataire était absent », ou « Le locataire a refusé de signer » ;
- côté gestionnaire : « Signature enregistrée appliquée sur confirmation de {nom du compte} » ou « Signé au doigt par … » ;
- « Signé sur place, {nom du bien}, {adresse} » (le nom seul sans adresse), avec date et heure en `AppClock::zone()`.

**Garde** : `RentalDocumentService::delete()` refuse un document `INVENTORY` rattaché à une validation.

**Tests** :
- `RentalInventoryValidationServiceTest` : deux signatures ; « absent » et « refus » (mention, envoi, historique) ; l'empreinte correspond à `hash_file` du fichier ; « Mémoriser » puis PDF en échec, alors rien n'est gardé ni validé ; notification au seul compte de la session ; échec de l'e-mail, et la notification part quand même ; **aucune table ne contient le nom tapé ni l'image du témoin** (balayage des tables de la base de test, chiffrées comprises) ;
- `RentalManagementControllerTest` : `user_account_id` posté ignoré ; résumé sans montant ni motif ; non-gestionnaire refusé ; en-tête `no-store` ;
- E2E : entrée signée, sortie signée, et la notification ouvre Documents.

**Docs** :
- nouveau sujet d'aide `modules/rental/help/locations-etat-des-lieux-signature.md` (questions déplacées depuis `locations-etat-des-lieux.md` pour qu'il n'y ait aucun doublon) ;
- `locations-documents.md` (ne pas dépasser 500 mots) ;
- spécifications §22.6 et §22.7, ARCHITECTURE §8.55, §8.56 et §8.125 ;
- SECURITY §5 (signataire sans compte utilisé) ;
- **RGPD** : paragraphe Locations (nom tapé et signature manuscrite du locataire, uniquement dans le PDF de l'état des lieux, envoyé au locataire et conservé avec la réservation ; photos dans le PDF), règle 30 du prompt ;
- `module.json` (libellé de `rental.document_signed`, version).

### Lot F — Liste vierge et « Signé sur papier » (environ 1,5 jour)

**Liste vierge** : `GET /mes-locations/{slug}/reservations/{id}/etat-des-lieux/liste.pdf?phase=`, sur le modèle de `RentalRequestController` l. 232. Elle passe par `DocumentPdfService::generate()` avec un corps `Stay\BlankInventorySheet` (pur) :
- les éléments, avec la quantité attendue (le modèle à l'entrée, la valeur d'entrée à la sortie) et une case à cocher ;
- les compteurs, avec un champ vide ;
- des lignes libres pour les incidents ;
- deux cadres de signature datés.

Le bouton « Imprimer la liste à vérifier » s'affiche avant la validation.

**« Signé sur papier »** : `renter_signing = 'paper'`, avec une **photo de la feuille obligatoire**. Elle passe par le normaliseur et devient un document `DocumentType::PHOTO` (pas pour le locataire, puisqu'elle est dans le PDF), référencé par une nouvelle colonne `paper_sheet_document_id`. Elle s'ajoute en annexe du PDF. Sans signature électronique (Q3).

**Tests** : `BlankInventorySheetTest` (entrée et sortie, valeur d'entrée minimale vide) ; service (papier sans photo refusé, photo en annexe et dans Documents) ; contrôleur (en-têtes, rôle).

**Docs** : le sujet d'aide du lot E, spécifications §22.7.

### Lot G — L'état des lieux coché à la main devient un état des lieux minimal signé (environ 2 jours)

**Schéma** (`rental_inventory_validations`) : `scope VARCHAR(20) NOT NULL DEFAULT 'full'` (`full` | `minimal`) et `remark_encrypted BLOB NULL` (Q7, nouveau contexte de chiffrement, inventaire des colonnes chiffrées si un test l'exige côté module).

**Service** : la validation minimale porte le texte fixe « Bien remis en bon état, sans remarque » (entrée) ou « Bien rendu en bon état, sans remarque » (sortie), ou la remarque libre, et à la sortie les incidents. Elle passe par le même écran et le même service que le lot E.

**Étapes du tableau de bord** : les clés d'état des lieux ne se cochent plus. `RentalMilestoneMarkService::set()` les refuse. `MilestoneEvidence` les dérive de la validation (minimale ou complète). L'action de l'étape mène à l'écran (Q4). `arrivalTickedByHand` et ses paramètres sont retirés ou réduits (`assertPhaseOpen`, `missingReadings`, `recordReading`, `setInventoryValue`).

**Tests** : `MilestoneEvidenceTest`, `RentalMilestoneMarkServiceTest` (coche refusée), un bien sans modèle produit un PDF signé, réouverture refusée, E2E dans `rental-lifecycle.spec.js` ou `rental-management.spec.js` s'ils couvrent la coche.

**Docs** : `locations-suivi.md` (497 mots : remplacer, pas ajouter), `locations-etat-des-lieux.md`, spécifications §22.7, ARCHITECTURE §8.56, RGPD (remarque chiffrée).

### Lot H — Clôture (environ 1 jour)

Parcours E2E complet sur mobile (projet Playwright à viewport mobile) : entrée signée, incident avec deux photos, sortie signée, envoi enregistré dans Documents, notification du gestionnaire. Relecture d'ARCHITECTURE, SECURITY, spécifications, aides et RGPD contre le comportement final. Tableau « critère → tests ». Journal du chantier. PR « Corrige #989 ».

---

## 4. Pièges

- **Transaction de #926.** `rememberIfAsked()` doit tourner **dans** `withBookingLocked()`, avec le gel et `attachPdf`. Or `validate()` fonctionne aujourd'hui par compensation, sans transaction : il faut le restructurer. Le décodage des signatures (`forSigning`, `witnessFromRequest`) et des photos se fait **avant** le verrou. Un PDF écrit sur disque avant un retour arrière reste orphelin : le supprimer dans le `catch`.
- **Le CPU sous verrou.** Dix photos réduites à 1 600 px par GD, plus Dompdf, sous `FOR UPDATE`, font attendre les autres gestionnaires. Préparer les dérivés avant le verrou, puis ne relire que la liste sous le verrou (une photo retirée entre-temps est refusée par le gel).
- **`bookingAction()` n'attrape que `RentalException`** : traduire `SignatureException` et l'exception du normaliseur.
- **La signature du témoin n'est jamais stockée.** Ni le nom tapé ni l'image ne vont en base, au journal ni dans l'historique : seul le PDF les porte. Le test balaie les tables. Le cadre est vidé à l'envoi, à l'annulation et au `pageshow` persistant (lot D).
- **Les noms de champs fixes du dialogue** : sans le préfixe du lot D, le dessin du locataire écraserait celui du gestionnaire (`signature_data`).
- **L'écran est lu par le locataire.** Jamais de montant, de décision, de « En attente de… », de référence ni de motif (règle de #980 et `renterIncidents()`). La signature enregistrée du gestionnaire n'est chargée que dans son propre bloc, depuis `/account/signature/image`, jamais incorporée à la page.
- **Hors ligne et caches.** Ni l'écran de signature ni la page État des lieux ne s'ajoutent à la liste hors ligne. L'écran répond `no-store`. Les dérivés `thumb` sont `private, immutable` côté navigateur, mais `sw.js` ne les écrit que pour une page de la liste. Aucune image ni signature dans une page mise en cache (`/account`).
- **EXIF et GPS supprimés côté serveur aussi.** Le client peut échouer ou être contourné. `UploadHandler` garde HEIC et les images non décodables **tels quels** : le normaliseur doit refuser au lieu de retomber sur ce comportement.
- **Dompdf** : un tableau à largeur fixe par page plutôt que `page-break-inside` sur `tr` ; des dimensions en attributs ; neutraliser `.body td` et `justify` ; JPEG uniquement (Dompdf l'intègre sans le réencoder, alors qu'un PNG ou WebP passe par GD, ce qui est lent et lourd) ; PNG seulement pour les deux signatures (transparence).
- **Taille du PDF et de l'e-mail.** 10 photos à 1 600 px en JPEG 80–85 pèsent environ 2,5 à 4 Mo, plus un tiers en base64 dans l'e-mail. Le test fixe un plafond. `PdfCompressor` reste un recours, pas une dépendance.
- **Heures.** `Incident::createdAt` vient de `CURRENT_TIMESTAMP` en base, et `validatedAt` est formaté directement aujourd'hui : passer par `AppClock::zone()` (comme le lot D de #926).
- **Ne pas réutiliser `rental_documents.fingerprint`** pour l'empreinte : c'est l'empreinte des termes d'un contrat, et la péremption s'en sert.
- **Supprimer un document envoyé ou signé.** Un PDF d'état des lieux non envoyé se supprime aujourd'hui depuis Documents ; un PDF signé ne doit plus l'être.
- **Les fichiers orphelins.** Un remplacement de photo de relevé, le retrait d'une photo et ses dérivés `thumb`, et la purge (§1.2).
- **`rental-booking.js`** recharge les panneaux après chaque `fetch` : la tuile se délègue au document, comme `rental-inventory.js`, et réduit au `change`.
- **Tests d'architecture.** `ExplicitDropsTargetRetiredColumnsTest` (`rental_incidents.file_id`), `HelpInvariantsTest` (≤ 500 mots, aucune question en double, chemins vivants), `UxConventionsTest` (croix de retrait derrière `data-confirm`), `TwigCommentsAreEnglishTest`, `ModuleManifestTest` (routes, version, libellé de la notification), matrice d'autorisations `scripts/authz-support.php`.
- **CodeQL** (`js/xss-through-dom`) pour `rental-photo-tile.js` : les miniatures se construisent avec `createElement` et `URL.createObjectURL`, jamais avec `innerHTML`.

### Fichiers critiques
- `modules/rental/src/Service/RentalInventoryValidationService.php`
- `modules/rental/src/Stay/InventoryReport.php`
- `core/Signature/SignatureSubmission.php` (et `core/Signature/PersonalSignatureService.php`, `core/View/templates/partials/signature_dialog.html.twig`, `public/assets/js/signature-pad.js`)
- `modules/rental/src/Controller/RentalManagementController.php` (`validateInventory`, `storePostedPhotos` — `uploadOptionalPhoto` avant le lot A —, `markMilestone`, `arrivalTickedByHand`)
- `modules/rental/schema.sql` (`rental_incidents`, `rental_inventory_validations`) et `core/File/UploadHandler.php`

---

## 5. Critères d'acceptation

Repris de la section « Tests » de l'issue, réécrits selon les décisions du §2.
Le lot H dresse le tableau « critère → tests ».

1. Validation de l'entrée et de la sortie avec les deux signatures : PDF figé,
   empreinte SHA-256 à l'historique de la réservation, envoi au locataire, et
   **notification** au seul compte signataire (Q1).
2. La signature du locataire (nom tapé, dessin) n'est dans aucune table hors du
   document.
3. Choix « Le locataire est absent » et « Le locataire refuse de signer » :
   mention dans le PDF, avertissement avant de confirmer, trace à l'historique ;
   l'e-mail part quand même.
4. Bien sans modèle : l'étape passe par l'écran de signature et produit un PDF ;
   la coche nue n'existe plus pour les deux étapes d'état des lieux, et une
   étape signée ne se rouvre pas (Q4).
5. Liste vierge (entrée et sortie) ; validation « Signé sur papier » refusée
   sans photo de la feuille, sans aucune signature électronique (Q3).
6. Photos :
   - tuile « + Photo » et son menu (« Prendre une photo », « Choisir dans la
     galerie ») ;
   - limite de 5 par incident et de 1 par relevé ;
   - retrait avant la validation, refusé après (Q5) ;
   - réduction côté client avec EXIF et GPS supprimés (test JS) **et**
     normalisation côté serveur (2 400 px, JPEG 85, aucune métadonnée, HEIC
     converti ou refusé — jamais gardé tel quel, Q6) ;
   - photos et miniatures supprimées avec la photo et par la purge de
     rétention.
7. PDF :
   - date et heure de constat de chaque incident, en heure belge ;
   - annexe à deux colonnes, dans l'ordre et avec les légendes attendus ;
   - taille raisonnable avec 10 photos (plafond fixé dans le test).
8. Parcours e2e sur mobile : entrée signée → incident avec deux photos → sortie
   signée → envoi enregistré dans Documents, et la notification du gestionnaire
   signataire ouvre Documents (Q1).
9. Aide (`locations-etat-des-lieux.md`, `locations-suivi.md`,
   `locations-documents.md`, sujet nouveau du lot E), `specifications.md`
   (§22.6, §22.7) et page de confidentialité à jour, chaque sujet d'aide sous
   500 mots.

---

## 6. Journal du chantier

| Lot | PR | Ce qui a été fait | Écarts |
|---|---|---|---|
| 0 — Document de chantier | #1008 | Ce document : état du code au commit `371b4597`, décisions du mainteneur (Q1, Q3, Q4, Q6, 22/10/2026) et décisions par défaut (Q2, Q5, Q7, Q8), lots, pièges, critères d'acceptation. | Le §2 de l'étude (« questions ») devient « Décisions ». |
| A — Photos : schéma, normalisation serveur, purge | #1008 | Table `rental_incident_photos` (`schema.sql`, clé unique `(incident_id, position)`, miroir SQLite de `RentalTestHelper`) ; `rental_incidents.file_id` retiré (`drops.sql`, sans reprise) ; `Stay\IncidentPhoto`, `Incident::$photos`. `Core\Image\PhotoNormalizer` (GD ; HEIC par Imagick seulement si `queryFormats('HEIC')` le liste, `forThisServer()` ; orientation EXIF appliquée, les huit valeurs ; 2 400 px au plus ; JPEG 85 sur une toile neuve, aucune métadonnée ; `ImageDimensionGuard`, et `pingImageBlob()` pour HEIC) et `PhotoNormalizationException` (une `UploadException`, inventaire des exceptions destinées au visiteur). `RentalStayService` : `reportIncident()` prend une liste de fichiers (5 au plus), `addIncidentPhotos()`, `removeIncidentPhoto()`, `removeReadingPhoto()` (Q5), le remplacement de la photo d'un relevé supprime l'ancien fichier ; `RentalStayRepository` : `createIncident()` écrit les photos dans la même transaction, `addIncidentPhotos()`, `deleteIncidentPhoto()`, `clearReadingPhoto()`, `photoFileIds()`, chaque refus lu par l'écriture elle-même. Contrôleur : `storePostedPhotos()` (champs `photos[]` et `photo`, normaliseur puis `UploadHandler` en `image/jpeg` seulement, puis `ImageVariantService::generate(…, 'thumb')`, tout ou rien) ; routes POST `/mes-locations/incident/photo`, `/mes-locations/incident/photo/retirer`, `/mes-locations/releve/photo/retirer` (`identified`, `module.json` 1.51.0, `ModuleManifestTest`). `AttachedFileRemover` supprime aussi les dérivés d'un fichier (`ImageVariantService::derivativeRelativePaths()`) ; `RentalRetentionService::purgeBooking()` supprime les photos des incidents et des relevés et leurs miniatures. Gabarits : `accept="image/jpeg,image/png,image/webp"` (Q6) ; la liste des incidents lie « Photo 1 », « Photo 2 »… Docs : ARCHITECTURE §8.56, SECURITY §6 et §25, spécifications §22.7, page RGPD (paragraphes « Relevés de compteurs et état des lieux », « Incidents et dégâts », §3.1) et règle 30 du prompt, aide `locations-incidents.md`. Tests : `PhotoNormalizerTest`, `AttachedFileRemoverTest`, `ImageVariantServiceTest`, `RentalStayServiceTest`, `IncidentPhotoScenarios` (dans `RentalStayRepositoryTest` et `RentalStayRepositoryOnMysqlTest`), `RentalRetentionServiceTest`, `RentalManagementControllerTest`, `Rental\RgpdCoverageTest`, `ModuleManifestTest` ; E2E `rental-journey.spec.js` (l'incident est constaté avec une photo, rendue en lien « Photo 1 »). | La limite de photos est liée en entier : SQLite compare un `COUNT(*)` à une valeur liée en texte par type, et la limite ne tenait pas (trouvé par le test du dépôt). La purge ne supprime pas explicitement les lignes `rental_incident_photos` : elles partent en cascade avec la réservation, comme `rental_incidents`. `UploadHandler` réencode encore en JPEG 90 le JPEG 85 du normaliseur, comme pour `PhotoIngestionService` (le plan laisse `UploadHandler` inchangé). L'interface reste à un seul champ photo par incident et par relevé, sans bouton de retrait : la tuile, plusieurs photos à l'écran et la croix sont au lot B. |
| B — Photos : tuile et réduction dans le navigateur | #1010 | `public/assets/js/photo-reduce.js` (`window.ScoutMagicPhotoReduce`, script classique pour garder l'URL versionnée par `asset()` : `createImageBitmap` avec `imageOrientation:'from-image'` et repli sur `<img>`, 2 400 px sur le grand côté, fond blanc, JPEG 0,85, nom en `.jpg`, HEIC tenté même sans type, le fichier d'origine rendu sur tout échec). `public/assets/js/rental-photo-tile.js` (module délégué au document) : le menu « Prendre une photo » / « Choisir dans la galerie » ouvre l'un des deux champs sans nom (`capture="environment"`, galerie `multiple`), chaque photo est réduite au `change`, attend en miniature (`createElement` et `URL.createObjectURL`, jamais `innerHTML`) et le seul champ nommé (`photos[]` ou `photo`) reçoit les fichiers par `DataTransfer` ; la limite (5 ou 1, photos déjà jointes comprises) masque la tuile et dit combien de photos n'ont pas été gardées ; les boutons d'envoi sont verrouillés pendant la réduction ; un incident déjà constaté envoie sa photo aussitôt (`data-photo-autosubmit`, route d'ajout du lot A). Gabarit commun `_photo_tile.html.twig` (miniatures `file_url(id, 'thumb')`, lien vers la photo par la visionneuse, croix = bouton d'un formulaire de retrait séparé par l'attribut `form`, derrière `data-confirm`) ; `_incidents.html.twig` (aide « Une vue d'ensemble, puis un gros plan. » sous le titre, sur mobile description, puis montant sur sa ligne, puis la rangée de photos, rappel « Photographiez le dégât, pas les personnes. » sous la tuile) et `_inventory_meters.html.twig` (tuile d'une photo, croix vers `/mes-locations/releve/photo/retirer`). `max_incident_photos` vient de `RentalStayService::MAX_INCIDENT_PHOTOS`. Sans JavaScript, le champ fichier ordinaire reste (`photo-tile-ready` sur `<html>`, `app.css`). Tests : Vitest `photo-reduce.test.js`, `rental-photo-tile.test.js` ; `PhotoTileTemplateTest` ; `RentalManagementControllerTest` (miniatures et scripts de la page) ; E2E `rental-journey.spec.js` (un incident avec deux photos choisies dans la galerie). Docs : aide `locations-incidents.md` (section « Les photos »), `design.md` §7.10 (la tuile devient un motif). | Le menu est un menu déroulant de Bootstrap, pas un composant propre. La croix d'une photo pas encore envoyée l'écarte sans question (rien n'a quitté le téléphone) ; celle d'une photo jointe demande. Un relevé qui a sa photo n'offre plus la tuile : la retirer d'abord pour la changer (le serveur sait remplacer, mais la tuile tient la limite d'une photo). `rental-lifecycle.spec.js` envoie encore la photo d'un relevé par le champ nommé, sans passer par la tuile. |
| C — PDF : dates des incidents et annexe photos | #1011 | `Stay\InventoryPhoto` (numéro, objet, compteur et phase, valeur, date, JPEG et dimensions) ; `Stay\InventoryPhotoAnnex`, pur : `fileIds()` et `photos()` (compteurs d'abord dans l'ordre du bien, entrée et sortie d'un même compteur sur la même ligne — une cellule vide est insérée avant la paire si elle devait chevaucher deux lignes —, puis les incidents dans l'ordre de constat, numérotation unique), `numbersOf()`, `reference()` (« photo 3 », « photos 3 et 4 », « photos 3, 4 et 5 »), `moment()` (« 12/07 à 18 h 40 » en `AppClock::zone()`), `fit()` et `html()` (un tableau `table-layout: fixed` de 3 × 2 cellules à 50 % par page, `page-break-after` entre les tableaux, dimensions en attributs, bordure de `.body td` et justification neutralisées en ligne, légendes échappées et raccourcies, URI `data:image/jpeg;base64` vérifiée par expression régulière après le nombre magique JPEG). `Service\InventoryPhotoRenderer` : copie de 1 600 px en JPEG 80 par `PhotoNormalizer::normalize()`, qui prend désormais une borne et une qualité facultatives, en mémoire, jamais stockée. `InventoryReport::html()` reçoit les photos : chaque incident est daté et cite ses photos, « Validé par » passe en heure belge, l'annexe suit. `RentalInventoryValidationService::validate()` prépare les copies avant le gel, puis ne rend après la relecture que les photos ajoutées entre-temps ; câblé dans `public/index.php`. Docs : ARCHITECTURE §8.56, spécifications §22.7, aide `locations-etat-des-lieux.md` (495 mots, une question ajoutée), page RGPD (« Relevés de compteurs et état des lieux » : les photos figurent en annexe du PDF envoyé) et règle 30 du prompt. Tests : `InventoryPhotoAnnexTest`, `InventoryReportTest` (heure belge depuis l'UTC, « photos 3 et 4 »), `RentalInventoryValidationServiceTest` (nouveau : dix photos de 2 400 px par le vrai dompdf, moins de 4 Mo, trois pages comptées avec smalot/pdfparser, dix images JPEG intégrées une fois chacune ; copies jamais stockées ; annexe d'entrée sans incident ; fichier disparu laissé de côté), `PhotoNormalizerTest`, `Rental\RgpdCoverageTest`. | La boîte d'une image est de 300 × 190 px, mesurée et non calculée : à 200 px, dompdf renvoie la troisième ligne sur une page à part dès que les légendes prennent quatre lignes. Une photo dont le fichier a disparu est omise (la numérotation continue) au lieu de bloquer la validation. Les photos de relevé ne sont pas citées dans le tableau des compteurs : la légende nomme le compteur et la phase. La transaction n'est pas restructurée (lot E). |
| E — Écran de signature et validation signée | #1012 | Schéma `rental_inventory_validations` : `renter_signing VARCHAR(20) NOT NULL` (`Stay\RenterSigning` : `on_site`, `absent`, `refused`), `validated_by_account_id`, `document_sha256 CHAR(64)` (miroir SQLite de `RentalTestHelper`) ; rien du témoin. `Stay\InventorySigning` (`onSite()` ou `without()`) et `Stay\InventorySignatureBlock` (pur : deux cadres, « Accepté sur place par {nom tapé}, signé au doigt » / « Le locataire était absent » / « Le locataire a refusé de signer », « Signature enregistrée appliquée sur confirmation de {compte} » / « Signé au doigt par {compte} », images en URI `data:image/png;base64,` vérifiée, « Signé sur place, {bien}, {adresse}, le … » en `AppClock::zone()`), qui remplace la ligne « Validé par » d'`InventoryReport::html()`. Page `GET /mes-locations/{slug}/reservations/{id}/etat-des-lieux/signer?phase=` (`signInventory()`, `booking_inventory_sign.html.twig`, `private, no-store`, `module.json` 1.52.0) : résumé sans montant, décision, attente, référence ni motif (les incidents arrivent au gabarit en date, description et photos), choix du locataire, champs `renter_` dans un fieldset que `rental-inventory-sign.js` désactive, signature du gestionnaire enregistrée ou tracée, « Mémoriser », « Valider, signer et envoyer », « Annuler » en lien. Le bouton de « État des lieux » devient le lien « Signer l'état des lieux d'entrée/de sortie » (plus de `data-confirm` ; le décompte « non vérifié » passe sur l'écran de signature, `rental-inventory.js` perd `validationQuestion()`). `validateInventory()` lit les deux signatures dans `bookingAction()`, avant tout verrou. `validate()` restructuré en une transaction sous `withBookingLocked()` (gel, relecture, `rememberIfAsked()`, PDF, `attachPdf`, `hash_file`, historique « État des lieux signé » avec le choix et l'empreinte), fichier PDF supprimé en cas d'échec ; après le commit `recordSigning(…, sha256)`, notification `rental.document_signed` (libellé « Document signé avec votre signature ») puis `sendDocument()` dans tous les cas. `RentalDocumentService::delete()` refuse un état des lieux signé ; Documents ne le propose plus. Nits SonarCloud de #1007 corrigés (`PersonalSignatureService`, `SignatureSubmission`, `signature-dialog.js`). Docs : aide `locations-etat-des-lieux-signature.md` (nouveau, deux questions déplacées), `locations-etat-des-lieux.md`, `locations-documents.md`, `locations-signatures.md`, `ma-signature.md`, spécifications §22.6 et §22.7, ARCHITECTURE §8.55, §8.56 et §8.125, SECURITY §5, page RGPD et règle 30 du prompt. Tests : `RentalInventoryValidationServiceTest` (deux signatures, absent et refus, empreinte = `hash_file`, « Mémoriser » puis PDF en échec, fichier supprimé après un échec tardif, notification au seul compte de la session, e-mail en échec, notification en échec journalisée, balayage de toutes les tables), `InventorySignatureBlockTest`, `InventoryReportTest`, `RentalStayServiceTest`, `RentalStayRepositoryTest`, `RentalDocumentRepositoryOnMysqlTest`, `RentalManagementControllerTest` (écran sans montant, motif ni référence, `no-store`, non-gestionnaire refusé, `user_account_id` posté ignoré, locataire sans tracé renvoyé à l'écran, choix inconnu refusé, état des lieux signé non supprimable), `ModuleManifestTest`, `Rental\RgpdCoverageTest` ; Vitest `rental-inventory-sign.test.js` ; E2E `rental-journey.spec.js` (entrée signée sur place, sortie locataire absent, chaque notification ouvre Documents) et `rental-lifecycle.spec.js` (entrée refusée, sortie signée), aide `tests/e2e/support/inventory-signing.js`. | Le gestionnaire ne peut pas importer d'image sur cet écran (`allow_import: false`) : le PDF ne dit que « signature enregistrée » ou « signé au doigt ». La colonne `renter_signing` reçoit `on_site` par défaut dans `recordInventoryValidation()` pour les fixtures de test ; le service passe toujours le choix. `forgetInventoryValidation()` est retiré (service et dépôt) : la transaction remplace la compensation. |
| F — Liste vierge et « Signé sur papier » | #1013 | Liste vierge : `Stay\BlankInventorySheet` (pur, échappé : chaque élément avec sa référence — le modèle à l'entrée, à la sortie la valeur d'entrée ou, ligne par ligne, celle du modèle quand l'entrée n'en a pas — et une case, les compteurs avec un champ vide et, à la sortie, le relevé d'entrée, six lignes libres pour les incidents, deux cadres de signature datés) ; `RentalInventoryValidationService::blankSheet()` ; route `GET /mes-locations/{slug}/reservations/{id}/etat-des-lieux/liste.pdf?phase=` (`inventorySheet()`, gestionnaires du bien seulement, `private, no-store`, jamais stockée ; `module.json` 1.53.0) ; bouton « Imprimer la liste à vérifier » sur « État des lieux » avant la signature. « Signé sur papier » : `RenterSigning::PAPER`, `Stay\PaperSheet` (fichier et compte de la session), `InventorySigning::onPaper()` ; l'écran désactive et masque les deux signatures (`fieldset[data-manager-signature]` ajouté) et active le champ `paper_sheet`, obligatoire ; `validateInventory()` ne lit alors aucune signature, stocke la photo par le normaliseur (`storePhotoFiles()`, extrait de `storePostedPhotos()`), la refuse absente (`PAPER_SHEET_MISSING`) et la retire si la validation échoue. `validate()` prend un `?AccountSignature` (null en papier ; `InventorySignatureBlock` refuse le mélange) ; copie de la feuille faite avant le verrou (`SHEET_UNREADABLE` si illisible) ; dans la transaction, `attachUploaded()` en `DocumentType::PHOTO` non destiné au locataire, colonne `paper_sheet_document_id` (fin de `rental_inventory_validations`, miroir SQLite) ; la feuille est la dernière photo de l'annexe, sur une page à elle (`InventoryPhotoAnnex::paperSheet()`, `sheetHtml()`) ; le bloc dit « Signé sur papier par les deux parties le … : voir la feuille signée, photo n ». Ni « Mémoriser », ni `recordSigning()`, ni notification ; le compte est à la ligne, à l'historique (« signé sur papier par les deux parties ») et au journal (`rental_inventory_signed_on_paper`) ; l'e-mail au locataire part. La photo de la feuille ne se supprime pas (`isInventoryValidationDocument()`). Docs : aide `locations-etat-des-lieux-signature.md` (492 mots, une question), spécifications §22.7, ARCHITECTURE §8.56, page RGPD et règle 30 du prompt. Tests : `BlankInventorySheetTest`, `InventorySignatureBlockTest`, `InventoryPhotoAnnexTest`, `RentalInventoryValidationServiceTest` (papier : photo en annexe et dans Documents, non supprimable, historique, journal, pas de notification, e-mail ; feuille illisible ; signature électronique refusée avec le papier ; liste vierge), `RentalManagementControllerTest` (en-têtes et contenu du PDF, non-gestionnaire, anonyme, phase inconnue, écran, papier sans photo, aucune signature lue même postée, feuille retirée après un refus), `ModuleManifestTest`, `Rental\RgpdCoverageTest` ; Vitest `rental-inventory-sign.test.js`. | La liste vierge est servie pour toute phase, validée ou non (le bouton ne s'affiche qu'avant la signature). Le signataire n'est pas notifié en mode papier : « Document signé avec votre signature » serait faux. La photo de la feuille est une copie de 1 600 px dans le PDF, comme les autres photos. |
