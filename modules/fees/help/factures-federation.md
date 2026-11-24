---
id: factures-federation
title: Importer et suivre les factures de la fédération
summary: L'acompte, les factures finales, la régularisation — et ce que le site vérifie avant d'accepter un document.
category: Espace chefs d'U
role_min: admin
question: Comment enregistrer la facture de cotisations de la fédération ?
question: Pourquoi le site refuse-t-il ma facture fédérale ?
paths: /admin/fees/factures, /admin/fees/factures/import
related: cotisations, justesse-des-tarifs, import-desk
---

La page **Factures** présente les documents de la saison et le cumul de
leurs totaux : acompte, factures finales et régularisation. L'acompte étant
déduit dans la facture finale par une ligne négative, ce cumul ne le
compte pas deux fois.

## Importer une facture

Déposez le PDF original envoyé par la fédération. Un scan ne convient
pas : le site lit sa couche texte.

**Facture acceptée.** Chaque montant est recalculé : prix × quantité,
nombre de personnes lorsqu'une liste est présente, puis somme des lignes.
Le total doit correspondre exactement au document. Son rapport de
vérification devient alors consultable.

Le récapitulatif TVA est distingué des cotisations. Deux cotisations
identiques pour deux personnes restent séparées ; une liste continuée à
la page suivante est comptée une seule fois. Une répétition ambiguë
refuse la lecture avec une explication. Une cotisation sans noms reste
vérifiable en quantité, mais limite les contrôles nominatifs.

**Total incohérent.** L'arithmétique ne retombe pas sur les montants du
document. Le message désigne la ligne concernée. Rien n'est enregistré.

**Format non interprétable.** La mise en page n'est pas reconnue. Rien
n'est enregistré. Signalez le problème sans envoyer le PDF : le journal
fournit des compteurs de lecture, sans aucun nom.

**Roster périmé.** Une section facturée est inconnue du site. Réimportez
Desk, puis reprenez le même PDF. La solution est d'actualiser les données,
pas d'associer manuellement un code à une autre section.

## Importer deux fois ne crée pas de doublon

Le numéro du document l'identifie. Si vous ne savez plus si une facture
est déjà importée, réessayez : le site vous le dira sans la modifier.

## Conserver le PDF comme justificatif

Lorsque Finances est activé, vous pouvez choisir un compte de l'unité.
Le PDF y devient un justificatif chiffré au repos, accessible aux mêmes
personnes que ce compte. Les noms restent uniquement dans ce fichier ;
les tables de vérification n'en conservent aucun.

Le rapprochement bancaire reste manuel. Sans Finances, le PDF n'est pas
conservé ; la vérification fonctionne pareil.

Le site ne modifie pas Desk et ne conteste pas la facture auprès de la
fédération. Il montre les écarts pour vous permettre de les vérifier.
