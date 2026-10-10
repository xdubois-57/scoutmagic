# Installer ScoutMagic

Ce guide s'adresse à l'administrateur d'unité qui installe le site. Le
[README](../README.md) en donne la version courte ; ici se trouvent le
détail de chaque étape et les raisons de chaque exigence.

## Prérequis

- PHP >= 8.4
- MySQL >= 8.0
- **Une tâche planifiée (cron) exécutant `php <racine>/public/cron.php` chaque minute** — voir « La tâche cron » ci-dessous. Ce n'est pas une option de confort : c'est le seul mécanisme qui fait travailler le site.
- Accès FTP au serveur d'hébergement

## Installation sur hébergement mutualisé

Aucun SSH, Git ou Composer nécessaire sur le serveur — seulement le FTP, et une seule fois.

1. Téléchargez `bootstrap.php` depuis la [dernière release](https://github.com/xdubois-57/scoutmagic/releases/latest).
2. Envoyez-le par FTP dans le dossier web vide que votre hébergeur sert comme racine du document.
3. Ouvrez-le dans un navigateur, en HTTPS (ex. `https://votre-domaine.be/bootstrap.php`) : ouvert en `http://`, il ne demande rien et vous renvoie vers l'adresse `https://`, pour que le jeton ne circule jamais en clair. Il écrit aussitôt un fichier `token.php` à côté de lui-même et vous demande sa valeur avant toute autre chose : lisez-la par FTP et recopiez-la. Après quelques essais ratés, la saisie est bloquée de plus en plus longtemps, comme dans l'assistant.
4. Il vérifie ensuite que le site **répond en HTTPS** depuis ce dossier, et ne va pas plus loin tant que ce n'est pas le cas : le message dit quoi corriger (certificat, port 443, adresse HTTPS qui ne mène pas à ce dossier), puis relancez la vérification.
5. **Pour remonter un site à partir d'une sauvegarde portable**, choisissez-la à cette étape : seul son en-tête est lu, par votre navigateur, pour afficher son site d'origine, sa date et sa version. Le bootstrap installe alors **cette version-là** plutôt que la dernière, puis envoie l'archive au serveur par fragments, en reprenant là où il s'était arrêté si la connexion tombe. Mettez ensuite le site à jour normalement, par Configuration › Maintenance. Sans sauvegarde, il installe la dernière release.
6. Cliquez sur **Installer**. L'écran explique lequel des deux types d'installation il a choisi pour votre hébergeur et pourquoi, puis rapporte la progression étape par étape et un tableau succès/échec pour chaque vérification de sécurité (y compris celles que votre propre navigateur a effectuées en récupérant des URLs directement). Tout échec annule proprement l'installation et explique quoi corriger — rien n'est laissé à moitié installé. À la fin, il se supprime lui-même et vous mène à l'assistant de configuration, qui reconnaît le jeton déjà saisi (pendant deux heures) ; avec une sauvegarde, directement à son mode restauration. `token.php` est supprimé automatiquement une fois l'assistant terminé.
7. **Configurez la tâche cron** dans le panneau de votre hébergeur : `* * * * * php <chemin absolu>/public/cron.php` (voir « La tâche cron » ci-dessous — le mot `php` est indispensable). L'assistant affiche la ligne exacte et une pastille d'état qui passe au vert toute seule ; tant qu'elle est rouge, le bouton « Installer » reste bloqué, parce qu'une installation sans cron ne ferait jamais rien en arrière-plan.
8. Complétez l'assistant : identifiants de base de données, paramètres de l'unité, configuration email, et votre compte administrateur — ou, en mode restauration, la phrase de passe de la sauvegarde et la base de données vide qui la recevra.

**Activer les mises à jour automatiques** : une fois installé, allez dans *Configuration > Maintenance* et générez un secret de webhook GitHub. Dans les *Settings > Webhooks* de votre dépôt GitHub, ajoutez un webhook avec :
- **Payload URL** : `https://votre-domaine.be/api/webhook/github`
- **Content type** : `application/json`
- **Secret** : la valeur générée sur la page Maintenance
- **Events** : *Releases* et *Pushes* — les deux que la page Maintenance demande

Sans cela, le site n'apprend jamais qu'une nouvelle release existe — voir ARCHITECTURE.md §8.17 pour le fonctionnement de l'installation des mises à jour une fois notifié.

## La tâche cron

```
* * * * * php /chemin/vers/le/site/public/cron.php
```

`public/cron.php` est **le seul mécanisme** qui fait avancer la file de
tâches : sauvegardes, mises à jour automatiques, notifications, rappels,
migrations de schéma, purges. Rien ne se déclenche à la visite d'un
membre — le site ne travaille que quand le cron le réveille. Sans cette
ligne, un site installé ne fait strictement rien en arrière-plan.

Trois choses à savoir avant de la copier :

- **Le mot `php` au début n'est pas facultatif.** Plusieurs panneaux
  d'hébergement proposent un champ « Adresse du script » et acceptent un
  simple chemin de fichier. Un chemin nu n'exécute rien, et ne le signale
  à personne : c'est ce qui a fait tourner l'installation de référence
  pendant des jours sans qu'aucune tâche ne parte.
- **Une fois par minute, pas moins.** La cadence attendue est la minute ;
  c'est elle qui borne le retard maximal d'une tâche et la durée pendant
  laquelle une mise à jour reste en cours.
- **Rien à craindre d'une tâche longue.** Un passage qui déborde sur la
  minute suivante garde un verrou : le passage suivant s'arrête aussitôt,
  sans rien afficher, et reprend au tour d'après.

La ligne exacte, avec le chemin réel de votre installation, est affichée
sur la page « Installation & serveur » et sur Configuration > Maintenance,
qui indiquent aussi en direct si le cron tourne et à quelle cadence. Lors
d'une **première installation**, le bouton « Installer » reste bloqué tant
qu'un passage réel n'a pas été détecté.

## Installation depuis les sources

Pour qui dispose d'un accès SSH, de Git et de Composer sur le serveur :

1. Cloner le dépôt.
2. Exécuter `composer install`.
3. Pointer la racine de votre serveur web vers `public/`.
4. Installer la tâche cron (voir « La tâche cron » ci-dessus) — l'assistant refusera de terminer sans elle.
5. Accéder au site — l'assistant de configuration vous guidera.
