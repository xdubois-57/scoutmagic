---
id: courrier-sortant-authentification
title: Sous quelle adresse le site écrit
summary: Les adresses d'expédition et de réponse, les enregistrements DNS, et la vérification que les retours arrivent.
category: Configuration
role_min: superadmin
question: Pourquoi les e-mails du site arrivent-ils dans les indésirables ?
question: Où arrivent les réponses aux messages du site ?
question: Comment savoir si le SPF et le DKIM sont bien publiés ?
question: Comment vérifier que le courrier de retour arrive quelque part ?
paths: /config/courrier-sortant, /config/courrier-sortant/authentification
related: courrier-sortant, courrier-sortant-pannes, adresses-email
---

Un message qui part n'est pas un message qui arrive. Les serveurs des
destinataires vérifient que votre domaine autorise bien le site à écrire
en son nom ; cette page dit sous quelle adresse il écrit, et si cette
vérification passe.

## Une adresse, quatre rôles

La même adresse joue presque toujours quatre rôles à la fois, et c'est
très bien ainsi. Le tableau de la page les nomme parce qu'un seul d'entre
eux a des conséquences invisibles :

- **Expéditeur affiché** : ce que la personne lit dans sa boîte.
- **Réponses** : où arrive un simple « Répondre ». Laissez le champ vide
  si l'adresse d'expédition est relevée ; renseignez-le si personne ne la
  relève.
- **Retour des rebonds** : où le serveur du destinataire écrit quand il
  refuse le message. **C'est sur le domaine de cette adresse-là que le
  SPF est vérifié**, jamais sur celui de l'expéditeur affiché.
- **Rapports DMARC** : où les autres opérateurs envoient leur résumé
  périodique. Facultatif : sans adresse, aucun rapport n'est demandé et
  rien ne cesse de fonctionner.

## Les trois enregistrements DNS

Le bouton « Vérifier les enregistrements » interroge votre zone DNS en
direct et propose la valeur à publier. Il n'est pas lancé à l'ouverture de
la page : un résolveur qui ne répond pas ferait attendre la page entière.

La valeur proposée **intègre celle déjà publiée** plutôt que de la
remplacer : un domaine ne peut porter qu'un seul SPF et qu'un seul
`_dmarc`, et en ajouter un second les invalide tous les deux.

Si vous avez plusieurs relais dans vos chaînes, **le SPF doit tous les
autoriser**. Le jour où le premier tombe, le message part par le suivant ;
un SPF qui ne le nomme pas fait échouer exactement les messages que le
repli devait sauver.

## Vérifier que les retours arrivent

Le site s'écrit à lui-même et regarde si le message revient dans une boîte
relevée par « Courrier entrant ». C'est un aller-retour réel : comparer
les adresses ne servirait à rien, une adresse d'unité étant très souvent
un alias qui délivre dans une boîte portant un autre nom.

Trois états : **vérifié**, avec la boîte et la date ; **jamais arrivé**,
et les réponses se perdent ; **jamais vérifié**. Modifier une adresse
remet son état à « jamais vérifié » — l'ancienne réponse ne dit plus rien
de la nouvelle adresse.

Sans le module « Courrier entrant », ou sans boîte ouverte à « Courrier
sortant », la vérification annonce qu'elle est impossible. Envoyer du
courrier n'en dépend pas.
