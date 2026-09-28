# Chantier — Maintenance : sous-pages, sauvegardes et chiffrement

Roadmap d'exécution en **6 itérations séquentielles**, issue #509. Traite-les dans l'ordre.
N'entame pas la suivante avant que la précédente soit fusionnée sur `main`.

---

## La règle qui gouverne tout le chantier

**Ce chantier réorganise et améliore. Il ne retire rien.**

Chaque fois qu'un bloc existant change d'écran, il arrive **tel quel** : mêmes champs, mêmes
avertissements, mêmes confirmations, mêmes textes d'aide. Déplacer n'est pas redessiner. Si tu te
surprends à simplifier un bloc « au passage », arrête-toi : ce n'est pas demandé, et c'est une
régression déguisée en nettoyage.

Les seuls endroits où quelque chose change vraiment sont nommés explicitement plus bas : la fusion
des sauvegardes manuelles, la fusion des sauvegardes automatiques, la rétention hors site, le
chiffrement généré, et le déplacement de la restauration.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. **Couverture RBAC explicite sur chacune des six sous-pages.**
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- **Chaque sous-page ship son sujet d'aide dans la même PR.** Une page d'aide monolithique
  découpée en six ne se découpe pas toute seule ; `tests/Core/Help/` échoue sinon.
- Aucun secret, aucun mot de passe d'archive, aucune donnée personnelle dans le journal, les
  messages d'erreur ou les traces.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR.

---

## La structure cible — **Décidé**

Six sous-pages, avec le **rail de sous-pages partagé** : `partials/page_picker.html.twig`, qui
alimente `partials/nav_rail.html.twig` (`nav nav-underline`, `flex-nowrap`, `overflow-auto`),
exactement comme le fait `modules/finance/views/_nav.html.twig`. **Ne recopie pas ce balisage à la
main** : le docblock du composant raconte que six endroits le dupliquaient avant qu'il existe, et
il a été écrit pour empêcher le septième.

1. **Santé de l'hébergement** — c'est la page d'accueil, à `/config/maintenance`, comme
   « Tableau de bord » l'est pour `/finance`. **Il n'y a pas de page hub séparée.**
2. **Mise à jour**
3. **Sauvegarde manuelle**
4. **Sauvegarde automatique**
5. **Sauvegardes récentes** (avec la restauration)
6. **Réinitialisation**

### Le durcissement des rôles — **Décidé**

Le menu Configuration a pour plancher `superadmin`, mais **21 routes de maintenance existent et 18
déclarent `admin`** — y compris la génération de sauvegarde, la restauration, la réinitialisation
et le secret du webhook. Un chef d'unité ne voit pas le menu mais atteint tout par son adresse.

**Toutes les routes de maintenance passent à `superadmin`, dans l'itération 1.** Les `POST` et les
`/api/` comptent autant que les `GET` : une page durcie dont le formulaire reste à `admin` ne
protège rien, c'est le verbe qui agit. Mentionne le changement dans les notes de version — quelqu'un
qui utilisait un lien direct tombera désormais sur un refus.

---

## IT-01 — Le socle

- Les six routes, le rail partagé sur chacune, et le découpage du gabarit
  `config/maintenance.html.twig` (1162 lignes) en six.
- Les 21 routes passées à `superadmin`.
- **Réinitialisation** déplacée telle quelle sur sa page — « Paramètres par défaut » et
  « Réinitialisation complète », leurs mots-clés `REINITIALISER` et `EFFACER`, la case à cocher, et
  le paragraphe sur les emplacements de stockage non effacés. Seule « Restaurer un backup » reste
  en place pour l'instant : elle bouge en IT-06.
- **Mise à jour** déplacée telle quelle : version installée avec ses notes de version et leur
  « Afficher plus » (`partials/clampable_notes.html.twig`), dernière vérification, bouton de
  vérification, dialogue d'installation, historique — **et tout le bloc des mises à jour
  automatiques sans exception** : activation, les quatre niveaux (correctifs, mineures, majeures,
  développement) avec leurs deux avertissements rouges, l'explication de X, Y et Z, le créneau jour
  et heure, la section webhook du mode développement avec l'état du secret et **la raison en clair
  du dernier rejet de poussée**, l'avertissement de silence, et la dernière installation réussie.
- L'ancre `#remote-backup`, vers laquelle `RemoteBackupController` redirige après chaque aller-retour
  par Google, doit continuer d'aboutir au bon endroit — elle pointe désormais vers une autre page.

---

## IT-02 — Santé de l'hébergement

Page neuve, et la seule de ce chantier qui n'existe nulle part aujourd'hui.

Elle réunit ce dont le site dépend côté hébergeur. Chaque ligne dit **trois** choses : l'état, **ce
qui cesse de marcher sans elle**, et **quoi demander à l'hébergeur**. Un « ffmpeg : absent » sans la
deuxième ligne ne sert personne.

- **Le cron**, avec sa dernière exécution et la ligne de crontab (déjà sur la carte santé actuelle).
- **ffmpeg et ffprobe** — sans eux, le téléversement de vidéos est refusé dans la galerie
  (`VideoProcessingService`, et la règle « le réglage *et* les deux binaires »).
- **Le chiffrement des archives** — `BackupService::supportsZipEncryption()` aujourd'hui, openssl
  après IT-03.
- **libsodium** — `PortableKeys` teste `sodium_crypto_pwhash` et bascule sur un repli ; dire que le
  repli marche mais résiste moins bien.
- **GD** — vignettes, icônes PWA, photos de section.
- **imap** — courrier entrant.
- **PHP, base de données, écriture de `storage/`.**

**L'espace disque n'est pas mesuré ici.** Il vit sur Configuration › Stockage, volume par volume ;
un chiffre ici et un autre là seraient deux réponses à la même question, dont une fausse. Un lien
suffit.

**L'état des mises à jour automatiques n'est pas ici non plus** : c'est l'état de la mise à jour,
pas celui de l'hébergeur.

---

## IT-03 — Le chiffrement généré

C'est le changement de fond, et il simplifie tout le reste.

### Le principe — **Décidé**

Le chiffrement protège un fichier **qui quitte le serveur**, pas un fichier qui existe. Et il ne
doit rien coûter à l'utilisateur.

**Toute archive est chiffrée à sa création avec un mot de passe généré par le site**, rangé dans
`secrets.enc`, et **révélé au moment où il sert** : sur l'écran de téléchargement pour tout ce qui
se télécharge, sur la page pour la phrase hors-site.

- **Le champ « mot de passe » disparaît de tous les formulaires de sauvegarde.** Il n'y a plus rien
  à saisir nulle part.
- **Un mot de passe par archive** en local. **La phrase à générations pour la hors-site**, qui ne
  change pas : `RemotePassphrase` la génère déjà, dans un alphabet sans `I`, `L`, `O`, `0` ni `1`,
  en six groupes de cinq, parce qu'elle est faite pour être recopiée sur papier.
- **La suppression d'une archive emporte sa ligne dans `secrets.enc`**, sans quoi le magasin de
  secrets enfle d'une entrée par sauvegarde, indéfiniment.
- Le téléchargement devient **immédiat** : l'archive est déjà chiffrée, il n'y a rien à préparer.
- Deux phrases doivent figurer à l'écran : un mot de passe affiché au téléchargement **doit être
  noté**, parce que le serveur qui pourrait le redire n'existera peut-être plus ; et régénérer la
  phrase, ou réinitialiser les clés, **rend illisibles les archives déjà produites**.

### La dépendance à libzip disparaît — **Décidé, sous condition**

`BackupService` chiffre avec `ZipArchive::setEncryptionName(EM_AES_256)`, qui exige libzip compilé
avec crypto. On bascule sur **`nelexa/zip`** (Ne-Lexa/php-zip) : PHP pur, sans l'extension php-zip
ni `ZipArchive`, WinZip AES-256, ZIP64, et rien d'autre à demander à l'hébergeur qu'openssl — que
le projet utilise déjà partout (`EncryptionService`, `SecretManager`, `SecretEnvelope`).

**Première tâche de l'itération, avant d'écrire quoi que ce soit d'autre : mesurer le pic mémoire**
de la bibliothèque sur une archive de plusieurs gigaoctets, avec un `memory_limit` de mutualisé.
Toute l'architecture de sauvegarde est bâtie pour tenir dans ces limites — budget de 20 secondes,
reprise plus tard. Une bibliothèque qui garde ses entrées en mémoire ferait tomber la sauvegarde
d'une unité avec galerie.

- **Si le test passe** : plus aucun hébergeur incapable de chiffrer, donc **plus de repli en clair,
  plus de sauvegarde portable indisponible, plus d'avertissement**. Trois cas particuliers
  disparaissent — supprime-les, ne les garde pas « au cas où ».
- **Si le test échoue** : on reste sur `libzip`, et le repli d'IT-04 s'applique. Vérifie aussi la
  licence et la vitalité du dépôt avant d'ajouter la ligne au `composer.json`, et note que la
  bibliothèque tire `symfony/finder` et `psr/http-message` avec elle.

### Tranché en cours de route (issue #619)

- **libzip reste le moteur de chiffrement.** La mesure demandée plus haut a été faite : `nelexa/zip`
  tient en mémoire (4 Mo de pic sur une archive de 1 Go, `memory_limit` de 128 Mo), mais chiffre
  environ cinq fois plus lentement que libzip (1 Go compressé et chiffré en AES-256 : 202 s contre
  44 s), et son dépôt n'a plus publié de version depuis juin 2022. Le mainteneur a choisi de rester
  sur libzip : le repli d'IT-04 s'applique donc.
- **La copie de sécurité d'une réinitialisation complète reste chiffrée.** La réinitialisation efface
  `secrets.enc`, donc le mot de passe de cette copie : la page de réinitialisation l'affiche et fait
  confirmer qu'il est noté **avant** d'effacer quoi que ce soit.
- **L'itération est livrée en deux PR**, pour rester sous la cinquantaine de fichiers :
  - IT-03a — un mot de passe généré par archive pour la sauvegarde complète et la portable
    (`Core\Maintenance\BackupPasswords`, clé `backup_password_{id}` de `secrets.enc`), révélé à côté du
    téléchargement, oublié avec l'archive, retrouvé seul pour restaurer une archive de ce serveur ; plus
    aucun champ de mot de passe dans les formulaires de sauvegarde, ni de mot de passe dans la charge
    d'une tâche planifiée ;
  - IT-03b — le chiffrement des sauvegardes automatiques et de sécurité, et la copie de la
    réinitialisation complète ci-dessus.

---

## IT-04 — Sauvegarde manuelle

### La fusion — **Décidé**

Trois blocs deviennent **une seule section** avec une liste à bulles de **quatre portées**, chacune
avec une courte description de ce qu'elle signifie, et **un seul bouton** :

1. **Configuration seule** — paramètres et structure, sans aucune donnée de membre.
2. **Site complet** — tout, hors emplacements de stockage, sans les clés : se restaure ici, reste
   illisible ailleurs.
3. **Base de données seule** — l'export SQL, sans les fichiers ; les champs personnels y restent
   chiffrés.
4. **Sauvegarde portable** — tout, **clés comprises** ; la seule restaurable sur une installation
   neuve ; **une seule est conservée**, la nouvelle remplace la précédente.

Les descriptions disent **à quoi chaque type sert**, pas seulement ce qu'il contient.

### Ce que la fusion coûte, et qu'il faut assumer

L'export de base est aujourd'hui **synchrone** : `POST /config/maintenance/backup/database` renvoie
directement un `.sql`. Les quatre types passent désormais par le même chemin — tâche de fond,
notification, puis une ligne dans la liste. Un contrôle unique dont le bouton tantôt télécharge et
tantôt notifie serait exactement ce que l'issue reproche à l'écran actuel.

### Le repli sans chiffrement — **Décidé, et seulement si IT-03 est retombée sur libzip**

Sur un serveur incapable de chiffrer : la configuration seule, le site complet et la base de données
partent **en clair**, avec un avertissement. **Jamais la portable** : elle contient les clés du
site, une portable en clair est l'intégralité des données de l'unité dans un fichier téléchargeable.
Elle bloque, elle ne dégrade pas.

Et l'écran ne ment pas : quand le serveur ne sait pas chiffrer, on n'affiche pas un champ ignoré.
L'avertissement dit quoi demander à l'hébergeur, dans l'ordre du plus simple : activer l'extension
PHP `openssl` ; à défaut, compiler l'extension `zip` contre libzip 1.2 ou plus avec le chiffrement.

---

## IT-05 — Sauvegarde automatique

### La fusion — **Décidé**

Les boîtes « Sauvegarde automatique » et « Sauvegarde hors site » n'en font plus qu'une, **nommées
par l'accident dont elles protègent**, pas par leur emplacement :

- **Sur ce serveur** — pour revenir en arrière après une fausse manœuvre, quand le site est toujours
  debout. Sa fréquence (aucune, quotidienne, hebdomadaire, bimensuelle, mensuelle) et son nombre de
  sauvegardes conservées ne changent pas.
- **Hors site** — pour le jour où le serveur n'existe plus et où tout est à réinstaller. Ce sont des
  sauvegardes **portables**, que l'assistant d'installation d'un site neuf sait reprendre, à
  condition de connaître la phrase de passe. Destination, cadence de 24 heures, phrase de passe avec
  sa génération et son bouton de régénération : inchangés.

La boîte doit rendre l'asymétrie **lisible**, pas la gommer.

### La rétention intelligente hors site — **Décidé**

Aujourd'hui : les 30 plus récentes, sous un plafond de volume, la borne la plus stricte l'emportant
(`RemoteRetention`). Désormais : **la dernière, puis une par semaine sur le mois écoulé, puis une
par mois au-delà**, sous **les mêmes bornes** de nombre et de volume.

- Faisable **sans nouvelle comptabilité** : la destination expose la date de chaque objet, et le nom
  de chaque archive porte déjà sa date et sa génération (`RemoteRetention::nameFor()`).
- Le gain réel : avec 30 autorisées, l'amincissement en garde une quinzaine — le plafond cesse de
  mordre et **la portée passe d'un mois à un an**, à volume égal.
- **Les archives d'une génération de phrase précédente ne sont pas traitées à part** : l'ancienneté
  seule décide. Elles resteront illisibles avec la phrase courante, et c'est assumé.
- La page affiche **l'état réel** : nombre d'archives, volume occupé, date de la plus ancienne. Une
  politique qu'on ne peut pas vérifier d'un coup d'œil ne rassure personne.
- Le témoin écrit par le bouton « Tester » d'une destination ne doit **jamais** être compté comme
  une archive — c'est déjà documenté dans `RemoteRetention`, et l'oublier a déjà supprimé la seule
  copie hors site d'une unité.

**La rétention locale ne change pas.** Elle sert à revenir en arrière de quelques jours, pas à
traverser l'année.

---

## IT-06 — Sauvegardes récentes et restauration

- La liste, déplacée telle quelle : une seule liste triée par date, familles mélangées, la famille
  en pastille sur la ligne et non en regroupement.
- **Le téléchargement révèle le mot de passe généré** (IT-03) puis sert le fichier, immédiatement.
- **La restauration est rapatriée** depuis la carte rouge « Réinitialisation ». Restaurer n'est pas
  réinitialiser : c'est ce à quoi sert une sauvegarde, et c'est là qu'on la cherche.

### Ce que le déplacement doit emporter intact

- Les deux sources : depuis ce serveur, ou depuis un fichier téléversé — **par morceaux** pour les
  grosses archives (`upload_id`, `chunked-upload.js`).
- **Les archives portables restent exclues** du choix serveur : une portable sert à repartir sur une
  installation neuve, pas à écraser celle-ci. Le contrôleur le refuse déjà et c'est lui qui fait foi.
- Le mot-clé `RESTAURER` à taper.
- La sauvegarde de sécurité prise automatiquement avant l'opération.

### Ce qui s'améliore

**Restaurer une archive de ce serveur ne demande plus aucun mot de passe** : le site connaît le
sien. Le champ ne subsiste que pour un fichier téléversé venu d'ailleurs.

---

## Écarté, explicitement

- **Retirer ou simplifier un bloc existant** au passage de son déplacement.
- **Une page d'accueil de Maintenance** séparée des six sous-pages.
- **Mesurer l'espace disque** sur la page de santé.
- **Un champ de mot de passe** dans un formulaire de sauvegarde.
- **Un repli en clair pour la sauvegarde portable.**
- **Une rétention intelligente sur les sauvegardes locales.**
- **Un traitement particulier des archives d'une génération de phrase périmée.**
