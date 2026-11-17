# Courrier entrant — configuration IMAP

Ce document s'adresse au **superadministrateur** du site. C'est le seul rôle
qui peut configurer une boîte : un chef d'unité ou un gestionnaire peut
*utiliser* une boîte déjà configurée dans son travail quotidien, mais ne
voit jamais le serveur, le compte ni aucun paramètre technique.

## 1. Ce que fait ce module, et ce qu'il ne fait pas

C'est une **passerelle en lecture seule** vers une ou plusieurs adresses
e-mail de l'unité. Elle sert à ce que les réponses des gens arrivent
automatiquement dans le bon dossier — la bonne réservation, par exemple.

**Ce n'est pas un client mail.** Il ne peut pas :

- marquer un message comme lu (les messages sont lus en `PEEK`, votre boîte
  reste exactement dans l'état où vous l'avez laissée) ;
- déplacer, supprimer ou renommer quoi que ce soit ;
- créer un dossier ;
- envoyer une réponse.

Ces opérations n'existent pas dans le code : ce n'est pas une politique
qu'on pourrait assouplir, c'est un vocabulaire qui n'a pas été écrit.

**Tout le courrier relevé est conservé**, y compris ce qu'aucun module ne
reconnaît : un message ignoré était un message perdu, et personne ne s'en
apercevait. Trois garanties vont avec : le Staff d'U est seul à voir ce
courrier, dans *Espace chefs d'U > Courrier reçu* ; ce que rien ne
rattache est supprimé au terme du délai de conservation (90 jours par
défaut, *Configuration > Réglages*) ; et aucune recherche dans le contenu
n'existe.

## 2. Choisir l'adresse à connecter, et son usage

Le conseil pratique : **connectez une adresse de l'unité** (`contact@…`,
`locations@…`) plutôt que la boîte personnelle de quelqu'un. Elle survit
au départ de la personne, et ce qui y arrive concerne l'unité.

Chaque adresse a **l'un de deux usages**, et c'est tout ce qu'il y a à
régler :

- **Courrier général de l'unité** — l'adresse où l'on trouve de tout.
  Tous les modules actifs y reconnaissent leurs messages. Le Staff d'U
  voit tout dans « Courrier reçu ». Les responsables d'un module ne voient
  que les messages rattachés à un dossier qu'ils gèrent : quand un module
  n'est pas sûr et fait une **proposition**, elle reste dans « Courrier
  reçu », où le Staff d'U la confirme ou l'écarte. Une fois confirmé, le
  message est rattaché et le module le voit.
- **Réservée à un module** — une adresse créée pour un seul usage
  (`locations@…`). Seul ce module la lit, et ses responsables voient tout
  ce qui y arrive, propositions comprises, comme le Staff d'U. **Un module
  n'a qu'une adresse réservée** ; seules les boîtes témoins du courrier
  sortant peuvent être plusieurs, et ne sont jamais le courrier général.

Il n'y a plus de réglage par module ni de page « Portée » : une adresse
fonctionne dès qu'elle est enregistrée. Le formulaire propose « Courrier
général » pour la première adresse et « Réservée à un module » ensuite ;
ce n'est qu'une valeur par défaut, plusieurs adresses générales restent
possibles.

**Prérequis : un fournisseur qui vérifie l'expéditeur.** L'adresse
d'expéditeur d'un e-mail est déclarative : n'importe qui peut écrire celle
d'un locataire. À l'arrivée de chaque message, le site lit le verdict que
le serveur de réception de la boîte a écrit en tête du message (l'en-tête
`Authentication-Results` : DMARC, ou à défaut SPF ou DKIM alignés sur le
domaine de l'expéditeur), et **seulement le premier**, celui ajouté par
votre fournisseur — ceux plus bas peuvent avoir été écrits par
l'expéditeur. Un message que rien ne vérifie est marqué « Expéditeur non
vérifié » partout où il s'affiche, ses notifications le disent, et aucune
adresse n'est jamais apprise de lui. Gmail, Outlook/Microsoft 365 et OVH
écrivent cet en-tête ; un fournisseur qui n'en écrit aucun laisse en tête
celui que l'expéditeur aurait forgé, et la vérification ne protège alors
plus de rien. Choisissez un fournisseur qui l'écrit.

## 3. Le fournisseur reconnu, et le mot de passe d'application

Le formulaire **reconnaît le fournisseur** d'après l'adresse, puis, pour un
domaine propre, d'après ses serveurs de messagerie (enregistrement MX), et
remplit le serveur, le port et le chiffrement. Ils restent visibles et
modifiables.

| Fournisseur | Reconnu par | Serveur | Port | Chiffrement |
|---|---|---|---|---|
| Gmail, Google Workspace | `gmail.com`, `googlemail.com`, ou un MX chez Google | `imap.gmail.com` | 993 | SSL |
| Outlook, Hotmail, Live, Microsoft 365 | `outlook.*`, `hotmail.*`, `live.*`, `msn.com`, ou un MX `*.protection.outlook.com` | `outlook.office365.com` | 993 | SSL |
| OVH | un MX `*.ovh.net` | `ssl0.ovh.net` | 993 | SSL |
| Infomaniak | `ik.me`, `ikmail.com`, `etik.com`, ou un MX Infomaniak | `mail.infomaniak.com` | 993 | SSL |

Un fournisseur inconnu laisse les trois champs vides : ils figurent dans
l'aide de l'hébergeur, rubrique IMAP. Utilisez toujours le port sécurisé.

**Il n'existe pas de connecteur Gmail natif, et c'est délibéré.** Le mode
d'accès que Google impose pour lire une boîte (`gmail.readonly`) est un
*scope restreint* : chaque unité déployant ScoutMagic devrait passer une
vérification et une évaluation de sécurité annuelle **payante** chez Google,
faute de quoi ses jetons expireraient tous les 7 jours et sa synchronisation
casserait chaque semaine.

Une boîte Gmail ou Outlook se connecte donc **en IMAP, avec un mot de passe
d'application**, et le formulaire l'explique sous le champ du mot de passe
quand il reconnaît l'un des deux :

- **Gmail** : activez la validation en deux étapes sur le compte, puis
  créez un mot de passe d'application dédié sur
  <https://myaccount.google.com/apppasswords> — l'adresse ne figure dans
  aucun menu, ce qui est l'étape où l'on abandonne. La création reste
  indisponible sur un compte inscrit au programme de protection avancée,
  sur un compte dont la validation en deux étapes n'accepte qu'une clé de
  sécurité, et sur un compte Workspace dont l'administrateur l'a
  désactivée : dans ces trois cas, la boîte doit être relevée autrement.
  Vérifiez qu'IMAP est activé dans les paramètres Gmail.
- **Outlook / Microsoft 365** : selon la configuration de votre
  organisation, IMAP peut devoir être activé par un administrateur, et
  Microsoft réserve de plus en plus souvent l'accès IMAP aux applications
  qui s'identifient par OAuth, que ScoutMagic ne propose pas.

## 4. Ajouter une adresse

`Configuration > Courrier entrant > Ajouter une adresse`. Les champs, dans
l'ordre :

| Champ | Ce qu'on attend |
|---|---|
| **Adresse e-mail** | L'adresse du compte IMAP. C'est sous cette adresse que la boîte apparaît partout : elle n'a pas d'autre nom. |
| **Mot de passe d'application** | Il ne sera **plus jamais réaffiché**. En modification, laissez-le vide pour conserver l'ancien. |
| **Serveur IMAP**, **Port**, **Chiffrement** | Remplis pour un fournisseur reconnu. `993` en SSL, `143` en STARTTLS ; il n'existe pas d'option « aucun ». |
| **Usage** | **Courrier général de l'unité** ou **Réservée à un module** (voir §2). |
| **Module** | Pour une adresse réservée. Seuls les modules actifs qui lisent le courrier et n'ont pas encore d'adresse réservée sont proposés. |
| **Dossiers lus (facultatif)** | Replié. Voir ci-dessous. |

**Les dossiers lus.** Par défaut, la boîte de réception, plus les messages
envoyés quand un module en a besoin (aujourd'hui les Locations). En
ouvrant « Dossiers lus (facultatif) », le formulaire liste les autres
dossiers du serveur à cocher, **sans jamais proposer les dossiers
spéciaux** que le serveur désigne : indésirables, corbeille, brouillons,
« Tous les messages », « Suivis », « Important ». Seule une boîte témoin
du courrier sortant se voit proposer son dossier d'indésirables, qu'elle
doit lire. Le dossier des envoyés est **trouvé automatiquement** quand le
serveur le désigne ; sinon, indiquez son nom.

**« Vérifier et enregistrer » se connecte avant d'enregistrer.** Une
adresse qui ne répond pas **n'est pas enregistrée** — ni ajoutée, ni
modifiée : le formulaire revient avec une phrase qui dit quoi vérifier,
et tous les champs restent remplis, sauf le mot de passe. Il n'y a plus de
case « Synchroniser cette boîte » : une adresse ajoutée est relevée, et on
la suspend avec « Désactiver » dans la liste.

## 5. Le certificat n'est pas négociable

**Un certificat TLS invalide fait échouer la connexion.** Il n'y a aucune
case « ignorer », ni dans l'interface ni dans la base de données. Tout
l'intérêt de lire une boîte en TLS est que personne d'autre ne la lise en
même temps ; une option pour passer outre annulerait exactement cela.

Si la vérification signale un problème de certificat, vérifiez le nom
d'hôte (un certificat est émis pour un nom précis) et le mode de
chiffrement avant de chercher plus loin.

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

## 7. La liste des adresses, et modifier une adresse

Chaque adresse y figure sur une carte : l'adresse, son usage en une
phrase, son état et sa dernière relève. En cas d'échec, une phrase dit ce
qui ne va pas, et « Modifier » permet de corriger. Le serveur et le port
n'y sont plus affichés. Pour relever tout de suite, sans attendre le
prochain passage, « Relever le courrier maintenant » se trouve dans
« Courrier reçu ».

Le mot de passe n'étant jamais réaffiché, **laissez le champ vide** pour le
conserver : une modification du port ou des dossiers ne le touche pas. Ne le
remplissez que pour le changer réellement. Une modification est vérifiée
comme un ajout.

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

Rien de global. Sur l'adresse générale, un module ne voit que les messages
rattachés à **un de ses propres objets**, et jamais une proposition ; sur
l'adresse qui lui est réservée, il voit tout ce qui y arrive. Il n'existe
ni liste générale, ni recherche dans le contenu. Un gestionnaire qui peut
ouvrir une réservation ne gagne pas pour autant une fenêtre sur la
correspondance de l'unité.

## 10. Les sondes de diagnostic, sur l'installation de support

Cette section ne concerne que l'installation qui **reçoit** les tickets de
support : sur une unité ordinaire, rien de tout ceci ne s'affiche.

Le module Tableau de bord support relève les boîtes déjà configurées ici
pour y reconnaître un seul type de message : une **sonde**, envoyée par une
installation qui teste l'acheminement de ses e-mails. Le sujet porte une clé
`SMP-…` que ce receveur a lui-même émise ; tout autre message de la boîte
reste le courrier de quelqu'un d'autre et n'est pas touché.

Deux points valent d'être connus :

- **Seuls les en-têtes sont conservés**, jamais le corps. Le diagnostic,
  c'est SPF, DKIM, DMARC et la chaîne des relais — une sonde n'a pas de
  contenu que quiconque ait besoin de relire.
- **Aucune boîte configurée, aucune sonde possible.** Le receveur répond
  alors « aucune boîte » aux installations qui demandent, ce qui est la
  vérité et non une panne : ajoutez une boîte ici pour que la fonction
  existe.

Le résultat — réception, délai, verdicts d'authentification, chaîne de
relais — se lit dans le détail d'une installation sur le tableau de bord
support, réservé aux super-administrateurs : ces lignes contiennent des
adresses IP et des noms de serveurs.
