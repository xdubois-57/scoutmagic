# Chantier — Partage vers Facebook et Instagram

Journal d'exécution du chantier « Partage vers Facebook et Instagram »
(issue #528, itérations IT-01 à IT-05). Une section par itération : ce qui
a été fait, les décisions prises en autonomie, les divergences constatées
entre le document de chantier et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/covoiturage.md`.

Le document de chantier est `docs/chantiers/CHANTIER-partage-social.md`,
sa maquette `docs/chantiers/maquettes/partage-social.html`. Chaque
itération est une pull request distincte, mergée sur `main` une fois la CI
verte.

---

## Vérification préalable du document

Les écarts déjà relevés dans l'issue #528 tiennent et sont repris ici pour
mémoire : l'exception « photos hors ligne » de SECURITY.md §6 a disparu, et
l'extrait de triage est la seconde exception délibérée — la route
éphémère d'IT-02 en sera une de plus, sur son modèle ; `PwaIconProcessor`
n'existe pas (les processeurs d'image existants sont
`Core\Photo\UnitLogoProcessor`, `SectionPhotoProcessor` et
`ImageVariantProcessor`) ; `AnthropicProvider` délègue à `HttpTransport`,
qui est le vrai modèle d'un client HTTP sans dépendance ; et deux écarts
de la maquette face au document (la case Facebook cochée-grisée, et un
texte sur le flou et le public qui doit dépendre de la destination),
tranchés à l'itération qui les rencontre.

---

## IT-01 — Le connecteur Meta

Le module `social` (« Réseaux sociaux », désactivé par défaut) :
« Configuration › Réseaux sociaux », la connexion de la Page Facebook et
du compte Instagram professionnel par l'application Meta de l'unité, la
tâche quotidienne qui renouvelle et vérifie, l'Api sans consommateur, la
déclaration de Meta à la page RGPD. Voir ARCHITECTURE.md §8.122,
SECURITY.md §38 et specifications.md §47.

### Décisions prises en autonomie

1. **Les secrets sont dans une colonne chiffrée, pas dans `secrets.enc`.**
   Le document dit « jetons chiffrés dans `secrets.enc` ». Écrire ce
   fichier réécrit tous les secrets du site, et un jeton Instagram est
   renouvelé chaque semaine : c'est exactement la raison pour laquelle le
   raccordement Google Drive en est sorti (SECURITY.md, `secrets.enc`), et
   docs/module-development.md fait de la colonne chiffrée le modèle d'un
   identifiant de module. `social_connections.secrets` porte, chiffrés
   ensemble, la clé secrète de l'application, le jeton en service et,
   entre le consentement Facebook et le choix d'une Page, le jeton
   utilisateur longue durée.
2. **« Tester la connexion » et « Reconnecter » sur chaque carte.** La
   maquette n'en montre qu'une paire, sous les deux cartes. Facebook et
   Instagram sont deux connexions distinctes chez Meta (Facebook Login
   d'un côté, Instagram Login de l'autre) : une paire unique ne pourrait
   dire laquelle tester, et reconnecter l'une ne répare pas l'autre.
3. **Instagram par Instagram Login, pas par la Page Facebook.** Le
   document ne tranche pas. Instagram Login n'exige pas que le compte soit
   lié à une Page, ce qui laisse une unité sans Page publier sur
   Instagram ; son jeton vaut soixante jours et se renouvelle — d'où la
   tâche quotidienne, qui le renouvelle quand il a une semaine.
4. **Plusieurs Pages : le site demande laquelle.** Le document suppose
   une Page. Un animateur gère souvent aussi une page personnelle ou celle
   d'une autre association : quand le consentement en donne plusieurs, la
   liste (identifiants et noms, sans jetons) est tenue en session et le
   jeton utilisateur, chiffré, dans la ligne, jusqu'au choix — puis
   effacé. Seule une Page proposée par ce consentement-là est acceptée.
5. **Un compte Instagram personnel est refusé à la connexion**, avec la
   phrase qui dit comment le passer en compte professionnel, plutôt qu'au
   premier « Publier ».
6. **Le journal signale une panne une fois.** La vérification tourne
   chaque nuit ; une autorisation retirée écrirait sinon la même ligne
   tous les jours. Il note la transition d'une connexion qui marchait vers
   une connexion refusée ou expirée.
7. **Une catégorie de sous-traitant de plus**,
   `SubProcessorView::CATEGORY_SOCIAL_PUBLISHING`, et la règle 33quater du
   prompt RGPD : Meta n'entre dans aucune des trois catégories existantes,
   et le prompt doit savoir retirer les paragraphes quand aucun compte
   n'est raccordé. Tant que rien n'est publié, la page dit que le site se
   limite au raccordement et ne publie rien ; IT-03 y ajoutera la
   publication, avec Meta comme destinataire de ce qui est publié.
8. **Un service de connexion partagé** (`Service\ConnectionService`),
   après la revue de #554 : « Tester la connexion » et la tâche jugent une
   connexion par le même code. Une panne réseau ou une erreur 5xx de Meta
   ne vaut pas refus ; un jeton utilisateur gardé pour un choix de Page
   jamais fait est effacé par la tâche au bout d'un jour.

### Reporté

Rien de ce qui relève d'IT-01. La publication elle-même, les cartes
d'image et la route éphémère arrivent avec IT-02 et IT-03.

---

## IT-02 — Le générateur de carte et la route éphémère

`Card\CardRenderer` compose l'image publiée, `Card\CardService` la garde
une heure derrière `/partage/carte/{token}` et la sert à Meta,
`Task\PurgeCardsHandler` efface les cartes expirées. Voir ARCHITECTURE.md
§8.122, SECURITY.md §6 et specifications.md §47.5. Rien ne publie encore :
le premier consommateur arrive avec IT-03.

### Le calibrage du flou

Cartes générées à partir des photos du jeu de données de référence
(`tests/fixtures/reference-dataset/photos/` : deux photos de groupe, un
portrait rapproché) à 1, 2, 3, 3,5, 4, 5, 6 et 7 % du côté.

- À 2 et 3 %, les sourires et l'expression se lisent encore sur le
  portrait.
- À 4 %, les traits ont disparu des photos de groupe, mais le portrait
  laisse deviner un visage souriant.
- **À 5 %, le portrait ne laisse plus rien deviner**, et la scène — une
  tente, un uniforme, une clairière — reste lisible. C'est la valeur
  retenue, et le plancher : `CardService::MIN_BLUR_RATIO`.
- Au-delà, l'image n'est plus qu'une tache de couleur.

Le premier essai (un seul agrandissement de l'image réduite) laissait
voir une grille de carrés flous dès 5 % ; l'agrandissement se fait
désormais par doublements, lissés à chaque étape.

### Décisions prises en autonomie

1. **Un carré de 1080 px** pour toutes les destinations. Instagram accepte
   de 4:5 à 1,91:1 et recadre sa grille en carrés ; Facebook montre un carré
   entier. Un format unique permet à la confirmation d'IT-03 de montrer
   l'image exacte qui part, quelle que soit la destination. La maquette
   dessine un aperçu paysage : c'est un aperçu, pas un format.
2. **Le réglage n'apparaît pas sur la page Paramètres.** Le document le
   voulait « en lecture seule sur la page Paramètres, comme
   `pwa_icon_version` » ; depuis #510, cette page ne montre que des lignes
   modifiables, et `pwa_icon_version` n'y figure pas davantage. Le réglage
   `social_card_blur_ratio` est déclaré `editable: false` : enregistré,
   lisible, jamais modifiable depuis l'interface — et le service ne descend
   jamais sous le plancher, quoi qu'il contienne.
3. **La police est DejaVu Sans Bold**, licence Bitstream Vera/Arev,
   permissive ; son texte officiel est versionné à côté
   (`modules/social/resources/fonts/`). Couverture complète du français.
4. **Une tâche de purge à part** (`purge_cards`, quotidienne) plutôt qu'une
   étape de la vérification des connexions : l'expiration est vérifiée à
   chaque requête, la purge n'est que du ménage.
5. **SECURITY.md** : c'est la **troisième** exception écrite à la règle
   « tout téléchargement passe par `/files/{id}` », après l'extrait de
   triage — le document parlait de la seconde, l'exception « photos hors
   ligne » ayant disparu depuis.

## IT-03 — Le partage depuis les actualités et les albums

« Partager » sur la page de modification d'un album et dans l'éditeur
d'une actualité, contribué par le module social à travers deux registres
que la galerie et les actualités possèdent (ARCHITECTURE.md §7.6). La page
`/partage/album/{id}` ou `/partage/actualite/{id}` montre la carte réelle,
la légende et chaque destination avec son état ; la publication part vers
la Page (photo pour un album, lien pour une actualité) et vers Instagram
(toujours une image), une seule fois par destination, décidé par une clé
unique en base. Voir ARCHITECTURE.md §8.122, SECURITY.md §38 et
specifications.md §47.6.

### Décisions prises en autonomie

1. **Une vraie page plutôt que la fenêtre de la maquette.** La maquette
   dessine la confirmation d'un album par-dessus un fond grisé ; la page
   dédiée garde l'image à sa taille sur un téléphone, survit à un
   rechargement après une erreur, se prête au retour « Post/Redirect/Get »
   qui réaffiche l'état de chaque destination, et c'est la forme que la
   maquette retient elle-même pour la « Nouvelle communication ». Libellés,
   ordre et avertissement sont ceux de la maquette.
2. **Les icônes de marque sont celles de Bootstrap Icons** (`bi-facebook`,
   `bi-instagram`), déjà servies par le site, plutôt que les dessins de la
   maquette ou les fichiers officiels de Meta.
3. **Qui peut partager** : la règle du module propriétaire, jamais
   recalculée — gérer l'album (`canManageAlbum()`), pouvoir modifier
   l'actualité (`canEdit()` : son auteur ou un administrateur). Un album
   délégué ou en cours de déplacement ne se partage pas.
4. **Quelles actualités peuvent sortir** : celles que le module
   Actualités tient déjà pour partageables (`isSociallyShareable()` —
   publique, lien direct, membres identifiés), c'est-à-dire celles dont
   l'image est déjà un fichier public. Une actualité réservée aux
   animateurs ou aux administrateurs montre pourquoi elle ne peut pas
   partir.
5. **L'image d'une actualité n'est pas floutée.** Le document ne floute
   que « toute image venant de la galerie » ; l'image d'une actualité a été
   choisie pour être montrée, et elle l'est déjà publiquement.
6. **Sans image, pas d'Instagram.** Un album sans photo de couverture ou
   une actualité sans image laisse Instagram indisponible, avec la raison ;
   la Page reste possible pour une actualité (publication de lien).
7. **Une publication interrompue** (le serveur tombe entre la réservation
   de la destination et la réponse de Meta) reste « en cours » quinze
   minutes, puis se présente comme un échec qu'on peut retenter en le
   confirmant : sans cela, une coupure bloquerait la destination pour
   toujours.
8. **La légende est limitée à 2 200 caractères**, la limite d'Instagram,
   pour toutes les destinations : une seule légende pour toutes.

## IT-04 — L'écran Communications

« Communications » dans l'espace animateurs : une communication libre
(image de la galerie ou téléversée, titre sur l'image, texte) publiée par
le même service qu'un album, et « Ce qui est parti », l'historique de
tout ce qui est parti, destination par destination, avec son réessai.
Le sélecteur de photo est une `Api` de la galerie
(`PhotoPickerInterface`). Voir ARCHITECTURE.md §8.122, SECURITY.md §38 et
specifications.md §47.7.

### Décisions prises en autonomie

1. **Une vignette choisie d'un clic**, sans bouton « Utiliser cette
   photo » : chaque vignette est un vrai bouton d'envoi. La maquette montre
   une sélection puis une confirmation ; sans script, un clic qui choisit
   et revient à la communication fait la même chose en un geste, et la
   photo retenue se juge aussitôt sur l'image publiée.
2. **« Téléverser » demande d'abord le fichier**, dans un champ visible
   sous les deux boutons : sans script, un bouton ne peut pas ouvrir le
   sélecteur de fichiers et envoyer le formulaire à la fois.
3. **Une communication se fige dès qu'une destination a été tentée.** Le
   document dit qu'un réessai renvoie « la même image et le même texte » ;
   figer la communication est la façon la plus simple de le garantir pour
   toutes les destinations, pas seulement pour le réessai.
4. **Une communication est à son auteur et aux administrateurs**, comme
   une actualité ; l'historique, lui, est lisible par tous les animateurs.
5. **Le titre sur l'image est obligatoire pour publier** : la carte sans
   titre ne dit pas ce qu'elle annonce.
6. **L'historique couvre albums et actualités**, pas seulement les
   communications : c'est « ce qui est parti ». Il garde, avec chaque
   publication, le titre, le texte envoyé et l'adresse de la publication
   (celle d'Instagram demandée après coup, sans jamais transformer une
   publication réussie en échec si Meta ne la donne pas).
7. **« Réessayer » passe par une page de confirmation**, comme le partage
   d'IT-03, plutôt que par une fenêtre.
8. **Le sélecteur voit les albums délégués** à travers les contrôles que
   les modules Groupes et Camps ajoutent après la galerie : le registre est
   construit à la première utilisation, sur la liste prise par référence.

## IT-05 — Le groupe de discussion comme destination

Le module Groupes a désormais un espace `Api` (`GroupPublisherInterface`)
qui liste les groupes où une personne peut publier et crée une
publication ordinaire — accès, limite de rythme, modération, média, lien,
notification, dans l'ordre du formulaire du groupe. Le module social
s'en sert en dépendance facultative : chaque groupe est une destination
à part entière (`group:{id}`), avec sa ligne d'historique et la règle
« une seule fois » appliquée groupe par groupe. Voir ARCHITECTURE.md
§8.40 et §8.122, SECURITY.md §38 et specifications.md §47.8.

### Décisions prises en autonomie

1. **La fenêtre de choix est une modale Bootstrap placée dans le
   formulaire** : ses cases partent avec « Publier ». Un petit script
   écrit en clair les groupes retenus et coche « Groupe de discussion » ;
   sans lui, le formulaire marche encore — on coche la case à la main.
2. **Une communication libre part sans lien** dans un groupe : elle n'a
   pas de page sur le site. L'album et l'actualité partent avec le leur.
3. **Un même texte pour toutes les destinations** : la légende saisie
   devient le texte de la publication du groupe.
4. **Le nom du groupe est gardé avec la publication**
   (`destination_label`) : l'historique continue de le nommer après un
   renommage ou une fermeture.
5. **Pas de ligne « non demandé » par groupe** dans l'historique : un
   groupe n'est pas un compte permanent comme la Page ou Instagram ; seuls
   les groupes tentés y figurent.
6. **Les groupes n'apparaissent sur une communication qu'une fois
   enregistrée** : leur état se lit sur le contenu, qui n'existe pas avant
   le premier enregistrement.
7. **Le lien de réessai garde `group:3` tel quel dans l'adresse** : le
   routeur compare le chemin brut, et un deux-points y est valide.
8. **Suites de la revue d'IT-04**, fusionnée avant qu'elle n'arrive :
   « Publier » demande désormais confirmation sur la page de partage et
   sur la communication — `confirm.js` lit aussi `data-confirm` sur le
   bouton pressé, pour qu'« Enregistrer », « Galerie » et « Téléverser »
   ne demandent rien ; la page d'une communication existante n'est plus
   titrée « Nouvelle communication » ; les deux `LIMIT` sont liés en
   paramètres.
