---
id: reinitialisation
title: Réinitialiser le site
summary: La zone de danger : paramètres par défaut et remise à zéro complète.
category: Configuration
role_min: superadmin
discovery: off
question: Comment remettre les paramètres par défaut ?
question: Comment remettre le site complètement à zéro ?
paths: /config/maintenance/reinitialisation
related: sauvegardes, restaurer, mises-a-jour
---

Le bloc rouge « Réinitialisation » regroupe les deux actions les plus
lourdes du site. Chacune exige de
taper un mot de confirmation exact, vérifié par le site, et une
sauvegarde de sécurité est prise automatiquement avant d'agir. Ces
actions demandent le rôle d'administrateur du site.

## Paramètres par défaut

Remet tous les réglages (généraux et modules) à leurs valeurs
d'origine. Les comptes, les membres, les contenus et les fichiers ne
sont pas touchés. Confirmation : tapez REINITIALISER.

Restaurer une sauvegarde n'est pas réinitialiser : cela se fait dans
« Sauvegardes récentes », et le sujet « Restaurer une sauvegarde »
l'explique.

## Réinitialisation complète

Efface définitivement toutes les données : le prochain visiteur
retrouvera l'assistant d'installation, comme sur un site neuf. Il faut
cocher la case de compréhension, taper EFFACER, puis confirmer encore
une fois.

Juste avant, le site prend une copie de sécurité chiffrée. Son mot de
passe est effacé avec tout le reste : affichez-le avec le bouton prévu,
notez-le, puis cochez « J'ai noté le mot de passe ». Sans lui, cette
copie ne s'ouvre plus.

Les dossiers déclarés comme **emplacements de stockage** ne sont pas
effacés : leur contenu n'est repris dans aucune archive, il serait donc
perdu sans retour. Le site cesse simplement de les connaître ;
supprimez-les vous-même si vous vouliez repartir d'un disque vide.

> Cette action est irréversible. La seule façon de revenir en arrière
> est une sauvegarde complète téléchargée **avant** — celle prise
> automatiquement au moment d'agir disparaît avec le serveur si
> l'hébergement est résilié. Téléchargez une copie d'abord.

## En pratique

Ces actions servent rarement : une remise à zéro avant de céder
l'hébergement, ou pour repartir proprement après des essais. Dans le doute, commencez
toujours par télécharger une sauvegarde complète.
