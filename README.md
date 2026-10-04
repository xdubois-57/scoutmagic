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

Cette liste est loin d'être exhaustive. Les
[spécifications fonctionnelles](specifications.md) décrivent en détail les
fonctions et modules de ScoutMagic.

## Utiliser ScoutMagic pour votre unité

ScoutMagic est actuellement en phase pilote, mais il peut déjà être utilisé
et testé dans une unité.

Cette période permet de recueillir les retours de vraies unités et
d'améliorer ScoutMagic avant sa première version pleinement stabilisée.

Vous souhaitez découvrir ScoutMagic, l'utiliser dans votre unité ou poser des
questions avant de vous lancer ? Contactez-nous à **info@scoutmagic.be**.

## Prérequis

L'hébergement doit fournir **PHP >= 8.4** et **MySQL >= 8.0** ; MariaDB 10.11
est également pris en charge. Les extensions et contraintes techniques de
référence sont détaillées dans la
[base technique](docs/exigences-non-fonctionnelles.md#5-technical-baseline).
Aucun shell, Composer ou Node.js n'est requis sur le serveur d'hébergement.
Les prérequis propres au développement sont dans
[CONTRIBUTING.md](CONTRIBUTING.md).

L'installation standard vérifie elle-même que l'hébergement satisfait les
conditions nécessaires avant de continuer.

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
2. Envoyez-le par FTP dans le dossier web vide servi par votre hébergeur.
3. Ouvrez `bootstrap.php` **en HTTPS**. En HTTP, l'installeur ne demande rien
   et redirige vers HTTPS. Il crée ensuite `token.php` : lisez sa valeur par
   FTP et recopiez-la dans l'écran demandé avant de poursuivre.
4. L'installeur vérifie que ce dossier répond bien en HTTPS. Si vous restaurez
   une sauvegarde portable, choisissez-la à cette étape : ScoutMagic installe
   d'abord la version qui l'a créée ; sans sauvegarde, il prend la dernière
   release.
5. Lancez l'installation puis suivez l'assistant de configuration jusqu'à la
   fin. Les contrôles de sécurité bloquent proprement l'installation si
   l'hébergement ne convient pas.

Aucun accès SSH, Git ou Composer n'est nécessaire sur le serveur.

Une fois l'assistant terminé, deux écrans constituent de bons premiers points
de contrôle :

- **Espace chefs d'U › Points d'attention** rassemble ce qui demande encore
  une intervention dans la vie de l'unité ;
- **Configuration › Maintenance › Santé de l'hébergement** vérifie les
  dépendances de l'hébergement et explique ce qui ne fonctionnerait pas en cas
  de problème.

La configuration du serveur, de la base de données et du cron est détaillée
dans [Installation & serveur](docs/help/installation-serveur.md).

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

- [Spécifications fonctionnelles](specifications.md) — comportement attendu de
  ScoutMagic et de ses modules.
- [Architecture](ARCHITECTURE.md) — architecture technique et décisions
  structurantes.
- [Sécurité](SECURITY.md) — exigences de sécurité et signalement privé des
  vulnérabilités.
- [Exigences non fonctionnelles](docs/exigences-non-fonctionnelles.md) — base
  technique, compatibilité et exigences transversales.
- [Pipeline de qualité](docs/quality-pipeline.md) — tests, analyse statique,
  intégration continue, contrôles de qualité et processus de release.
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

Pour préparer l'outillage JavaScript local, `npm ci` installe les dépendances
de développement ; Node.js et npm ne sont pas nécessaires en production.
`composer serve` lance le serveur PHP local avec des limites d'upload relevées,
car le serveur PHP intégré n'applique pas `public/.user.ini`.

Les commandes, valeurs et prérequis de développement sont documentés dans
[CONTRIBUTING.md](CONTRIBUTING.md), et la carte des contrôles se trouve dans
[docs/quality-pipeline.md](docs/quality-pipeline.md).

### Analyse statique JavaScript

`npm run typecheck` vérifie statiquement le JavaScript de production. Les
règles et le rôle de ce contrôle sont détaillés dans
[Analyse statique](docs/quality-pipeline.md#static-analysis).

### Tests de bout en bout

`npm run e2e` lance les scénarios Playwright sur une installation jetable ; le
répertoire `tests/e2e/specs/` constitue l'inventaire courant des scénarios.
Voir [End-to-end / Playwright](docs/quality-pipeline.md#end-to-end--playwright)
pour les niveaux de couverture et le fonctionnement du harnais.

### Analyse de sécurité dynamique

Voir [Dynamic scan / OWASP ZAP](docs/quality-pipeline.md#dynamic-scan--owasp-zap).

## Intégration continue

La carte détaillée des checks se trouve dans le
[pipeline de qualité](docs/quality-pipeline.md#continuous-integration).

La matrice d'autorisation : **toutes** les routes rejouées sous les six rôles.
Elle rejoue **toutes** les routes que l'application déclare et compare chaque
réponse au rôle minimal annoncé.

Les jobs bloquants sont :

- **`test`** : PHPStan et PHPUnit sur MySQL 8.
- **`database-mariadb`** : la même suite PHPUnit sur MariaDB 10.11.
- **`javascript-tests`** : analyse statique et tests JavaScript.
- **`e2e-tests`** : scénarios navigateur Playwright.
- **`authorization-matrix`** : **toutes** les routes rejouées sous les six rôles, soit un couple (route, rôle) par combinaison.
- **`dast-passive`** : analyse dynamique passive avec OWASP ZAP.
- **`security`** : audit des dépendances Composer.
- **`sonarqube`** : analyse SonarQube Cloud et Quality Gate.

### Créer une release

Le détail opérationnel est conservé dans la section
[Releases du pipeline de qualité](docs/quality-pipeline.md#releases). Avant de
créer un commit, un tag ou une release, le script exécute sept verrous, dans
cet ordre :

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
