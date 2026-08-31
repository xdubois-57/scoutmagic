---
id: installation-serveur
title: Installation & serveur
summary: L'identité du site, la base de données, l'e-mail, les DNS et le cron.
category: Configuration
role_min: superadmin
paths: /setup
related: reglages, sauvegardes, config-notifications
---

La page « Installation & serveur » regroupe les fondations du site.
C'est elle qui sert d'assistant à la toute première installation, puis
reste la page où l'on revient pour l'identité du site, la base de
données et l'envoi d'e-mails.

## L'identité du site

Le nom de l'unité, le nom court (cinq caractères au plus — il préfixe
l'objet de tous les e-mails, par exemple « [25SV] ») et l'adresse du
site. Le logo de l'unité se téléverse ici : il devient l'icône de
l'application installée et le favicon. Après un changement de logo, un
lien permet de prévenir les membres sur iPhone, qui doivent
réinstaller l'application pour voir la nouvelle icône.

## La base de données

Les accès à la base se testent avec « Tester la connexion » avant tout
enregistrement — le bouton « Enregistrer » reste d'ailleurs bloqué
tant qu'un test n'a pas réussi.

> Ne changez les accès à la base que si vous savez exactement
> pourquoi : une valeur erronée rend le site inaccessible, sans retour
> en arrière depuis l'interface. De même, changer l'adresse du site
> casse les liens contenus dans les e-mails déjà envoyés.

## L'envoi d'e-mails

Choisissez le mode d'envoi (un serveur SMTP est recommandé),
renseignez ses accès, puis testez avec « Envoyer un test » — sans
même devoir enregistrer d'abord. Le panneau « Configuration DNS
requise » vérifie en direct les trois enregistrements à créer chez
votre hébergeur de domaine (l'autorisation d'expéditeur, la signature
des e-mails, la politique de réception) : chacun affiche « OK » ou
« Manquant », avec la valeur exacte à copier. Sans ces
enregistrements, les e-mails de l'unité risquent la boîte à courrier
indésirable. Régénérer la clé de signature impose de mettre à jour
l'enregistrement correspondant.

## La tâche cron

Le bloc « Tâche cron » donne la ligne exacte à installer chez votre
hébergeur pour que les tâches de fond (sauvegardes, mises à jour,
notifications, rappels) tournent chaque minute. Elle est
indispensable : sans elle, le site ne travaille qu'au rythme des
visites, et rien ne se passe la nuit ni pendant les vacances.

Le mot `php` au début de la ligne n'est pas facultatif. Certains
panneaux d'hébergement proposent un champ « Adresse du script » et
acceptent un simple chemin de fichier : dans ce cas, rien ne
s'exécute, et aucun message ne le signale.

Un indicateur affiche l'état détecté et se met à jour tout seul.
Lors de la toute première installation, le bouton « Installer » reste
bloqué tant qu'il n'est pas passé au vert. Sur un site déjà
configuré, l'indicateur avertit mais n'empêche jamais d'enregistrer.

## Le compte administrateur

Les champs du bas créent ou remettent à jour le compte administrateur
du site : laissez-les vides pour ne rien changer.
