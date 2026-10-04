---
id: installation-serveur
title: Installation & serveur
summary: L'identité du site, la base de données, l'e-mail, les DNS et le cron.
category: Configuration
role_min: superadmin
discovery: off
question: Comment raccorder le site à sa base de données ?
question: Pourquoi les e-mails du site n'arrivent-ils pas ?
question: Comment vérifier que le cron du site tourne ?
paths: /setup
related: reglages, sauvegardes, config-notifications
---

« Installation & serveur » sert à la première installation puis aux réglages
qui dépendent directement de l'hébergement.

## La base de données

Les accès à la base se testent avec « Tester la connexion » avant tout
enregistrement — le bouton « Enregistrer » reste d'ailleurs bloqué
tant qu'un test n'a pas réussi.

> Ne changez les accès à la base que si vous savez exactement
> pourquoi : une valeur erronée rend le site inaccessible, sans retour
> en arrière depuis l'interface. De même, changer l'adresse du site
> casse les liens contenus dans les e-mails déjà envoyés.

## L'envoi d'e-mails, pendant l'installation seulement

L'assistant demande le mode d'envoi et ses accès. « Envoyer un test » les
essaie sans enregistrer et « Configuration DNS requise » donne les
enregistrements à créer chez votre hébergeur de domaine.

**Ensuite, ces réglages ne sont plus ici.** Le relais se règle dans
« Courrier sortant › Fournisseurs » ; un test s'envoie depuis la
« Sonde » ; les adresses, la clé de signature et la vérification DNS vivent
dans « Courrier sortant › Authentification ».

## La tâche cron

Le bloc « Tâche cron » donne la ligne exacte à installer chez votre
hébergeur pour que les tâches de fond (sauvegardes, mises à jour,
notifications, rappels) tournent chaque minute. Elle n'est pas
recommandée, elle est **obligatoire** : c'est le seul mécanisme qui
fait travailler le site. Sans elle, rien ne part — jamais, pas même à
la visite suivante.

Le mot `php` au début de la ligne n'est pas facultatif. Certains
panneaux d'hébergement proposent un champ « Adresse du script » et
acceptent un simple chemin de fichier : dans ce cas, rien ne
s'exécute, et aucun message ne le signale.

Un indicateur affiche l'état détecté et se met à jour tout seul.
Lors de la toute première installation, le bouton « Installer » reste
bloqué tant qu'il n'est pas passé au vert. Sur un site déjà
configuré, l'indicateur avertit mais n'empêche jamais d'enregistrer.

## Les mises à jour automatiques

Dans « Configuration › Maintenance », activez les mises à jour et générez le
secret. Côté GitHub, créez un webhook vers le chemin
`/api/webhook/github` de votre propre site, en `application/json`, avec ce
secret et l'événement **Releases** uniquement. Le canal stable vérifie aussi
les releases chaque jour si le webhook manque une livraison. Le mécanisme est
détaillé dans `ARCHITECTURE.md` §8.17.

## Le compte administrateur, pendant l'installation seulement

L'assistant crée le premier compte, pour se connecter sans attendre un
import Desk. Ensuite, les comptes se gèrent depuis « Comptes
superadmin ».
