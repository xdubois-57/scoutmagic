# Chantier — Locations : dates exclusives, modifications par contrat de remplacement, créance unique (#927)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§7.5, §8.43 à
§8.60), `SECURITY.md`, `design.md` (§7) et `specifications.md` (§22), qui restent
la source de vérité des règles. Il ne les recopie pas.

Maquette qui fait foi : `docs/chantiers/maquettes/locations-modifications.html`
(ouvrir dans un navigateur ; la barre en pointillés du haut simule les états).
Chaque zone y est étiquetée **nouveau / modifié / inchangé / retiré** ; ce qui
n'est pas dessiné ne change pas.

L'issue #927 demandait une relecture critique et des questions avant tout
développement. L'analyse ci-dessous, les décisions du §2 et la maquette ont été
**validées par le mainteneur** : le chantier est prêt à implémenter. Là où le
texte de l'issue diffère de ce document, **ce document l'emporte**.

---

## 0. Règles de conduite

- **Autonomie complète.** Enchaîner les lots sans demander de validation. Ne
  poser une question au mainteneur (commentaire sur #927) que pour un vrai
  changement de design ou une ambiguïté fonctionnelle que ce document et la
  maquette ne tranchent pas. Une décision du §2 ne se rediscute pas.
- **Un lot = une branche = une PR.** Rebaser sur `main` avant la fusion. Suite
  complète (PHPUnit sur MariaDB, Vitest, PHPStan, `npm run typecheck`,
  Playwright si une page change). **Fusionner et pousser sur `main` dès que tout
  est vert, sans demander de confirmation.** Jamais de fusion rouge, jamais de
  baseline élargie.
- **Lots d'une même vague : les lancer réellement en parallèle** (un agent et une
  branche par lot). Le second à fusionner rebase sur le premier.
- **Documentation dans le même lot** que le code qu'elle décrit
  (`specifications.md` §22, `ARCHITECTURE.md`, aide `modules/rental/help/*`,
  aide Finances si concernée). Le lot F consolide, il ne rattrape pas.
- **Journal** : tenir à jour le §6 (une ligne par lot fusionné).
- **Le site est en test** : schéma modifié directement dans les `schema.sql`,
  colonnes retirées via `drops.sql` selon la règle existante. **Aucune
  migration ni conversion** des réservations existantes ; le jeu de données de
  référence est reconstruit avec le nouveau modèle. Aucun texte d'interface ne
  parle d'un état antérieur.
- Code en anglais, interface en français. Tout bouton qui agit immédiatement ou
  envoie un e-mail passe par une confirmation qui dit la conséquence **et si un
  e-mail part ou non** (`ScoutMagicConfirm.ask()` / `data-confirm`).

---

## 1. État des lieux (vérifié dans le code au commit `f8fff77`)

**Disponibilité** (`RentalAvailabilityService`, `AvailabilityCalculator`,
`RentalBooking::occupiesTheAsset`)
- Calculée à la lecture : un blocage expiré ne bloque plus, sans tâche. ✔
- **Demande publique sans verrou** : `validateRange` puis INSERT, ni transaction
  ni verrou (`RentalRequestController.php:354`, `createFromPublicRequest`).
- Deux verrous différents : `RentalBookingRepository::withAssetLocked()` fait un
  `FOR UPDATE` sur les **lignes de réservations** (rien n'est verrouillé quand le
  bien n'en a pas) ; `RentalBlockRepository::withAssetLocked()` verrouille la
  **ligne du bien**.
- `firmOnly: true` dans `confirm` (:383) et `acceptChange` (:918) : la
  confirmation ignore les demandes concurrentes encore bloquées.
- `placeOption` re-bloque des dates **sans vérification**.
- La copie signée (`RentalSignedContractService::receiveCopy`) ne change ni
  statut ni blocage. Il n'existe pas de blocage ferme hors CONFIRMED/CLOSED.
- Rappel `HOLD_EXPIRING` : 1 jour par défaut, réglable par bien.

**Modifications** (`rental_change_requests`, `acceptChange`)
- Une table pour les deux sens ; accepter **réécrit immédiatement** la
  réservation, sans recalcul des lignes ni de la créance.
- Le locataire crée des demandes et répond aux propositions depuis sa page de
  suivi ; il y a aussi un bouton « Annuler ma demande » (demande d'annulation).
- Boîte Prix : toutes les lignes éditables, « Modifiée à la main »,
  « Recalculer les lignes automatiques ».

**Contrats**
- Validité par empreinte (`ContractFingerprint`), qui inclut **adresse et TVA du
  locataire** : remplir sa facturation après l'envoi remplace le contrat et
  renvoie la réservation à « Demande reçue » (`RentalContractValidityService`).
- Un contrat final clôt le circuit des copies (`whyNoCopy`, `pendingCopy`).

**Finances**
- Deux créances (loyer, caution), deux communications, créées **à la
  confirmation seulement** (`RentalPaymentService::ensureReceivables`) : le
  contrat imprime « — » et « Acompte reçu » ne se coche pas seul avant
  confirmation.
- Échéances : « confirmation + N jours » pour l'acompte, le solde et la caution.
- Annulation, refus, expiration : rien sur les créances. Décompte final : ne
  touche pas la créance. Restitution de caution : formulaire manuel hors
  Finances.
- API Finances disponible : `createReceivable`, `updateReceivableAmount(...,
  allowBelowReceived)`, `getReceivableStatus`, `SepaQrCodeInterface` (jamais
  affiché). **Absents** : lecture des mouvements d'une créance, rapprochement
  d'un remboursement (interne : `ReceivableAllocationService::allocateRefund`),
  et **l'import ignore la communication des débits** (seuls les crédits sont
  rapprochés automatiquement).

**Sous-pages** : Tableau de bord, Modifications, Finances (Prix, Paiements),
Documents, État des lieux, Facture (Coordonnées → Décompte → Facture, sans
repli), Courrier. « Retirer » une autre adresse du locataire est un bouton
icône + texte.

---

## 2. Décisions verrouillées

### 2.1 Disponibilité et blocages

- **Un seul verrou** : toute écriture qui crée, déplace ou prolonge une
  occupation (demande publique, option, envoi du contrat, confirmation,
  modification directe, création/édition d'un projet de modification,
  contresignature, blocage manuel) verrouille **la ligne du bien**
  (`SELECT … FROM rental_assets WHERE id = ? FOR UPDATE`) dans une transaction,
  **revérifie la disponibilité** puis écrit. L'ancien verrou sur les lignes de
  réservations disparaît.
- **Ce qui occupe** : réservations non finales avec blocage provisoire actif,
  réservations à blocage ferme, projets de modification actifs (§2.3),
  blocages manuels. **`firmOnly` est supprimé** ; aucun passe-droit pour un
  gestionnaire. Une réservation est exclue de ses propres conflits (et son
  projet actif des siens), jamais de ceux des autres. Capacité, quantité,
  tampons et règles de séjour inchangés.
- **Blocage ferme** = la réservation a, pour son contrat **en vigueur**, au
  moins une copie signée **non refusée**, ou elle est CONFIRMED/CLOSED. Dérivé
  à la lecture, jamais stocké. Il ne confirme rien : la confirmation reste
  manuelle (§22.5 inchangé sur ce point).
- **Copie refusée** : on retombe au blocage provisoire, porté à au moins
  **refus + 3 jours** (plafonné au début du séjour, jamais raccourci), le temps
  d'envoyer une copie correcte.
- Blocage automatique (30 j par défaut), prolongation par le gestionnaire,
  prolongation à l'envoi du contrat (`contract_hold_min_days`) : inchangés.
  **Rappel d'expiration : défaut 7 jours** (au lieu de 1), toujours réglable par
  bien.
- Une échéance passée libère les dates, même contrat envoyé, sans tâche ;
  l'échéance d'une option gestionnaire rend toujours la réservation expirée
  (inchangé). La tâche d'expiration ne touche jamais une réservation à blocage
  ferme ni les dates d'un projet actif.
- Les vues publiques (calendrier, formulaire, ICS, publication dans le
  calendrier) utilisent le même prédicat.

### 2.2 Le locataire ne modifie plus rien

Retirés de la page de suivi : « Modifier votre demande », la réponse aux
propositions, et **« Annuler ma demande »**. Le locataire contacte le
gestionnaire ; le gestionnaire agit dans ScoutMagic. Retirés aussi : les
propositions du gestionnaire, la notification `rental.change_request`, la raison
« À traiter » correspondante, `RenterDecision::PROPOSED/CHANGE_ACCEPTED/
CHANGE_REFUSED`, et la table `rental_change_requests`. La page de suivi garde :
état, documents reçus, dépôt de la copie signée, coordonnées de facturation,
informations pratiques, et gagne le paiement (§2.6).

### 2.3 Modifications

- **Avant le premier envoi d'un contrat** (aucun contrat n'a jamais eu
  `sent_at`) : changements appliqués directement à la réservation, sous le
  verrou, tracés dans l'historique des modifications et l'historique de la
  réservation, **sans e-mail**. Un contrat généré non envoyé devient « à
  régénérer ».
- **Après le premier envoi** : uniquement par un **projet de modification**,
  objet distinct (nouvelle table) qui porte un instantané : dates, participants,
  unités, lignes de prix et remise. **Un seul projet actif par réservation**,
  garanti en base (contrainte unique sur une colonne générée « actif ») et sous
  le verrou du bien.
- Cycle (maquette, page dédiée) : *en préparation* → *contrat envoyé* (copie
  attendue / copie refusée) → *copie reçue* → **contresigné = appliqué** ;
  ou *annulé*.
  - Enregistrer un projet dont les dates changent vérifie et **bloque ses
    nouvelles dates sans échéance** tant qu'il est actif. Sans changement de
    dates, aucune occupation en plus.
  - En préparation : éditable (revalidation des dates à chaque enregistrement).
  - « Générer et envoyer le contrat de remplacement » : contrat complet généré
    depuis l'instantané, même communication structurée ; dès l'envoi, les
    termes du projet sont figés. Corriger = annuler puis créer un nouveau
    projet.
  - Copie refusée : le projet reste actif, ses dates bloquées ; le locataire
    dépose une autre copie du **même** contrat.
  - **La contresignature finalise** (pas de bouton de plus), en une
    transaction sous le verrou : dates, participants, unités, prix et remise
    passent sur la réservation (même identifiant), la créance prend son nouveau
    montant (§2.5), l'ancien contrat passe « Remplacé », le nouveau devient le
    contrat en vigueur, l'historique est écrit. Les anciennes dates non reprises
    sont libérées d'elles-mêmes (elles ne sont plus celles de la réservation).
    Le contrat signé part ensuite au locataire ; un échec d'envoi laisse la
    modification appliquée et se signale comme tout e-mail « Non envoyé ».
    **Le statut de confirmation ne change pas.**
  - Annuler un projet : libère ses seules nouvelles dates, ne touche ni à la
    réservation ni au contrat en vigueur ni à la créance, garde l'historique,
    **e-mail au locataire** si un contrat de remplacement lui avait été envoyé
    (sinon aucun e-mail : il n'en savait rien).
  - Clôturer, annuler, refuser ou expirer la réservation annule son projet
    actif (même transaction).
- **Anciennes dates d'une réservation non signée pendant un projet** : elles
  suivent leur propre échéance. Le projet ne protège que ses nouvelles dates ;
  à son annulation, aucun blocage expiré n'est ressuscité.
- Projet possible seulement sur une réservation non finale.
- **Boîte Prix** (déplacée de Finances vers la modification) : lignes du tarif
  **en lecture seule**, recalculées à partir des dates et des participants ;
  **une seule ligne libre « Remise »** (libellé + montant négatif). Disparaissent
  : l'édition des lignes, l'ajout de lignes, « Modifiée à la main »,
  « Recalculer les lignes automatiques ».
- Après le séjour, un geste commercial passe par une ligne **« Geste
  commercial »** du décompte final, sans nouveau contrat.
- **Contrat en vigueur** : plus aucun remplacement automatique par empreinte
  après le premier envoi. Seule la contresignature d'un projet remplace un
  contrat. En particulier, des coordonnées de facturation saisies après l'envoi
  ne rendent plus le contrat caduc.
- Les copies signées, refus et contresignatures sont rattachés **au contrat**
  qu'ils concernent (et donc au projet) : un contrat final antérieur n'empêche
  plus de recevoir la copie d'un contrat de remplacement.

### 2.4 Écrans

- **Tableau de bord** : aucune boîte nouvelle. La boîte **« Prochaine action »**
  gagne les cas « modification en cours » (titre selon l'étape, rappel que les
  conditions affichées restent celles qui s'appliquent, action principale vers
  la modification ou vers « Vérifier et contresigner » sur Documents). L'étape
  « Dates bloquées » du cycle de vie dit « bloquées fermement » quand c'est le
  cas.
- **Modifications** : en tête, boîte « Modification en cours » (état, avant →
  après, prochaine étape, « Ouvrir la modification ») ou « Créer une
  modification » (ou, avant le premier envoi, « Modifier les dates, les
  participants ou le prix ») ; dessous, **historique** avec colonne **Statut**
  (Appliquée, Appliquée directement, Annulée) et lien de consultation en
  lecture seule.
- **Page dédiée d'une modification** : étapes, aujourd'hui / proposé, boîte
  Prix, actions selon l'étape. **Pas de vue des documents** : un lien vers
  Documents.
- **Documents** : le contrat de remplacement apparaît à côté du contrat en
  vigueur, avec sa copie, « Refuser la copie » et « Contresigner ». **Bannière
  « Une modification est en cours » sur cette page seulement** (ni tableau de
  bord, ni Finances).
- **Finances** (ordre strict) : 1. **Résumé** (prix, caution, attendu, reçu,
  restant dû ou trop-perçu, acompte et échéances ; aucun prix éditable) ;
  2. **Mouvements du compte** (crédits et débits rapprochés de la créance, date,
  libellé, montant, imputation) ; 3. **Informations de paiement** (bénéficiaire,
  IBAN, communication, montant à verser à cette étape, **QR SEPA visible et
  agrandissable** ; après le décompte : « À rembourser », instructions et
  « Rapprocher le remboursement »). États sans Finances, sans IBAN, sans montant
  dû.
- **Facture** (ordre strict) : 1. Décompte final ; 2. Coordonnées de
  facturation, **repliées** avec un résumé quand nom et adresse sont connus
  (TVA/BCE facultatifs n'empêchent pas le repli), dépliées sinon ; 3. Facture.
- **Courrier** : « Retirer » une autre adresse = **icône seule** (`bi-x-lg`,
  `aria-label`), confirmation inchangée.
- **Page de suivi du locataire** : contrat (y compris « un nouveau contrat vous
  a été envoyé », copie refusée et raison) ; **paiement avec QR** (bénéficiaire,
  IBAN, communication, montant à verser et échéance) dès qu'une créance existe.

### 2.5 Créance unique

- **Une créance par réservation = prix de location + caution**, une
  communication structurée, utilisée pour l'acompte, le solde, la caution et
  tout complément. Plus de créance ni de communication de caution.
- **Créée avant la génération du premier contrat** (idempotente : réutilisée
  pour toute régénération, version ou modification), pour que le contrat imprime
  la vraie communication. Paiements activés mais création impossible → la
  génération est refusée avec un message clair (jamais de contrat sans
  communication). Finances inactif ou paiements désactivés : fonctionnement
  dégradé explicite, sans communication.
- Avant le premier envoi, le montant suit les changements directs. Un projet en
  préparation ne change rien ; **à la contresignature**, le montant de la
  créance existante est mis à jour (location + caution), même référence,
  rapprochements conservés.
- **Échéances** : acompte = **premier envoi du contrat + N jours** (un contrat de
  remplacement ne le déplace pas) ; solde **et caution** = **X jours avant
  l'arrivée**. Le réglage d'échéance propre à la caution disparaît. Seuils
  comparés aux encaissements cumulés (acompte, puis total), sans ventilation
  affichée.
- **Refus, annulation, expiration** : montant ramené à **0** avec
  `allowBelowReceived` ; la créance et sa communication restent ; tout virement
  reçu apparaît en trop-perçu. Jamais de suppression d'une créance (hors purge de
  rétention, inchangée).
- **Décompte final validé** : montant ramené au **montant conservé** (location
  convenue + consommations + retenues − geste commercial) ; le prix convenu de
  la réservation reste intact. La caution à rendre apparaît en trop-perçu.
- Exercices comptables différents : rien de particulier, même catégorie.
- Un échec d'appel à Finances après une écriture métier est journalisé et
  signalé sur la réservation ; l'état métier ne ment pas (pas de contrat sans
  communication, pas de « remboursé » sans mouvement).

### 2.6 Restitution de la caution et mouvements — le gestionnaire boucle seul

- **Finances, pour toutes les créances** : à l'import d'un extrait, un **débit**
  dont la communication porte celle d'une créance est rapproché automatiquement
  comme remboursement, **dans la limite du trop-perçu** ; le reste du débit reste
  non rapproché.
- Nouvelles méthodes `Modules\Finance\Api\…` (lecture et action ciblées,
  jamais d'accès aux tables de Finances depuis Locations) :
  - mouvements rapprochés d'une créance (crédits et remboursements), en lecture
    seule ;
  - état étendu : dû, reçu, remboursé, trop-perçu ;
  - débits **candidats** au remboursement d'une créance : non rapprochés, sur
    son compte, d'un montant ≤ trop-perçu, postérieurs à une date — montant,
    date, nom de la contrepartie seulement ;
  - rapprocher un débit candidat comme remboursement (revérifie tout).
- Locations : « À rembourser : X € — virement à faire depuis la banque avec la
  communication +++…+++ » ; **« Rapprocher le remboursement »** en secours ;
  l'étape « Caution restituée » se coche quand le trop-perçu tombe à 0. Le
  formulaire manuel de restitution ne reste que sans Finances.
- Le virement lui-même se fait à la banque : le site ne déplace pas d'argent.

### 2.7 Écarté

Acceptation de principe ; modification initiée par le locataire ; annulation
par le locataire ; lignes de prix éditables ; recalcul manuel ; ventilation des
virements entre acompte, solde et caution ; migration des réservations
existantes ; logique comptable par exercice ; bascule automatique du statut à
la copie signée ou à la contresignature ; boîte « Dates » ou bannière sur le
tableau de bord.

---

## 3. Lots et ordre d'exécution

Trois vagues, **une PR par lot**. Les lots d'une vague tournent **en
parallèle** — les lancer en même temps.

```
Vague 1 :  [A Disponibilité]   [B API Finances]   [C Créance unique]
                  │                   │  ┌───────────────┘
Vague 2 :  [D Modifications ◄─ A, C]  [E Finances, Facture, suivi ◄─ B, C]
                  └──────────┬──────────┘
Vague 3 :            [F E2E, aide, docs, clôture]
```

**Propriété des fichiers partagés** (pour que D et E ne se marchent pas
dessus) : E possède `booking_finances.html.twig`, `booking_invoice.html.twig`,
`booking_mail.html.twig` et un nouveau partiel de paiement de la page de suivi ;
D possède tout le reste de la page de suivi, la page Modifications, la page
dédiée, `_journey.html.twig`, `booking_documents.html.twig` et la boîte Prix. D
retire l'inclusion de la boîte Prix dans Finances **en laissant E réécrire le
gabarit** : si D fusionne avant E, il supprime seulement la ligne d'inclusion.

### Lot A — Disponibilité exclusive (vague 1)

- Verrou unique sur la ligne du bien pour toutes les écritures du §2.1 (la
  demande publique comprise, dans une transaction).
- Suppression de `firmOnly` ; vérification dans `placeOption` et à l'envoi du
  contrat quand il prolonge le blocage.
- Blocage ferme dérivé des copies signées non refusées du contrat en vigueur ;
  refus = blocage porté à au moins refus + 3 jours.
- Interface de fournisseur d'occupation prête à recevoir les projets (D
  l'implémente).
- Défaut du rappel d'expiration à 7 jours.
- Tests : deux requêtes concurrentes (MariaDB, deux connexions), confirmations
  concurrentes, provisoire contre provisoire, option contre demande, expiration,
  copie reçue avant contresignature et expiration ensuite, copie refusée
  (+3 jours), blocage manuel, quantités et tampons.

### Lot B — API Finances (vague 1)

- Rapprochement automatique des débits portant une communication (§2.6),
  plafonné au trop-perçu, idempotent sur un réimport.
- Méthodes `Api\` du §2.6 + implémentation + tests (`ModuleBoundariesTest`
  inchangé), y compris la visibilité (aucun mouvement d'une autre créance).
- Aide et spécification de Finances : le remboursement rapproché par sa
  communication.

### Lot C — Créance unique (vague 1)

- Création avant la première génération de contrat, une seule créance location
  + caution, une communication ; suppression de la créance et de la
  communication de caution, de leurs colonnes et du réglage d'échéance de la
  caution.
- Échéances (§2.5), seuils, mise à 0 sur refus/annulation/expiration, montant
  conservé au décompte final, ligne « Geste commercial » du décompte.
- Méthode de mise à jour du montant utilisée par D à la contresignature
  (publique dans le module, pas dans `Api\`).
- Mot-clé `{{ communication }}` garanti à la génération ; refus de générer si
  paiements activés et créance impossible.
- Tests : idempotence, régénération, changement direct avant envoi, refus avec
  encaissement (trop-perçu), décompte avec et sans retenue, Finances inactif.

### Lot D — Modifications par contrat de remplacement (vague 2, après A et C)

- Nouveau modèle de projet (instantané, statut, horodatages, auteurs), un seul
  actif (contrainte en base), occupation de ses nouvelles dates via le
  fournisseur d'A.
- Changements directs avant le premier envoi ; boîte Prix (tarif en lecture
  seule + Remise) déplacée ; suppression de l'édition de lignes et du recalcul.
- Contrat de remplacement (génération depuis l'instantané, rattachement au
  projet), copies et refus par contrat, contresignature qui finalise
  atomiquement, annulation (e-mail si un contrat avait été envoyé), annulation
  du projet à la fin de la réservation.
- Fin du remplacement automatique par empreinte après le premier envoi.
- Retrait complet des demandes/propositions (§2.2), du bouton d'annulation du
  locataire, des notifications, raisons « À traiter » et e-mails associés, de
  la table.
- Écrans : Modifications, page dédiée, « Prochaine action », Documents
  (bannière, ligne v2), page de suivi (contrat de remplacement).
- Tests : un seul projet sous concurrence (deux gestionnaires), dates du projet
  occupées refusées, chevauchement ancien/nouveau, contresignature une seule
  fois et atomique même si l'e-mail échoue, annulation, fin de réservation avec
  projet actif, scénario « premier contrat non signé + projet + expiration ».

### Lot E — Finances, Facture, Courrier, paiement locataire (vague 2, après B et C)

- Finances : Résumé, Mouvements, Informations de paiement (QR agrandissable,
  « À rembourser », « Rapprocher le remboursement »), états sans Finances, sans
  IBAN, sans montant dû.
- Facture : ordre strict, coordonnées repliées quand nom et adresse sont
  connus.
- Courrier : bouton icône seule.
- Page de suivi : bloc paiement avec QR (partiel dédié).
- « Caution restituée » dérivée du trop-perçu à 0 ; formulaire manuel seulement
  sans Finances.
- Tests de contrôleur, Vitest pour l'agrandissement du QR et le repli.

### Lot F — Bout en bout, aide et clôture (vague 3)

- E2E : demande → contrat (communication imprimée) → copie (blocage ferme) →
  confirmation → modification → contrat de remplacement → copie refusée → copie
  → contresignature → séjour → décompte → remboursement rapproché ; deux
  demandes concurrentes ; page de suivi sans action de modification.
- **Guide utilisateur** : `locations.md`, `locations-reservation.md`,
  `locations-parcours.md`, `locations-finances.md`, `locations-facture.md`,
  `locations-documents.md`, `locations-signatures.md`, `locations-suivi.md`,
  `locations-courrier.md`, `gerer-les-locations.md`, et **un nouveau sujet sur
  les modifications** ; métadonnées `paths`, liens et textes qui parlent encore
  de demandes du locataire, de propositions, de deux communications ou de
  caution restituée à la main.
- Documentation technique : `specifications.md` §22 (y compris « quatre pages »,
  déjà faux), `ARCHITECTURE.md` (disponibilité, verrou, projet, contrats,
  créance, API Finances), aide Finances.
- Ligne de la maquette dans `docs/chantiers/maquettes/README.md` passée à
  « livré ».
- **Commenter #927** avec ce qui a été livré (lots, PR, écarts), **puis la
  clôturer**.

---

## 4. Pièges

- Un `FOR UPDATE` sur les lignes de réservations ne verrouille rien quand il n'y
  en a pas : toujours la ligne du bien.
- Le calendrier public passe minuit comme « maintenant » : garder un prédicat
  unique et tester la frontière.
- Ne jamais lire `rental_*` depuis Finances ni `finance_*` depuis Locations.
- Un contrat de remplacement garde la communication de la réservation : ne pas
  en générer une nouvelle.
- La contresignature est idempotente : deux clics, un seul basculement.
- Aucune remise ne réécrit le prix convenu historique ; le décompte ajoute des
  lignes à côté.
- Une notification ou un e-mail qui échoue ne défait jamais l'écriture métier.
- `UxConventionsTest` : confirmations sur les actions qui envoient un e-mail ou
  détruisent, icônes du jeu vendu, pas de script dans les gabarits.

---

## 5. Critères d'acceptation

1. Aucune paire de requêtes, même simultanées, ne produit deux occupations qui
   se chevauchent au-delà de la quantité du bien ; aucun passe-droit
   gestionnaire.
2. Une copie signée non refusée bloque fermement, avant toute contresignature ou
   confirmation, et aucune tâche ne libère ces dates.
3. Après le premier envoi, la réservation et son contrat en vigueur ne changent
   qu'à la contresignature d'un projet, une seule fois, atomiquement, sans
   changer le statut de confirmation.
4. Une seule communication pour acompte, solde, caution, contrat, QR et
   remboursement ; montant mis à jour à la contresignature, à 0 en cas
   d'abandon, au montant conservé au décompte ; remboursement rapproché
   automatiquement ou depuis la réservation.
5. Le locataire n'a plus aucune action de modification ni d'annulation ; il voit
   son paiement avec QR.
6. Sous-pages dans l'ordre de la maquette ; guide et documentation à jour ;
   suites vertes ; #927 commentée puis clôturée.

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| A — Disponibilité exclusive | #973 | Verrou unique sur la ligne du bien (`Repository\AssetLock`) pour la demande publique (transaction, revérification sous le verrou), l'option, l'envoi du contrat, la confirmation, la copie reçue ou refusée, le blocage manuel ; `firmOnly` et `Occupancy::$isFirm` supprimés ; blocage ferme dérivé des copies signées non refusées et non remplacées (`RentalBooking::holdsFirmly()`, sous-requête `signed_copy_received_at`), « Dates bloquées fermement » ; copie refusée = blocage d'au moins refus + 3 jours ; `Service\RentalOccupancyGuard` pour D (`onAsset()`, `rangeIsFree()` avec la référence de la réservation comme propriétaire) ; un même instant pour calendrier public, formulaire, calendrier géré et publication ; rappel d'expiration à 7 jours ; tests SQLite et MariaDB à deux connexions. | Un blocage manuel prend le verrou mais ne revérifie pas (il peut toujours recouvrir un séjour, §22.8) ; une copie déposée après l'échéance sur des dates reprises est refusée ; un envoi de contrat sur des dates reprises est refusé avant l'e-mail. |
| B — API Finances | #971 | Débit portant la communication d'une créance rapproché à l'import comme remboursement, plafonné au trop-perçu, idempotent ; `ExpectedReceivableInterface::getReceivableStanding()`, `getReceivableMovements()`, `findRefundCandidates()`, `reconcileRefund()` (`Api\ReceivableStanding`, `Api\ReceivableMovement`, `Api\RefundCandidate`) ; aide et §48.7 de la spécification Finances, ARCHITECTURE §8.81. | Les quatre méthodes nomment le module source (une créance d'un autre module est répondue absente) ; un remboursement automatique ne recule jamais ; le garde-fou de `updateReceivableAmount()` déduit les remboursements. §8.54 (« Finance ne rapproche pas les débits ») laissé au lot C/F qui réécrit ce paragraphe. |
