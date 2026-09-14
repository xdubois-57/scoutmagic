---
id: courrier-sortant-sonde
title: Savoir où arrivent vos messages
summary: Envoyer un message de test comme un vrai, noter où il est tombé, et comparer deux chemins.
category: Configuration
role_min: superadmin
question: Comment savoir si nos e-mails tombent dans les indésirables ?
question: Comment comparer deux fournisseurs d'envoi ?
question: À quoi sert une adresse témoin extérieure ?
question: Pourquoi ne pas envoyer un test automatique chaque semaine ?
paths: /config/courrier-sortant/sonde
related: courrier-sortant, courrier-sortant-authentification, courrier-sortant-pannes
---

Un message accepté par le serveur du destinataire n'est pas un message
lu : il peut avoir été rangé dans les indésirables, où presque personne
ne regarde. Aucun site ne peut voir l'intérieur de la boîte de quelqu'un
d'autre. La seule façon de savoir est d'envoyer un message et d'aller
regarder.

## Envoyer une sonde

Trois choses à choisir.

- **La destination.** Votre propre adresse chez un grand fournisseur
  (Gmail, Outlook, Hotmail, Proximus…) en dit déjà beaucoup : ce sont
  ceux qui trient le plus.
- **Le fournisseur.** Le message part par celui-là et par aucun autre,
  même s'il refuse. C'est voulu : si le site basculait discrètement sur
  un autre relais, vous mesureriez le mauvais chemin.
- **La voie.** « Masse » par défaut, parce que c'est celle du
  publipostage et celle qui pose problème. La voie d'authentification —
  celle des liens de connexion — n'échoue pour ainsi dire jamais : la
  tester rassurerait sans rien apprendre.

Le message envoyé est bâti **exactement comme un vrai envoi de l'unité** :
même mise en page, même expéditeur affiché, même signature technique. Un
message dépouillé serait classé autrement, et vous mesureriez alors autre
chose que ce que vous envoyez vraiment.

## Aller regarder, puis noter

Ouvrez la boîte de destination et cherchez le code affiché à l'écran —
il est dans le sujet. **Cherchez aussi dans les indésirables** : c'est
souvent là qu'il est, et c'est justement le résultat qui vous intéresse.

Puis dites à la page où vous l'avez trouvé : réception, indésirables, ou
jamais reçu. C'est vous l'instrument ; le site ne peut pas le deviner.

> **Ne sortez pas le message des indésirables.** Le marquer comme
> légitime apprend quelque chose au fournisseur : la mesure suivante ne
> vous dira plus si le message est bien arrivé tout seul.

## Ce que l'historique sert à faire

Une ligne seule ne dit pas grand-chose. Deux lignes, oui : même
destinataire, réception par un fournisseur, indésirables par l'autre —
et le débat est tranché. C'est aussi ce qui vous évite de refaire dans
trois mois un test que vous avez déjà fait sans plus vous rappeler ce
qu'il avait donné.

## L'adresse témoin d'un service extérieur

Plusieurs services gratuits donnent une adresse à usage unique : vous
envoyez votre message à cette adresse, puis vous ouvrez la page qu'ils
vous indiquent, et vous obtenez un verdict **chez plusieurs fournisseurs
d'un coup**, avec le détail de ce qui pénalise votre message. Collez
simplement leur adresse dans le champ « Destination ». Cela ne demande
aucune installation et rien à configurer ici.

## Pourquoi il n'y a pas d'envoi automatique

Un message de test qui tombe en indésirables et y reste renforce ce
classement à chaque envoi. Répétée toutes les semaines sans que personne
ne regarde, la sonde finirait par causer ce qu'elle mesure. Envoyez-en
une quand vous changez quelque chose, ou quand quelqu'un vous signale un
message manquant.
