---
id: config-envoi-mails
title: Configurer l'envoi de mails
summary: Les listes de diffusion personnalisées et la vitesse d'envoi.
category: Configuration
role_min: superadmin
paths: /config/mass-mail
related: envoi-de-mails, publipostage
---

Cette page règle ce qui vaut pour tous les envois groupés du site :
les listes de diffusion et la cadence d'envoi.

## Les listes de diffusion

Les listes par défaut — une par section, les membres actifs, les
animateurs — sont fournies par le site et ne se modifient pas (elles
portent un cadenas). Vous pouvez créer vos propres listes en croisant
des fonctions et des sections : par exemple « les intendants de
toutes les sections », ou « les animateurs des deux sections aînées ».

Une liste personnalisée demande un nom, une description, au moins une
fonction et au moins une section. Sa composition se recalcule à chaque
envoi à partir des données de la fédération : elle reste juste après
chaque mise à jour, sans entretien.

Une liste déjà utilisée par un e-mail ne peut pas être supprimée —
désactivez-la : elle disparaît du choix des nouvelles compositions,
mais l'historique des envois reste lisible.

## La vitesse d'envoi

Les e-mails partent par lots, à un rythme réglable : tant d'e-mails
toutes les tant de minutes. Ce réglage est **global au site** — il
s'applique à tous les envois en cours, quel que soit leur expéditeur.

> Les hébergeurs limitent souvent le nombre d'e-mails par heure. Si
> des envois finissent en erreur par paquets, ralentissez la cadence
> plutôt que de relancer : un rythme trop élevé peut faire classer
> toute l'unité en expéditeur indésirable.

Le préfixe du sujet des e-mails (le nom court entre crochets) et les
réglages du serveur d'envoi ne sont pas ici : ils se trouvent sur la
page « Installation & serveur ».
