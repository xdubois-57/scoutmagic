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
