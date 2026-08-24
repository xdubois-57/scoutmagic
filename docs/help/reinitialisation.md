---
id: reinitialisation
title: Réinitialiser ou restaurer le site
summary: La zone de danger : paramètres par défaut, restauration d'une sauvegarde, remise à zéro.
category: Configuration
role_min: admin
paths: /config/maintenance
related: sauvegardes, mises-a-jour
---

Le bloc rouge « Réinitialisation », en bas de la page Maintenance,
regroupe les trois actions les plus lourdes du site. Chacune exige de
taper un mot de confirmation exact, vérifié par le site, et une
sauvegarde de sécurité est prise automatiquement avant d'agir. Ces
actions demandent le rôle d'administrateur du site.

## Paramètres par défaut

Remet tous les réglages (généraux et modules) à leurs valeurs
d'origine. Les comptes, les membres, les contenus et les fichiers ne
sont pas touchés. Confirmation : tapez REINITIALISER.

## Restaurer une sauvegarde

Remplace la base de données et les fichiers actuels par ceux d'une
sauvegarde : choisissez-en une sur le serveur, ou téléversez une
archive téléchargée auparavant (une archive chiffrée demande son mot
de passe ; un gros fichier s'envoie automatiquement par morceaux).
Confirmation : tapez RESTAURER.

Tout ce qui a été fait sur le site **après** la date de la sauvegarde
sera perdu : inscriptions, messages, modifications de contenu.
Vérifiez la date affichée avant de confirmer.

## Réinitialisation complète

Efface définitivement toutes les données : le prochain visiteur
retrouvera l'assistant d'installation, comme sur un site neuf. Il faut
cocher la case de compréhension, taper EFFACER, puis confirmer encore
une fois.

> Cette action est irréversible. La seule façon de revenir en arrière
> est une sauvegarde complète téléchargée **avant** — celle prise
> automatiquement au moment d'agir disparaît avec le serveur si
> l'hébergement est résilié. Téléchargez une copie d'abord.

## En pratique

Ces actions servent rarement : une restauration après une mauvaise
manipulation, une remise à zéro avant de céder l'hébergement ou pour
repartir proprement après des essais. Dans le doute, commencez
toujours par télécharger une sauvegarde complète.
