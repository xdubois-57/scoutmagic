---
id: config-finance
title: Configurer les finances
summary: Comptes, catégories, règles automatiques et zone de danger.
category: Configuration
role_min: superadmin
paths: /config/finance, /config/finance/accounts, /config/finance/categories
related: finances, importer-extraits
---

La configuration du module Finances se fait en trois pages : la vue
d'ensemble, les comptes et les catégories (avec leurs règles).

## Les comptes

Créez un compte par compte bancaire réel, ou une caisse pour les
espèces. Un compte bancaire reste « Brouillon » tant que son IBAN et
son titulaire ne sont pas renseignés ; son activation crée d'office la
catégorie « Virement » correspondante. Le réglage « Visible à partir
de » décide qui voit le compte et ses justificatifs : intendant, chef
ou chef d'unité — changer ce niveau se répercute sur les
justificatifs déjà déposés.

On ne supprime pas un compte utilisé : on le désactive, l'historique
reste consultable.

## Les catégories et les règles

Les catégories structurent le bilan ; leur description est obligatoire
car elle sert aussi à l'IA pour trancher entre deux catégories
proches. Une catégorie utilisée ne se supprime pas — désactivez-la.

Les règles catégorisent automatiquement les mouvements à l'import :
chaque règle combine jusqu'à trois conditions (mot-clé ou expression
sur le libellé, contrepartie, montant), et **la première règle qui
correspond gagne** — leur ordre se change par glisser-déposer.
« Tester » essaie une règle à blanc, « Exécuter les règles » repasse
sur l'existant. La règle « IA », toujours en dernier, est désactivée
par défaut : l'activer envoie les libellés restants au connecteur IA,
ce qui peut engendrer des coûts.

## La zone de danger

Sur la vue d'ensemble, deux actions irréversibles : supprimer tous les
mouvements d'un compte, et archiver tous les reçus. Elles ne servent
qu'à repartir de zéro après des essais.

> Vérifiez l'exercice comptable courant en début d'année : sans
> exercice couvrant leurs dates, les imports bancaires sont refusés.
> Les mouvements plus vieux que la durée de conservation réglée (cinq
> ans par défaut) sont purgés automatiquement.
