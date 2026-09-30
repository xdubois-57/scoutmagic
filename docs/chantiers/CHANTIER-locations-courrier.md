# Chantier — Locations : la page Courrier d'une réservation

> **À implémenter après le #708** ([`CHANTIER-locations-demandes.md`](CHANTIER-locations-demandes.md)). Les deux chantiers touchent le même écran, le même service de courrier et le même parcours de notification des Locations : le #708 d'abord, puis celui-ci sur le code obtenu.
>
> Issue d'implémentation : [#720](https://github.com/xdubois-57/scoutmagic/issues/720).

**Périmètre : `modules/rental`, plus l'API du module `inbound_mail`.** Camps, Finance et les autres consommateurs du courrier entrant ne changent pas de comportement.

---

## 1. Le besoin

Une page **Courrier** par réservation qui montre, sans tri manuel, toute la correspondance rapprochée de cette réservation, reçue comme envoyée.

1. **La page est toujours là quand le module Locations est actif.** Plus aucune différence entre une boîte « dédiée » et une boîte « partagée » **dans les Locations**. La configuration du courrier entrant garde son choix dédiée/partagée, et les autres modules continuent de s'en servir ; les Locations l'ignorent.
2. **La page ne montre que le courrier rapproché de cette réservation.** C'est une chronologie, plus un écran de tri. **Pas de rattachement manuel.** Seule action sur un message : **« Détacher »**, avec une confirmation.
3. **Le courrier envoyé y figure aussi** : les e-mails envoyés par le site (journal des envois, texte compris) et les e-mails envoyés à la main **depuis la boîte de l'unité** (lus automatiquement dans son dossier « Envoyés »).
4. **Quand une règle reste ambiguë, ou plausible mais incertaine**, et que le module IA (`llm_connector`) est activé, **l'IA tranche**. Sinon, **on ne fait rien** : le message n'apparaît dans aucune location. Ni proposition, ni écran de tri.
5. **Le gestionnaire est prévenu** d'un nouveau message du locataire : une notification, et une pastille de non-lus sur l'onglet Courrier.
6. **Les pièces jointes des messages rapprochés, reçus comme envoyés**, sont ajoutées aux documents de la location, avec les mêmes restrictions.
7. **La référence d'une réservation devient aléatoire** au lieu de se suivre, pour être plus difficile à deviner. Ce n'est pas une sécurité forte, mais elle rend moins facile ce que l'issue #231 décrivait.

## 2. Comment ça se passe aujourd'hui

- La page Courrier n'existe que si **exactement une** boîte est déclarée dédiée aux Locations ; avec zéro ou deux boîtes, elle n'existe pas (issue #462, D8). Une boîte partagée ne suffit pas (`RentalCommunicationService::dedicatedMailbox()` : « Two is none » ; `isAvailable()` masque aussi la page sans boîte qui collecte).
- Quand elle existe, c'est l'écran de tri de tous les biens du gestionnaire (même composant que Camps, `@inbound_mail/partials/triage.html.twig`) : messages non rattachés, écartés, propositions, rattachement manuel, « Relancer l'analyse ».
- Les messages envoyés par le site n'y sont pas : seule une **empreinte aveugle du `Message-ID`** est conservée (`inbound_outbound_message_ids`, via `recordOutboundMessageId()`), pour reconnaître plus tard la réponse du locataire. Ni objet, ni texte, ni destinataire, ni date. Il n'existe aucune table de journal d'envoi. Un échec d'envoi ne laisse qu'un avertissement éphémère.
- Les messages envoyés à la main ne sont jamais lus : la synchronisation ne regarde que les dossiers configurés (vide = INBOX), et **toutes** les règles de `RentalMessageConsumer` lisent l'expéditeur, qui devrait être le locataire.
- En cas d'ambiguïté, `RentalMessageConsumer` produit au plus `MAX_PROPOSITIONS = 5` propositions. `Mail\BookingChoiceByModel` (niveau `CHEAP` du connecteur IA) ne fait que **classer** ces propositions : « il ne rattache jamais ». Les gestionnaires sont prévenus **seulement pour ces propositions** (`RentalMailNotifier::proposed()`, écouteur `PropositionListener`) ; un message rattaché ne prévient personne. `RentalAttentionProvider` compte les messages en attente d'une décision.
- Les autres adresses d'un locataire (`rental_booking_emails`) ne s'apprennent que par un rattachement manuel (`learnFrom()`), et ne sont visibles nulle part.
- `onLinked()` classe **toutes** les pièces jointes conservées du message comme documents `Non classé`, internes, avec `SOURCE_EMAIL`, sans doublon par fichier.
- La référence est **séquentielle** : `LOC-%04d-%04d` (`RentalBookingService`, ligne ~269), compteur `rental_reference_sequences`, reconnue par `BookingReferenceMatcher` (`LOC-\d{4}-\d{1,6}`).

## 3. Décisions prises

- **Un ticket unique**, en plusieurs étapes ; aucune n'est facultative.
- **La distinction dédiée/partagée reste dans `inbound_mail`** : ni l'enum `MailboxPurpose`, ni les colonnes, ni `isDedicatedTo()` / `dedicatedMailboxesFor()` ne disparaissent. Les Locations cessent de s'en servir.
- **Les e-mails écrits depuis une adresse personnelle n'apparaissent pas.** Seuls ceux envoyés depuis une boîte de l'unité ouverte aux Locations sont lus. L'aide le dit.
- **Pas de rédaction d'e-mail dans le site** dans ce chantier.
- **Pas de reprise de données, et aucun texte ne mentionne un état antérieur** : le site est en test.
- **Une réservation ne voit que ses propres messages.** L'ancienne vue « tous mes biens » disparaît.
- **Aucune écriture IMAP.** Le contrat de lecture reste sans vocabulaire d'écriture.

## 4. Les étapes

### Étape 1 — La page, sans condition sur les boîtes

- Suppression de `RentalCommunicationService::dedicatedMailbox()`, de son second usage dans `RentalBookingMailService` (repli d'adresse de réponse sur la boîte dédiée, ligne ~88) et de la condition d'affichage `isAvailable()`. L'adresse de réponse passe uniquement par `replyAddressFor()`, déjà fourni par `inbound_mail`.
- La page devient une chronologie de la réservation, reçus et envoyés mêlés par date, chaque message marqué de son sens (« Reçu » / « Envoyé »). Chaque message s'ouvre de la même façon, qu'il vienne de la boîte ou du journal.
- Sans boîte qui collecte pour les Locations, la page s'affiche quand même (le journal des envois n'en dépend pas), avec une phrase qui explique comment recevoir les réponses des locataires.
- **« Détacher »**, avec une confirmation (« Ce message ne concerne pas cette réservation ? ») : le message quitte la page, ses documents encore `Non classé` aussi (`onUnlinked()`, comme aujourd'hui), l'adresse apprise par ce message est retirée (étape 5), et **il n'est plus jamais rattaché automatiquement à cette réservation**.
- Retrait du texte « Cette page existe parce que les locations disposent d'une boîte dédiée ».
- Les routes `/mes-locations/courrier/*` qui ne servent plus (rattacher, écarter, reprendre, relancer, proposition) disparaissent avec leurs vues et leurs tests.
- `docs/rental-guide.md` §11 et l'aide sont réécrits.

### Étape 2 — Le journal des envois du site

- Nouvelle table `rental_booking_sent_emails`, dans `modules/rental/schema.sql` (la `version` de `module.json` est incrémentée) :
  - la réservation, la date, le type de message (accusé de réception, décision, document, infos pratiques, lien de suivi, notification au gestionnaire…) ;
  - le destinataire, l'objet et **le texte, chiffrés** comme les autres champs personnels du module ;
  - **le lien de suivi masqué dans le texte conservé** : il contient un jeton d'accès à la réservation ;
  - les pièces jointes, **par renvoi aux documents de la réservation** qu'elles sont déjà (contrat, facture, état des lieux…), sans copie ;
  - le **`Message-ID` en clair** (pour l'étape 4) ;
  - un état **envoyé ou échec**.
- Effacée avec la réservation (`ON DELETE CASCADE`), sans durée de conservation propre. Les nouvelles colonnes chiffrées respectent le garde RGPD des colonnes BLOB (#607).
- **Un seul point d'écriture** pour tous les envois de `RentalBookingMailService`. À vérifier : les rappels automatiques (`rental_reminders_sent`) et les e-mails ajoutés par le #708, qui ne passent peut-être pas par ce service.
- **Un envoi échoué** apparaît en rouge « Non envoyé », avec un bouton **« Renvoyer »** qui le renvoie. Un e-mail qui portait un lien de suivi est renvoyé avec un lien valide, pas avec le texte masqué.

### Étape 3 — Le sens d'un message et le dossier « Envoyés », dans l'API `inbound_mail`

- Un `MessageDirection` (reçu / envoyé) sur `CandidateMessage` et sur `InboundMessage`, stocké dans une nouvelle colonne de `inbound_messages` (défaut « reçu » ; `schema.sql` et `version` du module à jour).
- Un message envoyé n'est proposé **qu'aux consommateurs qui le déclarent** (une petite interface, par exemple `HandlesOutboundMail`). Les Locations le déclarent ; Camps et Finance n'en reçoivent jamais et leur code ne change pas.
- **Le dossier « Envoyés » est lu automatiquement**, sans réglage, sur toute boîte ouverte à un consommateur qui déclare le courrier envoyé : c'est le dossier que le serveur marque de l'attribut IMAP `\Sent` (lu par `listFolders()`, en lecture seule). La configuration de la boîte permet seulement de **corriger** ce dossier quand le serveur ne le marque pas.
- **Un message envoyé n'est stocké que s'il est rapproché** par un consommateur. Le reste du courrier envoyé de l'unité n'entre pas dans `inbound_messages`. C'est l'inverse du courrier reçu, qui est stocké quoi qu'on en dise : le courrier envoyé n'a d'intérêt qu'une fois rapproché.
- **Le contrat de lecture reste sans écriture** (`ARCHITECTURE.md` §2185, `SECURITY.md` §665, `NonIntrusiveReadTest`).
- Un message déjà stocké dans la boîte est écrit une fois (`findIdByMessageId`) ; le cas d'un message envoyé à la boîte elle-même est à traiter par l'agent.

### Étape 4 — Le rapprochement du courrier envoyé, côté Locations

- Pour un message envoyé, `RentalMessageConsumer::analyze()` applique les mêmes niveaux qu'à l'entrant en lisant les **destinataires** (`toEmails`) au lieu de l'expéditeur, y compris les autres adresses du locataire (étape 5).
- Les niveaux de référence et d'en-têtes de fil (`In-Reply-To`, `References`) s'appliquent tels quels. La fenêtre autour du séjour est réutilisée.
- Une **copie d'un e-mail envoyé par le site**, si le fournisseur la range dans « Envoyés », est reconnue **avec certitude** par son `Message-ID` (`inbound_outbound_message_ids`) et **n'est pas affichée en double** du journal de l'étape 2.

### Étape 5 — Les autres adresses du locataire

- Une liste **« Autres adresses du locataire »**, modifiable sur la réservation (ajouter, retirer), fondée sur `rental_booking_emails`. Un message venant de l'une d'elles, ou envoyé à l'une d'elles, est rapproché comme s'il s'agissait du locataire.
- **Un rattachement confirmé par l'IA ajoute l'adresse** de l'expéditeur (ou du destinataire, pour un message envoyé) à cette liste, marquée « ajoutée automatiquement ». Le gestionnaire la voit et peut la retirer ; « Détacher » le message qui l'a apprise la retire aussi.
- `learnFrom()` suit ces règles ; il n'y a plus de rattachement manuel pour l'appeler.

### Étape 6 — L'IA tranche un rapprochement ambigu ou incertain

- Quand une règle laisse plusieurs réservations plausibles, ou une seule sans certitude — en particulier **une référence citée par une adresse inconnue** —, et que le connecteur IA est disponible (`isTierAvailable`), **le modèle tranche parmi les candidats déjà produits par les règles déterministes**, ou répond « aucune ».
- Il **rattache** (origine `LinkOrigin::AI`, qui existe déjà) au lieu de seulement ordonner. `Mail\BookingChoiceByModel` est adapté en conséquence.
- Si l'IA est absente, décline, se trompe de format ou répond un identifiant qui n'est pas dans la liste : **rien n'est fait**, et le message n'est montré dans aucune location.

Garde-fous :

- **Les textes analysés sont attaquables** : n'importe qui peut écrire dans une boîte ouverte aux Locations (issue #231). L'IA ne choisit que **parmi la liste donnée**, et ses seuls effets sont le rattachement et l'adresse apprise, tous deux visibles et réversibles. Les documents d'un message restent `Non classé` et internes.
- **Les appels ne bloquent pas la synchronisation.** Appel borné par message (`MAX_PROMPT_CHARS`, `MAX_TOKENS`, délai), nombre d'appels borné par passage ; ce qui n'a pas pu être tranché est retenté à un passage suivant, puis abandonné. À l'agent de choisir et de justifier l'approche, et de la faire valider par le mainteneur si elle touche la synchronisation d'`inbound_mail`.
- **Données personnelles** : le texte du message part chez le fournisseur du modèle, comme `BookingChoiceByModel` le fait déjà ; à vérifier que c'est déclaré dans l'inventaire RGPD des sous-traitants du module Locations.

### Étape 7 — Plus de propositions ; une notification par nouveau message

Disparaissent :

- `AnalysisResult::proposing()` dans `RentalMessageConsumer`, et l'écouteur `PropositionListener` des Locations ;
- `RentalMailNotifier::proposed()` ;
- le comptage des messages en attente dans `RentalAttentionProvider` ;
- `RentalCommunicationService::propositions()`, `confirmProposition()` et `dismissProposition()`, avec leurs routes et tests.

Arrivent :

- une notification **« Nouveau message du locataire »** aux gestionnaires du bien, par le même mécanisme que la notification « Nouvelle demande de location » du #708, pour chaque message **reçu** nouvellement rapproché (pas pour un message envoyé) ;
- une **pastille de non-lus** sur l'onglet Courrier de la réservation, et dans la vue d'ensemble, par gestionnaire, remise à zéro à l'ouverture de la page Courrier.

### Étape 8 — Les pièces jointes, reçues et envoyées

`onLinked()` ajoute aux documents de la location (`Non classé`, interne, `SOURCE_EMAIL`), pour les messages **reçus comme envoyés** et avec **les mêmes restrictions** :

- les **PDF** ;
- les **DOC/DOCX** ;
- les **images d'une taille significative**, qui ne sont ni un logo ni une image de signature (filtre existant d'`inbound_mail`, `AttachmentPolicy` : `Content-Disposition: inline` avec Content-ID, puis un minimum de pixels — 200 par défaut).

**Sans doublon, par contenu** (SHA-256, déjà porté par `InboundAttachment::$contentHash`) : un fichier déjà présent dans les documents de la location n'est pas ajouté une seconde fois, y compris le contrat que le site a généré puis qu'un gestionnaire renvoie à la main. Les autres types restent sur le message, sans document.

Les pièces jointes du journal des envois du site (étape 2) sont déjà des documents de la réservation : elles ne sont pas ajoutées à nouveau.

### Étape 9 — Une référence aléatoire

- Une nouvelle réservation reçoit `LOC-AAAA-XXXXXX` : l'année de la demande, puis **six caractères tirés au hasard** (générateur cryptographique) dans un alphabet sans caractères ambigus (ni `0`/`O`, ni `1`/`I`/`L`), pour qu'elle se dicte au téléphone. Unicité garantie par l'index existant `uniq_rental_bookings_reference`, avec un nouveau tirage en cas de collision.
- Le compteur `rental_reference_sequences` et `claimNextReferenceSequence()` disparaissent (`drops.sql`), avec le commentaire du schéma qui les justifie. Le commentaire de `rental_bookings.reference` est réécrit.
- `BookingReferenceMatcher` reconnaît le nouveau format, sans distinction de casse. Les références déjà attribuées restent valables et reconnues.
- **Rien d'autre ne change** : la communication structurée des virements, les numéros de facture (qui doivent se suivre) et les numéros de version des documents gardent leur propre numérotation.
- Aucun texte ne présente la référence comme une sécurité : elle se devine moins facilement, rien de plus.

## 5. Les points de vigilance

- **Aucune écriture IMAP.** Le dossier « Envoyés » est lu comme les autres dossiers, et seul ce qui est rapproché est stocké.
- **Les autres consommateurs du courrier** ne changent pas de comportement : Camps et Finance ne reçoivent jamais de message envoyé, et la configuration dédiée/partagée fonctionne comme avant.
- **Le rapprochement par les destinataires** suit la même prudence que par l'expéditeur : un seul cas plausible donne un rattachement, une ambiguïté est tranchée par l'IA, ou ignorée.
- **Tests exigés**, dans le même changement :
  - page présente avec boîte dédiée, partagée et sans boîte ;
  - « Détacher » : documents retirés, adresse apprise retirée, message plus jamais rattaché à cette réservation ;
  - journal : un test par type d'envoi, texte chiffré, lien de suivi masqué, échec enregistré, « Renvoyer » avec un lien valide ;
  - sens d'un message selon le dossier ; dossier `\Sent` trouvé seul ; message envoyé non rapproché non stocké ; Camps et Finance jamais interrogés sur un message envoyé ;
  - rapprochement d'un message envoyé : réservation unique, ambiguïté, en-têtes de fil, copie d'un envoi du site non doublée ;
  - autres adresses : ajout, retrait, apprentissage par l'IA ;
  - IA présente, absente, en erreur, avec un identifiant hors liste, sur une référence citée par une adresse inconnue ;
  - notification et pastille de non-lus ;
  - pièces jointes reçues et envoyées : PDF, DOCX, image assez grande, logo, doublon par contenu ;
  - référence : format, alphabet, collision, reconnaissance de l'ancien et du nouveau format.

  Tout nouveau répertoire de tests PHP est déclaré dans `phpunit.xml`.
- **Qualité** : `vendor/bin/phpstan analyse` avant chaque commit PHP, `npm run typecheck` avant un commit qui touche `public/assets/js/`, PHPUnit en local avant chaque poussée. Code en anglais, interface en français.
- **Précisions** : l'agent qui implémentera ce chantier **relit le code du #708 tel qu'il a été fusionné** avant de commencer, et pose au mainteneur toute question utile sur l'écran, les notifications et la synchronisation.

## 6. Ce qui a été écarté

- **Écrire les e-mails envoyés dans le dossier « Envoyés » par IMAP (`APPEND`).** Il casse la garantie d'architecture du contrat de lecture, risque des doublons avec les fournisseurs qui rangent déjà les envois du site, dépend du nom du dossier et des droits d'écriture, et ne règle pas l'affichage. Le journal en base est indépendant du fournisseur, immédiat, et fonctionne sans boîte configurée.
- **Lire « Envoyés » sans modifier l'API**, en comparant l'expéditeur aux adresses des boîtes : fragile (alias, noms d'expéditeur), et exposerait Camps et Finance à des messages dont l'expéditeur est l'unité.
- **Rédiger les e-mails au locataire depuis le site.** Il rendrait visibles les e-mails aujourd'hui écrits depuis une adresse personnelle, mais n'est pas retenu : seul le dossier « Envoyés » de la boîte de l'unité est lu.
- **Un écran « Courrier à traiter » et le rattachement manuel.** Les messages non rapprochés ne sont ni montrés ni traités à la main : l'IA tranche, ou on ne fait rien.
- **Un journal sans le texte des e-mails.** Le texte est gardé, chiffré, pour que chaque message de la page se relise de la même façon.
- **Reprise des e-mails passés dans le journal, et des références déjà attribuées.** Écartée : le site est en test.

## 7. À la clôture

L'agent qui implémentera ce chantier clôture l'issue [#720](https://github.com/xdubois-57/scoutmagic/issues/720) lui-même, avec un commentaire qui liste les PR, une fois toutes fusionnées sur `main`.
