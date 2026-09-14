---
id: stockage
title: Choisir un stockage, et savoir lequel convient à quoi
summary: Où le site écrit ses fichiers, et ce que chaque type de stockage sait faire.
category: Configuration
role_min: superadmin
question: Où sont stockés les fichiers du site ?
question: Quel type de stockage choisir pour les photos ?
question: Comment déclarer un disque réseau ?
paths: /config/stockage/emplacements, /config/stockage/emplacements/nouveau, /config/stockage/emplacements/*/modification
related: stockage-espace, stockage-apres-un-sinistre, config-galerie
---

Un **emplacement de stockage**, c'est une destination où le site écrit
des fichiers : les photos d'un album, les sauvegardes. Vous les déclarez
ici, une fois ; chaque partie du site choisit ensuite le sien, sur sa
propre page de configuration.

## Quel type choisir

Le site ne vous demande pas de choisir sur des mots techniques. Chaque
fiche dit ce que la destination sait faire, en conséquences :

- **Photos** — le plus rapide veut dire que les photos vont directement
  du stockage au visiteur ; un peu plus lent veut dire qu'elles
  transitent par le site. Sur un album ouvert par trente parents le même
  soir, ça se sent.
- **Vidéos** — un non est le seul refus vraiment bloquant de cette
  liste. Sans la lecture par morceaux, un lecteur démarre le film mais
  n'y avance jamais : ce n'est pas plus lent, c'est inutilisable.
- **Sauvegardes** — un envoi volumineux interrompu reprend, ou repart de
  zéro. Sur une archive de plusieurs gigaoctets et une connexion
  capricieuse, la différence peut vouloir dire jamais.
- **Place restante** — certains stockages savent dire ce qu'ils
  contiennent, d'autres non. Ceux qui ne savent pas n'affichent rien
  plutôt qu'une barre fausse.

En pratique : le disque du serveur convient tant que le volume reste
raisonnable et ne demande aucune configuration. Un stockage externe
devient intéressant quand les photos dépassent ce que l'hébergement peut
porter.

## Un dossier ailleurs sur le serveur

Un emplacement local vise un simple sous-dossier du site, ou un **chemin
absolu** — un disque réseau, un second disque. Dans ce second cas, le
site vous prévient au moment où vous saisissez le chemin : ce dossier
n'est pas effacé par une réinitialisation complète, et son contenu n'est
repris dans aucune archive de sauvegarde.

Les deux moitiés comptent. Ne pas être effacé est une protection ; ne
pas être dans les archives veut dire que ce dossier a besoin de sa
propre copie de secours.

Un dossier situé dans la partie du site servie par le serveur web est
refusé : tout ce qui y serait déposé deviendrait téléchargeable par
n'importe qui, sans aucun contrôle.

## Tester un emplacement

Tester écrit réellement un fichier témoin, le relit et le supprime.
C'est la seule manière de distinguer un dossier présent d'un dossier
utilisable : un montage réseau peut avoir disparu, être passé en lecture
seule, ou être devenu très lent.

Le test s'arrête de lui-même au bout de quelques secondes plutôt que
d'attendre indéfiniment — une page de configuration figée est
précisément la page dont vous auriez besoin pour réparer le montage.

> Un cas résiste : un montage réseau dur dont le serveur a disparu
> bloque le système lui-même, et aucun réglage du site ne peut
> l'interrompre. Le remède est dans les options de montage, côté
> serveur.

## Supprimer un emplacement

Un emplacement encore utilisé ne se supprime pas : la fiche dit qui s'en
sert, réattribuez cet usage ailleurs d'abord. Supprimer un emplacement
**n'efface aucun fichier** — c'est la déclaration qui disparaît, pas le
contenu.
