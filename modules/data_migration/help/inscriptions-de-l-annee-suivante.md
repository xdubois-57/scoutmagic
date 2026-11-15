---
id: inscriptions-de-l-annee-suivante
title: Reprendre les inscriptions de l'année suivante
summary: Les demandes d'inscription en cours sur l'ancien site rejoignent la liste des demandes, sans qu'aucun e-mail ne parte.
category: Configuration
role_min: superadmin
discovery: off
question: Comment reprendre les demandes d'inscription de l'ancien site ?
question: Les familles reçoivent-elles un e-mail quand on importe leur demande ?
question: Que devient une demande déjà acceptée ou refusée sur l'ancien site ?
paths: /config/migration-donnees/inscriptions
related: migration-de-donnees, gerer-les-demandes, envoyer-l-e-mail-de-decision
---

Cet import reprend les **nouvelles demandes d'inscription** encore en
cours sur l'ancien site, pour l'année scoute que le fichier indique. Les
réponses aux campagnes de réinscription ne sont pas reprises. Il n'est
proposé que si le module « Inscriptions » est activé.

## Avant d'importer

- **L'année visée doit exister ici.** Le fichier la nomme, l'aperçu
  l'affiche, et l'import ne la crée jamais : les demandes d'une année
  absente sont ignorées.
- **Une demande incomplète est ignorée**, et le bilan nomme le champ qui
  manque (par exemple le téléphone). Elle n'est jamais enregistrée à
  moitié.
- **Un statut inconnu est ignoré**, jamais remplacé par un autre. Une
  demande « encodée » n'est reprise que si son membre Desk est retrouvé.
- Une section ou une catégorie de cotisation inconnue de ce site, un
  frère ou une sœur introuvable dans Desk : le lien est laissé de côté,
  la demande est reprise.

## Rien n'est envoyé

Ni accusé de réception, ni alerte à l'unité, ni e-mail de décision, ni
confirmation d'adresse. Les liens de suivi de l'ancien site ne
fonctionnent pas ici : chaque demande reçoit son propre lien, qui n'est
envoyé à personne. Une famille qui se connecte avec son adresse
retrouve sa demande comme d'habitude.

Une décision déjà prise sur l'ancien site (acceptée, refusée ou encodée)
est marquée « Communiquée par l'ancien site » dans le bloc « E-mail de
décision » de la fiche : la famille la voit tout de suite sur sa page de
suivi, sans nouvel e-mail.

## Après l'import

Les demandes sont des demandes **comme les autres**, sans marque
particulière : mêmes listes, mêmes décisions, même acceptation
automatique, même rapprochement avec Desk. Une demande qui ressemble à
une demande déjà présente est reprise et signalée « doublon possible »
dans la liste, comme si la famille l'avait déposée deux fois.
