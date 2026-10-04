---
id: config-reinscription-emails
title: Les e-mails de la campagne de réinscription
summary: Quels e-mails la campagne envoie, quand, et comment tous les couper.
category: Espace chefs d'U
role_min: admin
question: Quels e-mails la campagne de réinscription envoie-t-elle ?
question: Comment empêcher la campagne d'écrire aux familles ?
paths: /config/reinscription
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

**Une confirmation est demandée** avant d'enregistrer une configuration
qui ouvre immédiatement une campagne fermée en envoyant l'e-mail
d'ouverture : l'interrupteur « Campagne ouverte » activé, ou une date
d'ouverture mise à aujourd'hui. Si vous annulez, rien n'est enregistré
et la campagne reste fermée. Une date d'ouverture déjà passée n'ouvre
rien : une date manquée est manquée.

Rouvrir la campagne peu après sa fermeture, pour une famille en retard,
n'envoie pas d'e-mail d'ouverture : celui-ci annoncerait une échéance
déjà dépassée.

## Relancer maintenant

Avant l'envoi, la question rappelle qu'un e-mail va partir, quand le
dernier rappel automatique est parti et quand le prochain est prévu —
pour ne pas relancer deux jours avant un rappel déjà programmé.

Une relance manuelle est **indépendante** des rappels automatiques : elle
ne les remplace pas et ne les empêche pas, et vous pouvez en envoyer
plusieurs à des moments différents.
