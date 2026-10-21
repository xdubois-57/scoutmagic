# Chantier — Locations : incidents et dégâts de bout en bout (#980)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§7.5,
§8.53 à §8.56, §8.81), `SECURITY.md` (§5), `design.md` (§7) et
`specifications.md` (§22.5 à §22.7), qui restent la source de vérité des
règles. Il ne les recopie pas. Le chantier #927 (créance unique, rapprochement
des remboursements) est livré et sert de socle : relire `ARCHITECTURE.md`
§8.54 avant de toucher à l'argent d'une réservation.

**Pas de maquette** (décision Q18) : les écrans changent peu, et chaque lot
décrit ci-dessous ce qu'il affiche, phrase comprise. Ce qui n'est pas décrit ne
change pas.

L'issue #980 est **Ready** et détaillée : elle s'implémente telle qu'elle est
écrite, avec les réponses du §2 aux questions de planification. Là où le texte
de l'issue diffère de ce document, **ce document l'emporte**. Les numéros
« §6.21, §6.23, §6.26 » de l'issue sont d'anciens numéros : les sections
réelles sont `specifications.md` §22.5 (suivi), §22.6 (documents) et §22.7
(séjour).

---

## 0. Règles de conduite

- **Autonomie complète.** Enchaîner les lots sans demander de validation. Ne
  poser une question au mainteneur (commentaire sur #980) que pour un vrai
  changement de design ou une ambiguïté que ce document ne tranche pas. Une
  décision du §2 ne se rediscute pas. Q1, seule question posée au
  mainteneur, est tranchée (§2.6).
- **Un lot = une branche = une PR.** Rebaser sur `main` avant la fusion. Suite
  complète (PHPUnit sur MariaDB, `tests/Modules`, `tests/Core`,
  `tests/Security`, `tests/Architecture` entiers ; Vitest ; PHPStan ;
  `npm run typecheck` ; Playwright si une page change). **Jamais de fusion
  rouge, jamais de baseline élargie.**
- **Lots d'une même vague : en parallèle** (un agent et une branche par lot).
  Le second à fusionner rebase sur le premier. Respecter la propriété des
  fichiers du §3.
- **Documentation dans le même lot** que le code qu'elle décrit
  (`ARCHITECTURE.md`, `specifications.md`, aide `modules/rental/help/*` et
  `modules/finance/help/*`, `core/View/rgpd_default.html` et
  `RgpdContentService`). Le lot I consolide, il ne rattrape pas.
- **Journal** : une ligne par lot au §6.
- **Schéma déclaratif** (`modules/rental/schema.sql`, `modules/finance/
  schema.sql`), colonnes ajoutées sans migration à la main. **Une seule
  conversion de données** dans tout le chantier : `withhold` → `charge` (lot
  A), sur le modèle de `modules/leadership/src/Service/FormationStepMigration.
  php`. Aucune autre.
- **Les décomptes déjà validés ne sont jamais recalculés ni réécrits** : ils
  sont immuables et gardent leur version.
- Code en anglais, interface en français. **Aucun texte d'interface ne parle
  d'un état antérieur** (« désormais », « a changé », « nouveau »…). Tout
  bouton qui agit immédiatement ou envoie un e-mail demande d'abord, en disant
  la conséquence **et si un e-mail part ou non** (`data-confirm` /
  `ScoutMagicConfirm.ask()`).
- Le module Locations ne lit jamais une table Finances : uniquement les
  interfaces `Api\` (`ModuleBoundariesTest`, `ARCHITECTURE.md` §7.5).

---

## 1. État des lieux (vérifié dans le code au commit `066bfc05`)

**Incident.** `modules/rental/src/Stay/IncidentDecision.php` a quatre cas :
`PENDING`, `CHARGE` (« À ajouter au décompte »), `WITHHOLD` (« À retenir sur
la caution »), `WAIVE`. `decidable()` ne rend que les trois derniers ;
`isVisibleToRenter()` vaut `!PENDING`. `Stay/Incident.php` porte la
description (déchiffrée), les montants proposé et décidé, la date et l'auteur
de la décision, la photo, la date de création. Table `rental_incidents`
(`modules/rental/schema.sql` L1183–1210) : `description_encrypted BLOB`,
`decision VARCHAR(20)` à `'pending'` par défaut, clé étrangère vers la
réservation en `ON DELETE CASCADE`.

**Service et écran.** `Service/RentalStayService.php` : `reportIncident()`
(L469) refuse après la validation de l'état des lieux de sortie (garde dans
la requête, `RentalStayRepository.php` L597) ; `decideIncident()` (L527)
refuse `PENDING` et ne regarde pas le décompte ; `incidentsVisibleToRenter()`
(L585) n'a aucun appelant. Contrôleur `Controller/RentalManagementController.
php` : `reportIncident()` L3401, `decideIncident()` L3425, routes
`/mes-locations/incident` et `/mes-locations/incident-decision`
(`module.json` L441–457, `identified`), autorisation par `bookingAction()`
L4218 (CSRF, `manageableAssetById`, `bookingOfAsset`). Vue
`views/management/_incidents.html.twig` : une liste déroulante sur
`incident_decisions` et « Trancher ».

**Décompte.** `Stay/SettlementCalculator.php` : retenu = min(Σ `WITHHOLD`,
caution) ; conservé = total + retenu ; solde = conservé − payé (créance
unique) ou total − payé (sans Finances). `Stay/Settlement.php::keptCents()`.
Table `rental_settlements` (L1227–1260) versionnée, immuable une fois validée,
avec `security_deposit_withheld_cents` et `security_deposit_return_cents`.
`RentalStayService::recordSettlement()` L664 et `validateSettlement()` L736 :
la validation refuse une réservation abandonnée ou une version dépassée, puis
appelle `RentalPaymentService::settleAmount($booking, conservé, auteur,
retenu)`. **Rien ne regarde les incidents en évaluation.** Écran
`views/management/_settlement.html.twig` sur la page Facture
(`booking_invoice.html.twig`).

**Caution.** `Service/RentalPaymentService.php` : `settleAmount()` L465
(`recordSettledWithholding()`, `bringTo()`), `statusFor()` L567 qui dérive
`SecurityDepositStatus` ; la restitution saisie à la main
(`recordSecurityDepositReturn()` L908) n'existe que sans Finances. Étape
« Caution restituée » / action « Rendre la caution » :
`Booking/BookingMilestones.php` L381–390, cochée par
`Booking/MilestoneEvidence.php` L235–278. Rappel `SECURITY_DEPOSIT_TO_RETURN` :
`Reminder/ReminderPlanner.php` L323–343, sur CONFIRMED **et** CLOSED (L137).

**Créance unique** (#927, lot C). La validation ramène la créance au montant
conservé : un dégât facturé au-delà de la caution laisse déjà un restant dû.
`paymentInstructions()` / `instructionsFrom()` (L735, L1103) alimentent
`_finance_instructions.html.twig` et `views/public/_payment.html.twig`.
**Écart** : après un décompte, l'échéance affichée reste la `balance_due_date`
passée.

**Paiements de tiers.** L'API Finances `modules/finance/src/Api/
ExpectedReceivableInterface.php` n'a que `getReceivableStanding`,
`getReceivableMovements`, `findRefundCandidates` et `reconcileRefund`
(implémentés dans `Service/ExpectedReceivableService.php` L184–260 et
`ReceivableAllocationService.php` : `allocate()` L701, `refundCandidatesFor()`
L859, `refundFromCandidate()` L900, verrou créance puis transaction,
`isUnreconciledFor()`). `Api/ReceivableMovement` n'a ni identifiant de
transaction, ni auteur ou date de rapprochement, ni « détachable ».
`Api/StatementImportStatusInterface` (`lastStatementImportedAt`) est câblé en
`$financeStatementStatusForOthers` (`public/index.php` L8404). Côté
Locations : `RentalPaymentService::refundCandidates()` / `reconcileRefund()`
(L796, L818), route `/mes-locations/remboursement-rapprocher`, partiels
`_finance_movements.html.twig` et `_finance_instructions.html.twig`,
`financesContext()` L2425, câblage `public/index.php` L12176. **Règle 1 de
`ARCHITECTURE.md` §8.81 : jamais d'imputation au-delà de ce qui reste dû.**

**Facture.** `Document/DocumentType.php` : `INVOICE` générée, racine
`facture`, versions v1, v2… `Service/RentalDocumentService::generate()` L340
et `valuesFor()` L749. La facture imprime `$booking->effectiveTotalCents()`,
pas le décompte. Aucun numéro de facture, aucun QR dans un PDF :
`Core\Pdf\DocumentPdfService` n'accepte que des images `data:` et assainit le
HTML. Envoi : `sendDocument()` L2181 puis `RentalBookingMailService::
sendDocument()` L510 (e-mail `rental.document`).

**Clôture.** `Booking/BookingTransition` permet CONFIRMED → CLOSED ;
`RentalOperationsService::changeStatus()` L136 n'a aucune garde ; le
contrôleur (L3508) ajoute seulement `outstandingMoneyWarning()` (L3570).

**Points d'attention.** `Service/RentalAttentionProvider.php` (adresse du
bailleur, conformité) ; `/admin/points-attention` est `admin`.

**Suivi du locataire.** `views/public/tracking.html.twig`,
`RentalRequestController::tracking()` L468 : aucune rubrique d'incidents.

**Modèle de conversion.** `FormationStepMigration` : drapeau de réglage
`editable: false`, `runOnce()` appelé depuis `public/index.php` (L12700),
UPDATE idempotent, journal avec le seul compte. Test :
`tests/Modules/Leadership/Service/FormationStepMigrationTest.php`.

**Documentation concernée.** `ARCHITECTURE.md` §8.54, §8.56, §8.81 ;
`specifications.md` §22.5, §22.6, §22.7 ; aide `modules/rental/help/
locations-etat-des-lieux.md`, `locations-facture.md`, `locations-finances.md`,
`locations-suivi.md`, `locations-documents.md`, `locations-reservation.md`,
`locations-gabarits.md`, `gerer-les-locations.md` ; `modules/finance/help/
rapprochement.md`, `controle-des-creances.md` ; `core/View/rgpd_default.html`
(« Incidents et dégâts ») et `core/View/RgpdContentService.php` (règle 30).

---

## 2. Décisions verrouillées

### 2.1 Trois états par incident

- **En cours d'évaluation** (`pending`), avec deux champs facultatifs :
  « En attente de… » (devis, expertise, assurance du locataire) et
  « Référence » (n° de dossier de l'assurance). **Facturer** (`charge`) : le
  montant décidé devient une ligne du décompte final. **Ne pas facturer**
  (`waive`) : motif facultatif.
- Les trois textes sont **chiffrés** comme la description, ont une longueur
  maximale vérifiée par le serveur, et n'entrent ni dans le journal ni dans
  l'historique de la réservation. **Le motif d'un « Ne pas facturer » n'est
  jamais montré au locataire** (Q6).
- `WITHHOLD` disparaît. « Trancher » propose les trois états, **en
  évaluation compris** (Q5) : on y revient pour compléter « En attente de… »
  et « Référence », et un incident tranché peut repasser en évaluation.
- L'écran « Trancher » montre l'état actuel et, sous chaque choix, une phrase
  sur sa conséquence (« Le montant s'ajoute au décompte. S'il dépasse la
  caution, le surplus est demandé au locataire. »).

### 2.2 La conversion `withhold` → `charge` (Q17)

- Le ticket l'emporte : les incidents `withhold` deviennent `charge`, **avec
  le même montant** (le décidé, à défaut le proposé), par
  `IncidentDecisionMigration::runOnce()` sur le modèle de
  `FormationStepMigration` (drapeau `rental_incident_withhold_migrated`,
  `editable: false` ; UPDATE idempotent ; journal avec le seul compte).
- Une valeur `withhold` restée en base se lit `CHARGE` (hydratation
  défensive).
- **Les décomptes déjà validés ne sont pas recalculés** : ils gardent leur
  version, leur montant conservé et leur retenue.

### 2.3 Décompte et caution (Q4)

- Un dégât n'est plus « retenu sur la caution » : il est facturé, et le
  montant conservé est le total du décompte (location, consommations, dégâts
  facturés, moins le geste commercial).
- Les champs de caution du décompte restent, **dérivés à la validation** : le
  remboursement dû se prend d'abord sur la caution, donc
  **retenu = C − min(C, max(0, reçu − conservé))** (C la caution, reçu ce qui
  est arrivé sur la créance), et la caution rendue est le reste. C'est
  cohérent avec « dont la caution à rendre » de `_settlement.html.twig`.

### 2.4 Un dégât en évaluation bloque le décompte et retient la caution

- Tant qu'un incident est en évaluation, le décompte s'enregistre mais **ne se
  valide pas** : le bouton est remplacé par une phrase avec un lien vers
  l'incident (`…/etat-des-lieux#incident-{id}`). Une version dont les
  incidents ont changé depuis son enregistrement ne se valide pas non plus
  (empreinte des incidents sur la version).
- La caution reste retenue : boîte Finances « Caution retenue : dégât en cours
  d'évaluation », étape « Rendre la caution » en attente avec la raison.
- **Point d'attention** du module (`RentalAttentionProvider`, Q7) quand un
  incident reste en évaluation **plus d'un mois civil après la date de
  départ**, N compté depuis le départ (Q8) : « Caution retenue depuis N jours :
  dégât en attente de … ». Le point montre la référence, le bien, N et le
  texte « En attente de… », **jamais le nom du locataire**.

### 2.5 Un dégât plus cher que la caution

- Rien de nouveau côté créance : la validation la ramène au montant conservé,
  et ce qui dépasse ce qui a été payé est un restant dû, payé avec **la même
  communication et le même QR**.
- **Échéance après un décompte validé : la date de validation + 30 jours**
  (Q13), au lieu de la `balance_due_date` passée.
- Le « courrier du décompte » est l'e-mail de la facture et de la facture
  complémentaire (`rental.document`) : **aucun nouvel e-mail** (Q14).

### 2.6 Rapprocher à la main un virement sans communication

- **Q1 — tranchée par le mainteneur le 21/10.** Un virement entrant rapproché
  à la main s'impute **en entier, même au-delà de ce qui reste dû** : l'excédent
  se lit en trop-perçu jusqu'à la validation du décompte, qui le fait compter
  sur le dégât. C'est une exception écrite à la règle 1 de `ARCHITECTURE.md`
  §8.81, limitée au rapprochement manuel d'un paiement entrant (lot B l'écrit).
- Candidats : virements entrants non rapprochés du compte du bien, de ce
  **montant exact** ; nom du payeur facultatif, comparé en sous-chaîne,
  insensible à la casse et aux accents (Q9). Jamais la liste complète du
  compte ; les crédits qui portent la communication d'une créance (quelle
  qu'elle soit) sont exclus. Le journal note la recherche avec les seuls
  identifiants et le nombre de résultats — ni nom, ni montant (Q9).
- Depuis : **le premier envoi d'un contrat** (`MIN(sent_at)`), à défaut la
  date de réception de la demande (Q10).
- « Détacher » est refusé dès qu'un décompte validé est postérieur au
  rapprochement (Q11).
- Sans extrait importé, la recherche le dit (« Aucun extrait importé depuis
  le … »), via `StatementImportStatusInterface`.

### 2.7 Ce que voit le locataire

- Section « Incidents et dégâts » de la page de suivi : les incidents
  tranchés (description, date du constat, « Facturé : 120 € » / « Non
  facturé ») ; un incident en évaluation **seulement** par la phrase « Un
  dégât est en cours d'évaluation ; la caution reste retenue jusqu'à son
  règlement ». Sa description n'est jamais construite pour cette page. Le
  motif d'un « Ne pas facturer » n'y figure jamais (Q6).

### 2.8 Décision prise après un décompte validé

- Permise. La page Facture dit « Le décompte validé ne tient pas compte de la
  décision du … » et propose d'enregistrer un nouveau décompte ; la version
  validée reste intacte. La nouvelle version reprend **les lignes de la
  dernière version validée plus la nouvelle ligne** (Q12).
- **Facture complémentaire** (si la facture a déjà été envoyée) : elle porte la
  différence entre le montant conservé du décompte nouvellement validé et
  celui du décompte sur lequel la facture envoyée était fondée (Q2). La
  facture enregistre, à sa génération, `settlement_id` et `billed_kept_cents`
  s'il existe un décompte validé ; sans décompte, la base est son
  `prix_total` figé. La facture ordinaire est inchangée par ailleurs.
- Numéro de la facture d'origine : la référence de la réservation plus la
  version (« LOC-…-v1 »), comme affiché aujourd'hui ; pas de nouvelle
  séquence (Q3).
- Pas de QR sur la facture ordinaire (inchangée) ; la facture complémentaire
  renvoie à la facture d'origine et porte le QR du restant dû, ou, en faveur
  du locataire, le montant à rembourser sans QR (Q15).

### 2.9 Clôturer avec un solde non recouvré

- Comme le ticket : action « Clôturer avec un solde non recouvré », motif
  obligatoire, **aucun e-mail**, créance ramenée à ce qui a été reçu, ligne
  « Montant non recouvré » dans une nouvelle version validée, rappels arrêtés,
  statut Clôturée, historique. Un paiement tardif apparaît en trop-perçu.
- La clôture normale est gardée : refusée tant qu'un incident est en
  évaluation, qu'une caution reste à rendre ou qu'un montant reste dû. **Sans
  Finances**, la garde ne compte que les incidents en évaluation et l'état
  manuel de la caution (Q19).

### 2.10 Le contrat type (Q16)

- Seule la phrase citée change, dans la clause de caution : restituée « dans
  le mois suivant le départ ou le règlement d'un dégât en cours
  d'évaluation ».

### 2.11 Écarté

- Signaler un dégât après la validation de l'état des lieux de sortie
  (annexe 8.1 du Code wallon du tourisme, point 10).
- La signature de l'état des lieux par le locataire (ticket séparé).
- Recouvrement judiciaire, mises en demeure, intérêts de retard.
- Un nouvel e-mail « décompte » (Q14), une nouvelle séquence de numéros de
  facture (Q3), un QR sur la facture ordinaire (Q15).

---

## 3. Lots et ordre d'exécution

```
Vague 0 : [0 Document de chantier]
Vague 1 : [A Trois états + conversion] [B API Finances, entrées] [H Contrat type]
Vague 2 : [C L'évaluation bloque ◄ A] [D « Rapprocher un virement » ◄ B] [E Suivi du locataire ◄ A]
Vague 3 : [F Décision après validation + facture complémentaire ◄ A, C] [G Clôtures ◄ A, C]
Vague 4 : [I Bout en bout, documentation, clôture de #980 ◄ tous]
```

**Propriété des fichiers.** `RentalManagementController.php` fait 4 597
lignes : chaque lot garde ses méthodes ensemble, et n'édite pas celles d'un
autre.

- A possède `IncidentDecision`, `Incident`, `SettlementCalculator`, la
  conversion et `views/management/_incidents.html.twig`.
- C possède `_settlement.html.twig`, `BookingMilestones` et
  `MilestoneEvidence`.
- D possède `_finance_movements.html.twig`, un nouveau
  `_finance_transfer.html.twig` et l'API Finances (avec B).
- E possède `views/public/tracking.html.twig` et un nouveau
  `views/public/_incidents.html.twig`.
- F possède `booking_invoice.html.twig`, `DocumentType`,
  `RentalDocumentService` et `StandardTemplates::invoice*`.
- G possède `RentalOperationsService::changeStatus()` et `ReminderPlanner`.
- H possède le corps du contrat dans `StandardTemplates`.
- `module.json` : chaque lot ajoute ses routes dans son propre bloc.

### Lot 0 — Document de chantier (vague 0)

- Ce document : état des lieux, décisions (le ticket et les réponses aux
  questions), lots, pièges, critères d'acceptation, journal.

### Lot A — Trois états, conversion, calcul (vague 1)

- **Schéma** (`rental_incidents`) : `awaiting_encrypted BLOB NULL`,
  `reference_encrypted BLOB NULL`, `waive_reason_encrypted BLOB NULL` ;
  commentaire de la colonne `decision` mis à jour.
- **Conversion** `Modules\Rental\Service\IncidentDecisionMigration::runOnce()` :
  drapeau `rental_incident_withhold_migrated` (`editable: false`) ;
  `UPDATE … SET decision = 'charge', decided_amount_cents =
  COALESCE(decided_amount_cents, proposed_amount_cents) WHERE decision =
  'withhold'` ; journal avec le seul compte ; appelée depuis le bloc Locations
  de `public/index.php`. Hydratation défensive : `withhold` se lit `CHARGE`.
- **Domaine** : `IncidentDecision` perd `WITHHOLD` et
  `reducesSecurityDeposit()` ; libellés « En cours d'évaluation »,
  « Facturer », « Ne pas facturer » ; `consequence()` ; `decidable()` inclut
  `PENDING`. `Incident` gagne `awaiting`, `reference`, `waiveReason`.
  `SettlementCalculator` perd la retenue des incidents ; champs de caution
  dérivés selon le §2.3.
- **Service et contrôleur** : `decideIncident()` reçoit les trois textes
  facultatifs avec leurs limites ; historique et journal sans texte.
- **Écran** `_incidents.html.twig`, « Trancher » : choix en boutons radio,
  état actuel, phrases de conséquence, champs affichés selon le choix ; aucun
  script dans le gabarit.
- **Documentation** : aide `locations-etat-des-lieux.md` ;
  `specifications.md` §22.7 ; `ARCHITECTURE.md` §8.56 ; texte RGPD.
- **Tests** : `SettlementCalculatorTest` ; `IncidentDecisionMigrationTest`
  (SQLite, `#[Group('database')]`) et `…OnMysqlTest` (`UsesProductionEngine`) ;
  tests du repository (et `OnMysql`) pour les nouvelles colonnes ; chiffrement
  façon `NothingInClear` ; `RentalStayServiceTest` ; contrôleur et
  `RentalRbacTest` ; gabarit par `TestTwig` ; enums lus par les gabarits.

### Lot B — API Finances, côté entrées (vague 1)

- `findPaymentCandidates(sourceModule, receivableId, amountCents,
  ?counterpartyContains, since)`, `reconcilePayment(...)`,
  `detachPayment(...)`, DTO `Api\PaymentCandidate` ; `ReceivableMovement`
  gagne des champs en fin de constructeur, avec valeurs par défaut.
- `ReceivableAllocationService` : candidats qui excluent les crédits portant
  la communication d'une créance ; verrous ; imputation manuelle ;
  détachement ; recatégorisation.
- Documentation : `ARCHITECTURE.md` §8.81, spécification Finances, aide.
- Tests : service, concurrence `OnMysql`, `ModuleBoundariesTest`.

### Lot H — Contrat type (vague 1)

- Clause « 4. Garantie » de `StandardTemplates` : la phrase du §2.10.
- Tests : `StandardTemplatesTest`, `DocumentKeywords`.
- Documentation : aide `locations-gabarits.md` si elle cite la clause ;
  `specifications.md` §22.6.

### Lot C — L'évaluation bloque le décompte et la caution (vague 2, après A)

- **Schéma** : `rental_settlements.incident_basis_hash CHAR(64) NULL`.
- `validateSettlement()` refuse tant qu'un incident est en évaluation, et
  quand l'empreinte enregistrée ne correspond plus aux incidents.
- **Écran** : phrase et lien dans `_settlement.html.twig` ;
  `_finance_summary.html.twig` « Caution retenue : dégât en cours
  d'évaluation » ; explication d'attente dans `BookingMilestones` ;
  formulation de `RenterNextStep`.
- **`RentalAttentionProvider`** : requête bornée des incidents en évaluation
  plus d'un mois civil après le départ (§2.4).
- **Documentation** : aide `locations-facture.md`, `locations-finances.md` ;
  `specifications.md` §22.7.
- **Tests** : service, point d'attention (bornes), étapes, gabarits, `OnMysql`
  si l'arithmétique de dates est faite par le serveur.

### Lot D — « Rapprocher un virement » (vague 2, après B)

- `RentalPaymentService` : candidats, rapprochement, détachement,
  `lastStatementImportedAt`, avec un nouveau paramètre
  `?StatementImportStatusInterface` en dernier. Depuis : §2.6 (Q10).
- Routes POST `virement-chercher`, `virement-rapprocher`, `virement-detacher`.
- Dialogue dans Finances ; mouvements « rapproché à la main par …, le … » et
  « Détacher ».
- JS `public/assets/js/rental-transfer-reconcile.js` et son test Vitest.
- Documentation et tests : RBAC, intégration avec le vrai Finances sur SQLite.

### Lot E — Le locataire voit les incidents (vague 2, après A)

- `renterIncidents()` : les tranchés (description, date, « Facturé : X € » /
  « Non facturé ») ; un incident en évaluation ne donne que la phrase fixe, et
  sa description n'est jamais construite.
- Partiel `views/public/_incidents.html.twig` inclus dans le suivi.
- Documentation : aide `locations-suivi.md` ; `specifications.md` §22.5.
- Tests du contrôleur de suivi : visible ; réduit ; absent du HTML ; motif
  absent ; mauvais jeton → 404.

### Lot F — Décision après validation et facture complémentaire (vague 3)

- **Schéma** : `rental_documents.settlement_id`, `billed_kept_cents`,
  `supplements_document_id`.
- `DocumentType::SUPPLEMENTARY_INVOICE` (racine `facture-complementaire`) ;
  `StandardTemplates::supplementaryInvoice()` et ses mots-clés ; QR passé en
  dehors du corps assaini.
- **Page Facture** : bandeau « Le décompte validé ne tient pas compte de la
  décision du … » et « Enregistrer un nouveau décompte » ; après validation
  avec une facture déjà envoyée, « Générer la facture complémentaire ».
- Envoi et liste des documents. La facture d'origine n'est jamais touchée.
- Documentation et tests.

### Lot G — Clôture gardée et clôture non recouvrée (vague 3)

- **Schéma** : `rental_settlements.unrecovered_cents`,
  `unrecovered_reason_encrypted`, `unrecovered_at`.
- `SettlementLine::ORIGIN_UNRECOVERED`.
- `RentalClosureService` : `whyNotClosable()` ; `closeUnrecovered()` sous
  verrou (recalculer et comparer, nouvelle version validée,
  `settleAmount(reçu)` avec `allowBelowReceived`, `compareAndSetStatus`,
  historique, aucun e-mail).
- `ReminderPlanner` s'arrête sur CLOSED.
- Route `POST /mes-locations/cloturer-non-recouvre` ; dialogue (motif
  obligatoire, « Aucun e-mail n'est envoyé au locataire »).
- Documentation et tests : motif, aucun e-mail, créance, ligne, statut,
  paiement tardif en trop-perçu, clôture normale refusée dans les trois cas,
  double envoi, RBAC, `OnMysql`.

### Lot I — Bout en bout et clôture (vague 4)

- Étendre `tests/e2e/specs/rental-journey.spec.js` (la limite HumanCheck
  interdit une cinquième spec de demande publique) : séjour → incident en
  évaluation → paiement de l'assureur rapproché à la main → décompte →
  facture complémentaire.
- Consolider la documentation et le §6.
- **Commenter #980** avec ce qui a été livré, **puis la clôturer**.

---

## 4. Pièges

- **L'argent.** L'exception de Q1 (imputation au-delà du dû) ne vaut que pour le
  rapprochement manuel d'un paiement entrant ; un candidat qui porte la
  communication d'une autre créance ; la validation d'une version périmée
  (empreinte des incidents) ; le montant non recouvré recalculé sous verrou.
  Un ancien `WITHHOLD` était plafonné à la caution, un `CHARGE` ne l'est
  pas : cela ne concerne que les versions non validées.
- **La comptabilité.** La créance est abaissée, jamais annulée ; les lignes
  rapprochées à la main ne sont pas touchées par le passage automatique ;
  recatégorisation ; le compte est revérifié côté serveur ; concurrence avec
  les trésoriers.
- **Le RBAC.** `bookingAction()` partout ; tests à `identified` et en dessous.
- **Le RGPD.** La liste des candidats expose la contrepartie et le texte libre
  d'autres familles : elle est limitée par le montant exact, la date, l'état
  non rapproché et l'exclusion des communications. POST seulement. Tout champ
  nouveau écrit au sujet d'un locataire est chiffré, et le texte RGPD le dit.
  La page du locataire ne construit jamais la description d'un incident en
  évaluation.
- **L'idempotence.** Drapeau de la conversion ; paire unique sous verrou ;
  détacher deux fois ; clôturer deux fois (compare-and-set) ; facture
  complémentaire deux fois.
- **Le processus.** Fichiers chauds (§3) ; constructeurs étendus en fin de
  liste ; `UxConventionsTest` ; PHPStan et `npm run typecheck` sans nouvelle
  baseline.
- `rowCount()` après un UPDATE : lignes CHANGÉES sur MySQL et MariaDB,
  TROUVÉES sur SQLite. Une requête que le moteur décide a son test `OnMysql`.

---

## 5. Critères d'acceptation

1. Un incident a trois états ; « Trancher » les propose tous, dit l'état
   actuel et la conséquence de chaque choix ; plus aucun `withhold` en base.
2. Un dégât facturé entre au décompte ; au-delà de la caution, le surplus est
   un restant dû payé avec la même communication et le même QR.
3. Tant qu'un incident est en évaluation, le décompte ne se valide pas, la
   caution reste retenue et l'étape attend ; au-delà d'un mois civil après le
   départ, un point d'attention le dit.
4. Un virement sans communication se rapproche à la main depuis la
   réservation, parmi les seuls candidats du §2.6, et se détache tant que le
   §2.6 le permet ; il s'impute en entier, même au-delà du dû (Q1).
5. Le locataire voit les incidents tranchés et la seule phrase d'un incident
   en évaluation ; jamais un motif.
6. Une décision après validation propose une nouvelle version ; la facture
   complémentaire ne porte que la différence et renvoie à la facture
   d'origine, intacte.
7. La clôture normale est gardée ; la clôture non recouvrée exige un motif,
   n'envoie aucun e-mail et laisse une trace.
8. Aide et documentation à jour ; suites vertes ; #980 commentée puis
   clôturée.

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| 0 — Document de chantier | #993 | Ce document : état des lieux au commit `066bfc05`, décisions du ticket et réponses aux questions Q1 à Q19 (Q1 tranchée par le mainteneur), lots et propriété des fichiers, pièges, critères d'acceptation. | — |
| A — Trois états, conversion, calcul | #993 | `IncidentDecision` à trois états (`PENDING` sélectionnable, `consequence()`, `fromStored()` qui lit `withhold` comme `CHARGE`) ; `Incident` gagne `awaiting`, `reference`, `waiveReason` (colonnes `*_encrypted`, un contexte de chiffrement chacune) ; `IncidentDecisionMigration::runOnce()` (drapeau `rental_incident_withhold_migrated`, `RentalStayRepository::convertWithheldIncidentsToCharges()`) câblée dans `public/index.php` ; `SettlementCalculator` sans retenue d'incident, `securityDepositAtSettlement()` (§2.3) ; `validateSettlement()` dérive les champs de caution de l'argent au moment de la validation et les écrit dans le compare-and-set ; « Trancher » en boutons radio avec l'état actuel, la conséquence de chaque choix et les champs du choix (`rental-inventory.js`, `data-incident-field`), ancre `#incident-{id}` ; aide `locations-incidents.md` (nouveau sujet), `locations-etat-des-lieux.md`, `gerer-les-locations.md`, une ligne de `locations-facture.md` ; spec §22.7, ARCHITECTURE §8.56 et §8.54 (`settleAmount()`), `rgpd_default.html` (« Incidents et dégâts »). | Colonne `rental_settlements.kept_cents` ajoutée (hors plan) : `Settlement::keptCents()` la lit, et une version enregistrée avant le lot (NULL) garde « total + retenue » — ainsi une version validée ne change pas de sens et une version non validée d'avant la conversion se valide au bon montant. Sans Finances, la caution est rendue entière (le dégât est dans le solde). Le motif d'un « Ne pas facturer » n'est gardé qu'avec ce choix ; « En attente de… » et « Référence » restent quel que soit l'état. Limites : 200, 100 et 500 caractères. Sujet d'aide séparé : `locations-etat-des-lieux.md` dépassait les 500 mots. Phrase de conséquence de « En cours d'évaluation » limitée à ce qui est vrai après A (le blocage du décompte vient avec C). |
| H — Contrat type | #992 | `StandardTemplates` : la caution est restituée « dans le mois suivant le départ ou le règlement d'un dégât en cours d'évaluation » ; spec §22.6 ; `StandardTemplatesTest`. | Le modèle standard est lu en direct tant qu'il n'est pas personnalisé : un contrat déjà généré et un modèle personnalisé ne changent pas. |
| C — L'évaluation bloque le décompte et la caution | #995 | Colonne `rental_settlements.incident_basis_hash` ; `Stay\IncidentBasis::hashOf()` (id, état et montant facturé de chaque incident), lu avant le calcul des lignes par `recordSettlement()` ; `RentalStayService::validateSettlement()` refuse tant que `pendingIncidentIds()` n'est pas vide (`SETTLEMENT_WAITS_FOR_ASSESSMENT`) et quand `Settlement::isOutdatedFor()` (`SETTLEMENT_OUTDATED`), et l'UPDATE de validation porte un `NOT EXISTS` sur les incidents en évaluation ; `incidentBasisHash()`, `outdatedSettlementIds()`, `hasIncidentUnderAssessment()` ; page Facture : phrase et « Voir l'incident » (`#incident-{id}`) ou « enregistrez-en un nouveau » à la place de « Valider » ; Finances : « Caution retenue : dégât en cours d'évaluation » ; `MilestoneEvidence::collect(…, $damageUnderAssessment)` → `awaitingAssessment`, `BookingMilestones::for(…, $awaitingAssessment)`, `BookingMilestone::$awaitsAssessment` (« Caution restituée » mène à « Trancher l'incident ») ; `RenterNextStep::DAMAGE_UNDER_ASSESSMENT` ; `RentalAttentionProvider` (nouveau paramètre `?RentalStayRepository`) : `findPendingIncidentsDepartedBy()`, `daysHeldPastAMonth()`, `damageWaiting()` ; phrase de conséquence de « En cours d'évaluation » complétée ; aide `locations-facture.md`, `locations-finances.md`, `locations-incidents.md` ; spec §22.7 ; ARCHITECTURE §8.56. | Une version enregistrée sans empreinte (avant le lot) n'est pas tenue pour dépassée : le lot A la laisse se valider au montant enregistré (correctif de revue 822b4989) ; seul le blocage d'un dégât en évaluation la retient. « Décompte final réglé » attend aussi, pour que le locataire lise la phrase fixe dès que le décompte est l'étape suivante. Le point d'attention dit aussi la référence du dossier (« Référence ») en plus de celle de la réservation ; il paraît même sans caution configurée (formulation du §2.4). Un mois civil finit le même jour du mois suivant, ou son dernier jour (31/01 → 28/02) ; le point paraît le lendemain. |
| D — « Rapprocher un virement » | #997 | `RentalPaymentService` : `transferSince()` (premier `sent_at` d'un contrat, `RentalPaymentRepository::firstContractSentAt()`, à défaut `receivedAt`), `lastStatementImportedAt()` (nouveau paramètre `?StatementImportStatusInterface` en dernier, câblé avec `$financeStatementStatusForOthers`), `paymentCandidates()`, `reconcilePayment()`, `detachPayment()` et `canDetach()` ; routes POST `virement-chercher` (JSON seulement, liste rendue par Twig), `virement-rapprocher`, `virement-detacher`, toutes par `bookingAction()` ; « Rapprocher un virement » sous « Mouvements du compte », dialogue `_finance_transfer.html.twig` (+ `_finance_transfer_candidates`, `_finance_transfer_statement`), « Rapproché à la main par …, le … » et « Détacher » (`_finance_movement_detach.html.twig`) ; `rental-transfer-reconcile.js` et son test Vitest ; aide `locations-virement.md` (nouveau sujet) et une phrase de `locations-finances.md`, spec §22.5 et §22.7, ARCHITECTURE §8.54, `rgpd_default.html` (paiements d'une location). | `bookingAction()` accepte qu'un traitement rende un tableau, ajouté au JSON (la recherche). Sans JavaScript, la recherche est refusée par un message plutôt que rendue en page. « Détacher » est refusé dès qu'un décompte est validé à la même seconde ou après le rapprochement (lecture prudente de Q11), sans verrou commun avec la validation. Le compte de « Aucun extrait importé depuis le … » est celui du bien (la créance ne mémorise pas le sien côté Locations). Sujet d'aide séparé : `locations-finances.md` dépassait les 500 mots. |
| E — Suivi du locataire | #996 | `RentalStayService::renterIncidents()` (remplace `incidentsVisibleToRenter()`) sur `RentalStayRepository::findRenterIncidents()`, qui rend un `Stay\RenterIncidents` (`decided` : des `Stay\RenterIncident` — description, `reportedAt`, `chargedCents` ou null —, et `assessmentInProgress`) ; partiel `views/public/_incidents.html.twig` (« Incidents et dégâts », « Constaté le … », « Facturé : X € » / « Non facturé », la phrase fixe d'un dégât en cours d'évaluation) inclus dans `tracking.html.twig` ; `RentalRequestController` gagne `?RentalStayService $stayService` en dernier paramètre, câblé dans `public/index.php` ; aide `locations-suivi.md` ; spec §22.5 ; `RentalTrackingIncidentsTest`, un test `OnMysql` du repository. | La requête ne rend, pour un incident en évaluation, que sa décision : ni chiffré, ni montant, ni date (`CASE WHEN decision IN (…)`) ; les textes internes ne sont jamais sélectionnés. Les incidents tranchés restent listés à côté de la phrase quand un autre est en évaluation (§2.7). Un « Facturer » sans montant décidé montre le proposé (`effectiveAmountCents()`) ; un « Ne pas facturer » ne montre aucun montant. La section est absente sans incident. Le prochain pas du lot C (`RenterNextStep`) suit la même règle : `DAMAGE_UNDER_ASSESSMENT_NO_DEPOSIT` quand aucune caution n'est détenue. |
