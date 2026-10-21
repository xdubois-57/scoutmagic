# Chantier — Envois groupés : une remise par adresse, un historique par animé, une notification par compte (#999)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§8.61,
§8.62, §8.71 à §8.71decies, §8.96), `SECURITY.md` (§4, §5), `design.md` (§7)
et `specifications.md` (§8, §24), qui restent la source de vérité des règles.
Il ne les recopie pas. Il suppose le chantier #729 livré
(`CHANTIER-listes-de-diffusion-blocs.md` : listes en blocs, groupes de membres
et groupes de contacts).

Maquette qui fait foi : `docs/chantiers/maquettes/envois-groupes.html` (six
écrans à 390 px). Chaque zone y est étiquetée **nouveau / modifié /
inchangé** ; ce qui n'est pas dessiné ne change pas.

L'issue #999 ne contient que des spécifications fonctionnelles. L'analyse
ci-dessous a été menée contre le code, et les points ambigus ont été tranchés
avec le mainteneur le 21/10/2026 (§2.8). Là où le texte de l'issue diffère de
ce document, **ce document l'emporte**.

---

## 0. Règles de conduite

- **Autonomie complète.** Enchaîner les lots sans demander de validation. Ne
  poser une question au mainteneur (commentaire sur #999) que pour un vrai
  changement de design ou une ambiguïté fonctionnelle que ce document et la
  maquette ne tranchent pas. Une décision du §2 ne se rediscute pas.
- **Un lot = une branche = une PR.** Rebaser sur `main` avant la fusion. Suite
  complète (PHPUnit sur MariaDB, Vitest, PHPStan, `npm run typecheck`,
  Playwright si une page change). **Fusionner et pousser sur `main` dès que tout
  est vert, sans demander de confirmation.** Jamais de fusion rouge, jamais de
  baseline élargie.
- **Lots d'une même vague : les lancer réellement en parallèle** (un agent et
  une branche par lot). Le second à fusionner rebase sur le premier.
- **Documentation dans le même lot** que le code qu'elle décrit. Le lot D
  consolide, il ne rattrape pas.
- **Journal** : tenir à jour le §6 (une ligne par lot fusionné).
- **Le site est en test** : schéma modifié directement dans
  `modules/mass_mail/schema.sql`, table remplacée retirée par `DROP TABLE` dans
  `modules/mass_mail/drops.sql`. **Aucune reprise** des envois existants : leur
  suivi et leur historique disparaissent avec l'ancienne table. Aucun texte
  d'interface ne parle d'un état antérieur.
- Code en anglais, interface en français. Tout bouton qui agit immédiatement
  passe par une confirmation qui dit la conséquence **et si un e-mail part ou
  non**.

---

## 1. État des lieux (vérifié dans le code au commit `b48bbf8`)

**Une seule table pour trois notions.** `mass_mail_recipients` porte une ligne
par couple (animé, adresse) — « deliberately NOT unique per email_id »
(`schema.sql` L301-316). Chaque ligne est un message SMTP à une seule adresse
(`SendBatchHandler` L203, `MailService::send(string $to, …)` L183), porte son
propre jeton de désinscription (L186-200), alimente l'historique de la fiche et
déclenche la notification. L'envoi, l'historique et la notification ne
peuvent donc pas être comptés séparément.

**Liste classique** (`MassMailService::freezeListRecipients()` L929,
`MailingListService::resolveMembersForYears()` L637).
- Le dédoublonnage porte sur l'**adresse Desk, au niveau de l'animé**
  (`resolveMembersForYears` L697-708, `deduplicateByMemberAndAddress` L772-797) :
  si Alice et Bob partagent l'adresse Desk familiale, **Bob est retiré
  entièrement** — pas de message, pas d'historique, pas de notification.
- Chaque animé gardé s'étend ensuite à toutes ses adresses valides
  (`MemberEmailService::resolveValidAddressesForMassMail()` L403 : adresse Desk
  si sa ligne `desk` est valide, adresses secondaires `manual` confirmées, moins
  les adresses bloquées après rebonds), **sans dédoublonnage entre animés** : une
  adresse secondaire familiale présente sur deux fiches reçoit deux messages.
- `mass_mail_suppressed_addresses` n'est pas lue pour les adresses d'animés,
  seulement pour les contacts et le publipostage.

**Publipostage** (`freezeMergeRecipients()` L1136-1278,
`AudienceImportService`).
- Une ligne avec Tiers produit **un message par adresse** de l'animé ; une
  ligne sans Tiers, un message par adresse de la cellule Email (séparateur `;`
  au gel, `;` ou `,` à l'import).
- Avec un Tiers, la colonne Email est ignorée (L104-111) ; un Tiers inconnu
  refuse le fichier (L107, L143-145). Conforme au ticket.
- La recherche du Tiers (`MemberResolutionRepository::findMemberIdsByDeskIds()`
  L395-411) ignore `member_desk_id_aliases` et `merged_into_member_id` ;
  `Core\Import\MemberRepository::findByDeskId()` les gère.
- Les autres modules passent par `MergeDraftService` /
  `Api\MassMailDraftInterface::createMergeDraft()` avec des lignes
  `{email, values}` sans animé ; le service **fusionne les lignes de même
  adresse** (L137-155) et perd les valeurs de la seconde. Appelants : Actualités
  (une ligne par réponse), Finances (`CampaignReminderService` : une ligne par
  adresse familiale, jusqu'à 6 enfants en blocs `{{#Prénom N}}`), Encadrement
  (une ligne par animateur).

**Historique** (`RecipientRepository::findRecentSentForMember()` L239,
`findSentDetailForMember()` L288, via `Api\MassMailQueryInterface`).
`WHERE member_id = ? AND status = 'sent'`, sans regroupement par e-mail : un
animé à 3 adresses voit 3 fois la même communication, et la limite de 10 compte
des lignes. Le détail (`GET /members/{id}/emails/{recipient_id}`,
`MemberEmailController`) contrôle `MemberService::canAccess()` puis
l'appartenance de la ligne à l'animé. Une adresse secondaire confirmée
**après** l'envoi voit déjà tout l'historique, sans renvoi ni notification :
conforme.

**Notification** `mass_mail.email_received` : une par ligne envoyée
(`SendBatchHandler::dispatchEmailReceivedNotification()` L492), au compte dont
l'identifiant égale l'adresse de la ligne, lien `RecipientEmailLink::url()`.
Aucun dédoublonnage (`NotificationService::dispatch()`).

**Purge** (`PurgeMergeAudiencesHandler`) : 18 mois après le dernier envoi,
efface les lignes de fichier, met `audience_row_id` à NULL et supprime les
notifications par leur URL exacte.

**Suivi** (`tracking.html.twig`, `getTrackingData()` L1369) : une ligne par
ligne de destinataire ; « Renvoyer » par ligne (`resendToRecipient()` L1331).
Aucun statut de rebond sur la ligne ; aucune relance automatique.

**Observé, hors périmètre.** `MemberEmailService::unsubscribe()` (L493) passe en
`inactive` toutes les lignes `member_emails` de l'adresse : une adresse
secondaire désinscrite perd aussi l'accès aux fiches qu'elle ouvrait. Comportement
existant, inchangé ici ; à reprendre dans un ticket dédié si ce n'est pas voulu.

---

## 2. Décisions verrouillées

### 2.1 Trois notions, trois tables

`mass_mail_recipients` est **remplacée** (`DROP TABLE` dans `drops.sql`) par :

| Table | Une ligne = | Sert à |
|---|---|---|
| `mass_mail_deliveries` | **un message** réellement expédié | envoi, suivi, renvoi, désinscription |
| `mass_mail_delivery_addresses` | une adresse dans le « À » d'un message | qui a reçu, refus par adresse, désinscription |
| `mass_mail_targets` | **un animé ciblé** par une communication (liste) ou par une ligne du fichier | historique, notification |
| `mass_mail_delivery_targets` | le lien message ↔ animé ciblé | « Non envoyée », suivi, « concerne aussi » |

- `mass_mail_deliveries` : `id`, `email_id` (CASCADE), `audience_row_id` (NULL,
  SET NULL à la purge), `status` (`pending` / `sent` / `error`),
  `error_message`, `sent_at`, `attempts`, `unsubscribe_token_hash`,
  `created_at` ; index `(status, id)` et `(email_id)` comme aujourd'hui.
- `mass_mail_delivery_addresses` : `id`, `delivery_id` (CASCADE),
  `email_address_encrypted` (chiffrée, déchiffrée uniquement dans son
  repository — SECURITY §5), `email_blind_index` (unique avec `delivery_id`),
  `member_email_id` (NULL), `refused_at` / `refused_reason` (refus du relais pour
  cette adresse seule), `unsubscribed_at`.
- `mass_mail_targets` : `id`, `email_id` (CASCADE), `member_id`,
  `scout_year_id`, `audience_row_id` (NULL ; SET NULL à la purge) ; unique
  `(email_id, member_id)` **pour une liste** ; pour le publipostage, une ligne
  par ligne du fichier (deux lignes du même Tiers = deux cibles) — l'unicité se
  vérifie dans le service, la contrainte SQL ne pouvant pas exprimer « unique si
  `audience_row_id` est NULL ».
- `mass_mail_delivery_targets` : `(delivery_id, target_id)`, clé primaire.

### 2.2 Liste classique

- **Cibles** : chaque animé que la liste désigne pour les années choisies. Le
  dédoublonnage par adresse Desk **au niveau de l'animé disparaît** : Alice et
  Bob partageant une adresse sont tous les deux ciblés. Un animé désigné par
  plusieurs critères, groupes ou années est une seule cible ; son
  `scout_year_id` est l'année la plus récente qui l'a désigné (règle actuelle).
- **Adresses éligibles d'un animé** : celles de
  `resolveValidAddressesForMassMail()` (Desk valide + secondaires confirmées,
  moins les rebonds bloqués), **moins** les adresses présentes dans
  `mass_mail_suppressed_addresses`.
- **Messages** : **un message par adresse distincte** (comparée en minuscules)
  parmi toutes les cibles et tous les groupes de contacts de la liste. Un
  message est lié à toutes les cibles dont l'adresse fait partie ; un message
  dont l'adresse ne vient que d'un groupe de contacts n'est lié à aucune cible.
- **Partager une adresse ne rend pas destinataire.** Une adresse de Bob atteinte
  parce qu'elle est aussi celle d'Alice ne crée pas de cible Bob si Bob n'est
  pas désigné par la liste.
- **Cible sans adresse éligible** : la cible existe, sans message ; elle est
  « Non envoyée » sur la fiche et visible dans le suivi.
- Listes concernées : toutes les listes par défaut, les listes personnalisées
  (groupes de membres + groupes de contacts), la liste externe d'Inscriptions,
  « Anciens ». Pour « Anciens », l'année de chaque cible reste sa dernière année
  active (règle existante).

### 2.3 Publipostage

- **Une ligne du fichier = une cible au plus = un message**, toutes ses
  adresses dans le « À ». Les adresses sont dédoublonnées **dans la ligne**,
  jamais entre lignes : deux lignes vers la même adresse font deux messages.
- **Ligne avec Tiers reconnu** : adresses = adresses éligibles de l'animé
  (§2.2) ; une cible pour cet animé, liée au message. La colonne Email de la
  ligne est **ignorée** et l'import l'annonce en avertissement (maquette,
  écran 6) ; elle reste utilisable comme variable.
- **Recherche du Tiers** par `Core\Import\MemberRepository::findByDeskId()` (ou
  une variante groupée de même règle) : un ancien identifiant (alias) ou celui
  d'un membre fusionné mène à l'animé actuel. Un Tiers inconnu refuse toujours
  le fichier.
- **Ligne sans Tiers** : adresses de la cellule Email (`;` ou `,`), moins les
  adresses supprimées et bloquées ; **aucune cible**, donc ni historique ni
  notification, même si une adresse appartient à un animé ou à un compte.
- Une ligne qui n'a plus aucune adresse donne un message en `error`
  (« Aucune adresse valide »), comme aujourd'hui.
- **Plusieurs adresses visibles entre elles** : accepté pour le publipostage.
  Une ligne avec Tiers ne réunit que les adresses d'un même animé ; une ligne
  sans Tiers réunit ce que le chef a écrit dans sa cellule. L'aide
  `publipostage.md` le dit.
- `MassMailDraftInterface::createMergeDraft()` : chaque ligne accepte un
  `member_id` facultatif, qui a exactement la sémantique d'un Tiers (adresses de
  l'animé, `email` ignoré). `MergeDraftService` **ne fusionne plus** les lignes
  de même adresse.

### 2.4 Envoi d'un message à plusieurs adresses

- `Core\Mail\MailService::send()` accepte une liste d'adresses en « À » (une
  chaîne reste acceptée). La garde de suppression et de rebonds
  (`vouchesForRecipient`) s'applique **à chaque adresse** ; une adresse refusée
  par la garde est retirée du « À » et marquée `refused_at` ; si aucune ne reste,
  le message est en `error`.
- **Refus partiel du relais** : si le relais refuse certaines adresses (`RCPT`)
  et accepte les autres, le message est `sent`, les adresses refusées sont
  marquées `refused_at` / `refused_reason`. **Ne jamais traiter un refus partiel
  comme un échec du message** : un « Renvoyer » renverrait aux adresses qui l'ont
  déjà reçu. PHPMailer lève une exception **après** avoir envoyé `DATA` aux
  adresses acceptées : le vérifier dans la version utilisée (7.1.1) et le
  couvrir par un test avec un faux relais. Voir aussi #916 (garde sur le
  premier essai).
- Rebonds asynchrones : `BounceStateRepository::recordSend()` est appelé pour
  chaque adresse acceptée.
- « Renvoyer » (`POST /mass-mail/recipients/{id}/resend`, renommé
  `/mass-mail/deliveries/{id}/resend`) renvoie le message entier à ses adresses
  non refusées.

### 2.5 Désinscription

- Un jeton par **message** (`mass_mail_deliveries.unsubscribe_token_hash`),
  régénéré à chaque envoi comme aujourd'hui (`Core\Security\CapabilityToken`).
- **Message à une adresse** : page et en-têtes actuels inchangés, y compris
  `List-Unsubscribe-Post` (RFC 8058, un clic).
- **Message à plusieurs adresses** : `List-Unsubscribe` pointe vers la page,
  **sans** `List-Unsubscribe-Post`. `GET` affiche la page à cases (maquette,
  écran 4) ; `POST` reçoit les adresses cochées (identifiants des lignes de
  `mass_mail_delivery_addresses`, jamais des adresses en clair) et applique à
  **chacune** les effets actuels : `mass_mail_suppressed_addresses`,
  `ListBlockService::unsubscribeEverywhere()`, et
  `MemberEmailService::unsubscribe()` quand la ligne porte un
  `member_email_id`. `POST` sans case cochée : refusé. Idempotent.
- SECURITY.md §4 : décrire la variante à cases du point d'entrée public.

### 2.6 Historique de la fiche

- Une entrée = une cible (`mass_mail_targets`) de l'animé dont la communication
  est en `sending` ou `sent` :
  - au moins un message lié est `sent` → entrée normale ;
  - tous les messages liés sont terminés et aucun n'est `sent`, ou la cible n'a
    aucun message → entrée **« Non envoyée »** (maquette, écran 1), sans
    notification ;
  - sinon (encore en attente) → pas encore affichée.
- « Communications récentes » : les 10 dernières **entrées**, triées par date
  d'envoi de la communication (date du premier message `sent`, à défaut date de
  lancement). Liste classique : une entrée par communication. Publipostage avec
  Tiers : une entrée par ligne du fichier.
- Détail : `GET /members/{id}/emails/{target_id}` (le paramètre change de nom et
  de sens). Contrôles : `MemberService::canAccess()` sur l'animé, puis la cible
  appartient à cet animé. Le contenu est rendu comme aujourd'hui (gabarit, ou
  personnalisation relue depuis la ligne du fichier, ou « personnalisation
  effacée »). Pour une entrée « Non envoyée », un bandeau le dit.
- **« Concerne aussi »** (maquette, écran 2), liste classique seulement : les
  autres cibles de la **même communication** dont l'animé passe `canAccess()`
  pour la personne connectée, avec un lien vers leur entrée. Aucun autre animé
  n'est nommé. En publipostage, une ligne n'a qu'une cible : le bloc n'apparaît
  pas.
- Accès des adresses ajoutées plus tard : inchangé (déjà conforme).

### 2.7 Notification « Nouvel email »

- **Au plus une par compte et par communication (liste) ou par ligne
  (publipostage).** Compte concerné = compte dont l'identifiant égale une
  adresse **acceptée** d'un message `sent` lié à au moins une cible.
- Mémorisée dans `mass_mail_notified_accounts` (`email_id`, `audience_row_id`
  NULL, `user_account_id`, unique sur les trois — NULL traité comme 0 par une
  colonne générée ou par le service), écrite dans la même transaction que la
  notification : un renvoi ou un second message ne renotifie pas.
- Lien : l'entrée (§2.6) d'une cible liée au message qui a atteint ce compte et
  que ce compte peut voir (`canAccess()`), la première par nom d'animé. Les
  autres sont atteintes par « concerne aussi ».
- Corps : l'objet tel qu'envoyé (personnalisé en publipostage), règle actuelle.
- Purge : `PurgeMergeAudiencesHandler` reconstruit les URL des cibles de
  l'audience purgée (le lien change de forme, §2.6) et supprime les
  notifications correspondantes, comme aujourd'hui.
- Aucune notification rétroactive pour une adresse ajoutée après l'envoi
  (inchangé : la notification n'est émise qu'à l'envoi).

### 2.8 Décisions prises avec le mainteneur (21/10/2026)

1. Désinscription d'un message à plusieurs adresses : **page à cases**.
2. Bouton « un clic » des messageries sur un tel message : **ouvre la page**,
   sans désinscrire.
3. Tiers et Email sur une même ligne : **Email ignoré**, avertissement à
   l'import.
4. Échec total pour un animé : **affiché « Non envoyée »** sur sa fiche.
5. Ligne sans Tiers : **aucune notification**, aucune fiche.
6. Rappels Finances : **une ligne par enfant (créance), avec son Tiers**, au
   lieu d'une ligne par adresse familiale regroupant les enfants. Encadrement :
   une ligne par animateur, avec son Tiers. Actualités : inchangé (réponses sans
   Tiers).
7. Réponses à un formulaire d'actualité : **un e-mail par réponse**, plus de
   fusion par adresse.

### 2.9 Écarté

- Reprise des envois passés (site en test).
- Notification ou page « mes e-mails » pour une ligne sans Tiers.
- Copie cachée (`Bcc`) ou un message par adresse en publipostage.
- Relance automatique des messages en erreur.
- Le sort de l'accès aux fiches après désinscription d'une adresse secondaire
  (§1, observé).
- Fusion de membres : l'historique d'un membre absorbé reste sur l'ancien
  identifiant (`MemberMergeRepository` ne déplace pas les tables des modules) —
  voir #914.

---

## 3. Lots et ordre d'exécution

Trois vagues, **une PR par lot**. Les lots d'une vague tournent **en
parallèle** — les lancer en même temps.

```
Vague 1 :  [A Envoi à plusieurs adresses (cœur)]   [C Fichier et brouillons des modules]
                        │                              │            │
Vague 2 :  [B Messages, cibles, historique, notifications ◄─ A, C]   [E Finances et Encadrement ◄─ C]
                        └───────────────────┬───────────────────────┘
Vague 3 :                    [D Bout en bout, aide, docs, clôture]
```

**Propriété des fichiers.** A ne touche qu'au cœur (`core/Mail/*`). C possède
`AudienceImportService`, `MergeDraftService`, `Api\MassMailDraftInterface` et
la recherche du Tiers. B possède tout le reste de `modules/mass_mail` et le
cœur de la fiche (`MemberPageService`, `members/show.html.twig`,
`members/email_detail.html.twig`). E ne touche qu'à `modules/finance` et
`modules/leadership`.

**Entre C et B**, `MergeDraftService` peut produire des lignes avec `member_id`
que l'ancien gel traite comme des lignes avec Tiers (`audience_rows.member_id`
existe déjà) : rien ne casse. **Entre A et B**, rien n'appelle encore la forme à
plusieurs adresses.

### Lot A — Envoi à plusieurs adresses (vague 1)

- `MailService::send()` : « À » en liste ; garde de suppression et de rebonds
  par adresse ; résultat par adresse (acceptée / refusée par la garde / refusée
  par le relais) rendu à l'appelant ; refus partiel du relais = envoi réussi
  avec adresses refusées (§2.4). Les appelants actuels (une adresse) ne
  changent pas de comportement.
- Tests : faux relais qui refuse une adresse sur deux ; toutes refusées ; garde
  qui retire une adresse supprimée ; domaine internationalisé (lien avec #916,
  sans le corriger ici).
- ARCHITECTURE.md : la section du `MailService` décrit la liste et le résultat
  par adresse.

### Lot B — Messages, cibles, historique, notifications (vague 2, après A et C)

- Schéma du §2.1, `drops.sql` (`mass_mail_recipients`), version de
  `module.json` montée.
- Gel liste classique (§2.2) et publipostage (§2.3) ; « Voir les destinataires »
  de #729 et `estimateRecipientCount()` comptent des **messages** (adresses
  distinctes) et nomment le nombre d'animés ciblés.
- `SendBatchHandler` : un message par `mass_mail_deliveries`, « À » multiple,
  jeton par message, en-têtes de désinscription selon §2.5, notification selon
  §2.7, fin d'envoi quand plus aucun message n'est `pending`.
- Suivi (maquette, écran 3), « Renvoyer » par message, page de désinscription
  (écran 4), aperçu du publipostage (écran 5).
- Historique et détail (écrans 1 et 2) via `Api\MassMailQueryInterface` (les
  méthodes gardent leur rôle, la forme des entrées change), `MemberPageService`.
- Purge : `PurgeMergeAudiencesHandler`, `RecipientEmailLink` (renommé selon le
  nouveau sens), `AudienceRepository::deleteById()`.
- `RgpdContentService` (règle 10bis, point c) : décrire messages, adresses et
  cibles au lieu des « destinataires ».
- Tests : tous les scénarios du §5 du ticket, plus : deux groupes de membres
  désignant le même animé ; contact égal à l'adresse d'un animé ciblé et d'un
  animé non ciblé ; « Anciens » ; liste externe d'Inscriptions ; plusieurs
  années ; adresse supprimée exclue ; refus partiel ; renvoi sans renotification ;
  désinscription à cases (une, deux, aucune, jeton invalide) ; en-tête
  `List-Unsubscribe-Post` présent pour une adresse et absent pour plusieurs ;
  « concerne aussi » qui ne nomme pas un animé inaccessible ; purge qui
  supprime les notifications des cibles purgées. Vitest pour le suivi et la page
  de désinscription si du JavaScript change.
- Aide : `envoi-de-mails.md`, `publipostage.md`, `se-desinscrire.md`,
  `docs/help/page-membre.md`. ARCHITECTURE.md §8.61, §8.71, §8.71octies, §8.96 ;
  SECURITY.md §4.

### Lot C — Fichier et brouillons des modules (vague 1)

- Recherche du Tiers par alias et membre fusionné (§2.3).
- Avertissements d'import : Email ignoré à côté d'un Tiers ; adresse en double
  dans une cellule (maquette, écran 6).
- `MassMailDraftInterface::createMergeDraft()` : `member_id` facultatif par
  ligne ; `MergeDraftService` écrit `audience_rows.member_id` quand il est donné
  et ne fusionne plus les lignes de même adresse.
- Tests : `AudienceImportServiceTest` (alias, fusionné, avertissements),
  `MergeDraftServiceTest` (deux réponses de même adresse = deux lignes ; ligne
  avec `member_id`).
- Aide Actualités (`reponses-au-formulaire.md`, `suivi-apres-un-evenement.md`)
  et ARCHITECTURE.md §8.71 : « deux réponses d'une même famille sont deux
  e-mails ».

### Lot E — Finances et Encadrement (vague 2, après C)

- `CampaignReminderService` : **une ligne par créance**, avec le `member_id` de
  l'animé ; un seul bloc de paiement par message (prénom, montant,
  communication, QR) ; plus de blocs `{{#Prénom N}}` ni de `MAX_BLOCKS`. La
  règle « un virement par enfant » est tenue par construction. Le marquage
  « familles notifiées » reste un geste explicite, inchangé.
- `LeadershipController::draft()` : `member_id` de l'animateur sur chaque ligne.
- Tests : `CampaignReminderServiceTest` (une ligne par créance, deux enfants
  d'une famille = deux lignes, gabarit sans sections) ; test du brouillon
  Encadrement.
- Aide : `modules/finance/help/rappels.md` (« Un bloc par enfant » devient « un
  e-mail par enfant ») et `modules/leadership/help/encadrement.md`.
  ARCHITECTURE.md, section des rappels de campagne.

### Lot D — Bout en bout, aide et clôture (vague 3)

- Playwright (`tests/e2e/specs/mass-mail.spec.js` et `mass-mail-merge.spec.js`,
  ou une spec nouvelle) : liste avec deux animés partageant une adresse → 3
  messages capturés par le bac à sable, une entrée sur chaque fiche, une
  notification pour le compte de l'adresse commune ; publipostage d'une ligne à
  deux adresses → un message capturé avec deux adresses en « À », page de
  désinscription à cases.
- Jeu de données de référence : une fratrie partageant une adresse familiale et
  une adresse secondaire, pour que l'instance de test montre ces cas.
- Relecture de `specifications.md` §8 et §24, d'`ARCHITECTURE.md` (§8.61 à
  §8.71decies, §8.96) et des sujets d'aide touchés par B, C et E.
- Ligne de la maquette dans `docs/chantiers/maquettes/README.md` passée à
  « livré ».
- **Commenter #999** avec ce qui a été livré (lots, PR, écarts), **puis la
  clôturer**.

---

## 4. Pièges

- Le dédoublonnage se fait **sur l'adresse pour les messages** et **sur l'animé
  pour les cibles** : ne jamais déduire une cible d'une adresse, ni retirer une
  cible parce que son adresse est déjà prise.
- Pas d'`ORDER BY` ni de comparaison SQL sur une adresse chiffrée : comparer en
  PHP sur l'adresse normalisée, ou sur l'index aveugle.
- Un refus partiel du relais n'est pas un échec (§2.4).
- `IN ()` est refusé par MySQL et MariaDB mais toléré par SQLite : garder chaque
  liste d'identifiants vide avant la requête.
- La notification et la purge se retrouvent par l'**URL exacte** : toute
  construction du lien passe par une seule fonction partagée.
- Le jeton de désinscription ne dit jamais quelles adresses existent à qui ne
  le présente pas : un jeton absent ou faux donne la réponse « lien plus
  valide », la même dans les deux cas.
- `UxConventionsTest` : confirmations (« Renvoyer » renvoie un e-mail), icônes
  du jeu vendu, pas de script dans les gabarits.
- Jamais de lecture d'une table `mass_mail_*` hors du module ; le cœur passe par
  `Api\MassMailQueryInterface`.

---

## 5. Critères d'acceptation

1. Tous les scénarios du tableau §5 de l'issue #999 sont couverts par des tests
   et passent, avec les précisions du §2.8.
2. Deux animés ciblés partageant une adresse : un message à cette adresse, une
   entrée sur chacune des deux fiches.
3. Un animé non ciblé n'a aucune entrée, même s'il partage une adresse avec un
   animé ciblé.
4. Une ligne de publipostage = un message, toutes ses adresses en « À » ; deux
   lignes = deux messages, même vers les mêmes adresses.
5. Au plus une notification par compte et par communication (liste) ou par
   ligne (publipostage), jamais rétroactive.
6. Un animé vers qui rien n'est parti voit l'entrée « Non envoyée », sans
   notification.
7. Un message à plusieurs adresses ne se désinscrit jamais en un clic ; la page
   à cases désinscrit exactement les adresses cochées.
8. Rappels Finances et brouillons Encadrement apparaissent sur la fiche de
   l'animé concerné ; deux réponses d'actualité de même adresse donnent deux
   e-mails.
9. Aide et documentation à jour ; suites vertes ; #999 commentée puis clôturée.

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| | | | |
