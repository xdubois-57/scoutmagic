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
- **Communiquer** : actualités, discussions, e-mails, listes de diffusion,
  notifications et réseaux sociaux.
- **Gérer la vie de l'unité** : inscriptions et réinscriptions, passages,
  membres, cotisations, attestations, finances, paiements par code QR et
  campagnes de paiement, locations, année scoute, mises à jour automatiques,
  etc.

Cette liste est loin d'être exhaustive. ScoutMagic propose de nombreuses
autres fonctionnalités et peut être complété par des modules selon les
besoins de chaque unité.

## Utiliser ScoutMagic pour votre unité

ScoutMagic est actuellement en phase pilote, mais il peut déjà être utilisé
et testé dans une unité.

Cette période permet de recueillir les retours de vraies unités et
d'améliorer ScoutMagic avant sa première version pleinement stabilisée.

Vous souhaitez découvrir ScoutMagic, l'utiliser dans votre unité ou poser des
questions avant de vous lancer ? Contactez-nous à **info@scoutmagic.be**.

## Installation

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

- [Spécifications fonctionnelles](specifications.md) — fonctionnement attendu
  de ScoutMagic et de ses modules.
- [Architecture](ARCHITECTURE.md) — architecture technique et décisions
  structurantes.
- [Sécurité](SECURITY.md) — exigences de sécurité et signalement privé des
  vulnérabilités.
- [Pipeline de qualité](docs/quality-pipeline.md) — tests, analyse statique,
  intégration continue, contrôles de qualité et processus de release.
- [Développement de modules](docs/module-development.md) — création et
  intégration d'un module ScoutMagic.
- [Guide de location](docs/rental-guide.md) — gestion des biens proposés à la
  location.
- [Configuration du courrier entrant](docs/inbound-mail-setup.md) — connexion
  d'une boîte mail en lecture seule.

Les règles destinées aux contributeurs et aux agents de développement se
trouvent également dans [AGENTS.md](AGENTS.md).

### Analyse statique JavaScript

Les commandes, garanties et limites de l'analyse statique JavaScript sont
documentées dans le [pipeline de qualité](docs/quality-pipeline.md#static-analysis).

### Tests de bout en bout

La suite Playwright, ses deux niveaux et son rôle dans le CI sont documentés
dans le [pipeline de qualité](docs/quality-pipeline.md#end-to-end--playwright).
L'inventaire à jour des scénarios est le répertoire `tests/e2e/specs/`.

### Analyse de sécurité dynamique

Les profils OWASP ZAP et leur rôle sont documentés dans le
[pipeline de qualité](docs/quality-pipeline.md#dynamic-scan--owasp-zap) ; le
modèle de sécurité détaillé reste dans [SECURITY.md](SECURITY.md).

## Données, sécurité et responsabilité

Chaque unité qui déploie ScoutMagic agit en tant que responsable de
traitement au sens du RGPD pour les données qu'elle y héberge. Elle reste
responsable de la conformité de ses traitements et de la sécurité de son
hébergement. L'auteur et les contributeurs du projet ne sont ni responsables
de traitement ni sous-traitants pour les instances déployées par des tiers,
et n'ont aucun accès aux données qui y sont hébergées.

Une faille de sécurité découverte dans ScoutMagic ne doit pas être publiée
dans une issue GitHub. La procédure de signalement privé est décrite dans
[SECURITY.md](SECURITY.md).

ScoutMagic est un logiciel libre fourni sans garantie d'aucune sorte,
conformément aux conditions de la licence AGPL-3.0.

## Licence et contributeurs

ScoutMagic est distribué sous licence [AGPL-3.0](LICENSE).

Le projet est développé et maintenu par Xavier Dubois. Voir [NOTICE](NOTICE)
pour la liste des contributeurs et [LICENSE](LICENSE) pour les conditions
complètes de licence, y compris les conditions additionnelles relatives à
l'usage du nom « ScoutMagic ».
