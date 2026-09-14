---
id: config-galerie
title: Configurer la galerie
summary: Les quatre onglets de la galerie — albums externes, limites photo et vidéo, et le déplacement d'un album.
category: Configuration
role_min: superadmin
question: Où sont stockées les photos de la galerie ?
question: Comment déplacer un album vers un autre stockage ?
question: Comment limiter la taille des photos envoyées ?
paths: /config/gallery
related: galerie, gerer-la-galerie, stockage
---

La page se lit en quatre onglets.

## Général

**Albums externes** : un album qui pointe vers un lien de partage
plutôt que d'héberger ses médias — un album Google Photos partagé par
un parent, par exemple. Rien n'est copié sur le site : si le partage
est retiré, l'album se vide.

**Nombre maximum de médias par album** : la somme des photos et des
vidéos.

**Emplacement des nouveaux albums** : où iront les photos des albums
créés **à partir de maintenant**.

> Les albums existants ne bougent pas. C'est le sens exact du mot
> « nouveaux » : changer ce réglage ne déplace rien. Pour déplacer un
> album, voir l'onglet « Albums ».

Les emplacements eux-mêmes ne se déclarent pas ici mais dans
Configuration › Stockage — c'est une décision qui dépasse la galerie,
puisque les sauvegardes s'en servent aussi.

## Photos et Vidéos

Les tailles maximales, la dimension à laquelle une photo est réduite,
la durée maximale d'une vidéo. Baisser ces limites est le second levier
quand l'espace se fait rare, le premier étant de changer d'emplacement.

Une vidéo n'est lisible que sur un emplacement qui sait lire par
morceaux — sinon le lecteur démarre le film mais ne peut jamais y
avancer. La page Stockage le dit emplacement par emplacement, sur la
ligne « Vidéos ».

## Albums

Déplace les photos et vidéos d'un album d'un emplacement vers un autre,
en arrière-plan. **L'album est indisponible pour les membres pendant
l'opération**, puis tout reprend sans autre intervention. Un
déplacement qui échoue laisse l'album exactement comme avant : la
source n'est libérée qu'à la toute dernière étape.

Certains albums de cette liste appartiennent à un autre module — les
photos d'un groupe de discussion, par exemple. Ils n'apparaissent nulle
part ailleurs dans la galerie, mais ils occupent une place réelle sur
un emplacement réel : les lister ici est ce qui vous permet d'expliquer
l'espace consommé.

## Où voir la place restante

Sur la page Configuration › Stockage, qui mesure l'espace par volume et
dit, pour chacun, s'il s'appuie sur le quota que vous avez déclaré ou
sur ce que rapporte le système.
