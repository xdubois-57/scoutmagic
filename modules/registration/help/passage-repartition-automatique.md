---
id: passage-repartition-automatique
title: Répartir automatiquement les passages
summary: Placer d'un seul geste tous ceux que personne n'a encore placés, et repartir de zéro.
category: Espace chefs d'U
role_min: admin
paths: /passage
related: passage, previsions, config-reinscription
---

Le bouton **« Optimiser la répartition »** de la page Passage place d'un
seul geste tous ceux que personne n'a encore placés — nouvelles
inscriptions et changements de branche ensemble.

## Avant de lancer

Le dialogue vous dit d'abord combien de personnes restent à répartir et
combien d'assignations seront conservées : **une ligne qui porte déjà une
section n'est jamais touchée**. Pour tout reprendre à zéro, réinitialisez
d'abord.

## Les deux méthodes

- **Souhaits et équilibre** — respecte les souhaits sans dépasser l'écart
  configuré entre les sections d'une même branche. C'est le choix par
  défaut.
- **Respecter les souhaits** — chacun va où on l'a demandé, sans aucun
  équilibrage.

## Ce qui compte, dans l'ordre

La section demandée par la famille d'abord, puis les frères et sœurs
ensemble, puis les souhaits d'amitié. Seuls les souhaits rattachés à une
seule personne — ou que vous avez tranchés vous-même — sont utilisés.

Deux limites sont réglables dans la configuration de la réinscription :
l'écart maximal entre sections, et le fait de garder ou non une fratrie
ensemble. Quand les deux ne peuvent pas tenir en même temps, ce sont les
**premières années** qui priment, et le résultat vous dit de combien
l'effectif dépasse.

Le bouton répond toujours quelque chose : il n'y a pas de cas où il
refuse. Une répartition qu'il ne peut pas rendre parfaite arrive quand
même, avec la phrase qui explique pourquoi.

## Réinitialiser

**« Réinitialiser »** vide toutes les destinations de l'année préparée,
puis repose celles qui n'étaient pas un choix — une branche qui ne compte
qu'une seule section. Le résultat est donc rarement une page entièrement
vide, et c'est normal.

Rien de tout cela n'écrit quoi que ce soit dans les données de la
fédération : comme les choix faits à la main, ces destinations préparent
l'encodage dans Desk, rien de plus.
