---
id: courrier-sortant-routage
title: Faire passer un fournisseur par un autre relais
summary: Quand les boîtes témoins montrent qu'un fournisseur écarte vos envois, vous pouvez router ses messages par un autre de vos relais — ou laisser le site le faire.
category: Configuration
role_min: superadmin
question: Un fournisseur met tous mes envois en indésirables, que faire ?
question: Comment changer de relais pour Gmail seulement ?
question: Qu'est-ce que le routage automatique par fournisseur ?
paths: /config/courrier-sortant/temoins
related: courrier-sortant-temoins, courrier-sortant, courrier-sortant-acheminement
---

## Ce que le tableau vous propose

Sous les résultats, « Ce que ces résultats suggèrent » reprend chaque
fournisseur de messagerie mesuré et dit ce qu'on peut en conclure. Quand
l'un d'eux écarte une part notable de vos envois, un bouton vous propose
de faire passer ses messages par un autre de vos relais.

Le relais proposé est simplement le suivant dans l'ordre de la voie
masse, celui que vous avez vous-même déclaré sur la page
« Acheminement ». Rien n'est appliqué tant que vous ne cliquez pas.

## Deux verrous, et ils ne sont pas décoratifs

**Il faut au moins cinq envois mesurés** chez un fournisseur avant qu'un
constat veuille dire quelque chose. En dessous, une seule campagne
malchanceuse — une formulation qui a déplu à un filtre — représente la
majorité des observations, et router là-dessus, c'est router sur du
bruit.

Ces cinq envois se comptent en **publipostages**, pas en copies : cinq
boîtes chez le même fournisseur sur un seul envoi, c'est une observation
répétée cinq fois, pas cinq observations.

**Et changer de relais n'est pas gratuit.** La réputation d'un relais se
construit sur un trafic régulier et prévisible. En envoyer une partie
ailleurs donne à chacun moins de ce dont sa réputation dépend. Le remède
peut donc coûter plus cher que le défaut : un constat répété sur
plusieurs envois vaut qu'on agisse, un mauvais mois non.

## Si vous n'avez qu'un seul relais

C'est le cas le plus fréquent, et il n'y a alors nulle part où router.
La page l'écrit plutôt que de vous proposer un bouton inutile. La
réponse à un fournisseur qui vous filtre est alors de revoir ce que vous
envoyez — objet, fréquence, pièces jointes — et de vérifier la page
« Authentification ».

## Le routage automatique

Le bouton « Appliquer automatiquement » confie la décision au site. Il
agit une fois par fournisseur, lors du balayage quotidien, et seulement
quand les deux verrous ci-dessus sont franchis.

Il ne revient **jamais** en arrière de lui-même, et c'est voulu : un
fournisseur qui ne pose plus de problème ne le pose plus **sur son
nouveau relais**. Le ramener sur l'ancien le remettrait en difficulté, et
les deux lectures alterneraient indéfiniment. Revenir à l'ordre habituel
reste votre décision, depuis le lien de la ligne concernée.

## Ce qui n'est jamais routé

Seuls les **publipostages** changent de chemin. Un lien de connexion, une
notification, un accusé de réception prennent toujours la même route,
quel que soit le destinataire. Un lien magique ne vit que quelques
minutes : une route qui varierait selon le fournisseur de la personne
serait impossible à diagnostiquer le jour où quelqu'un ne peut plus se
connecter.

Un relais désactivé, à quota épuisé ou mis de côté par le coupe-circuit
reste écarté malgré votre choix : ces raisons-là priment toujours.
