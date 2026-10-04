# ScoutMagic

**Le site web de votre unité scoute, clé en main.**

ScoutMagic est un site libre et gratuit, pensé pour les unités de la
fédération « Les Scouts ». Il réunit en un seul endroit ce qui est
aujourd'hui éparpillé entre un vieux site, des tableurs, des groupes de
messagerie et des boîtes mail : la vitrine publique de l'unité, l'espace
des animateurs et celui des familles.

Vos membres viennent directement de Desk : pas de double encodage. Le site
s'installe chez l'hébergeur de votre choix, avec un simple accès FTP, et
se met ensuite à jour tout seul.

## Fonctionnalités

- **Membres à jour depuis Desk** : un import du fichier de la fédération,
  et sections, fonctions et staffs suivent.
- **Connexion sans mot de passe** : lien par e-mail, passkey ou mot de
  passe, avec des accès adaptés à chaque rôle.
- **Sur téléphone comme une application** : le site s'installe sur l'écran
  d'accueil et reste consultable hors ligne.
- **Communication** : actualités, calendrier d'activités, notifications,
  e-mails groupés et groupes de discussion privés par section.
- **Photos et vidéos** : galeries par activité.
- **Vie de l'unité** : inscriptions en ligne, camps, encadrement et
  formations, trombinoscope du staff.
- **Argent** : finances de l'unité, cotisations et location des locaux et
  du matériel.
- **Données protégées** : données personnelles chiffrées, sauvegardes
  automatiques, aucun pistage des visiteurs.
- **Aide intégrée** : un bouton d'aide sur chaque page, et tout le guide
  sur `/aide`.

Chaque fonction optionnelle est un module : vous n'activez que ce dont
votre unité a besoin.

## Installation rapide

Il vous faut un hébergement web avec PHP 8.4, une base MySQL ou MariaDB,
un nom de domaine en HTTPS, un accès FTP et la possibilité d'ajouter une
tâche planifiée (cron).

1. Téléchargez `bootstrap.php` depuis la
   [dernière version](https://github.com/xdubois-57/scoutmagic/releases/latest).
2. Envoyez-le par FTP dans le dossier web, vide, de votre domaine.
3. Ouvrez `https://votre-domaine.be/bootstrap.php` dans votre navigateur et
   suivez les écrans. Il vous demande d'abord un code qu'il vient d'écrire
   à côté de lui, à relire par FTP, puis installe le site.
4. Ajoutez la tâche planifiée que l'écran vous affiche, dans le panneau de
   votre hébergeur. Elle est obligatoire : sans elle, le site ne fait rien
   en arrière-plan (sauvegardes, mises à jour, rappels).

Le guide complet, y compris la remise en ligne depuis une sauvegarde, est
dans [docs/installation.md](docs/installation.md).

## Configuration

L'assistant de configuration prend le relais à la fin de l'installation.
Il vous demande, dans l'ordre :

- l'accès à la base de données, testé avant d'être enregistré ;
- le nom de l'unité, son nom court et son logo ;
- la manière d'envoyer les e-mails, avec les réglages DNS à faire chez
  votre fournisseur de domaine ;
- votre compte d'administrateur.

Pour que le site installe seul ses mises à jour, reliez-le ensuite aux
nouvelles versions depuis **Configuration › Maintenance › Mise à jour** :
la page explique quoi faire.

## Premières étapes

1. **Importez vos membres** : exportez la liste complète depuis Desk, puis
   importez-la dans l'espace chefs d'unité.
2. **Choisissez vos modules** dans **Configuration › Modules**.
3. **Personnalisez le site** : page d'accueil, contact et sections se
   modifient directement à l'écran.

Ensuite, deux pages méritent une visite régulière :

- **Points d'attention**, dans l'espace chefs d'unité : ce qui ne va pas
  dans l'unité aujourd'hui, avec quoi faire et pour quand.
- **Santé de l'hébergement**, dans **Configuration › Maintenance** : tout
  ce dont le site dépend chez votre hébergeur, ce qui cesse de marcher
  sans chaque élément, et quoi lui demander.

Deux guides complètent l'aide intégrée : la
[location des biens de l'unité](docs/rental-guide.md) et le
[raccordement d'une boîte mail](docs/inbound-mail-setup.md).

## Licence

ScoutMagic est développé et maintenu par Xavier Dubois, sous licence
[AGPL-3.0](LICENSE) : vous pouvez l'utiliser, le modifier et le
partager, à condition que les versions modifiées restent open source. Une
version modifiée ne peut pas être diffusée sous le nom « ScoutMagic » sans
autorisation. Les contributeurs sont listés dans [NOTICE](NOTICE).

ScoutMagic est fourni sans garantie d'aucune sorte (voir LICENSE,
sections 15-16). Toute unité qui déploie ScoutMagic agit en tant que
responsable de traitement au sens du RGPD pour les données qu'elle y
héberge : c'est à elle qu'incombent l'évaluation de la conformité RGPD, la
sécurisation de son hébergement, la tenue du registre de traitement et la
notification en cas de violation de données. L'auteur et les contributeurs
du projet ne sont ni responsables de traitement ni sous-traitants pour les
instances déployées par des tiers, et n'ont aucun accès aux données qui y
sont hébergées.

## Sécurité

Une faille de sécurité se signale en privé, comme expliqué dans
[SECURITY.md](SECURITY.md), jamais dans un ticket public. Les corrections
sont apportées bénévolement, sans garantie de délai.

## Contribuer

Les contributions sont les bienvenues. Commencez par
[CONTRIBUTING.md](CONTRIBUTING.md), puis
[docs/developpement.md](docs/developpement.md) pour l'environnement de
développement et les tests.
