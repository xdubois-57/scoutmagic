---
id: appareils-synchronises
title: Appareils synchronisés
summary: Autoriser un téléphone à recopier le carnet d'adresses du staff.
category: Mon compte
role_min: admin
question: Comment retrouver les contacts du staff dans mon téléphone ?
question: Que se passe-t-il si je révoque un appareil ?
question: Où retrouver le mot de passe de mon appareil ?
paths: /account/devices
related: mon-compte, synchronisation-contacts, carnet-adresses-synchronise
---

Depuis Mon compte, « Gérer mes appareils » ouvre la liste des appareils
autorisés à recopier le carnet d'adresses des animateurs et du Staff
d'U — celui que tout membre identifié voit déjà sur le trombinoscope.

## Enregistrer un appareil

Donnez-lui un nom, puis « Enregistrer un appareil ». Le site affiche
alors un nom d'utilisateur (votre adresse e-mail) et un mot de passe.

Ce mot de passe **n'est affiché qu'une seule fois**. Copiez-le tout de
suite dans le carnet d'adresses de l'appareil. Si vous le perdez,
révoquez l'appareil et enregistrez-en un autre — personne, pas même un
superadministrateur, ne peut le retrouver.

## Ce que l'appareil reçoit

Les fiches des animateurs et du Staff d'U : nom, totem, section,
fonction, adresses e-mail, téléphones, adresses postales, photo et
historique des fonctions. **Aucun animé, aucun numéro de parent.**

## Connecter l'appareil

L'adresse à donner à l'application, le sens de circulation des fiches et
la marche à suivre quand la connexion échoue sont dans
« Carnet d'adresses synchronisé », où la même page vous emmène.

## Ce que cela implique

La copie descendue appartient à l'appareil. Elle suit ses sauvegardes,
elle part avec lui, et **révoquer ici n'efface rien de ce qui est déjà
descendu** : cela arrête seulement les synchronisations suivantes.

Un appareil enregistré et jamais utilisé reste un identifiant valide. Le
site vous le signale, et ne le supprime pas de lui-même.

## Votre accès suit votre rôle

Votre identifiant d'appareil ne s'autorise jamais tout seul : à chaque
requête, le site recalcule votre rôle. Si vous quittez le Staff
d'unité, la synchronisation s'arrête d'elle-même, sans attendre que
quiconque pense à révoquer quoi que ce soit.

> Un superadministrateur peut couper la synchronisation pour tout le
> site, et révoquer n'importe quel appareil. La création et la
> révocation d'un appareil sont consignées au journal, ainsi que les
> échecs d'authentification.
