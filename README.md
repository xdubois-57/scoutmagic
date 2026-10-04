# ScoutMagic

ScoutMagic est un site web open source destiné aux unités de la fédération
**Les Scouts** en Belgique. Il rassemble dans un même endroit de nombreux
outils utiles aux animés, parents, animateurs et équipes d'unité.

L'objectif est de faciliter la vie de l'unité : mieux partager les
informations, communiquer, organiser les activités et simplifier certaines
tâches administratives. ScoutMagic est conçu pour être flexible afin de
pouvoir s'adapter aux besoins des différentes unités.

Chaque unité dispose de sa propre installation et garde le contrôle de ses
données.

## Fonctionnalités

Parmi les nombreuses possibilités de ScoutMagic :

- **Découvrir et suivre la vie de l'unité** : actualités, sections, contacts,
  galeries photos et trombinoscope.
- **Garder votre agenda à jour** : consulter le calendrier et vous y abonner
  depuis votre téléphone ou votre agenda habituel.
- **Retrouver les informations utiles** : informations sur les membres,
  documents et aide intégrée au site.
- **Préparer les activités et les camps** : présences, covoiturage, recherche
  et gestion des endroits de camp, autorisations parentales et fiches
  médicales.
- **Communiquer** : actualités, groupes de discussion, e-mails, listes de
  diffusion, notifications et réseaux sociaux.
- **Gérer la vie de l'unité** : inscriptions et réinscriptions, passages,
  membres, cotisations, attestations, finances, paiements par code QR et
  campagnes de paiement, locations, année scoute, mises à jour automatiques,
  etc.

Cette liste est loin d'être exhaustive. L'[inventaire détaillé des
fonctionnalités](docs/features.md) présente plus précisément ce que ScoutMagic
propose, et les [spécifications fonctionnelles](specifications.md) décrivent le
comportement attendu de chaque fonction et module.

## Utiliser ScoutMagic pour votre unité

ScoutMagic est actuellement en phase pilote, mais il peut déjà être utilisé
et testé dans une unité.

Cette période permet de recueillir les retours de vraies unités et
d'améliorer ScoutMagic avant sa première version pleinement stabilisée.

Vous souhaitez découvrir ScoutMagic, l'utiliser dans votre unité ou poser des
questions avant de vous lancer ? Contactez-nous à **info@scoutmagic.be**.

## Prérequis

Les prérequis techniques de référence sont conservés dans
[README-reference.md](README-reference.md#prérequis), et les prérequis de
développement sont repris dans [CONTRIBUTING.md](CONTRIBUTING.md). L'installation
standard vérifie elle-même que l'hébergement satisfait les conditions
nécessaires avant de continuer.

### La tâche cron

ScoutMagic a besoin d'une tâche cron exécutant `php public/cron.php` chaque
minute. La ligne exacte adaptée à l'hébergement, son contrôle et les points
d'attention sont détaillés dans
[Installation & serveur](docs/help/installation-serveur.md).

## Installation et premiers pas

L'installation de ScoutMagic se fait à l'aide du fichier `bootstrap.php`
fourni avec chaque release.

1. Téléchargez `bootstrap.php` depuis la
   [dernière release](https://github.com/xdubois-57/scoutmagic/releases/latest).
2. Envoyez-le par FTP à la racine du répertoire web dans lequel vous
   souhaitez installer ScoutMagic.
3. Ouvrez l'URL correspondant à `bootstrap.php` dans votre navigateur.
4. Suivez les instructions affichées à l'écran.

L'assistant vérifie votre hébergement, télécharge ScoutMagic et vous guide
jusqu'à la fin de l'installation. Aucun accès SSH, Git ou Composer n'est
nécessaire sur le serveur.

Une fois l'assistant terminé, deux écrans constituent de bons premiers points
de contrôle :

- **Espace chefs d'U › Points d'attention** rassemble ce qui demande encore
  une intervention dans la vie de l'unité ;
- **Configuration › Maintenance › Santé de l'hébergement** vérifie les
  dépendances de l'hébergement et explique ce qui ne fonctionnerait pas en cas
  de problème.

La configuration du serveur, de la base de données, du cron et des mises à
jour automatiques est détaillée dans le guide
[Installation & serveur](docs/help/installation-serveur.md).

## Contribuer

ScoutMagic est un projet open source développé bénévolement et en évolution
constante. Si vous souhaitez contribuer, proposer une amélioration ou
simplement échanger à propos du projet, vous pouvez contacter son mainteneur
à **info@scoutmagic.be**.

La meilleure manière d'aider est aussi d'utiliser ScoutMagic et de partager
votre expérience. Tous les retours sont utiles : facilité d'utilisation,
fonctionnalités qui répondent ou non à vos besoins, aide difficile à
comprendre, élément que vous vous attendiez à trouver ailleurs ou problème
rencontré.

Vous n'avez pas besoin de proposer une solution. Un simple « je n'ai pas
compris comment faire ceci » ou « je m'attendais à trouver cela ici » est
déjà précieux.

Les suggestions et problèmes peuvent être signalés dans les
[issues GitHub](https://github.com/xdubois-57/scoutmagic/issues). GitHub ne
doit cependant pas être un obstacle : vous pouvez également nous transmettre
votre retour par e-mail et nous nous chargerons de le consigner si
nécessaire.

Tester n'est pas la seule manière de contribuer. Vous pouvez aussi améliorer
les textes ou l'aide, proposer des idées, travailler sur l'expérience
utilisateur ou contribuer directement au code.

Pour contribuer au code, consultez [CONTRIBUTING.md](CONTRIBUTING.md).

## Documentation

La documentation détaillée est volontairement séparée de ce README :

- [Fonctionnalités détaillées](docs/features.md) — inventaire fonctionnel
  destiné aux personnes qui découvrent le projet.
- [Spécifications fonctionnelles](specifications.md) — comportement attendu de
  ScoutMagic et de ses modules.
- [Architecture](ARCHITECTURE.md) — architecture technique et décisions
  structurantes.
- [Sécurité](SECURITY.md) — exigences de sécurité et signalement privé des
  vulnérabilités.
- [Pipeline de qualité](docs/quality-pipeline.md) — tests, analyse statique,
  intégration continue, contrôles de qualité et processus de release.
- [Référence détaillée de l'ancien README](README-reference.md) — contenu
  technique et opérationnel conservé intégralement lors de la simplification.
- [Développement de modules](docs/module-development.md) — création et
  intégration d'un module ScoutMagic.
- [Installation & serveur](docs/help/installation-serveur.md) — base de
  données, e-mail, cron et maintenance de l'hébergement.
- [Guide de location](docs/rental-guide.md) — gestion des biens proposés à la
  location.
- [Configuration du courrier entrant](docs/inbound-mail-setup.md) — connexion
  d'une boîte mail en lecture seule.

Les règles destinées aux contributeurs et aux agents de développement se
trouvent également dans [AGENTS.md](AGENTS.md).

## Développement

Les commandes et prérequis de développement sont documentés dans
[CONTRIBUTING.md](CONTRIBUTING.md), et la carte des contrôles se trouve dans
[docs/quality-pipeline.md](docs/quality-pipeline.md).

### Analyse statique JavaScript

La documentation active est dans le [pipeline de qualité](docs/quality-pipeline.md#static-analysis) ;
la version détaillée auparavant publiée ici reste dans
[la référence de l'ancien README](README-reference.md#analyse-statique-javascript).

### Tests de bout en bout

La documentation active est dans le [pipeline de qualité](docs/quality-pipeline.md#end-to-end--playwright) ;
la description détaillée et l'ancien inventaire des scénarios restent dans
[la référence de l'ancien README](README-reference.md#tests-de-bout-en-bout-e2e).

### Analyse de sécurité dynamique

La documentation active est dans le [pipeline de qualité](docs/quality-pipeline.md#dynamic-scan--owasp-zap) ;
les explications détaillées auparavant publiées ici restent dans
[la référence de l'ancien README](README-reference.md#analyse-de-sécurité-dynamique-dast-owasp-zap).

La matrice d'autorisation : **toutes** les routes rejouées sous les six rôles.
Elle rejoue **toutes** les routes que l'application déclare et compare chaque
réponse au rôle minimal annoncé.

## Intégration continue

La carte détaillée des checks reste dans le
[pipeline de qualité](docs/quality-pipeline.md#continuous-integration). Cette
liste courte reste ici parce que les tests d'architecture vérifient que chaque
job bloquant est visible depuis le README :

- **`test`** : PHPStan et PHPUnit sur MySQL 8.
- **`database-mariadb`** : la même suite PHPUnit sur MariaDB 10.11.
- **`javascript-tests`** : analyse statique et tests JavaScript.
- **`e2e-tests`** : scénarios navigateur Playwright.
- **`authorization-matrix`** : **toutes** les routes rejouées sous les six rôles, soit un couple (route, rôle) par combinaison.
- **`dast-passive`** : analyse dynamique passive avec OWASP ZAP.
- **`security`** : audit des dépendances Composer.
- **`sonarqube`** : analyse SonarQube Cloud et Quality Gate.

### Créer une release

Le détail opérationnel est conservé dans [README-reference.md](README-reference.md#créer-une-release-mainteneneurs).
Avant de créer un commit, un tag ou une release, le script exécute sept verrous,
dans cet ordre :

1. **Déploiement** : la release précédente doit être déployée.
2. **Intégration continue** : les checks requis doivent être verts.
3. **Sécurité** : les dépendances et alertes de sécurité sont contrôlées.
4. **Fraîcheur des dépendances** : les dépendances directes sont vérifiées.
5. **API navigateur dépréciée** : les API critiques sont contrôlées.
6. **SonarQube Cloud** : l'analyse du commit et son Quality Gate sont vérifiés.
7. **Sources externes** : les sources dont dépend le site sont vérifiées.

## Données, sécurité et responsabilité

Chaque unité qui déploie ScoutMagic agit en tant que responsable de
traitement au sens du RGPD pour les données qu'elle y héberge. Elle reste
responsable de l'évaluation de la conformité de ses traitements, de la
sécurité de son hébergement, de la tenue de son registre de traitement et des
notifications requises en cas de violation de données. L'auteur et les
contributeurs du projet ne sont ni responsables de traitement ni
sous-traitants pour les instances déployées par des tiers, et n'ont aucun
accès aux données qui y sont hébergées.

Une faille de sécurité découverte dans ScoutMagic ne doit pas être publiée
dans une issue GitHub. La procédure de signalement privé est décrite dans
[SECURITY.md](SECURITY.md). Les corrections sont apportées sur une base
bénévole, sans garantie de délai.

ScoutMagic est un logiciel libre fourni sans garantie d'aucune sorte,
conformément aux conditions de la licence AGPL-3.0.

## Licence et contributeurs

ScoutMagic est distribué sous licence [AGPL-3.0](LICENSE).

Le projet est développé et maintenu par Xavier Dubois. Voir [NOTICE](NOTICE)
pour la liste des contributeurs et [LICENSE](LICENSE) pour les conditions
complètes de licence, y compris les conditions additionnelles relatives à
l'usage du nom « ScoutMagic ».
