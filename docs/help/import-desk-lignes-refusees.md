---
id: import-desk-lignes-refusees
title: Un fichier Desk refusé ligne par ligne
summary: Pourquoi l'import refuse un fichier dont une valeur est illisible, et comment le corriger.
category: Espace chefs d'U
role_min: admin
question: Pourquoi mon fichier Desk est-il refusé ligne par ligne ?
question: Que veut dire « date de naissance illisible » à l'import ?
question: Comment corriger un fichier Desk refusé ?
paths: /admin/import
related: import-desk, config-desk
---

Avant d'écrire quoi que ce soit, le site relit chaque ligne du fichier.
Il suffit d'une seule valeur illisible pour que **tout le fichier soit
refusé** : rien n'est importé, rien n'est conservé, et la page affiche la
liste de toutes les erreurs, avec pour chacune le numéro de ligne, le
code Tiers, la colonne et la valeur trouvée. Par exemple :

> Ligne 12 (T0280) — Date de naissance « 28/11/20 » illisible : format
> attendu JJ/MM/AAAA.

Le numéro de ligne est celui qu'affiche un tableur, l'en-tête étant la
ligne 1. Ce que le site exige :

- une **date de naissance** pour chaque ligne, lisible (JJ/MM/AAAA, ou
  AAAA-MM-JJ) et vraisemblable : ni dans le futur, ni avant 1900 ;
- un **Tiers**, un **Nom** et un **Prenom** sur chaque ligne, et les
  mêmes nom, prénom, genre, date de naissance et e-mail sur toutes les
  lignes d'un même Tiers ;
- un **Genre** qui vaut F, H ou X (M n'est pas accepté : Desk écrit H pour un garçon) ;
- **true** ou **false**, et rien d'autre, dans les colonnes de
  consentement courrier et de fonction principale — un tableur réglé en
  français écrit parfois VRAI ou FAUX ;
- une **FONCTION** sur chaque ligne, et exactement une fonction
  principale par membre ;
- un **e-mail** vide ou bien formé ;
- des **dates de fonction** vides ou lisibles, dont aucune ne commence
  après la fin de l'année scoute choisie — sinon le fichier vise
  probablement une autre année ;
- une **branche** chaque fois qu'une section est indiquée ;
- autant de colonnes sur chaque ligne que dans l'en-tête (le « ; » que
  Desk ajoute en fin de ligne est accepté) : une ligne coupée ou décalée
  vient presque toujours d'un guillemet mal fermé.

Ces erreurs viennent presque toujours d'un fichier retouché dans un
tableur. Le plus sûr est de corriger dans Desk, puis de refaire l'export
et de déposer le nouveau fichier tel quel, sans l'ouvrir entre-temps.
Les codes que le site ne connaît pas encore (fonction, branche,
section, cotisation) ne sont pas des erreurs : ils sont créés et
signalés sur la page « Correspondances Desk ».
