# Chantier — Acceptation de la politique de protection des données

Roadmap d'exécution en **5 itérations séquentielles**, issue #626. Elle remplace #495, dont elle reprend le
constat et qu'elle étend au suivi par membre. Traite-les dans l'ordre. N'entame pas la suivante
avant que la précédente soit fusionnée sur `main`.

Maquette de référence : `docs/chantiers/maquettes/rgpd-acceptations.html`. Elle fait foi pour la
hiérarchie des écrans, les libellés français et les états d'interaction, et pour rien d'autre.

Relevé sur `main` 7e2df60.

---

## Le besoin

1. Savoir, pour chaque membre, **quand** la politique de protection des données a été acceptée
   pour la dernière fois, **quelle version**, et **par qui** (nom et e-mail du compte).
2. Le voir sur la fiche d'un membre (`/admin/members/{id}`), et dans une liste filtrable sous
   Configuration › RGPD.
3. Redemander l'acceptation, par un e-mail automatique, quand elle date de plus de **X mois**
   (18 par défaut, réglable). L'e-mail demande de **se connecter sur le site en acceptant la
   politique** : l'acceptation reste un geste explicite, jamais un clic dans un e-mail.

## Ce que le code fait aujourd'hui, et pourquoi il faut partir de zéro

- **Aucune acceptation n'est enregistrée à la connexion.** `AuthController::hasRgpdConsent()`
  (`core/Http/Controller/AuthController.php`) bloque la connexion si la case n'est pas cochée,
  sans rien garder :

  ```php
  $value = $jsonBody !== null ? ($jsonBody['rgpd_consent'] ?? null) : $request->getBody('rgpd_consent');

  return $value === true || $value === '1' || $value === 1 || $value === 'true';
  ```

- **Le texte n'est archivé nulle part.** La politique vit dans l'entrée modifiable `rgpd.text`
  (modes « personnalisé » et « IA », écrite par `RgpdConfigController::save()` et
  `RgpdGenerationRunner`) ou dans le fichier livré `core/View/rgpd_default.html` (mode « par
  défaut », `RgpdContentService::getDefaultContent()`). Dès qu'elle change, l'ancien texte
  disparaît.
- **La date affichée en mode « par défaut » est celle du dernier déploiement.** `PageController::
  rgpd()` affiche `filemtime()` du fichier livré, et `InstallUpdateHandler::copyRecursive()`
  recopie tous les fichiers avec `copy()` à chaque mise à jour.
- **La location hache un texte vide.** `RentalRequestController::privacyText()`
  (`modules/rental/src/Controller/RentalRequestController.php`) lit une clé que rien n'écrit :

  ```php
  return (string) ($this->editableContentService->get('rgpd_content', '') ?? '');
  ```

  Toutes les réservations portent donc `privacy_version = e3b0c44298fc`, le hash de la chaîne vide.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `design.md` et `docs/module-development.md`. Ils
priment sur ce fichier sur toute règle générale ; si tu découvres qu'ils décrivent une réalité que
le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR, **Corrige #626** sur la dernière seulement.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. **Couverture RBAC explicite sur chaque nouvelle route** (autorisée à
  `role_min`, refusée un niveau en dessous).
- Toute requête de dépôt qui repose sur le moteur (upsert, `INSERT` sur index unique rattrapé en
  23000, date calculée par le serveur) a sa classe `…OnMysqlTest` sur `Tests\UsesProductionEngine`.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface, commits et PR en
  français.
- **Chaque page vue par un utilisateur ship son sujet d'aide dans la même PR** (`docs/help/`).
- Aucune adresse, aucun nom dans le journal, les messages d'erreur ou les traces : `member_id` et
  `user_account_id` seulement (SECURITY.md §11).
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR.

---

## Les décisions — **Décidé**

| Sujet | Décision |
|---|---|
| Où l'on accepte | **À la connexion**, pour les trois méthodes (lien par e-mail, mot de passe, passkey). L'inscription publique et la demande de location gardent leur case, hors du suivi par membre. |
| Lien compte ↔ membre | Celui de la connexion : un compte dont l'adresse est l'adresse Desk d'un membre (`member_years.email_blind_index`, année en cours, `is_active = 1`) ou une adresse ajoutée **valide** (`member_emails.status = 'valid'`), en excluant une adresse Desk désactivée pour ce membre. C'est exactement `RoleResolver::findAllMatchingMemberYears()`. Un parent qui se connecte accepte donc pour ses enfants. |
| Qui | Nom et e-mail du compte **tels qu'au moment de l'acceptation**, chiffrés, gardés même si le compte change ou disparaît. |
| Périmètre | Membres **actifs de l'année en cours**, animés et staff. |
| Version | Chaque texte publié est archivé ; l'acceptation pointe vers sa version, servie à `/rgpd/{version}`. |
| Délai | Paramètre du cœur, **18 mois** par défaut ; **0 désactive** l'e-mail (la liste reste). |
| Jamais acceptée | Le délai part de la **mise en service du suivi** (date enregistrée au premier démarrage d'IT-05), pour ne pas écrire à toute l'unité le jour du déploiement. |
| Relance | Un e-mail à l'échéance, puis **tous les 30 jours** tant que l'acceptation manque. |
| Un e-mail par adresse | Une adresse reliée à plusieurs membres (fratrie) reçoit **un seul** e-mail qui les cite tous. |
| Rétention | La dernière acceptation de chaque adresse est gardée tant qu'un membre de l'année en cours y est rattaché ; l'historique au-delà de **3 ans** est purgé. La page RGPD par défaut le dit. |

---

## IT-01 — Les versions de la politique

Même mécanisme que les conditions de location (#494, `Modules\Rental\Service\
RentalConditionsService`), dans le cœur.

- Table `privacy_policy_versions` dans `schema/core.sql` : `version CHAR(12)` (12 premiers
  caractères du hash), `text_hash CHAR(64)` **unique**, `body_html MEDIUMTEXT`, `created_at`,
  `created_by_user_account_id` (nullable, `ON DELETE SET NULL`). Insert-only.
- `Core\Privacy\PrivacyPolicyVersion` (objet valeur), `PrivacyPolicyVersionRepository`
  (`findByHash()`, `findByVersion()`, `archive()` qui lit, insère, et relit la ligne gagnante sur
  un 23000), `PrivacyPolicyService` :
  - `currentHtml()` : **la seule** lecture du texte en vigueur, les deux modes compris. Remplace
    les copies de `PageController::rgpd()` et `RgpdConfigController::index()`.
  - `current(?int $userAccountId = null)` : archive si besoin et renvoie la version en vigueur.
    **Archivée à la lecture**, pas seulement à l'enregistrement : le texte arrive par trois portes
    (enregistrement, génération IA, fichier livré), une seule passe par un contrôleur.
  - `recordSave()` : archive le texte sortant **avant** d'écrire le nouveau (appelé par
    `RgpdConfigController::save()`, qui écrit aussi le changement de mode, et par
    `RgpdGenerationRunner`). `reset()` n'écrit rien : il renvoie le texte par défaut à l'éditeur.
  - `find(string $version)` : `null` si ce n'est pas 12 caractères hexadécimaux.
- Le hash porte sur le texte **avant** l'injection de la date (`<span id="rgpd-last-updated">`).
  La date affichée devient `created_at` de la version, ce qui corrige la date de déploiement.
- `GET /rgpd` affiche la version en vigueur ; `GET /rgpd/{version}` une version archivée, avec
  « Cette version n'est plus celle en vigueur. » et un lien vers l'actuelle ; 404 sinon. Publique,
  fil d'Ariane comme `/rgpd`.
- **Correction de la location** : `RentalRequestController::privacyText()` et `privacyVersion()`
  passent par `PrivacyPolicyService::current()`. Les réservations déjà enregistrées gardent leur
  hash vide : le documenter dans le docblock, ne rien inventer.
- Tests : deux enregistrements créent deux versions lisibles ; une génération IA crée une version ;
  le mode par défaut archive le fichier livré ; une version inconnue répond 404 ; la location
  enregistre le hash du vrai texte.

---

## IT-02 — Enregistrer l'acceptation à la connexion

- Table `privacy_policy_acceptances` : `user_account_id` (nullable, `ON DELETE SET NULL`),
  `email_encrypted` + `email_blind_index` (**purpose `email`**, le même que `user_accounts`,
  `member_years` et `member_emails`, puisqu'on joint dessus), `name_encrypted` (nullable),
  `policy_version`, `policy_hash`, `accepted_at`. Index `(email_blind_index, accepted_at)`.
  Contextes de chiffrement `privacy_policy_acceptances.email` et `.name`.
- **L'acceptation est enregistrée quand la session est ouverte**, pas quand la case est cochée :
  c'est la preuve que la personne contrôle l'adresse.
  - Mot de passe et passkey : juste après `AuthSession::login()` dans `loginWithPassword()` et
    `passkeyVerify()`.
  - Lien par e-mail : la case est cochée à `requestMagicLink()`, la session s'ouvre plus tard dans
    `verifyMagicLink()` ou à la confirmation depuis un autre appareil. Porter la version cochée
    dans la ligne `magic_links` (nouvelle colonne `privacy_policy_version`) et l'enregistrer à
    l'ouverture de la session, sur les deux chemins.
- **Le formulaire porte la version affichée** (champ caché dans les trois formulaires de
  `auth/login.html.twig`, envoyé par `public/assets/js/auth.js`). Si elle n'est plus en vigueur :
  refus avec « La politique de protection des données a été mise à jour. Relisez-la avant de vous
  connecter. », comme pour les conditions de location.
- Libellé de la case : « J'accepte la politique de protection des données (version du 3 septembre
  2026) », le lien s'ouvrant dans un nouvel onglet.
- Arrivée depuis l'e-mail de rappel (`/login?motif=rgpd`) : bandeau « Pour confirmer votre accord,
  connectez-vous en cochant la case ci-dessous. »
- Journal : `privacy_policy_accepted`, avec `user_account_id` et la version, rien d'autre.
- Tests : chaque méthode enregistre une acceptation avec le bon nom et le bon e-mail ; une version
  périmée est refusée ; le lien par e-mail ouvert sur un autre appareil enregistre la version
  cochée au départ.

---

## IT-03 — La fiche membre

- `PrivacyPolicyService::statusForMember(int $memberId)` (ou un service de lecture dédié) :
  dernière acceptation parmi toutes les adresses du membre (celle de Desk de l'année en cours et
  les adresses ajoutées valides), la version, le nom et l'e-mail déchiffrés, l'état, le dernier
  rappel et le nombre de rappels.
- États : **À jour** (dernière version, moins de X mois), **Version plus ancienne**, **Plus de X
  mois**, **Jamais acceptée**. Plus de X mois l'emporte sur Version plus ancienne.
- Carte « Protection des données » sur `core/View/templates/admin/members/show.html.twig` (route
  `admin`, `MemberSearchController::show()`), à côté de « Demande d'inscription d'origine » :
  Acceptée le, Version (lien vers `/rgpd/{version}`), Par (nom, e-mail), Rappels, et « Voir
  l'historique » qui déplie les acceptations précédentes. Pour « Jamais acceptée », la date du
  premier rappel prévu.
- Tests : les quatre états ; une acceptation via une adresse ajoutée valide compte ; une adresse
  ajoutée `pending` ou `inactive` ne compte pas ; la carte n'expose rien sous `admin`.

---

## IT-04 — Configuration › RGPD en sous-pages

- Rail `partials/page_picker.html.twig`, comme `modules/finance/views/_nav.html.twig`. **Ne
  recopie pas le balisage.**
  1. **Acceptations** — `/config/rgpd`, la page d'accueil.
  2. **Contenu de la politique** — `/config/rgpd/contenu`, l'écran actuel **tel quel**, plus la
     mention « Chaque modification publiée crée une nouvelle version… ».
- Toutes les routes `/config/rgpd/*` restent `superadmin`. Mettre à jour `MenuBuilder::addPage`
  et les liens qui visent l'ancien écran.
- La liste : membres actifs de l'année en cours, trois compteurs, les filtres **Tous**, **Pas la
  dernière version** (jamais acceptée comprise) et **Plus de X mois** (le libellé suit le
  réglage), un filtre par section, une recherche par nom ou totem (`display_name`). Filtres en
  paramètres d'URL, pour qu'un lien garde le filtre. État vide de `design.md` §7.
- **Pas de requête N+1** : les acceptations se lisent en une requête par lot de blind index, pas
  une par membre.
- Aide : un sujet `docs/help/` pour « Acceptations », avec deux à quatre lignes `question:`.
- Tests : RBAC des deux sous-pages ; chaque filtre ; un membre à deux adresses n'apparaît qu'une
  fois.

---

## IT-05 — Le réglage et l'e-mail de rappel

- Paramètre du cœur dans `public/index.php`, affiché dans Configuration › Réglages :
  `privacy_policy_reacceptance_months`, type `number`, défaut `18`, validation `^[0-9]+$`,
  libellé « Délai avant de redemander l'acceptation (mois) », description reprise de la maquette.
- Réglage interne `privacy_policy_tracking_started_at`, écrit une fois au premier passage de la
  tâche : c'est le départ du délai des membres qui n'ont jamais accepté.
- Table `privacy_policy_reminders` : `member_id` (clé, `ON DELETE CASCADE`), `last_sent_at`,
  `sent_count`.
- Tâche du cœur `send_privacy_policy_reminders`, déclarée dans `CoreTaskHandlers::all()`, une fois
  par jour. Elle sélectionne les membres échus (acceptation, ou mise en service, plus ancienne que
  X mois ; dernier rappel il y a plus de 30 jours), **regroupe par adresse**, envoie un e-mail par
  adresse via `MailService::send()`, et met à jour `privacy_policy_reminders`. Lot borné par
  passage, reprise au suivant. X = 0 : elle ne fait rien.
- E-mail du cœur déclaré dans `EmailTemplateRegistry::getCoreTemplates()` (modifiable dans
  Configuration › E-mails), gabarits `email/privacy_policy_reminder.html.twig` et `.text.twig`.
  Texte de la maquette, dont « Pour continuer à participer aux activités de l'unité, nous vous
  demandons de relire et d'accepter notre politique de protection des données. » Variables : la
  date de dernière acceptation, les membres concernés, le délai, le lien `/login?motif=rgpd`.
- Journal : un compteur par passage (`privacy_policy_reminders_sent`), jamais une adresse.
- **Page RGPD** : mettre à jour `rgpd_default.html` et `RgpdContentService::buildSystemPrompt()`
  (« Données collectées » : acceptations avec nom, e-mail, version et date ; « Durée de
  conservation » : la règle de rétention ci-dessus). AGENTS.md § RGPD page maintenance.
- Tests : pas d'envoi avant X mois après la mise en service ; envoi à l'échéance ; pas de second
  envoi avant 30 jours ; un seul e-mail pour une fratrie ; X = 0 n'envoie rien ; une acceptation
  après le rappel arrête les relances.

---

## Hors de ce chantier

- Ajouter une case à la **réinscription** (`/reinscription`). Si c'est souhaité, un ticket séparé.
- Faire entrer l'inscription publique et la location dans le suivi par membre.
