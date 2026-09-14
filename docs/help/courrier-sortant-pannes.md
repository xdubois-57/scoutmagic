---
id: courrier-sortant-pannes
title: Quand le courrier ne part plus
summary: La réserve pour les liens de connexion, les fournisseurs mis à l'écart, les messages différés et leur relance.
category: Configuration
role_min: superadmin
question: Que deviennent les messages quand plus aucun fournisseur ne répond ?
question: Pourquoi un publipostage s'arrête-t-il avant d'avoir atteint le quota ?
question: Comment relancer des e-mails qui n'ont pas pu partir ?
paths: /config/courrier-sortant, /config/courrier-sortant/acheminement
related: courrier-sortant, alertes-operationnelles
---

Un relais qui ne répond plus, un quota épuisé par un publipostage : voici
ce que le site fait des messages qu'il ne peut pas remettre.

## La réserve pour les liens de connexion

Sur un fournisseur qui porte à la fois la voie « Masse » et une autre, le
site met une part de son quota de côté. Le publipostage s'arrête avant
cette part ; les liens de connexion, eux, la trouvent encore là.

Le nombre n'est pas à régler : il vient de votre historique — votre pointe
quotidienne hors publipostage des trente derniers jours, plus une marge.
« Acheminement » l'affiche sous la voie « Authentification » avec la
phrase qui dit d'où il sort, vérifiable sur vos propres compteurs.

C'est pour cela qu'un envoi en masse peut s'arrêter à 930 sur un quota de
1000. Ce n'est pas une erreur : ce sont vos connexions de demain matin.

## Un fournisseur qui tombe est mis à l'écart

Quand un relais refuse trois messages d'affilée pour une raison qui lui
appartient — il ne répond plus, le mot de passe est refusé — le site cesse
de l'essayer quelques minutes, et plus longtemps à chaque rechute. Sa
fiche le dit, et l'heure du prochain essai aussi.

Une adresse refusée ne compte pas : c'est le destinataire qui n'existe
pas, pas le relais qui va mal. Sans quoi une adresse mal orthographiée
suffirait à écarter un fournisseur en parfait état.

Une voie n'est **jamais** vidée par ce mécanisme : même si tous ses
fournisseurs sont à l'écart, le dernier est tout de même essayé. Mieux
vaut une tentative inutile qu'un site où plus personne ne peut se
connecter.

## Les messages différés

Quand aucun fournisseur d'une voie ne peut prendre un message, il est mis
de côté et réessayé — dans cinq minutes, puis de plus en plus tard,
pendant la durée fixée dans « Réglages ». La page en donne le compte, voie
par voie.

Un report n'est pas un silence : la personne qui a cliqué sur « Envoyer »,
elle, a vu un envoi réussi. Tant que la file ne se vide pas, ces messages
ne sont pas partis — et le site vous prévient quand elle cesse de se
vider.

**La voie « Authentification » ne diffère jamais.** Un lien de connexion
livré demain n'est plus un lien de connexion : il ne vit qu'un quart
d'heure, et la personne est devant son écran. Elle a besoin de la vérité
tout de suite.

## Relancer les échecs

Passé ce délai, un message est abandonné. Il reste visible un temps, avec
les autres, répartis par âge : vous voyez s'il s'agit de la panne de ce
matin ou d'une accumulation de la semaine.

« Relancer » les remet en file, pour la fenêtre et les voies que vous
choisissez. La fenêtre par défaut est la plus courte, volontairement : un
bouton qui renverrait quinze jours de messages d'un clic ne servirait
qu'une fois.

Après le délai de conservation, les abandonnés disparaissent pour de bon,
leur contenu avec eux.
