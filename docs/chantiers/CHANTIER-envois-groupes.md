# Chantier — Envois groupés : un message par adresse, un historique par animé, une notification par compte (#999)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§8.61,
§8.62, §8.71 à §8.71decies, §8.96), `SECURITY.md` (§4, §5), `design.md` (§7)
et `specifications.md` (§8, §24), qui restent la source de vérité des règles.
Il ne les recopie pas. Il suppose le chantier #729 livré
(`CHANTIER-listes-de-diffusion-blocs.md` : listes en blocs, groupes de membres
et groupes de contacts).

Maquette qui fait foi : `docs/chantiers/maquettes/envois-groupes.html` (cinq
écrans à 390 px). Chaque zone y est étiquetée **nouveau / modifié /
inchangé** ; ce qui n'est pas dessiné ne change pas.

L'issue #999 décrivait le comportement attendu. Elle a été analysée contre le
code, ses points ambigus ont été tranchés avec le mainteneur le 21/10/2026
(§2.7), et ses spécifications ont été mises à jour en conséquence. Là où le
texte de l'issue diffère de ce document, **ce document l'emporte**.

**Chantier terminé.** Les lots A, B, C et D sont livrés (§6) : B en deux
parties, B1 seul et B2 avec D dans la PR qui corrige #999.

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

**Une seule table pour trois notions.** `mass_mail_recipients` (`schema.sql`
L317-371) porte une ligne par couple (animé, adresse) ; son commentaire le dit
(« deliberately NOT unique per email_id », L307). Chaque ligne est un message
SMTP à une seule adresse (`SendBatchHandler` L203,
`Core\Mail\MailService::send(string $to, …)` L183), porte son propre jeton de
désinscription (L186-200), alimente l'historique de la fiche et déclenche la
notification. L'envoi, l'historique et la notification ne peuvent donc pas être
comptés séparément.

**Liste classique** (`MassMailService::freezeListRecipients()` L929,
`MailingListService::resolveMembersForYears()` L637).
- Le dédoublonnage porte sur l'**adresse Desk, au niveau de l'animé**
  (`resolveMembersForYears` L697-708, `deduplicateByMemberAndAddress` L772-797) :
  si Alice et Bob partagent l'adresse Desk familiale, **Bob est retiré
  entièrement** — pas de message, pas d'historique, pas de notification.
- Chaque animé gardé s'étend ensuite à toutes ses adresses valides
  (`Core\Member\MemberEmailService::resolveValidAddressesForMassMail()` L403 :
  adresse Desk si sa ligne `desk` est valide, adresses secondaires `manual`
  confirmées, moins les adresses bloquées après rebonds), **sans dédoublonnage
  entre animés** : une adresse secondaire familiale présente sur deux fiches
  reçoit deux messages.
- `mass_mail_suppressed_addresses` n'est pas lue pour les adresses d'animés,
  seulement pour les contacts et le publipostage.

**Publipostage** (`freezeMergeRecipients()` L1136-1278,
`AudienceImportService`).
- Une ligne avec Tiers produit un message par adresse de l'animé ; une ligne
  sans Tiers, un message par adresse de la cellule Email (séparateur `;` au gel,
  `;` ou `,` à l'import). Aucun dédoublonnage entre lignes. C'est déjà le
  découpage voulu (§2.3) ; ce qui manque, c'est le rattachement par ligne
  (historique, notification).
- Avec un Tiers, la colonne Email est ignorée (L104-111) ; un Tiers inconnu
  refuse le fichier (L107, L143-145).
- La recherche du Tiers (`MemberResolutionRepository::findMemberIdsByDeskIds()`
  L395-411) ignore `member_desk_id_aliases` et `merged_into_member_id` ;
  `Core\Import\MemberRepository::findByDeskId()` (L50) les gère.
- Les autres modules passent par `MergeDraftService` /
  `Api\MassMailDraftInterface::createMergeDraft()` avec des lignes
  `{email, values}` sans animé ; le service **fusionne les lignes de même
  adresse** (L137-155) et perd les valeurs de la seconde. Appelants :
  - Actualités (`FormController` L625, L716-735) : une ligne par réponse ;
  - Finances (`CampaignReminderService`) : une ligne par adresse familiale,
    jusqu'à 6 enfants en blocs `{{#Prénom N}}` ; **au-delà du sixième, les
    enfants suivants sont omis sans le dire** (`continue`, L225) ;
  - Encadrement (`LeadershipController::draft()` L289-322) : une ligne par
    animateur, depuis `PersonLine`, qui ne porte que `memberYearId`.

**Historique** (`RecipientRepository::findRecentSentForMember()` L239,
`findSentDetailForMember()` L288, via `Api\MassMailQueryInterface`).
`WHERE member_id = ? AND status = 'sent'`, sans regroupement par e-mail : un
animé à 3 adresses voit 3 fois la même communication, et la limite de 10 compte
des lignes. Le détail (`GET /members/{id}/emails/{recipient_id}`, où `{id}` est
un **identifiant de `member_years`**, `MemberEmailController` L50-85) contrôle
`MemberService::canAccess(email, memberYearId, …)` (L325) puis l'appartenance de
la ligne à l'animé. Une adresse secondaire confirmée **après** l'envoi voit déjà
tout l'historique, sans renvoi ni notification : conforme.

**Notification** `mass_mail.email_received` : une par ligne envoyée
(`SendBatchHandler::dispatchEmailReceivedNotification()` L492), au compte dont
l'identifiant égale l'adresse de la ligne, lien `RecipientEmailLink::url()` ; pas
de lien quand la ligne `member_years` de l'année n'existe plus (L515-520). Aucun
dédoublonnage (`NotificationService::dispatch()`).

**Purge** (`PurgeMergeAudiencesHandler`) : 18 mois après le dernier envoi,
efface les lignes de fichier, met `audience_row_id` à NULL et supprime les
notifications par leur URL exacte.

**Suivi** (`tracking.html.twig`, `getTrackingData()` L1369) : une ligne par
ligne de destinataire ; « Renvoyer » par ligne (`resendToRecipient()` L1331,
`POST /mass-mail/recipients/{id}/resend`). Aucune relance automatique.

**Désinscription** : jeton par ligne, `GET`/`POST /mass-mail/unsubscribe/{id}`,
en-têtes `List-Unsubscribe` et `List-Unsubscribe-Post` (RFC 8058). Effets :
`mass_mail_suppressed_addresses`, `ListBlockService::unsubscribeEverywhere()`,
et `MemberEmailService::unsubscribe()` (L493) quand la ligne porte un
`member_email_id`.

**Observé, hors périmètre.** `MemberEmailService::unsubscribe()` passe en
`inactive` toutes les lignes `member_emails` de l'adresse : une adresse
secondaire désinscrite perd aussi l'accès aux fiches qu'elle ouvrait.
Comportement existant, inchangé ici.

---

## 2. Décisions verrouillées

### 2.1 Trois notions, quatre tables

`mass_mail_recipients` est **remplacée** (`DROP TABLE` dans `drops.sql`) par :

| Table | Une ligne = | Sert à |
|---|---|---|
| `mass_mail_deliveries` | **un message**, toujours à **une seule adresse** | envoi, suivi, renvoi, désinscription |
| `mass_mail_targets` | **un animé ciblé** par une communication (liste) ou par une ligne du fichier (publipostage) | historique, notification |
| `mass_mail_delivery_targets` | le lien message ↔ animé ciblé | « Non envoyée », suivi, « concerne aussi » |
| `mass_mail_notified_accounts` | un compte déjà notifié pour une communication ou une ligne | une notification au plus |

- `mass_mail_deliveries` : `id`, `email_id` (CASCADE), `audience_row_id` (NULL ;
  SET NULL à la purge), `email_address_encrypted` (chiffrée, déchiffrée
  uniquement dans son repository — SECURITY §5), `email_blind_index`,
  `member_email_id` (NULL), `status` (`pending` / `sent` / `error`),
  `error_message`, `sent_at`, `attempts`, `unsubscribe_token_hash`,
  `created_at`. Index `(status, id)`, `(email_id)`, `(email_blind_index)` ;
  **noms d'index nouveaux** (le comparateur de schéma les apparie par nom —
  AGENTS.md § Database ; ne pas réutiliser `idx_mmr_*`). Le commentaire du
  schéma décrit le jeton tel qu'il est réellement haché
  (`Core\Security\CapabilityToken`, SHA-256), pas `password_hash()` comme
  l'ancien.
- **Gel atomique et unique.** Le gel écrit messages et cibles dans la même
  transaction que le passage `test` → `sending` (règle actuelle,
  `MassMailService::startSending()` L878-888) : un second gel de la même
  communication est impossible, et c'est cette transition qui garantit
  l'unicité. **Ne pas chercher à l'obtenir par un index unique incluant
  `audience_row_id`** sur `mass_mail_deliveries` ou `mass_mail_targets` : un
  index ordinaire ne protège plus rien après la purge (qui met la colonne à
  NULL, et MySQL tient plusieurs NULL pour distincts), et un index sur
  `COALESCE(audience_row_id, 0)` ferait au contraire échouer la purge dès que
  deux lignes du fichier visent la même adresse ou le même Tiers.
- `mass_mail_targets` : `id`, `email_id` (CASCADE), `member_id`,
  `scout_year_id`, `audience_row_id` (NULL ; SET NULL à la purge). Liste : une
  cible par animé et par communication (vérifié par le service). Publipostage :
  une cible par ligne du fichier qui a un Tiers (deux lignes du même Tiers =
  deux cibles).
- `mass_mail_delivery_targets` : `(delivery_id, target_id)`, clé primaire ;
  **`ON DELETE CASCADE` sur les deux clés étrangères**, sans quoi la suppression
  d'un e-mail (et donc de ses messages et cibles) serait bloquée.
- `mass_mail_notified_accounts` : `email_id` (CASCADE), `audience_row_id`
  (NULL, **`ON DELETE CASCADE`** — contrairement aux deux tables ci-dessus),
  `user_account_id` (CASCADE) ; unique sur (`email_id`, `COALESCE(audience_row_id,
  0)`, `user_account_id`) par une colonne générée. La cascade est obligatoire :
  avec `SET NULL`, purger une audience ramènerait à NULL les lignes de plusieurs
  enfants notifiés au même compte et violerait l'index unique, faisant échouer
  la purge. Rien n'est perdu : une audience n'est purgée qu'une fois tous ses
  envois terminés, et ses notifications sont supprimées dans la même passe.
- Toutes les clés étrangères pointent vers des tables du module ou du cœur
  (`members`, `member_emails`, `scout_years`, `user_accounts`) ; aucun autre
  module ne référence ces tables.

### 2.2 Liste classique

- **Cibles** : chaque animé que la liste désigne pour les années choisies. Le
  dédoublonnage par adresse Desk **au niveau de l'animé disparaît** : Alice et
  Bob partageant une adresse sont tous les deux ciblés. Un animé désigné par
  plusieurs critères, groupes ou années est une seule cible ; son
  `scout_year_id` est l'année la plus récente qui l'a désigné (règle actuelle).
- **Adresses éligibles d'un animé** : celles de
  `resolveValidAddressesForMassMail()` (Desk valide + secondaires confirmées,
  moins les rebonds bloqués), **moins** les adresses présentes dans
  `mass_mail_suppressed_addresses`. Ce filtre se fait au gel, dans le module :
  la garde du cœur (`vouchesForRecipient`) ne connaît que les rebonds et ne
  peut pas lire une table du module.
- **Messages** : **un message par adresse distincte** (comparée en minuscules)
  parmi toutes les cibles et tous les groupes de contacts de la liste. Un
  message est lié à toutes les cibles dont il porte une adresse ; un message
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

- **Une ligne du fichier = une communication** : au plus une cible, et **un
  message par adresse distincte de la ligne**. Les adresses sont dédoublonnées
  **dans la ligne**, jamais entre lignes : deux lignes vers la même adresse font
  deux messages, chacun avec ses propres valeurs. Aucune adresse n'est jamais
  visible d'une autre.
- **Ligne avec Tiers reconnu** : adresses = adresses éligibles de l'animé
  (§2.2) ; une cible pour cet animé, liée à chacun des messages de la ligne. La
  colonne Email de la ligne est **ignorée**, et l'import le signale en
  avertissement (maquette, écran 5) ; elle reste utilisable comme variable.
- **Recherche du Tiers** par la règle de
  `Core\Import\MemberRepository::findByDeskId()` (une variante groupée est
  bienvenue) : un ancien identifiant (alias) ou celui d'un membre fusionné mène
  à l'animé actuel. Un Tiers inconnu refuse toujours le fichier.
- **Ligne sans Tiers** : adresses de la cellule Email (`;` ou `,`), moins les
  adresses supprimées et bloquées ; **aucune cible**, donc ni historique ni
  notification, même si une adresse appartient à un animé ou à un compte.
- Une ligne qui n'a plus aucune adresse ne produit **aucun message** (un message
  a toujours une adresse) : avec un Tiers, sa cible est « Non envoyée » ; avec ou
  sans Tiers, le suivi montre la ligne du fichier avec « Aucune adresse valide »
  (§2.4).
- `MassMailDraftInterface::createMergeDraft()` : chaque ligne accepte un
  `member_id` facultatif, qui a exactement la sémantique d'un Tiers (adresses de
  l'animé, `email` ignoré). `MergeDraftService` **ne fusionne plus** les lignes
  de même adresse.

### 2.4 Envoi, suivi, renvoi, désinscription

- **Un message = une adresse**, exactement comme aujourd'hui : `MailService`,
  la chaîne de relais, la file différée et la garde des destinataires ne
  changent pas.
- **Suivi** (maquette, écran 3) : une ligne par message (son adresse, les animés
  qu'il concerne, en publipostage le numéro de ligne du fichier), plus une ligne
  par cible restée sans aucun message et, en publipostage, par ligne du fichier
  restée sans aucun message. Les compteurs comptent des messages et nomment le
  nombre d'animés ciblés.
- « Renvoyer » : `POST /mass-mail/deliveries/{id}/resend` (remplace
  `/mass-mail/recipients/{id}/resend`), même règle qu'aujourd'hui, ne renotifie
  personne (§2.6).
- **Désinscription inchangée** pour la personne : un jeton par message, la page
  actuelle, `List-Unsubscribe` et `List-Unsubscribe-Post` (un clic). Les routes
  gardent leur forme (`/mass-mail/unsubscribe/{id}`), `{id}` désignant
  désormais un message. SECURITY.md §4 (liste des exceptions CSRF) : « jeton par
  destinataire » devient « jeton par message ».
- « Voir les destinataires » (#729) et `estimateRecipientCount()` comptent des
  messages (adresses distinctes) et nomment le nombre d'animés ciblés.

### 2.5 Historique de la fiche

- Une entrée = une cible (`mass_mail_targets`) de l'animé dont la communication
  est en `sending` ou `sent` :
  - au moins un message lié est `sent` → entrée normale ;
  - tous les messages liés sont terminés et aucun n'est `sent`, ou la cible n'a
    aucun message → entrée **« Non envoyée »** (maquette, écran 1), sans
    notification ;
  - sinon (encore en attente) → pas encore affichée.
- « Communications récentes » : les 10 dernières **entrées**, triées par date
  d'envoi (premier message `sent` de la cible, à défaut lancement de la
  communication). Liste classique : une entrée par communication. Publipostage
  avec Tiers : une entrée par ligne du fichier.
- Détail : `GET /members/{id}/emails/{target_id}` — `{id}` reste un identifiant
  de `member_years`, le second paramètre change de sens. Contrôles :
  `MemberService::canAccess()` sur ce `member_years`, puis la cible appartient à
  son animé. **Aucune nouvelle sous-route** sous `/members/{id}/emails/…` : le
  cœur y possède déjà `resend`, `delete`, `reactivate`, `bounce-unblock` pour
  les adresses (`MemberEmailAddressController` L88-183).
- Le contenu est rendu comme aujourd'hui (gabarit, ou personnalisation relue
  depuis la ligne du fichier, ou « personnalisation effacée »). Pour une entrée
  « Non envoyée », un bandeau le dit.
- **« Concerne aussi »** (maquette, écran 2), liste classique seulement : les
  autres cibles de la **même communication** dont l'animé passe `canAccess()`
  pour la personne connectée, avec un lien vers leur entrée. Aucun autre animé
  n'est nommé. Le lien d'une cible passe par son `member_years`
  (`member_id`, `scout_year_id`) ; sans cette ligne, l'animé est nommé sans
  lien. En publipostage, une ligne n'a qu'une cible : le bloc n'apparaît pas.
- Accès des adresses ajoutées plus tard : inchangé (déjà conforme).

### 2.6 Notification « Nouvel email »

- **Au plus une par compte et par communication (liste) ou par ligne
  (publipostage).** Compte concerné = compte dont l'identifiant égale l'adresse
  d'un message `sent` lié à au moins une cible.
- `mass_mail_notified_accounts` est écrite dans la même transaction que la
  notification : un second message de la même communication (ou ligne) vers une
  autre adresse du même compte, ou un renvoi, ne renotifie pas.
- Lien : l'entrée (§2.5) d'une cible liée au message qui a atteint ce compte et
  que ce compte peut voir (`canAccess()`), la première par nom d'animé ; les
  autres sont atteintes par « concerne aussi ». Sans `member_years`, pas de lien
  (règle actuelle).
- Texte de la notification : l'objet du message, personnalisé en publipostage
  comme dans l'e-mail envoyé (règle actuelle).
- Purge : `PurgeMergeAudiencesHandler` reconstruit les URL des cibles de
  l'audience purgée et supprime les notifications correspondantes, comme
  aujourd'hui ; une seule fonction construit ces URL.
- Aucune notification rétroactive pour une adresse ajoutée après l'envoi
  (inchangé : la notification n'est émise qu'à l'envoi).

### 2.7 Décisions prises avec le mainteneur (21/10/2026)

1. **Un message par adresse**, publipostage compris. Écarté : un message par
   ligne avec plusieurs adresses en « À ». Raisons : chaque parent verrait
   l'adresse des autres (parents séparés), le « Répondre à tous » et la
   désinscription en un clic ne tiendraient plus, et l'envoi multi-adresses
   aurait demandé de reprendre les trois couches du courrier sortant (chaîne de
   relais, garde des destinataires refusés, file différée) pour un gain de
   quota incertain selon le fournisseur. La vraie économie vient du
   dédoublonnage par adresse des listes classiques (§2.2).
2. Tiers et Email sur une même ligne : **Email ignoré**, avertissement à
   l'import.
3. Échec total pour un animé : **affiché « Non envoyée »** sur sa fiche.
4. Ligne sans Tiers : **aucune notification**, aucune fiche.
5. Rappels Finances : **une ligne par enfant (créance), avec son animé**, au
   lieu d'une ligne par adresse familiale regroupant les enfants. Encadrement :
   une ligne par animateur, avec son animé. Actualités : réponses toujours sans
   animé.
6. Réponses à un formulaire d'actualité : **un e-mail par réponse**, plus de
   fusion par adresse.

### 2.8 Écarté

- Reprise des envois passés (site en test).
- Plusieurs adresses dans un même message, copie cachée (`Bcc`).
- Notification ou page « mes e-mails » pour une ligne sans Tiers.
- Relance automatique des messages en erreur.
- L'accès aux fiches après désinscription d'une adresse secondaire (§1).
- Fusion de membres : l'historique d'un membre absorbé reste sur l'ancien
  identifiant (`MemberMergeRepository` ne déplace pas les tables des modules) —
  voir #914.

---

## 3. Lots et ordre d'exécution

Trois vagues, **une PR par lot**. Les lots d'une vague tournent **en
parallèle** — les lancer en même temps.

```
Vague 1 :  [A Fichier et brouillons des modules]   [B Messages, cibles, historique, notifications]
                        │                                          │
Vague 2 :  [C Finances et Encadrement ◄─ A]                        │
                        └──────────────────┬───────────────────────┘
Vague 3 :                 [D Bout en bout, aide, docs, clôture]
```

**Propriété des fichiers.**
- A possède `AudienceImportService`, `MergeDraftService`,
  `Api\MassMailDraftInterface`, dans `MemberResolutionRepository` la seule
  méthode `findMemberIdsByDeskIds()`, l'aide
  `modules/mass_mail/help/publipostage.md`, et dans ARCHITECTURE.md §8.71 ainsi
  que, dans §8.61, le seul paragraphe sur la reconnaissance des en-têtes et la
  recherche du Tiers.
- B possède tout le reste de `modules/mass_mail` (aide comprise, sauf
  `publipostage.md`), le reste d'ARCHITECTURE.md §8.61, §8.71octies et §8.96,
  SECURITY.md §4, et, dans le cœur, la fiche
  (`MemberPageService`, `members/show.html.twig`,
  `members/email_detail.html.twig`), plus le commentaire de
  `modules/registration/src/Service/ExternalMailingListService.php` (L30) qui
  cite `mass_mail_recipients`.
- C ne touche qu'à `modules/finance` et `modules/leadership`.

**A et B sont indépendants** : le gel de B lit déjà `audience_rows.member_id`,
que A remplit désormais aussi pour les brouillons des modules. Rien ne casse
dans un ordre ou dans l'autre.

### Lot A — Fichier et brouillons des modules (vague 1)

- Recherche du Tiers par alias et membre fusionné (§2.3).
- Avertissements d'import : Email ignoré à côté d'un Tiers ; adresse en double
  dans une cellule (maquette, écran 5).
- `MassMailDraftInterface::createMergeDraft()` : `member_id` facultatif par
  ligne (phpdoc de la forme des lignes) ; `MergeDraftService` écrit
  `audience_rows.member_id` quand il est donné et ne fusionne plus les lignes de
  même adresse. Les doublures de test qui implémentent l'interface
  (`CampaignReminderServiceTest` L421, `LeadershipRbacTest` L830) suivent la
  nouvelle forme.
- Tests : `AudienceImportServiceTest` (alias, membre fusionné, avertissements,
  fichier refusé en entier quand une ligne porte un Tiers inconnu à côté d'une
  ligne valide),
  `MergeDraftServiceTest` (deux réponses de même adresse = deux lignes ; ligne
  avec `member_id`).
- Aide Actualités (`modules/news/help/reponses-au-formulaire.md`,
  `suivi-apres-un-evenement.md`) et ARCHITECTURE.md §8.71 : « deux réponses
  d'une même famille sont deux e-mails » ; `modules/mass_mail/help/publipostage.md`
  (Email ignoré à côté d'un Tiers, séparateurs `;` et `,`, un message par
  adresse de la ligne, une entrée par ligne sur la fiche de l'animé pour une
  ligne avec Tiers, aucune pour une ligne sans Tiers).

### Lot B — Messages, cibles, historique, notifications (vague 1)

- Schéma du §2.1, `drops.sql` (`mass_mail_recipients`), version de
  `module.json` montée.
- Gel liste classique (§2.2) et publipostage (§2.3) ; au gel, la cellule Email
  se découpe sur `;` **et** `,`, comme à l'import.
- `SendBatchHandler` : un message par `mass_mail_deliveries` (inchangé côté
  transport), notification selon §2.6, fin d'envoi quand plus aucun message n'est
  `pending`.
- Suivi et « Renvoyer » (§2.4, maquette écran 3), aperçu du publipostage
  (écran 4), désinscription sur la nouvelle table (comportement inchangé).
- Historique et détail (§2.5, écrans 1 et 2) via `Api\MassMailQueryInterface`
  (les méthodes gardent leur rôle, la forme des entrées change),
  `MemberPageService`.
- Purge : `PurgeMergeAudiencesHandler`, `RecipientEmailLink` (renommé selon le
  nouveau sens), `AudienceRepository::deleteById()` et
  `findRecipientEmailLinksForAudiences()`.
- `core/View/RgpdContentService.php` (règle 10bis, point c) : décrire messages
  et cibles au lieu des « destinataires ».
- Tests : chaque ligne du tableau §5 de l'issue #999, plus : deux groupes de
  membres désignant le même animé ; contact égal à l'adresse d'un animé ciblé et
  d'un animé non ciblé ; « Anciens » ; liste externe d'Inscriptions ; plusieurs
  années ; adresse supprimée exclue d'un animé ; renvoi sans renotification ;
  deux adresses d'un même compte dans une communication → une notification ;
  « concerne aussi » qui ne nomme pas un animé inaccessible ; cible sans
  `member_years` (pas de lien) ; purge qui supprime les notifications des cibles
  purgées ; désinscription par message (un clic, page, jeton invalide). Vitest
  pour le suivi si son JavaScript change.
- Aide : `modules/mass_mail/help/envoi-de-mails.md`, `se-desinscrire.md`,
  `docs/help/page-membre.md` (pas `publipostage.md`, qui appartient à A).
  ARCHITECTURE.md §8.61 (hors paragraphe de A), §8.71octies, §8.96 ;
  SECURITY.md §4.

### Lot C — Finances et Encadrement (vague 2, après A)

- `CampaignReminderService` : **une ligne par créance**, avec le `member_id` de
  l'animé ; un seul bloc de paiement par message (prénom, montant,
  communication, QR) ; plus de blocs `{{#Prénom N}}` ni de `MAX_BLOCKS` — plus
  aucun enfant omis. La règle « un virement par enfant » est tenue par
  construction. Le marquage « familles notifiées » reste un geste explicite,
  inchangé.
- `LeadershipController::draft()` : `member_id` de l'animateur sur chaque ligne,
  obtenu depuis `PersonLine::memberYearId` (une lecture groupée des
  `member_years`, pas une requête par ligne).
- Tests : `CampaignReminderServiceTest` (une ligne par créance, deux enfants
  d'une famille = deux lignes, sept enfants = sept lignes, gabarit sans
  sections) ; test du brouillon Encadrement.
- Aide : `modules/finance/help/rappels.md` (« Un bloc par enfant » devient « un
  e-mail par enfant ») et `modules/leadership/help/encadrement.md`.
  ARCHITECTURE.md, section des rappels de campagne.

### Lot D — Bout en bout, aide et clôture (vague 3)

- Playwright (`tests/e2e/specs/mass-mail.spec.js`,
  `mass-mail-merge.spec.js`, ou une spec nouvelle) : liste avec deux animés
  partageant une adresse et ayant chacun la leur → 3 messages capturés par le
  bac à sable, une entrée sur chaque fiche, une notification pour le compte de
  l'adresse commune ; publipostage de deux lignes du même Tiers → deux messages
  par adresse, deux entrées sur la fiche.
- Jeu de données de référence : une fratrie partageant une adresse familiale et
  une adresse secondaire, pour que l'instance de test montre ces cas.
- Relecture de `specifications.md` §8 et §24, d'`ARCHITECTURE.md` (§8.61 à
  §8.71decies, §8.96) et des sujets d'aide touchés par A, B et C.
- Ligne de la maquette dans `docs/chantiers/maquettes/README.md` passée à
  « livré ».
- **Commenter #999** avec ce qui a été livré (lots, PR, écarts), **puis la
  clôturer**.

---

## 4. Pièges

- Le dédoublonnage se fait **sur l'adresse pour les messages** et **sur l'animé
  pour les cibles** : ne jamais déduire une cible d'une adresse, ni retirer une
  cible parce que son adresse est déjà prise.
- En publipostage, ne **jamais** dédoublonner entre lignes : deux lignes vers la
  même adresse portent des valeurs différentes.
- Pas d'`ORDER BY` ni de comparaison SQL sur une adresse chiffrée : comparer en
  PHP sur l'adresse normalisée, ou sur l'index aveugle.
- `IN ()` est refusé par MySQL et MariaDB mais toléré par SQLite : garder chaque
  liste d'identifiants vide avant la requête.
- La notification et la purge se retrouvent par l'**URL exacte** : toute
  construction du lien passe par une seule fonction partagée.
- `{id}` de `/members/{id}/…` est un `member_years`, jamais un `members`.
- `mass_mail_notified_accounts.audience_row_id` est en `ON DELETE CASCADE`, pas
  `SET NULL` comme ailleurs dans le module (§2.1) ; un test de purge avec deux
  lignes d'une même famille notifiées au même compte le vérifie.
- `UxConventionsTest` : confirmations (« Renvoyer » renvoie un e-mail), icônes
  du jeu vendu, pas de script dans les gabarits.
- Jamais de lecture d'une table `mass_mail_*` hors du module ; le cœur passe par
  `Api\MassMailQueryInterface`.

---

## 5. Critères d'acceptation

1. Tous les scénarios du tableau §5 de l'issue #999 (version mise à jour) sont
   couverts par des tests et passent.
2. Deux animés ciblés partageant une adresse : un message à cette adresse, une
   entrée sur chacune des deux fiches.
3. Un animé non ciblé n'a aucune entrée, même s'il partage une adresse avec un
   animé ciblé.
4. Un message part toujours à une seule adresse ; une ligne de publipostage
   **avec Tiers** (ou rattachée à un animé par un module) = une entrée sur la
   fiche, un message par adresse ; deux telles lignes = deux entrées, même vers
   les mêmes adresses ; une ligne sans Tiers = des messages, aucune entrée.
5. Au plus une notification par compte et par communication (liste) ou par
   ligne (publipostage), jamais rétroactive.
6. Un animé vers qui rien n'est parti voit l'entrée « Non envoyée », sans
   notification.
7. Désinscription inchangée pour la personne, un clic compris.
8. Rappels Finances et brouillons Encadrement apparaissent sur la fiche de
   l'animé concerné ; deux réponses d'actualité de même adresse donnent deux
   e-mails.
9. Aide et documentation à jour ; suites vertes ; #999 commentée puis clôturée.

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| A — Fichier et brouillons des modules | #1025 | Tiers retrouvé par alias et membre fusionné (`findMemberIdsByDeskIds()` groupée, insensible à la casse comme la collation) ; avertissements « colonne Email ignorée » et « adresse en double » (écran 5), adresse répétée dans une cellule gardée une fois ; `createMergeDraft()` accepte un `member_id` par ligne et ne fusionne plus les lignes de même adresse ; aide `publipostage.md`, Actualités, ARCHITECTURE §8.61 (import) et §8.71. | La recherche suit aussi une chaîne de fusions jusqu'au membre actuel et un membre fusionné sans alias, au-delà de `findByDeskId()`. `publier-une-actualite.md` corrigée aussi (elle disait « un destinataire » pour deux réponses de même adresse). |
| B1 — Messages, cibles, envoi, suivi, désinscription | #1030 | Schéma §2.1 (`mass_mail_deliveries`, `mass_mail_targets`, `mass_mail_delivery_targets`, `mass_mail_notified_accounts`, `mass_mail_recipients` supprimée) ; gel liste (§2.2) et publipostage (§2.3, `;` et `,`) ; `SendBatchHandler` un message par adresse, une notification par compte et par communication ou ligne ; suivi par message, « Renvoyer » sur `/mass-mail/deliveries/{id}/resend` ; « Voir les destinataires » en messages et animés ciblés ; historique par cible côté module (`Api\MassMailQueryInterface`) ; purge ; désinscription par message ; aide et docs. | `audience_row_key` est une colonne ordinaire écrite par le dépôt, pas une colonne générée : l'outillage de migration n'en connaît pas (comme `rental_booking_modifications.active_booking_id`). Un contact refusé (adresse invalide, désinscrite, suspendue) reste un message en erreur, comme avant. « Non envoyée » sur la fiche et dans le détail est venu dans B1 même, sur une remarque de la revue (sans lui, une entrée sans message disait « Envoyé le ») ; de même la notification d'un compte sur une adresse secondaire confirmée, qui perdait son lien. Écart à §2.2 : `mass_mail_suppressed_addresses` n'est pas lue pour les adresses d'un animé, comme avant le chantier — leur désinscription les passe « Inactif » et « Réactiver » les rend ; lue aussi pour elles, la désinscription devenait sans retour (revue de #1030, suite dans #1032). « Concerne aussi », l'estimation et l'aperçu du publipostage viennent avec B2. |
| B2 — Fiche de l'animé, estimation, aperçu | #PRNUM (avec D) | « Cette communication concerne aussi … » limité aux animés que la personne connectée peut déjà voir (`canAccess`), lien vers leur entrée, sans lien sans `member_years` ; estimation avant l'envoi et onglet « Destinataires » en messages et animés ciblés ; aperçu du publipostage (écran 4) avec les adresses de la ligne, chacune un message. | « Concerne aussi » limité à dix noms puis « et N autres » (un animateur voit toutes les fiches). |
| C — Finances et Encadrement | #1029 | Rappel de campagne : une ligne par créance, rattachée à l'enfant par son `member_id`, sans adresse propre ; un seul bloc de paiement par e-mail (colonnes `Prénom`, `Montant`, `Communication`, `QR`, `Bénéficiaire`, `IBAN`), plus de sections `{{#Prénom N}}` ni de plafond de six enfants ; brouillon Encadrement : une ligne par personne avec son `member_id`, lu en une requête groupée (`LeadershipRepository::findMemberIdsByMemberYearIds()`) ; aide `rappels.md`, `encadrement.md`, ARCHITECTURE §8.84 et §8.65. | Qui est relancé ne change pas : un enfant (ou un animateur) sans adresse dans Desk reste hors du brouillon, même si sa fiche a une adresse secondaire. Aucun gabarit de rappel n'est stocké (le corps est construit dans le code) : un brouillon antérieur garde son audience numérotée et s'affiche comme avant. `specifications.md` ne décrit pas ces rappels : inchangée. Le phpdoc de `MassMailDraftInterface` (lot A/B) cite encore « un bloc par créance » en exemple. |
| D — Bout en bout, aide et clôture | #PRNUM | Spec Playwright `tests/e2e/specs/mass-mail-shared-address.spec.js` (deux scénarios) sur un foyer semé par le scénario lui-même (`scripts/e2e-support.php seed-shared-address-siblings`, tampon par test) : deux enfants seuls dans leur section, même adresse Desk — identifiant du compte des parents — et chacun une adresse confirmée ; la liste de leur section dépose trois messages, une entrée sur chaque fiche, une notification pour les parents qui ouvre l'entrée d'un enfant, « concerne aussi » vers l'autre ; deux lignes du même Tiers : deux messages par adresse avec les valeurs de chaque ligne, deux entrées sur la fiche, aucune sur celle de l'autre enfant, une notification par ligne ; vertes trois fois de suite sur une même instance, et rouges quand la notification part une fois par cible. `loginWithCredentials()` pour un compte semé par un scénario ; `E2eSharedAddressSiblingsTest` pour le descripteur. Jeu de données de référence : l'adresse du second parent confirmée sur les trois enfants Delvaux (scénario 17, `ExtrasBlueprint::SECONDARY_EMAILS`, vérifiée par `ReferenceDatasetBuilderTest`). Relecture : `specifications.md` (§24.1 et ses deux avertissements par ligne, page du détail d'une communication), `ARCHITECTURE.md` (§8.22 et §8.37, qui parlaient encore de lignes de destinataires et d'un dédoublonnage disparu ; §8.61, la nouvelle spec), phpdoc de `MassMailDraftInterface::createMergeDraft()` (une ligne par personne, plus de blocs `{{#Prénom N}}`), aide `un-email-plusieurs-animes.md` et `adresses-email.md` ; maquette « livré ». | Le premier passage du planificateur d'une exécution E2E porte la première sauvegarde automatique de l'instance, qui archive `vendor/` : environ une minute avec un `vendor/` installé depuis les archives, plus de trois dans un conteneur où Composer a dû cloner les dépôts, au-delà des 120 s de `runScheduler()` — d'où les dépassements locaux, sans rapport avec le code. Depuis #999, une adresse désinscrite est écartée de tous les envois groupés, adresses de membres comprises, et « Réactiver » sur la fiche ne lui rend que la connexion : l'aide le dit, et aucun écran ne permet à l'unité de lever une désinscription. |
