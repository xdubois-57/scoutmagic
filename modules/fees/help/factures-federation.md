---
id: factures-federation
title: Importer et suivre les factures de la fédération
summary: L'acompte, les factures finales, la régularisation — et ce que le site vérifie avant d'accepter un document.
category: Espace chefs d'U
role_min: admin
paths: /admin/fees/factures, /admin/fees/factures/import
related: cotisations, justesse-des-tarifs, import-desk
---

La fédération envoie plusieurs documents sur une saison : un acompte en
novembre, les factures finales de janvier et de février, une
régularisation en fin d'année. La page **Factures** les présente dans
l'ordre où ils sont arrivés, avec le cumul de ce que l'unité a payé.

## Le cumul ne compte jamais l'acompte deux fois

L'acompte est déduit *à l'intérieur* de la facture finale, par une ligne
négative. Le total imprimé sur la facture finale est donc déjà net. Le
cumul additionne ces totaux tels quels : c'est ce que l'unité a
réellement payé, pas la somme des montants bruts.

## Importer : trois issues, et deux sont des refus

Déposez le PDF que la fédération a envoyé, tel quel. Un scan ne convient
pas : le site lit le texte du document, il ne le reconnaît pas sur une
image.

**La facture est acceptée.** Le site a relu chaque ligne, recalculé
chaque sous-total et vérifié que la somme retombe exactement sur le total
imprimé. Elle rejoint la saison, et son rapport de vérification devient
consultable.

**Total incohérent.** La lecture ne retombe pas sur le total du document,
et l'écran nomme la ligne où elle décroche. Rien n'est enregistré : une
facture à moitié lue produirait une vérification à moitié fausse, ce qui
est pire que pas de vérification du tout. Si le document est bon et que
le site s'y perd, le modèle de facture a probablement changé — signalez-le.

**Roster périmé.** Le document se lit parfaitement, mais il facture une
section que ce site ne connaît pas. Ce n'est pas une correspondance que
quelqu'un aurait oublié d'encoder : c'est que Desk a changé et que le
site n'a pas été réimporté depuis. Réimportez Desk, puis reprenez le même
fichier — il passera tel quel. Il n'existe volontairement aucun écran
pour associer un code de section à la main.

## Importer deux fois le même document ne fait rien

Le numéro du document est son identité. Si vous n'êtes pas sûr d'avoir
déjà importé la facture de janvier, essayez : le site vous dira qu'elle
est déjà là et n'enregistrera rien de plus.

## Conserver le PDF dans Finances

Si le module Finances est activé, vous pouvez rattacher le PDF à un
compte de l'unité : il y devient un justificatif ordinaire, chiffré au
repos, visible par les mêmes personnes que ce compte. C'est aussi le seul
endroit où les noms figurant sur la facture sont conservés — le site,
lui, n'en garde aucun.

Le rapprochement avec le mouvement bancaire reste manuel. Le site ne
devine pas quel virement paie quelle facture.

Si Finances est désactivé, la case n'apparaît pas, le PDF n'est pas
conservé, et la vérification fonctionne exactement pareil.

## Ce que le site ne fait pas

Il n'écrit jamais dans Desk, et il ne conteste rien à votre place. Il
lit, il recalcule, il montre où ça diverge. La correction — dans Desk ou
auprès de la fédération — reste la vôtre.
