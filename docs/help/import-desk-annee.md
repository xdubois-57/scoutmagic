---
id: import-desk-annee
title: Quelle année scoute importer, et quand
summary: Les années que l'import Desk accepte selon la date, et la confirmation demandée pour l'année publique.
category: Espace chefs d'U
role_min: admin
question: Pourquoi je ne peux pas importer l'année prochaine ?
question: Pourquoi le site me demande de confirmer l'import dans l'année publique ?
question: Quand l'import passe-t-il à l'année suivante ?
paths: /admin/import
related: import-desk, annee-scoute, reglages
---

L'import Desk n'accepte pas n'importe quelle année : il suit le
calendrier de la rentrée, à partir de l'année publique, celle que
voient les familles.

## Les trois périodes

- **Avant le 1er juillet**, seule l'année publique est importable.
- **Du 1er juillet au 14 août**, l'année suivante l'est aussi, et c'est
  elle que la page propose. Importer dans l'année publique reste
  possible, mais le site vous demande de confirmer : ce fichier
  modifierait l'année que voient les familles. Le fichier reste
  sélectionné si vous annulez.
- **À partir du 15 août**, seule l'année suivante est importable,
  jusqu'à ce que vous basculiez le site vers elle depuis la page
  « Année scoute ».

Après la bascule, la règle repart de la nouvelle année publique : si
vous basculez dès le mois d'août, seule la nouvelle année est
importable jusqu'à l'été suivant. Le site n'ouvre jamais une deuxième
année d'avance.

Importer ne change jamais l'année que voient les familles. Le bloc
« Dernier import » suit l'année choisie dans la liste. Depuis la page
« Année scoute », le lien d'import ouvre cette page sur l'année cible,
présélectionnée seulement si la règle l'accepte aujourd'hui. La page
montre toujours les trois périodes, celle du jour mise en évidence.

## Régler les deux dates

Les deux dates se règlent dans Configuration > Réglages :
« Ouverture de l'import pour l'année suivante » (1er juillet par
défaut) et « Date d'encodage Desk » (15 août par défaut), au format
MM-JJ. Elles doivent tomber avant le 1er septembre, et l'ouverture doit
précéder la date d'encodage : sinon le réglage est refusé, avec un
message qui cite les deux valeurs.

## En mode configuration

Le mode configuration rend importables toutes les années existantes,
par exemple pour reprendre un import ancien. Une année hors de la règle
habituelle demande la même confirmation. Les contrôles du fichier et la
protection contre un export filtré (le mot **REMPLACER**) s'appliquent
toujours.

> Une année refusée n'est jamais importée, même en renvoyant le
> formulaire : le contrôle se fait sur le serveur, et le refus est
> consigné au journal.
