---
id: connecteur-ia
title: Activer l'intelligence artificielle
summary: Choisir un fournisseur d'IA, sa clé, et ce que le site en fait.
category: Configuration
role_min: superadmin
discovery: 1
question: Comment brancher une intelligence artificielle sur le site ?
question: Combien coûte l'IA branchée sur le site ?
question: Que fait le site avec l'IA une fois branchée ?
paths: /config/llm
related: config-rgpd, recus, retrospectives
---

Le connecteur IA aide à lire un reçu, suggérer une catégorie comptable ou
résumer une rétrospective. **L’IA propose, elle ne décide jamais** : chaque
suggestion attend votre validation. Sans modèle disponible, la saisie
manuelle reste utilisable.

## Choisir un fournisseur

La page indique où se trouvent les serveurs de chaque fournisseur et ses
liens de confidentialité. Lisez-les : les textes et images envoyés quittent
le serveur de l’unité.

> Le fournisseur devient un sous-traitant au sens du RGPD. Après activation,
> vérifiez la page Protection des données du site ; son mode « Généré par
> IA » sait tenir compte du fournisseur choisi.

## Activer

Obtenez une **clé API** avec le pas-à-pas affiché, collez-la, puis cliquez
sur « **Enregistrer et activer** ». Changer la sélection seul ne l’active
pas. La clé est conservée chiffrée et jamais réaffichée ; laissez le champ
vide lors d’une modification pour garder l’actuelle.

L’enregistrement découvre les modèles et attribue automatiquement les rôles
économique, performant et OCR. Il retire les anciennes affectations
incompatibles, comme le rafraîchissement hebdomadaire.

## Si un modèle manque

Un modèle texte peut analyser un PDF contenant du texte sans savoir lire
une photo. Sans modèle image vérifié, la ligne OCR reste vide : les photos
et PDF scannés ne reçoivent pas de suggestions. Vous pouvez compléter le
reçu manuellement.

Sans modèle de conversation compatible, le test explique l’indisponibilité.
Le journal indique les exclusions. Chez Scaleway, les modèles inconnus
restent exclus jusqu’à vérification de leur documentation ; la lecture
d’images est ensuite testée avec une image synthétique, sans donnée de
l’unité.

## Les coûts

Le fournisseur facture directement l’unité ; le site n’affiche ni compteur
ni budget. La catégorisation IA des finances et la modération automatique
des groupes peuvent multiplier les appels : surveillez le tableau de bord
du fournisseur.

Chez Scaleway, la vérification peut ajouter trois petits appels de test au
maximum par découverte, arrêtés au premier succès. Une réussite est
réutilisée sept jours et renouvelée au passage hebdomadaire ; un échec temporaire peut être retesté après une
heure. Ces appels peuvent être facturés.
