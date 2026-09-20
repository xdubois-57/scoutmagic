# Chantier — Contact d'un membre : vCard, QR et CardDAV

Journal d'implémentation du document de chantier
`docs/chantiers/CHANTIER-contact-membre-carddav.md` (itérations IT-01 à
IT-03, issue #398). Une section par itération : ce qui a été livré, les
décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/courrier-sortant.md`.

---

## IT-01 — La vCard et le QR

**Livré.**

- `Core\Contact` : `ContactCard` (la définition unique du contenu),
  `ContactAffiliation` (une année d'historique et sa mise en forme),
  `VCardVariant` (le drapeau : QR ou fiche complète), `VCardBuilder` (le
  rendu vCard 3.0), `ContactCardService` (l'assemblage),
  `ContactPhotoResolver` (le portrait), `ContactQrCodeBuilder` (le
  symbole), `ContactCardException`, `Repository\ContactCardRepository`
  (l'historique et la révision) et
  `Controller\MemberContactController`.
- `Core\Photo\SquareJpegEncoder` : le « petit JPEG carré » d'un portrait,
  extrait de `Modules\Trombinoscope\Pdf\StaffPhotoEmbedder`, qui le
  délègue désormais. Deux fonctionnalités en ont besoin pour la même
  raison — ni l'une ni l'autre ne peut porter une URL — et une seconde
  copie du code GD aurait dérivé.
- Deux routes, `role_min: admin`, sur la page d'un membre et nulle part
  ailleurs : `GET /admin/members/{id}/contact-vcard` et
  `GET /admin/members/{id}/contact-qr`.
- L'écran : une carte « Ajouter à mes contacts » sur
  `admin/members/show.html.twig` et son dialogue, monté sur
  `partials/modal.html.twig`. Le code QR et le fichier sont offerts
  ensemble, sans détection d'appareil.
- Le chargement paresseux du code QR dans
  `public/assets/js/member-search.js` : l'`<img>` part sans `src`, et le
  script le pose à la première ouverture du dialogue.
- Journalisation `security` : `member_contact_vcard_downloaded` et
  `member_contact_qr_served`, avec `member_id` et rien d'autre.
- Documentation : `ARCHITECTURE.md` §8.115, `specifications.md` §4.4 (la
  page d'un membre passe de trois à quatre cartes d'action),
  `core/View/rgpd_default.html` §2.12, et le sujet d'aide
  `docs/help/fiche-de-contact.md`.
- Tests : `tests/Core/Contact/` (quatre classes),
  `tests/Core/Photo/SquareJpegEncoderTest.php`, la couverture RBAC des
  deux routes dans `tests/Core/Member/Controller/MemberSearchRbacTest.php`
  et quatre cas Vitest dans `tests/js/member-search.test.js`.

**Décisions prises en autonomie.**

- **vCard 3.0 plutôt que 4.0.** 4.0 est publié depuis 2011 et n'est
  toujours pas ce que lisent les deux carnets d'adresses visés : iOS et
  l'application Contacts d'Android importent 3.0 sans broncher et ont
  historiquement malmené ou refusé 4.0. Une fiche que personne n'ouvre
  n'est pas une fiche.
- **Le format d'une ligne d'historique quand il y a plusieurs fonctions
  la même année.** Le chantier impose `année · fonction · section` et
  « plusieurs fonctions la même année tiennent sur la même ligne », sans
  dire comment les séparer. Retenu : `2025-2026 · Animateur · Louveteaux
  ; Trésorier` — le point-médian sépare les champs d'une fonction, le
  point-virgule sépare deux fonctions. Un autre séparateur de champ
  aurait rendu la ligne illisible dès la deuxième fonction.
- **Une section sans nom configuré n'est pas nommée du tout.** Le
  chantier dit « jamais le code Desk » ; `sections.name` est nullable.
  La ligne s'arrête donc à la fonction, exactement comme pour un
  Trésorier. Aucun repli sur `desk_code`.
- **`REV` est l'agrégat de ce qui *aurait pu* bouger.** Il n'existe pas
  d'`updated_at` sur `member_years` : un import Desk réécrit la ligne sur
  place (`MemberYearRepository::upsert()`), donc son `created_at` dit
  quand le membre est apparu, jamais quand son numéro a changé. `REV` est
  donc le maximum de la création de la ligne annuelle, des imports
  couvrant ses années, de la photo et des adresses configurées. Il
  **sur-déclare** plutôt qu'il ne sous-déclare : un import qui n'a rien
  changé pour ce membre pousse quand même sa révision. C'est le sens sûr
  — le client retélécharge une fiche qu'il avait déjà, là où l'erreur
  inverse laisse un numéro périmé dans un téléphone pour toujours. Et
  surtout, cette lecture **ne déchiffre rien**, ce qui est exactement la
  propriété dont le `getctag` d'IT-03 aura besoin.
- **`UID` = `scoutmagic-member-{members.id}`.** Stable, dérivé de
  l'identité persistante, identique dans les deux variantes. Pas de
  composante propre à l'installation : deux unités partageant un carnet
  d'adresses n'est pas un cas réel, et une composante variable casserait
  la stabilité qui fait tout l'intérêt de `UID`.
- **Les deux routes n'ont pas de point dans leur chemin.**
  `Router::matchPath()` interpole le motif d'une route directement dans
  une expression régulière sans l'échapper : un `.` littéral y
  vaudrait « n'importe quel caractère ». Le nom du fichier est porté par
  `Content-Disposition`, que le navigateur lit de toute façon. Le
  problème du routeur lui-même est reporté (voir plus bas).
- **Le code QR est chargé à l'ouverture du dialogue**, pas au chargement
  de la page. La route rend la fiche et la consigne au journal : la
  laisser se charger avec la page produirait une fiche — et une entrée de
  journal — pour chaque page membre simplement consultée.
- **Une adresse e-mail secondaire « pending » n'est pas exportée.** Le
  chantier dit « toutes les adresses actives configurées par le membre » ;
  une adresse déclarée mais jamais confirmée n'est connue de personne, et
  rien d'autre sur le site ne la traite comme une adresse.
- **La couverture RBAC des deux routes vit dans
  `MemberSearchRbacTest`**, qui est déjà le test de frontière de tout
  l'espace `/admin/members` et monte à lui seul le `FrontController`, le
  Twig et les globales nécessaires. Un fichier dédié aurait recopié cent
  lignes de montage pour deux routes dont le plancher est précisément
  « le même que la page ».
- **Le sujet d'aide est un nouveau sujet** (`fiche-de-contact`) plutôt
  qu'une section de `fiche-membre-admin`, qui pesait déjà 471 mots pour
  un plafond dur de 500 : `design.md` §7.11 prescrit exactement ce
  découpage au-delà de ~400 mots.

**Divergences entre le chantier et le dépôt réel.**

- **`SECURITY.md` §4 ne documente pas « l'unique exception délibérée »
  mais *sept*.** Le chantier annonce que les routes CardDAV d'IT-03
  feront « la deuxième » exception après le webhook GitHub ; le dépôt en
  compte déjà sept (webhook GitHub, intake de statistiques, intake de
  tickets, archive de ticket, sondes de courrier, extrait de triage,
  désabonnement en un clic). IT-03 écrira donc la **huitième**, avec son
  périmètre, comme demandé — le raisonnement du chantier est intact, seul
  le compte a vieilli.
- **`Core\Http\Request` porte plus que `$_POST`.** Le chantier prévient
  qu'il faudra lire `php://input` à la main pour le corps XML d'un
  `PROPFIND` ; `Request::getRawBody()` existe déjà et fait exactement
  cela. IT-03 l'utilisera plutôt que de toucher aux superglobales.
- **`Core\Member\MemberService` et `SectionService` accèdent directement
  à PDO.** Le piège décrit par le chantier (« `MemberProfile` ne porte que
  les fonctions d'une seule année ») est exact et a été évité ; en
  revanche ces deux Services préparent eux-mêmes leurs requêtes et
  déchiffrent eux-mêmes des colonnes, contrairement au contrat
  Controller → Service → Repository d'`ARCHITECTURE.md` §13 et à
  `SECURITY.md` §5 (« Only Repositories call `EncryptionService` »).
  `Core\Contact` respecte le contrat pour sa part, et la refonte est
  reportée plutôt qu'élargie à cette PR — voir plus bas.

**Reporté.**

- **`Router::matchPath()` n'échappe pas les parties littérales d'un motif
  de route** avant de l'interpoler dans une expression régulière. Aucune
  route du dépôt ne contient aujourd'hui de métacaractère, donc rien
  n'est faux en production ; c'est un piège dormant, et une issue
  GitHub le décrit avec son test (#409). Les deux routes de cette itération
  l'évitent en n'utilisant pas de point.
- **La refonte de `MemberService` et `SectionService` en Repository** —
  issue #413, avec les deux options chiffrées et le test d'architecture
  qui épinglerait la règle. Rien n'est faux en production : tout est en
  requête préparée. Ce que cela coûte, c'est un déchiffrement qui a deux
  foyers au lieu d'un, et une duplication que le docbloc de
  `MemberFunctionInfo::deduplicate()` constate déjà.

---

## IT-02 — Les identifiants d'appareil

Les deux écrans existent et se visitent dès cette itération ; ce qui
n'arrive pas avant IT-03, c'est la synchronisation elle-même — rien ne
lit encore le carnet d'adresses. Entièrement testable seule : c'est le
socle d'authentification.

**Livré.**

- La table `device_credentials` : compte, libellé donné par l'utilisateur,
  empreinte du secret, création, dernière synchronisation, révocation.
- `Core\Contact\Device` : `DeviceCredential` (l'objet de valeur, qui n'a
  aucune propriété où un secret pourrait tenir), `NewDeviceCredential`
  (l'unique exemplaire en clair du secret, le temps d'un aller vers
  l'écran), `DeviceCredentialRepository`, `DeviceCredentialService`,
  `DeviceAuthenticator` et `AuthenticatedDevice`.
- Deux écrans : « Appareils synchronisés » sous Mon compte
  (`/account/devices`, `role_min: admin`) — la liste, la création, la
  révocation — et Configuration > « Synchronisation des contacts »
  (`/config/synchronisation-contacts`, `role_min: superadmin`) — le
  coupe-circuit du site et **tous** les appareils, tous comptes
  confondus, avec révocation sur chacun.
- Le réglage `contact_sync_enabled`, exclu de la page Paramètres
  générique et piloté depuis sa propre page.
- `public/assets/js/device-credentials.js` : la création passe par une
  requête JSON parce que la réponse porte l'unique exemplaire en clair du
  secret, qui ne doit être garé nulle part en route — même raison que
  `MaintenanceController::generateWebhookSecret()`.
- Journalisation `security` : `device_credential_created`,
  `device_credential_revoked`, `device_credential_auth_failed`,
  `contact_sync_enabled` / `_disabled`. Jamais une synchronisation
  réussie.
- Documentation : `ARCHITECTURE.md` §8.117, `SECURITY.md` §2,
  `specifications.md` §4.5 et le tableau de Mon compte,
  `core/View/rgpd_default.html` §2.13, et les sujets d'aide
  `docs/help/appareils-synchronises.md` et
  `docs/help/synchronisation-contacts.md`.
- Tests : `tests/Core/Contact/Device/` (trois classes, dont vingt-deux cas
  sur l'authentificateur), `tests/Core/Contact/Controller/` (les deux
  écrans plus la frontière RBAC des six routes), et
  `tests/js/device-credentials.test.js`.

**Décisions prises en autonomie.**

- **Le secret est comparé par SHA-256 et `hash_equals()`, pas par
  bcrypt.** C'est le précédent que `SECURITY.md` §4 tient déjà pour le
  jeton de triage et pour le désabonnement en un clic : à 32 octets
  d'entropie une empreinte rapide vaut bcrypt, et la route est anonyme et
  sondée toutes les quelques minutes — y mettre un bcrypt donnerait à
  n'importe qui un levier d'amplification CPU sur un hébergement mutualisé.
  Le chantier dit « secret haché », sans dire avec quoi ; c'est ce
  raisonnement-là qui tranche.
- **Le nom de l'appareil est stocké en clair**, comme
  `webauthn_credentials.device_label` juste au-dessus dans le même schéma :
  c'est la même donnée, saisie par la même personne, pour le même usage.
  Suivre le précédent plutôt qu'inventer une règle plus stricte pour un
  champ identique.
- **Pas de `scout_year_id` sur la table**, avec la raison qu'`AGENTS.md`
  § Database exige quand on l'omet : un identifiant appartient à un
  *compte*, pas à une saison, et il doit survivre au 1er septembre. Ce que
  l'année décide, c'est le rôle — recalculé à chaque requête et jamais
  stocké là.
- **Le coupe-circuit est actif par défaut.** C'est un coupe-circuit, pas
  un opt-in : rien ne se synchronise tant qu'un administrateur n'a pas
  enregistré d'appareil, et un interrupteur qu'il faut d'abord allumer
  n'est pas un coupe-circuit.
- **Dix identifiants vivants par compte au maximum.** Pas une frontière de
  sécurité — ils appartiennent tous à la même personne et meurent avec son
  rôle — mais une borne sur une liste qu'un écran doit rester capable
  d'afficher, et sur une table que n'importe quel admin pourrait sinon
  faire grossir sans fin.
- **Les échecs d'authentification sont journalisés, mais bornés** à cinq
  par heure et par adresse source, comptés dans
  `human_check_rate_limits` sous un `form_key` à eux. Le chantier demande
  de journaliser les échecs ; un client mal configuré réessaie toutes les
  quelques minutes indéfiniment, et journaliser chaque refus enterrerait
  le journal sous un seul mauvais mot de passe — ou permettrait d'y
  enterrer une vraie tentative. C'est exactement le traitement que
  `SECURITY.md` décrit déjà pour les refus de l'extrait de triage.
- **Ni le coupe-circuit ni l'absence d'en-tête `Authorization` ne sont
  journalisés.** Le premier est un acte unique d'un superadministrateur,
  déjà dans le journal ; le second est la première requête ordinaire de
  tout client, qui revient aussitôt avec ses identifiants.
- **`DeviceAuthenticator` n'est pas encore câblé dans
  `public/index.php`**, parce qu'aucune route ne l'appelle avant IT-03.
  Il est couvert par PHPStan et par vingt-deux cas de test ; IT-03
  n'ajoutera que les routes.
- **La couverture RBAC des six routes vit dans son propre fichier**,
  `tests/Core/Contact/Controller/ContactSyncRbacTest.php`, contrairement à
  IT-01 : il n'existait pas de test de frontière à étendre pour
  `/account` ni pour `/config`, et deux planchers différents
  (`admin` et `superadmin`) méritaient d'être affirmés côte à côte.

**Divergences entre le chantier et le dépôt réel.**

- **Aucune ici.** Les trois pièges qu'annonçait IT-02 — le secret jamais
  récupérable, le refus de `SettingService`, le rôle re-résolu — décrivent
  exactement ce que le dépôt permet, et le précédent du webhook GitHub
  s'applique tel quel.

**Reporté.**

- Rien. Aucun problème réel n'a été constaté puis laissé de côté dans
  cette itération.
