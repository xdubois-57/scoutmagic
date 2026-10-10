# ScoutMagic

ScoutMagic est un site web destiné aux unités de la fédération Les Scouts
en Belgique. Il rassemble dans un même endroit de nombreux outils utiles
aux animés, aux parents, aux animateurs et aux équipes d'unité.

L'objectif est de faciliter la vie de l'unité : mieux partager les
informations, communiquer, organiser les activités et simplifier certaines
tâches administratives. ScoutMagic est conçu pour être flexible, afin de
s'adapter aux besoins de chaque unité.

## Fonctionnalités

Parmi les nombreuses possibilités de ScoutMagic :

- **Découvrir et suivre la vie de l'unité** : actualités, sections,
  contacts, galeries photos et trombinoscope.
- **Garder votre agenda à jour** : consulter le calendrier et vous y
  abonner depuis votre téléphone ou votre agenda habituel.
- **Retrouver les informations utiles** : informations sur les membres,
  documents et aide intégrée au site.
- **Préparer les activités et les camps** : présences, covoiturage,
  recherche et gestion des endroits de camp, autorisations parentales et
  fiches médicales.
- **Communiquer** : actualités, groupes de discussion, mails, listes de
  diffusion, notifications et médias sociaux.
- **Gérer la vie de l'unité** : inscriptions et réinscriptions, passages,
  membres, cotisations, attestations, finances, paiements par code QR et
  campagnes de paiement, locations, année scoute, mises à jour
  automatiques, etc.

Cette liste est loin d'être complète. ScoutMagic propose beaucoup d'autres
fonctions : n'hésitez pas à explorer le site et à découvrir ce qui vous
intéresse.

ScoutMagic est un projet open source, développé bénévolement et en
évolution constante. Chaque unité garde son propre site, et reste
responsable et en contrôle de ses données.

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
[CONTRIBUTING.md](CONTRIBUTING.md) ; les autres documents sont listés
ci-dessous.

## Documentation

Pour les utilisateurs et les administrateurs d'unité :

- [docs/installation.md](docs/installation.md) — l'installation complète
  et la tâche cron.
- [docs/rental-guide.md](docs/rental-guide.md) — louer les biens de
  l'unité.
- [docs/inbound-mail-setup.md](docs/inbound-mail-setup.md) — raccorder
  une boîte mail en lecture seule.

Pour les contributeurs :

- [specifications.md](specifications.md) — ce que fait chaque fonction du
  site.
- [ARCHITECTURE.md](ARCHITECTURE.md) — l'architecture technique de
  référence.
- [SECURITY.md](SECURITY.md) — les exigences de sécurité.
- [design.md](design.md) — l'interface, le vocabulaire et la charte
  éditoriale.
- [docs/exigences-non-fonctionnelles.md](docs/exigences-non-fonctionnelles.md)
  — les exigences non fonctionnelles : dimensionnement, performances,
  reprise après sinistre.
- [docs/quality-pipeline.md](docs/quality-pipeline.md) — la chaîne
  qualité : tests, intégration continue, revues et release.
- [AGENTS.md](AGENTS.md) — les règles de contribution, pour les humains
  comme pour les agents IA.
- [CONTRIBUTING.md](CONTRIBUTING.md) — soumettre une contribution.
- [docs/developpement.md](docs/developpement.md) — l'environnement de
  développement, les tests et la publication d'une release.
- [docs/module-development.md](docs/module-development.md) — créer un
  module.
