# Locations — guide du Staff d'U

Ce document s'adresse aux personnes qui gèrent la location des biens de
l'unité : un local, un terrain, des tentes, une remorque, du matériel. Il
décrit **ce que le module fait et ce qu'il ne fait pas**, dans l'ordre où
vous allez le rencontrer.

Il ne remplace pas les pages elles-mêmes : chacune explique ce qu'elle
attend. Il répond aux questions qu'on se pose avant de commencer.

## 1. Deux espaces, jamais mélangés

- **L'espace public** (`/locations`) : ce que voit quelqu'un d'extérieur à
  l'unité. Une page par bien, un calendrier de disponibilité, une
  estimation de prix, un formulaire de demande. Personne n'a besoin d'un
  compte.
- **L'espace de gestion** (`Espace animés > Mes locations`) : les
  réservations, l'argent, les documents, le séjour — et les réglages du
  bien lui-même : règles de réservation, tarif, acompte et caution.
  Réservé aux gestionnaires du bien.
- **La page du parc** (`Espace chefs d'U > Locations`) : quels biens
  existent et qui les gère. Rien d'autre.

Il n'y a **qu'une seule autorité** dans ce module : « gestionnaire de ce
bien ». Le Staff d'U l'est de tous les biens, par sa fonction, sans avoir à
se désigner nulle part — il règle donc le tarif d'un bien au même endroit
que n'importe quel gestionnaire, pas sur un écran réservé aux chefs.

Un visiteur n'atteint jamais le second, et le premier n'affiche jamais rien
qui concerne une personne : le calendrier public dit *loué* ou
*indisponible*, jamais **par qui**.

## 2. Créer un bien

`Espace chefs d'U > Locations > Ajouter un bien`.

Trois choses à décider tout de suite, parce qu'elles changent tout le
reste :

1. **Nuits ou jours pleins.** Un local se loue à la nuit : le jour du
   départ redevient libre pour l'arrivée suivante. Une remorque se loue en
   jours pleins : le jour du retour est encore occupé. Ce réglage décide à
   la fois le calendrier, le prix et la disponibilité. Il se choisit dès la
   création (« Mode de location » — une suggestion suit le type de bien) et
   reste modifiable ensuite dans les réglages du bien.
2. **La quantité.** Un local, c'est un. Huit tentes, c'est huit — et le
   public verra combien il en reste.
3. **Public ou non.** Un bien non public existe, se gère, se réserve à la
   main, mais aucun visiteur ne le voit. C'est le réglage par défaut : un
   bien n'apparaît en ligne que quand vous le décidez.

Le reste (capacité, heures d'arrivée et de départ, téléphone d'urgence) se
règle ensuite et se modifie à tout moment. Les **règles de réservation**, le
**tarif** et l'**acompte** ne se règlent pas ici : ils appartiennent au bien
et se modifient depuis son espace de gestion, onglet « Réglages ».

## 3. Désigner les gestionnaires

**Un gestionnaire n'est pas forcément un chef.** C'est souvent
l'intendant, un parent, un ancien. Vous les désignez bien par bien : ils ne
voient que leurs biens, et rien des autres. Sur leurs biens, en revanche,
ils font **tout** : les demandes, l'argent, les documents, le séjour, et les
réglages du bien.

Vous les cherchez par leur nom, leur prénom ou leur totem. Seuls les membres
d'au moins **16 ans** sont proposés — un gestionnaire accède aux coordonnées
des locataires, à l'argent et aux contrats. Cet âge est réglable
(`Paramètres > Âge minimum d'un gestionnaire de bien`).

Le Staff d'U voit tous les biens, sans avoir à se désigner nulle part.

Un gestionnaire qui disparaît d'un import Desk est **suspendu, pas
supprimé** : son accès s'arrête, son attribution reste, et elle se réactive
d'elle-même s'il réapparaît. Il apparaît barré d'un « Désactivé » dans la
liste ; le décocher, lui, le retire définitivement.

Vous pouvez aussi marquer certains d'entre eux comme **contact du
locataire** : ce sont eux dont le nom et le téléphone partent dans l'email
d'informations pratiques, une semaine avant le séjour.

## 4. Le prix

Le moteur tarifaire couvre ce qu'une unité facture réellement : au
participant et par nuit, à la nuit, au séjour, à l'unité louée. Vous y
ajoutez des frais fixes (nettoyage, chauffage) et des taxes (taxe de
séjour), un **minimum facturable** en nombre de participants, et des tarifs
différents par catégorie de locataire (mouvement de jeunesse, école,
particulier…).

Trois montants ne se confondent jamais :

- **l'estimation** montrée au visiteur au moment de sa demande, figée pour
  toujours — c'est la preuve de ce qu'on lui a annoncé ;
- **le prix convenu**, que vous négociez ensuite ;
- **ce qui a été reçu**, qui vient du module Finances.

Modifier votre tarif ne change **aucune** réservation existante.

**Aucune TVA n'est calculée.** Les prix sont ce que le locataire paie. Une
mention d'exonération configurable par bien apparaît sur la facture.

Tout cela se règle depuis le bien lui-même : `Mes locations > le bien >
Réglages`. Le simulateur qui s'y trouve passe par exactement le même moteur
que la page publique et que le contrat : ce que vous y voyez est ce que le
visiteur verra.

**Tant qu'aucun tarif n'est défini** — ni tarif par défaut, ni case de
grille — la page publique affiche « Tarif sur demande » à la place d'une
estimation, et la vue d'ensemble du bien comme la page du Staff d'U vous le
signalent. Le champ « Tarif par défaut » suffit pour commencer.

**Pour fermer les demandes quelques semaines** — travaux, absence — posez un
blocage de dates sur la période depuis le calendrier du bien : les dates
cessent d'être proposées au public. Il n'existe pas d'interrupteur séparé.

## 5. Une demande arrive

Le visiteur reçoit un accusé de réception avec **un lien personnel**. Ce
lien est sa seule façon de revenir sur sa demande : il n'a pas de compte.
Il est stocké chiffré, personne ne peut le relire — si le locataire le
perd, vous en générez un nouveau, et l'ancien cesse de fonctionner.

Les dates sont **bloquées** pendant un délai réglable, le temps que vous
répondiez. Ce blocage qui expire **ne refuse rien** : la demande reste en
attente, les dates redeviennent simplement libres pour les autres. Vous
recevez un rappel juste avant.

Vous recevez aussi une notification à chaque nouvelle demande. **Elle ne
contient aucune donnée du locataire** — juste la référence et un lien vers
la fiche protégée.

## 6. Suivre une réservation

Tout se passe sur la fiche de la réservation :

- **États** : reçue, en cours d'examen, information demandée, proposée,
  confirmée, refusée, annulée, expirée, clôturée.
- **Option** : vous posez une option avec une échéance, pour laisser au
  locataire le temps de décider sans que les dates partent ailleurs.
- **Commentaires internes** : chiffrés, visibles des seuls gestionnaires,
  **jamais** affichés sur la page du locataire.
- **Historique** : ce qui est arrivé à la réservation, sans aucune donnée
  personnelle.
- **Demandes de modification** : le locataire peut demander d'autres dates
  ou un autre nombre de participants ; rien ne change tant que vous n'avez
  pas décidé.

## 7. L'argent

Le **compte** sur lequel les virements sont attendus est associé au bien
par un chef d'unité, depuis `Espace chefs d'U > Locations`. C'est le seul
réglage d'argent qui ne soit pas entre vos mains : la liste des comptes
porte les IBAN de l'unité. Tant qu'aucun compte n'est associé, vous ne
pouvez pas activer les paiements, et le message vous dit à qui le demander.

Avec le module Finances actif : une créance est créée à la confirmation,
avec une communication structurée. Acompte et solde ont leurs échéances ;
la **caution fait l'objet d'une créance séparée**, pour qu'elle ne se
confonde jamais avec le prix.

Ce qui a été reçu n'est jamais stocké : c'est recalculé à chaque affichage
depuis les virements de l'extrait de compte. La restitution de la caution
se saisit à la main — ScoutMagic ne rapproche pas les virements sortants.

Sans le module Finances, le module fonctionne : il ne parle simplement pas
d'argent.

## 8. Contrat et facture

**Un modèle standard est fourni.** Tant que vous n'écrivez rien, le contrat
et la facture sont générés depuis le modèle standard de ScoutMagic pour la
location d'un bien scout en Belgique (inspiré des modèles Atouts Camps) —
un point de départ à relire et à adapter, jamais un avis juridique.
L'éditeur s'ouvre pré-rempli avec ce texte ; dès que vous l'enregistrez
modifié, c'est votre version qui compte, et un bouton « Réinitialiser au
modèle standard » permet d'y revenir.

Trois niveaux, chacun figé quand le suivant naît :

1. **le gabarit du bien**, que vous écrivez une fois ;
2. **le contrat de la réservation**, copie du gabarit prise à la première
   génération — modifier le gabarit ensuite ne touche aucune réservation
   existante ;
3. **le PDF**, regénérable autant de fois que nécessaire.

**Une regénération n'écrase jamais** : la v2 s'ajoute à côté de la v1, qui
est peut-être déjà signée.

Des mots-clés (`{{ bien }}`, `{{ date_arrivee }}`, `{{ prix_total }}`…)
sont remplacés à la génération. La liste est affichée à côté de l'éditeur,
et un mot-clé inconnu vous est signalé **à l'édition**, jamais rendu tel
quel dans un contrat signé.

**Le locataire ne télécharge rien depuis le site.** « Destiné au
locataire » veut dire *à lui envoyer par email*. En cas d'email perdu, vous
le renvoyez.

## 9. Le séjour

- **Compteurs** : relevés à l'entrée et à la sortie. Un compteur qui
  recule vous est signalé plutôt que deviné — c'est un compteur remplacé,
  un chiffre transposé, ou un relevé pris au mauvais endroit.
- **État des lieux** : la liste est figée dans la réservation à la
  confirmation, intitulés compris. « Personne n'a regardé » et « quelqu'un
  a regardé, c'était bon » sont deux états distincts.
- **Dégâts** : constatés, décrits, photographiés. **Rien n'est facturé
  automatiquement** : vous choisissez explicitement de facturer, de retenir
  sur la caution, ou de ne rien réclamer. Tant que rien n'est décidé, le
  locataire n'en voit rien.
- **Décompte final** : le nombre réel de participants, les consommations,
  les dégâts facturés, ce qui a été payé, le solde. Il **ne modifie jamais
  le prix convenu**.

Ces pages ne sont **pas** disponibles hors ligne. Photographiez sur place,
encodez au retour : une page mise en cache dans une cave sans réseau vous
ferait perdre la saisie sur le chemin du retour.

## 10. Le calendrier de l'unité

Vous pouvez publier l'occupation d'un bien dans le calendrier de l'unité,
soit dès la confirmation, soit dès le blocage des dates.

Un lecteur ordinaire — animateur, parent, visiteur — voit
`Local Saint-Georges — loué` et **rien d'autre**. Seuls les gestionnaires
du bien et le Staff d'U voient l'organisation, le nombre de participants et
le contact. Ce n'est pas configurable, et ce n'est pas un affichage
masqué : le détail n'est tout simplement pas construit pour quelqu'un qui
n'y a pas droit.

Rien n'est recopié dans le calendrier : si vous désactivez la publication,
l'occupation disparaît d'elle-même.

## 11. Les emails du locataire

Avec le module Courrier entrant, les réponses du locataire remontent
automatiquement sur sa réservation, dans l'onglet Communications.

Le rattachement se fait d'abord par la **référence** dans le sujet, puis
par la **conversation**, puis, à défaut, par l'**adresse de l'expéditeur**
si le message tombe dans une fenêtre autour du séjour. **En cas
d'ambiguïté, rien n'est rattaché** — mieux vaut un email qui reste dans la
boîte qu'un email dans le mauvais dossier.

Chaque message affiche **comment** il a été rattaché. Un rattachement sur
la seule adresse est présenté comme incertain, parce qu'il l'est.

Une pièce jointe devient un document de la réservation, en « Non classé »
et en visibilité interne — jamais présumée être le contrat signé. Vous la
reclassez en un clic.

Vous pouvez **détacher** un message (il est alors supprimé, avec ses pièces
jointes non reclassées) ou le **déplacer** vers une autre réservation —
uniquement parmi les biens que vous gérez. Vous ne pouvez pas rattacher un
email vous-même : il n'y a aucun accès à la boîte depuis ici.

## 12. Le registre de conformité

Par bien : un intitulé libre, un document, une échéance, une remarque.

**C'est un pense-bête, pas une vérification de conformité.** ScoutMagic ne
connaît aucune règle réglementaire, ne vérifie rien et ne donne aucun avis
juridique. Ce qu'un bien doit posséder dépend de votre commune, de votre
fédération et de l'année — c'est à vous de le savoir. Le module vous
rappelle simplement ce que vous y avez inscrit, avant que ça n'expire.

## 13. Les rappels

Le module vous rappelle : une demande sans réponse, un blocage qui expire,
un acompte ou un solde non reçu, une caution non reçue, un contrat non
établi, un état des lieux à faire, un décompte à établir, une caution à
restituer, un document de conformité qui expire.

Ils arrivent dans votre centre de notifications, jamais par email, et
**aucun ne contient de donnée du locataire**.

Un seul rappel part vers le locataire : les informations pratiques, une
semaine avant son séjour.

**Si votre hébergeur ne propose pas de tâche planifiée réelle**, la page de
configuration vous le dit. Les rappels partent quand même, mais seulement à
l'occasion d'une visite du site : ils peuvent donc arriver avec plusieurs
heures de retard.

## 14. Combien de temps les données sont conservées

Une réservation est **entièrement supprimée** après un délai réglable,
compté depuis la clôture de l'exercice comptable contenant le séjour — pas
depuis le séjour lui-même, pour que toutes les locations d'un même exercice
partent ensemble.

Sont supprimés : la réservation, ses lignes, ses documents, ses jetons, ses
relevés, son état des lieux, son décompte et les emails rattachés, fichiers
compris. Une demande refusée ou restée sans suite suit exactement la même
règle.

Il ne subsiste **qu'une ligne anonyme** — le bien, le mois, le nombre de
jours, le montant — pour que les trois chiffres de la page d'accueil du
bien restent justes après la purge.

La valeur par défaut de 7 ans reprend l'usage comptable belge courant.
**C'est une aide au paramétrage, pas un conseil juridique** : vérifiez ce
qui s'applique à votre unité.

## 15. L'intelligence artificielle

Si l'unité a activé le module Connecteur IA, vous pouvez demander une aide
ponctuelle : lire l'index d'un compteur sur une photo, proposer le
classement d'une pièce jointe, résumer un échange, repérer ce qui manque
dans une demande.

**Chaque proposition est une proposition.** L'IA n'accepte ni ne refuse une
réservation, ne modifie aucun prix convenu, n'impute aucun dégât, ne retient
aucune caution, ne déclenche aucun remboursement et ne change aucun statut
financier définitif. Vous validez, ou vous écartez.

Sans ce module, rien de tout cela n'apparaît et le reste fonctionne à
l'identique.
