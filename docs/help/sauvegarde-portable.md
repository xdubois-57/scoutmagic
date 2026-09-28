---
id: sauvegarde-portable
title: Emporter le site ailleurs
summary: La sauvegarde portable, la seule qui se restaure sur une autre installation — et ce que cela coûte.
category: Configuration
role_min: superadmin
discovery: off
question: Comment restaurer mon site chez un autre hébergeur ?
question: Pourquoi ma sauvegarde est-elle illisible sur une installation neuve ?
paths: /config/maintenance/sauvegarde-manuelle
related: sauvegardes, sauvegardes-conserver, reinitialisation
---

Les sauvegardes ordinaires du site excluent volontairement ses clés de
chiffrement : elles ne quittent jamais le serveur. C'est ce qui rend
ces archives inoffensives si l'une d'elles fuite — les données
personnelles qu'elles contiennent restent illisibles — et c'est aussi
ce qui fait qu'elles se restaurent **ici et nulle part ailleurs**.
Recopiée sur un autre hébergement, une sauvegarde complète donne un
site qui démarre et dont la base est illisible.

La **sauvegarde portable** est celle qui lève cette limite : elle
emporte les clés avec elle. C'est la seule que vous pouvez restaurer
sur une installation neuve, chez un autre hébergeur, après un sinistre
ou un déménagement.

## Ce que cela coûte, dit franchement

Son mot de passe protège, à lui seul, **toutes les données de
l'unité**. Qui obtient le fichier et la phrase de passe peut tout lire
— adresses, dates de naissance, téléphones — sur n'importe quelle
machine, sans rien d'autre.

C'est pour cela qu'elle est un bloc séparé sur la page Maintenance,
avec son propre avertissement, et qu'elle a trois règles que les
autres n'ont pas.

## Un mot de passe généré par le site

Vous n'avez rien à inventer : le site génère le mot de passe de
chaque archive, trente caractères faciles à recopier sur papier, et
le conserve. Il s'affiche dans **Sauvegardes récentes** au moment où
vous téléchargez l'archive, ou avec le bouton en forme de clé.

**Notez-le au moment de télécharger l'archive.** Le jour où vous en
aurez besoin, le serveur qui pourrait vous le redire n'existera
peut-être plus — c'est même la raison d'être de cette sauvegarde.
Une archive dont le mot de passe est perdu est définitivement
illisible, par un intrus comme par vous. Notez-le là où vous notez
ce qui compte, pas dans un fichier posé à côté de l'archive.

## Une seule est conservée

La nouvelle remplace la précédente. Ce nombre n'est pas réglable,
contrairement aux autres sauvegardes : chaque copie supplémentaire est
un exemplaire de plus des clés du site posé sur le serveur.

## Téléchargez-la, puis supprimez-la du serveur

C'est la règle qui compte le plus, et la seule que le site ne peut pas
appliquer à votre place.

Laissée sur le serveur, cette archive annule la protection qu'elle
transporte : les clés du site se retrouvent dans un fichier posé juste
à côté de ce qu'elles protègent, sur le serveur même auquel la
sauvegarde est censée survivre. Au bout d'une semaine, le site vous le
rappelle dans ses points d'attention, et le rappel ne s'arrête que
lorsque l'archive a quitté la liste des sauvegardes.

Rangez-la comme vous rangeriez les statuts de l'unité : ailleurs, et à
un endroit dont vous vous souviendrez.

## Ce qu'elle ne contient pas

Le contenu des emplacements de stockage, la galerie photo comprise.
C'est l'archive faite pour partir, et les fichiers sont ce qui la
rendrait trop lourde pour ça — sauvegardez-les à part si vous y tenez.
