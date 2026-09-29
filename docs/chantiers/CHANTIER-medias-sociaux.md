# Chantier — Médias sociaux : composeur unique, carte dessinée dans le navigateur, page publique

Roadmap d'exécution en **5 itérations**, module `modules/social`. Ordre et parallélisme en fin de
document.

Ce chantier **remplace plusieurs décisions** du chantier « partage social » précédent. Là où les deux
documents divergent, **celui-ci fait foi** — la liste exacte est dans « Ce qui change par rapport au
chantier précédent ».

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`), sans demander de confirmation. Un test rouge arrête
  l'itération : tu corriges, tu ne contournes pas, tu ne désactives rien.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. Couverture RBAC explicite sur toute route nouvelle ou modifiée.
- Toute modification de `schema.sql` impose de **relever la `version` dans `module.json`** : c'est
  ce relèvement qui applique le schéma sur une installation existante.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- Chaque écran modifié ship son sujet d'aide dans la même PR ; `tests/Core/Help/` échoue sinon.
- Aucune donnée personnelle, aucun jeton, aucun secret dans le journal ni dans un message d'erreur.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR.
- **Quand la dernière itération est fusionnée, clôture l'issue d'implémentation** avec un commentaire
  qui liste les PR.

### Rien à reprendre, rien à rediriger

Le site est en test et **rien n'a encore été partagé**. Donc :

- **aucune redirection** : `/communications`, `/partage/album/{id}` et `/partage/actualite/{id}`
  disparaissent purement et simplement ;
- **aucune reprise de données** pour les colonnes nouvelles ;
- **aucun texte d'interface ne mentionne un état antérieur** (« désormais », « a déménagé »,
  « ne se règle plus ici »…).

---

## Ce qui change par rapport au chantier précédent

| Avant | Maintenant |
|---|---|
| Flou plancher `MIN_BLUR_RATIO = 0.05`, « sans exception » | Curseur de net à très flou, défaut 0,025, **image nette permise** |
| Photo brute, nette, vers un groupe de discussion | **La même carte pour toutes les destinations**, groupes compris |
| Pages de partage dédiées `/partage/album/{id}`, `/partage/actualite/{id}` | **Un seul composeur**, ouvert pré-rempli par « Partager » |
| « Partager » visible seulement avec un compte Meta | Visible **dès qu'une destination est possible**, groupes compris |
| Bouton « Enregistrer », aperçu rendu par le serveur | Plus d'« Enregistrer » ; **carte dessinée dans le navigateur** |
| Carte rendue par GD (`CardRenderer`) | Carte rendue **par le navigateur**, le serveur ne fait que contrôler |
| Une seule fois par paire contenu + destination, **bloqué** | **Averti, pas bloqué** ; seule la même communication deux fois vers la même destination reste bloquée |

---

## Décisions verrouillées

### Nom et adresse

- La page s'appelle **« Médias sociaux »**, à l'adresse **`/medias-sociaux`** : entrée de menu,
  titre, fil d'Ariane, notifications qui citent la page, sujet d'aide.
- **Le code n'est pas renommé** : `CommunicationController`, l'entité `Communication` et sa table
  gardent leur nom. Une « communication » reste l'objet qu'on publie ; « Médias sociaux » est la page.

### Le bouton « Partager »

- Sur le **formulaire de modification d'un album** (`album_form.html.twig`) et dans l'**éditeur
  d'une actualité** (`editor.html.twig`, une fois l'article enregistré).
- **Une icône seule**, `bi-share`, avec `aria-label="Partager"`, un `title` pour l'infobulle, et une
  cible tactile de **44 × 44 pixels** (`design.md`).
- **Il apparaît dès qu'au moins une destination est possible pour la personne qui regarde** : un
  compte Meta utilisable (`SocialConnection::isUsable()`), **ou** le module de discussions actif
  **avec au moins un groupe où elle a le droit de publier**. `AlbumShareAction` et
  `ArticleShareAction` testent aujourd'hui `connectedDestinations() === []`, qui ne connaît que
  Facebook et Instagram : il leur faut une question plus large, portée par `SocialSharingInterface`.
- **Quand aucune destination n'est possible**, le bouton est absent et l'éditeur le dit en une ligne,
  avec un lien vers la configuration quand c'est Meta qui manque. Un bouton qui disparaît sans
  explication n'est pas acceptable.
- Un clic ouvre **le composeur**, pré-rempli.

### Un seul composeur

- Toute publication passe par la page de nouvelle communication.
- **La communication mémorise sa source** : un type et un identifiant (un album, une actualité).
  Générique, pour qu'une autre partie du site puisse devenir source plus tard sans rien changer ici.
- **Aucun champ d'adresse n'est jamais affiché ni modifiable.** Le lien vient toujours de la source.
  Une communication créée de zéro n'a pas de lien.
- Quand il y a une source, une ligne dit ce qu'on partage : « Partage de l'album **Week-end de
  rentrée** », « Partage de l'actualité **…** ».
- **Pré-remplissage** : un album donne sa couverture et son nom ; une actualité donne son image, son
  titre et son résumé. Le **titre et l'image venus d'une source sont verrouillés** ; le **texte** et
  le **flou** restent libres. Les boutons « Téléverser » et « Galerie » disparaissent quand l'image
  vient d'une source.
- **« Téléverser » ouvre directement le sélecteur de fichier** (champ masqué), et l'envoi part dès
  qu'un fichier est choisi, sans second clic.
- **Plus de bouton « Enregistrer ».** Tout est enregistré à « Publier ». Pendant un aller-retour vers
  la galerie ou un téléversement, le brouillon se conserve seul, sans bouton ni mention ; rien n'est
  publié ni figé avant « Publier ».
- **Après « Publier », retour à l'historique** (`publishNow()` renvoie aujourd'hui sur la page de la
  communication elle-même).

### La carte, dessinée dans le navigateur

- **Le navigateur dessine l'aperçu et l'image finale** dans un `<canvas>`, et envoie l'image finale au
  serveur à « Publier ». Un seul moteur de rendu : ce qu'on voit est exactement ce qui part.
- **L'aperçu suit le curseur et la frappe en direct**, limité au rythme de rafraîchissement de
  l'écran. Aucun délai d'attente.
- **Le serveur ne fait plus que contrôler** l'image reçue : format, **dimensions exactes de la carte**,
  poids maximal. C'est l'image reçue qui est figée et servie ensuite à Instagram, aux groupes, à la
  page publique et à toute nouvelle tentative depuis l'historique. `CardRenderer` quitte le chemin de
  publication.
- Ce n'est pas un pouvoir nouveau : un chef peut déjà téléverser n'importe quelle image.
- **Même police**, DejaVu Sans Bold, **servie par le site** via `@font-face` — la politique de
  sécurité du contenu interdit les polices externes.
- **Même échelle de flou** qu'aujourd'hui : un rayon en proportion du petit côté de l'image.
- **Réduire avant de flouter** : une photo de plusieurs mégapixels traitée telle quelle épuise la
  mémoire d'un téléphone.
- **Qualité JPEG réglée à l'export** pour que la carte reste légère : WhatsApp ignore les images
  d'aperçu trop lourdes.
- Toutes les images viennent du site : le canvas n'est jamais « contaminé » par une origine
  étrangère, et l'export reste possible.
- **Le blocage « titre manquant » est calculé sur la saisie.** Aujourd'hui, `ShareSourceResolver`
  renvoie « Donnez d'abord un titre à l'image. » à partir du titre **enregistré**, d'où un blocage qui
  persiste tant qu'on n'a pas enregistré.
- Le moteur de rendu est **testé avec Vitest**, en particulier le découpage des lignes du titre.

### Le flou

- **Un curseur**, affiché en langage clair de « Net » à « Très flou » — pas un ratio.
- **Défaut 0,025**, deux fois moins qu'aujourd'hui. `social_card_blur_ratio` reste en lecture seule et
  devient simplement **la position par défaut du curseur**.
- **L'image nette est permise.** Le plancher `CardService::MIN_BLUR_RATIO` disparaît, et **tous les
  textes qui affirment le contraire sont réécrits** : la description du réglage dans `module.json`
  (« jamais en dessous de 0,05 »), le docblock de `CardService`, les phrases « floutée, sans
  exception » de `views/communications/edit.html.twig`, l'aide, `specifications.md`.
- **Le même flou pour toutes les destinations, groupes compris** : les groupes reçoivent la carte, et
  plus la photo brute.
- La valeur est **enregistrée avec la communication** et **figée** avec le reste.
- La confirmation affiche **« Cette photo de la galerie part sans flou. »** quand le curseur est à
  « Net » et que la photo vient de la galerie.

### Le partage d'une actualité

- **Facebook reçoit une publication de lien** vers l'article, avec l'aperçu construit par Facebook à
  partir des balises Open Graph que le module Actualités émet déjà. L'image y paraît telle que la page
  la publie — c'est la couverture choisie pour être publique — donc **hors du curseur**.
- **Instagram et les groupes reçoivent la carte.**
- Aucun besoin de relire la page de l'article : le site connaît déjà son titre, son résumé et son
  image. `OgScraperService` n'intervient pas.

### La page publique d'une communication

- Elle appartient au **module `social`**, pas à la galerie. Aucune route publique n'apparaît dans la
  galerie.
- **Adresse à jeton, non devinable.** Jamais fondée sur un identifiant séquentiel, qui permettrait
  d'itérer et de récolter toutes les communications partagées.
- Elle montre **ce qui a été publié** — la carte, le titre, le texte — et un bouton **« Voir sur le
  site »** qui mène à la source mémorisée. Si la source a été supprimée depuis, le bouton disparaît et
  la page continue d'afficher ce qui a été publié.
- Ses **balises Open Graph** portent le titre, le texte et la carte : c'est elle que lisent Facebook,
  WhatsApp ou Messenger pour construire leur aperçu.
- Son image est **stable** — pas la route éphémère d'une heure qui sert Instagram, que ce chantier ne
  touche pas. Facebook met en cache et revient lire plus tard ; une image qui disparaît casse l'aperçu.
- **Quand elle sert** : pour un album partagé sur Facebook (la publication de lien pointe vers elle),
  et pour **tout partage natif ou copie de lien**. Une actualité se partage sur Facebook par sa propre
  adresse. Une communication sans source part sur Facebook en publication photo.
- **Elle est créée au premier partage qui en a besoin**, pas avant.
- **Elle reste en ligne**, sans retrait possible, même si la source est supprimée.
- L'album lui-même **reste à `identified`** : la page publique n'expose ni ses autres photos ni son
  contenu.
- **`SECURITY.md` §6** gagne une exception décrite une fois : la page publique d'une communication et
  son image, leur périmètre, et la raison pour laquelle elles échappent à `/files/{id}`.

### Partage natif et copie de lien

- Une **icône de partage** sur chaque communication de l'historique : sur mobile, elle ouvre la
  **feuille de partage du téléphone** ; ailleurs, elle **copie le lien** de la page publique et
  affiche brièvement « Lien copié ».
- **Tout partage fige la communication, copie de lien comprise.** Sinon, les destinataires verraient
  une carte différente de celle qu'on leur a envoyée : les applications relisent la page à chaque
  affichage.
- **L'historique dit ce que le site sait vraiment** : « Partagé depuis le téléphone » quand la feuille
  de partage a été menée au bout (pas quand elle est annulée), « Lien copié » pour une copie. Jamais
  « envoyé » : le site ne peut pas le savoir.

### Partager plusieurs fois

- **Même source, message différent** : une nouvelle communication, **permise, avec avertissement**.
- **Même communication, deux fois vers la même destination** : **toujours bloqué**. C'est le même
  message au même endroit, jamais voulu.
- **Le composeur ouvert depuis une source affiche en tête ses partages précédents** : destination,
  date, auteur, partages natifs et copies compris.
- **La confirmation reprend, destination par destination** : « Jamais partagé ici », ou « Cet album
  est déjà parti sur Facebook le 12 septembre », avec **« avec le même message »** quand le texte est
  identique — le cas qui signale presque toujours une erreur.

### Finitions

- La case « Groupe de discussion » et son libellé sont **alignés verticalement**, de même que les noms
  de groupes et leurs cases dans le dialogue de sélection. Aujourd'hui le texte est plus bas que la
  case.

---

## Les itérations

### IT-01 — Le composeur unique

Renommage en « Médias sociaux » et nouvelle adresse ; suppression des pages de partage dédiées ;
bouton « Partager » en icône, disponible dès qu'une destination existe, avec la ligne d'explication
sinon ; composeur pré-rempli depuis la source, ligne « Partage de… », titre et image verrouillés,
aucun champ d'adresse ; source mémorisée (schéma) ; fin du bouton « Enregistrer » et brouillon
silencieux ; retour à l'historique après publication.

### IT-02 — La carte dans le navigateur et le flou

Moteur de rendu `<canvas>` pour l'aperçu et l'image finale, testé avec Vitest ; envoi de l'image à
« Publier » et contrôle serveur ; `CardRenderer` retiré du chemin de publication ; curseur de flou,
valeur stockée et figée, plancher supprimé et textes réécrits ; la carte envoyée aussi aux groupes ;
blocage « titre manquant » calculé sur la saisie ; confirmation « part sans flou » ; téléversement
en un clic ; alignements.

### IT-03 — Le partage d'une actualité

Lien de l'article transmis par la source ; publication de lien sur Facebook avec l'aperçu Open
Graph de l'article ; carte pour Instagram et les groupes.

### IT-04 — La page publique d'une communication

Route publique à jeton, balises Open Graph, image stable, bouton « Voir sur le site » ; album
partagé sur Facebook en publication de lien vers cette page ; exception documentée dans
`SECURITY.md` §6 ; tests d'accès anonyme et de non-exposition du reste de l'album.

### IT-05 — Partage natif et partages multiples

Feuille de partage et copie de lien depuis l'historique ; création de la page publique au premier
partage ; gel au premier partage de toute nature ; historique honnête ; avertissement à la place du
blocage ; partages précédents en tête du composeur ; confirmation détaillée avec dates et « même
message ».

---

## Ordre et parallélisme

```
IT-01 ──► IT-02 ──┬──► IT-03 ──┐
                  │            ├──► IT-05
                  └──► IT-04 ──┘
```

- **IT-01 d'abord**, seule : tout le reste s'appuie sur le composeur et sur la source mémorisée.
- **IT-02 ensuite**, seule : elle réécrit le chemin de publication et la production de la carte, que
  les suivantes consomment.
- **IT-03 et IT-04 tournent en parallèle**, dans deux branches et deux espaces de travail distincts,
  pour gagner du temps. **Lance-les en même temps.** Leur seule zone de contact est le choix, dans la
  publication Facebook, entre publication photo et publication de lien : la seconde à fusionner se
  rebase sur la première et reprend ce point, rien de plus.
- **IT-05 en dernier** : elle a besoin de la page publique d'IT-04, et ses avertissements portent sur
  toutes les destinations.

---

## Écarté, explicitement

- **Un champ d'adresse** affiché ou modifiable, et donc la lecture de pages extérieures choisies par
  quelqu'un.
- **Un délai d'attente** avant la régénération de l'aperçu.
- **Un rendu de la carte par le serveur**, en plus ou à la place de celui du navigateur.
- **Un plancher de flou** ; **un curseur de flou** sur la publication de lien d'une actualité.
- **Le retrait d'une page publique**, par qui que ce soit.
- **Des redirections** depuis les anciennes adresses, et **toute reprise de données**.
- **Un statut « envoyé »** pour un partage natif ou une copie de lien.
- **Renommer le code** (`Communication`, `CommunicationController`, la table).
