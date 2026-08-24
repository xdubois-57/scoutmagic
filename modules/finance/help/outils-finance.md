---
id: outils-finance
title: Les outils de paiement
summary: Fabriquer un code QR de virement, et vérifier une communication structurée.
category: Espace animateurs
role_min: intendant
paths: /finance/tools
related: finances, recus, importer-extraits
---

Deux petits outils qui ne partagent que leur page. Aucun des deux
n'enregistre quoi que ce soit : ils répondent, c'est tout.

## Le code QR de paiement

Renseignez le bénéficiaire, l'IBAN, le montant et une communication, et
la page produit un code QR que n'importe quelle application bancaire
européenne sait scanner pour préremplir un virement. Faites-en une
capture d'écran, ou collez-le dans un courrier, une affiche, un e-mail.

L'IBAN est réellement vérifié : sa longueur **et** sa clé de contrôle.
Un chiffre à côté et le QR n'est pas produit — mieux vaut l'apprendre
ici que par un virement parti au mauvais endroit. Le montant s'écrit
avec une virgule ou un point, comme vous préférez.

La communication est libre : « Camp 2026 — Alice » convient très bien.
Si vous collez une communication structurée, elle sera affichée comme
telle par l'application bancaire.

> Cet outil **ne crée aucun paiement attendu**. Fabriquer un QR pour le
> montrer à quelqu'un n'est pas la même chose que décider qu'une somme
> est due : si vous voulez suivre le paiement, passez par le formulaire
> d'un article, qui crée la créance et la réconcilie avec les extraits.

## Vérifier une communication

Vous voyez une communication structurée sur un extrait et vous vous
demandez à quoi elle correspond ? Collez-la ici. Les espaces, les
`+++` et les `***` sont optionnels — douze chiffres suffisent.

Trois réponses possibles :

- **Invalide** : ce n'est pas une communication structurée belge
  correcte. Elle compte douze chiffres, dont les deux derniers
  contrôlent les dix premiers ; une erreur de recopie se voit ici.
- **Valide, mais inconnue ici** : elle est bien formée, et aucun
  paiement attendu ne lui correspond parmi les comptes auxquels vous
  avez accès. Elle vient probablement d'ailleurs.
- **Valide et reconnue** : la page vous dit ce qu'elle concerne, sur
  quel compte et pour quel montant.

Ce que vous voyez suit les mêmes règles que le reste des finances : un
paiement attendu sur un compte que vous ne pouvez pas ouvrir vous
répondra « inconnue ici ». Voyez « Qui voit quels comptes » dans l'aide
des finances.

Le site retient qu'une vérification a eu lieu et son résultat, jamais la
communication que vous avez saisie ni le libellé trouvé.
