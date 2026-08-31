# Courrier entrant — configuration IMAP

Ce document s'adresse au **superadministrateur** du site. C'est le seul rôle
qui peut configurer une boîte : un chef d'unité ou un gestionnaire peut
*utiliser* une boîte déjà configurée dans son travail quotidien, mais ne
voit jamais le serveur, le compte ni aucun paramètre technique.

## 1. Ce que fait ce module, et ce qu'il ne fait pas

C'est une **passerelle en lecture seule** vers une ou plusieurs boîtes mail
de l'unité. Elle sert à ce que les réponses des gens arrivent
automatiquement dans le bon dossier — la bonne réservation, par exemple.

**Ce n'est pas un client mail.** Il ne peut pas :

- marquer un message comme lu (les messages sont lus en `PEEK`, votre boîte
  reste exactement dans l'état où vous l'avez laissée) ;
- déplacer, supprimer ou renommer quoi que ce soit ;
- créer un dossier ;
- envoyer une réponse.

Ces opérations n'existent pas dans le code : ce n'est pas une politique
qu'on pourrait assouplir, c'est un vocabulaire qui n'a pas été écrit.

**Un message qu'aucun module ne reconnaît n'est jamais enregistré.** Ni
stocké, ni listé, ni signalé. ScoutMagic ne devient pas une archive de votre
boîte : ce serait la pire situation possible au regard du RGPD — toutes les
données, aucun écran pour les consulter, aucune raison de les garder.

## 2. Choisir la boîte à connecter

Le conseil pratique : **créez une boîte dédiée** (`locations@…`,
`secretariat@…`) plutôt que de connecter la boîte personnelle de quelqu'un.
Pas parce que le module abîmerait quoi que ce soit, mais parce qu'une boîte
dédiée survit au départ de la personne, et parce que le contenu qui y
arrive concerne l'unité et pas un individu.

Vous pouvez configurer **plusieurs boîtes** ; une boîte peut alimenter
plusieurs modules, et un module peut lire plusieurs boîtes.

## 3. Gmail et Outlook : un mot de passe d'application

**Il n'existe pas de connecteur Gmail natif, et c'est délibéré.** Le mode
d'accès que Google impose pour lire une boîte (`gmail.readonly`) est un
*scope restreint* : chaque unité déployant ScoutMagic devrait passer une
vérification et une évaluation de sécurité annuelle **payante** chez Google,
faute de quoi ses jetons expireraient tous les 7 jours et sa synchronisation
casserait chaque semaine.

Une boîte Gmail ou Outlook se connecte donc **en IMAP, avec un mot de passe
d'application** :

- **Gmail** : activez la validation en deux étapes sur le compte, puis
  créez un mot de passe d'application dédié. Serveur `imap.gmail.com`,
  port `993`, chiffrement `SSL`. Vérifiez qu'IMAP est activé dans les
  paramètres Gmail.
- **Outlook / Microsoft 365** : serveur `outlook.office365.com`, port
  `993`, chiffrement `SSL`. Selon la configuration de votre organisation,
  IMAP peut devoir être activé par un administrateur.
- **Hébergeur classique (OVH, Infomaniak…)** : les paramètres IMAP figurent
  dans la documentation de l'hébergeur. Utilisez toujours le port sécurisé.

## 4. Ajouter la boîte

`Configuration > Courrier entrant > Ajouter une boîte`.

| Champ | Ce qu'on attend |
|---|---|
| **Nom** | Ce que verront les gestionnaires — et **la seule chose** qu'ils verront. « Locations », « Secrétariat ». |
| **Compte** | L'adresse du compte IMAP. |
| **Serveur IMAP** | `imap.gmail.com`, `outlook.office365.com`, celui de votre hébergeur. |
| **Port** | `993` en SSL, `143` en STARTTLS. |
| **Chiffrement** | `SSL`, `TLS` ou `STARTTLS`. Il n'existe pas d'option « aucun ». |
| **Mot de passe** | Le mot de passe d'application. Il ne sera **plus jamais réaffiché**. |
| **Dossiers surveillés** | Un par ligne, avec leur nom exact sur le serveur. Vide = INBOX. |

Puis **testez la connexion** avant de compter dessus. Le test vous dit ce
qui ne va pas en une phrase actionnable, et liste les dossiers réellement
visibles — utile pour vérifier l'orthographe exacte d'un dossier, qui est
sensible à la casse.

## 5. Le certificat n'est pas négociable

**Un certificat TLS invalide fait échouer la connexion.** Il n'y a aucune
case « ignorer », ni dans l'interface ni dans la base de données. Tout
l'intérêt de lire une boîte en TLS est que personne d'autre ne la lise en
même temps ; une option pour passer outre annulerait exactement cela.

Si le test signale un problème de certificat, vérifiez le nom d'hôte (un
certificat est émis pour un nom précis) et le mode de chiffrement avant de
chercher plus loin.

## 6. Ce qui est stocké, et comment

- **Le mot de passe et le compte sont chiffrés au repos.** Le mot de passe
  n'est lu qu'au moment d'ouvrir une connexion, et n'apparaît nulle part
  ailleurs — ni dans une liste, ni dans un message d'erreur, ni dans une
  trace d'exécution.
- **Les messages d'erreur sont réécrits.** Le texte brut d'une bibliothèque
  IMAP contient couramment le nom du compte et, sur certains serveurs, le
  refus verbatim du mot de passe essayé. Ce texte n'est jamais affiché ni
  enregistré : vous lisez une phrase écrite pour vous.
- **Les messages rattachés** sont chiffrés au repos (objet, adresses,
  corps), et leurs pièces jointes stockées hors du site public, sous un nom
  généré, derrière le contrôle d'accès aux fichiers.

## 7. Modifier une boîte

Le mot de passe n'étant jamais réaffiché, **laissez le champ vide** pour le
conserver : une modification du port ou des dossiers ne le touche pas. Ne le
remplissez que pour le changer réellement.

**Désactiver** une boîte arrête la synchronisation en conservant sa
configuration et sa position de lecture — c'est ce qu'il faut faire pour
une boîte qui pose problème, plutôt que de la supprimer.

**Supprimer** une boîte efface sa configuration et sa position de lecture.
Les messages déjà rattachés à un dossier métier ne sont **pas** supprimés :
ils appartiennent maintenant à ce dossier et suivent sa politique de
conservation.

## 8. La synchronisation

Elle tourne par lots, en repartant de sa position précédente, à
l'intervalle réglé dans *Configuration > Réglages* sous « Intervalle
entre deux relèves du courrier » — quinze minutes par défaut, entre 5
minutes et 24 heures.

Descendre trop bas n'accélère rien et se retourne contre vous :
plusieurs hébergeurs de messagerie ralentissent ou bloquent
temporairement un client qui se reconnecte sans arrêt, et l'unité perd
alors toutes ses relèves jusqu'à ce que le blocage se lève. Un
changement s'applique dès la visite suivante : une relève déjà
programmée plus loin que le nouvel intervalle est avancée, plutôt
qu'attendre l'échéance de l'ancien.

Si votre serveur renumérote un dossier — cela arrive après une restauration
ou une migration — ScoutMagic le détecte et relit le dossier depuis le
début sans rien dupliquer : la déduplication se fait sur l'identifiant
propre de chaque message, pas sur sa position.

**Sans tâche planifiée réelle sur votre hébergement**, la synchronisation ne
tourne qu'à l'occasion d'une visite du site : un message peut donc mettre
plusieurs heures à remonter. Si votre hébergeur le permet, faites appeler
`public/cron.php` toutes les minutes.

## 9. Ce qu'un module consommateur peut faire de vos messages

Rien de global. Un module ne peut demander que les messages rattachés à
**un de ses propres objets** : il n'existe ni liste générale, ni recherche,
ni accès au contenu de la boîte. Un gestionnaire qui peut ouvrir une
réservation ne gagne pas pour autant une fenêtre sur la correspondance de
l'unité.
