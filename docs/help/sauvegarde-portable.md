---
id: sauvegarde-portable
title: Emporter le site ailleurs
summary: La sauvegarde portable, la seule qui se restaure sur une autre installation — et ce que cela coûte.
category: Configuration
role_min: admin
discovery: off
question: Comment restaurer mon site chez un autre hébergeur ?
question: Pourquoi ma sauvegarde est-elle illisible sur une installation neuve ?
paths: /config/maintenance
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

## Une phrase de passe d'au moins seize caractères

Quatre mots ordinaires suffisent, et se retiennent : c'est la
longueur, pas les majuscules ni les chiffres, qui protège vraiment. Le
site refuse plus court.

**Elle n'est jamais enregistrée en clair.** Le site en garde une copie
chiffrée le temps de fabriquer l'archive — dans la tâche de
sauvegarde, jusqu'à la suppression de celle-ci — parce que la
génération se fait en arrière-plan, après que vous avez quitté la
page. Nulle part ailleurs, et jamais lisible telle quelle.

Une archive dont la phrase de passe est perdue est donc
définitivement illisible — par un intrus comme par vous. Notez-la là
où vous notez ce qui compte, pas dans un fichier posé à côté de
l'archive.

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

La galerie photo. C'est l'archive faite pour partir, et les photos sont
ce qui la rendrait trop lourde pour ça — sauvegardez-les à part si vous
y tenez.
