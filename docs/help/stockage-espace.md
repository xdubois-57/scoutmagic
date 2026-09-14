---
id: stockage-espace
title: Lire la place restante, volume par volume
summary: Pourquoi l'espace se mesure par disque et non par dossier, et ce que veut dire le quota déclaré.
category: Configuration
role_min: superadmin
question: Combien reste-t-il de place sur le site ?
question: Pourquoi le site annonce-t-il de la place alors que l'hébergement est plein ?
question: À quoi sert le quota disque déclaré ?
paths: /config/stockage
related: stockage, maintenance
---

Le tableau de bord de la page Stockage répond à deux questions : ce qui
ne va pas, et combien il reste de place. Ce qui ne va pas vient en
premier, avec sa conséquence écrite en toutes lettres plutôt qu'un badge
rouge à interpréter.

## L'espace se mesure par volume

Deux dossiers sur le même disque partagent la même place libre : les
afficher séparément laisserait croire qu'on en a deux fois plus. Le site
reconnaît un volume à son numéro de périphérique et non à son nom, ce
qui lui permet de voir qu'un dossier au nom de disque réseau est en
réalité le disque système.

## Les deux mesures, et pourquoi le site dit laquelle il utilise

Chaque volume annonce sur quoi son pourcentage est calculé :

- **Quota déclaré** — la part que votre contrat d'hébergement vous
  accorde, que vous renseignez dans les réglages. C'est le chiffre qui
  vous concerne.
- **Mesure système** — ce que le serveur rapporte. Sur un hébergement
  partagé, c'est le disque de l'hébergeur, commun à d'autres comptes et
  bien plus grand que votre part : un chiffre rassurant peut être vrai
  du disque et faux de vous.

C'est pourquoi 62 % ne veut pas dire la même chose d'un volume à
l'autre, et pourquoi la phrase qui dit d'où vient le chiffre fait partie
de l'encart.

Renseignez le réglage du quota disque pour obtenir un chiffre qui a un
sens sur le volume principal. Ce quota ne s'applique **qu'à** celui-là :
sur un disque réseau, le système dit vrai et aucun quota n'est
nécessaire.

## Un dossier hors de l'espace de stockage du site

Un volume qui porte un tel dossier le signale. Une réinitialisation
complète ne l'atteint pas, et aucune archive de sauvegarde ne le
reprend : c'est une protection d'un côté, et une chose à sauvegarder
autrement de l'autre.
