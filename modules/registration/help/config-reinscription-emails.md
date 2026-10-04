---
id: config-reinscription-emails
title: Les e-mails de la campagne de réinscription
summary: Quels e-mails la campagne envoie, quand, et comment tous les couper.
category: Espace chefs d'U
role_min: admin
question: Quels e-mails la campagne de réinscription envoie-t-elle ?
question: Comment empêcher la campagne d'écrire aux familles ?
paths: /config/reinscription, /config/reinscription/reglages
related: config-reinscription, reinscription
---

Tant que l'interrupteur **« Envoyer les e-mails de la campagne »** est
actif (c'est le cas par défaut), la campagne écrit d'elle-même aux
familles :

- un e-mail d'**ouverture**, à toutes les familles, quand la campagne
  s'ouvre — à la date prévue ou par l'interrupteur « Campagne ouverte » ;
- les deux **rappels automatiques**, aux familles qui n'ont pas encore
  répondu pour tous leurs enfants ;
- un e-mail de **clôture**, aux mêmes, quand la campagne se ferme — à la
  date prévue ou par l'interrupteur.

Chacun de ces quatre e-mails part **une seule fois par campagne**. Le
bouton « Relancer maintenant » envoie en plus une relance à la demande.

Désactivé, **aucun** de ces e-mails ne part, relance manuelle comprise :
la campagne s'ouvre et se ferme quand même aux dates prévues, et les
familles doivent être prévenues autrement.

Chaque e-mail part une fois par campagne, donc rien ne part deux fois :
rouvrir une campagne dont l'e-mail d'ouverture est parti n'écrit à
personne, et la refermer après son e-mail de clôture non plus. Fermer une
campagne qui n'a pas commencé, ou dont la date de clôture est passée,
n'écrit à personne non plus. Chaque e-mail porte l'année de sa campagne, la même en mai et en octobre. S'il n'y a aucune famille à prévenir, la confirmation le dit : « Aucun e-mail ne partira ».

**Tout enregistrement qui change quelque chose est confirmé**, et la
confirmation dit si un e-mail va partir : combien, lesquels, à qui — ou
pourquoi aucun. Si vous annulez, rien n'est enregistré. Une date
d'ouverture déjà passée n'ouvre rien : une date manquée est manquée.

## Relancer maintenant

Avant l'envoi, la question dit combien de familles vont la recevoir,
quand le dernier rappel automatique est parti et quand le prochain est
prévu — les mêmes dates que le tableau de bord — pour ne pas relancer
deux jours avant un rappel déjà programmé.

Une relance manuelle est **indépendante** des rappels automatiques : elle
ne les remplace pas et ne les empêche pas, et vous pouvez en envoyer
plusieurs à des moments différents.
