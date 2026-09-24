# Chantier — Import bancaire : CODA et répartition par IBAN

Roadmap d'exécution en **3 itérations séquentielles**, issue #399. Traite-les dans l'ordre.
N'entame pas la suivante avant que la précédente soit fusionnée sur `main`.

IT-01 se livre et s'éprouve **sans une seule ligne de CODA** : elle ne change que la façon dont un
fichier trouve ses comptes. IT-02 ajoute le format. IT-03 referme.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. Couverture RBAC explicite sur chaque frontière de rôle touchée.
- **Toute édition de `modules/finance/schema.sql` impose de bumper `version` dans son
  `module.json`, dans le même changement.** Sans le bump, la colonne n'est créée que sur une
  activation neuve et toutes les installations existantes tombent sur `Unknown column`.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- Chaque écran modifié voit son sujet d'aide mis à jour dans la même PR. `tests/Core/Help/`
  échoue sinon.
- **Aucune donnée bancaire dans le journal, les messages d'erreur ou les traces.** Un IBAN de
  contrepartie, un nom de contrepartie, un montant : rien de tout ça ne sort. Les libellés, les
  commentaires et les données de contrepartie sont chiffrés en base ; ils ne doivent pas
  réapparaître en clair ailleurs.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR.

### Le jeu de données de référence n'est pas optionnel

`AGENTS.md` § *Reference dataset* impose de vérifier `tests/fixtures/reference-dataset/` dans le
même changement dès qu'on touche à l'analyseur d'extraits ou au pipeline d'import. **Ce chantier
touche aux deux.** Le dataset est donc rejoué et vérifié à chaque itération, et il gagne en IT-02
un fichier CODA couvrant **deux comptes** — c'est le comportement neuf que rien d'autre n'exerce.
`Tests\Integration\ReferenceDatasetFormatTest` fait échouer la PR qui le casse.

---

## Le contexte, et ce que ce chantier n'est pas

L'issue #399 demande d'intégrer **Ponto**, l'API d'Isabel, pour télécharger automatiquement les
extraits de n'importe quelle banque belge. La douleur réelle qu'elle décrit est ailleurs : « je ne
supporte que BNP ».

Ce chantier soigne cette douleur par le **CODA**, le format d'extrait normalisé belge que toutes
les banques belges exportent nativement. Zéro euro, zéro certificat, zéro DSP2, et quasiment toutes
les banques couvertes. Il n'automatise pas le téléchargement : quelqu'un exporte encore le fichier.

**Ponto est reporté, pour trois raisons établies** (à consigner dans l'issue, voir la clôture) :

1. Isabel ne publie pas son tarif ; deux intégrateurs annoncent **4 € par mois et par compte
   lié**, hors TVA. Pour une unité à deux ou trois comptes, 100 à 150 € par an.
2. La DSP2 impose de **refaire la liaison tous les 90 jours**, lecteur de carte en main. Si le
   trésorier oublie, la synchronisation s'arrête sans bruit.
3. L'API Ponto Connect est servie par **Ibanity**, qui authentifie *l'application* par un
   certificat client et impose la signature des requêtes en production. Ce certificat identifie le
   logiciel, pas l'unité. ScoutMagic est libre, auto-hébergé, déployé par FTP sur du mutualisé :
   embarquer une clé privée dans l'artefact est exclu, et enregistrer chaque unité comme
   développeur Ibanity n'est pas un geste de trésorier bénévole. **C'est le vrai blocage**, et il
   demande une réponse d'Isabel avant tout développement.

Ne commence aucun travail sur Ponto dans ce chantier.

---

## IT-01 — La répartition par IBAN

Aucun format nouveau. On change seulement la façon dont un fichier trouve ses comptes.

### Ce qui existe aujourd'hui

Le trésorier choisit un compte cible et un format dans une liste, dépose son fichier, et
`ImportService::verifyIban()` compare l'IBAN trouvé dans le fichier à celui du compte visé —
**abandon sur désaccord, sans option de forçage**. La comparaison passe par l'index aveugle, et
l'IBAN est normalisé des deux côtés (un ancien bug l'a appris : la même valeur écrite avec des
espaces faisait échouer tous les imports du bon fichier, avec un message trompeur).

### Ce qui change — **Décidé**

**Le choix du compte disparaît de l'écran.** On dépose un fichier ; chaque ligne rejoint le compte
dont l'IBAN correspond. C'est l'IBAN du fichier qui décide, plus un humain dans une liste : l'erreur
de destination n'est plus rattrapée, elle devient impossible.

**Le contrat d'analyseur change.** `BankStatementParserInterface::extractSourceIban()` renvoie
aujourd'hui **un** IBAN par fichier ; il doit désormais annoncer **les comptes que le fichier
couvre**, et chaque `StatementLine` doit savoir à quel compte elle appartient. C'est la seule vraie
modification d'interface du chantier. `BnpParser` s'y plie en répondant « un seul compte ».

Le CSV BNP porte bien l'IBAN du titulaire : colonne 5, « Numéro de compte ». Les contreparties sont
deux colonnes plus loin (`COL_COUNTERPARTY_ACCOUNT = 7`, `COL_COUNTERPARTY_NAME = 8`) — champs
distincts, jamais confondus.

**Un IBAN inconnu de ScoutMagic est ignoré, jamais créé en douce.** Le rapport de fin le nomme et
explique comment ajouter ce compte sur le site.

**Tout ou rien : la transaction couvre le fichier entier.** `ImportService` enveloppe déjà sa boucle
dans une seule transaction de base de données ; elle s'étend maintenant à l'ensemble des comptes.
Un import à moitié réparti entre trois comptes serait impossible à démêler.

**Une date qui ne tombe dans aucune année scoute fait tout abandonner**, en nommant les dates
trouvées et l'année manquante. Ne tente pas de créer l'exercice : `FiscalYearRepository` interroge
`scout_years` — l'exercice comptable **est** l'année scoute, celle dont la page « Année scoute »
orchestre l'arrivée en quatre étapes surveillées. Un décalage d'un octet dans un fichier produit des
dates plausibles et fausses ; il inventerait une année pour tout le site.

**Une ligne de bookkeeping par compte** (`StatementImportRepository`), rattachée au même fichier
déposé.

**Pas de récapitulatif avant écriture** : un rapport après coup, qui dit par compte ce qui est
entré, ce qui était déjà là, et ce qui a été ignoré.

---

## IT-02 — L'analyseur CODA

### À faire

`Modules\Finance\Parser\CodaParser`, implémentant le contrat élargi d'IT-01, branché dans
`BankStatementParserFactory` — qui est déjà construit pour grandir : son `supportedCodes()` ne
renvoie `['bnp']` que parce que rien d'autre n'existe encore.

### La détection automatique — **Décidé**

**La liste des formats disparaît de l'écran.** Un CODA se reconnaît à sa structure, un CSV BNP à
son en-tête : le fichier sait ce qu'il est, le trésorier n'a pas à le savoir. On supprime du même
coup toute une famille d'erreurs — choisir « BNP » pour un fichier CODA.

**La liste réapparaît uniquement après un échec de détection** : « Nous n'avons pas reconnu ce
fichier. Choisir le format manuellement ». Un forçage disponible en permanence serait repris par
habitude, y compris à tort ; disponible seulement quand il sert, il sert.

### Cinq pièges du format, tous réels

1. **L'encodage.** Un CODA arrive en ISO-8859-1 ou CP850, pas en UTF-8. Conversion avant tout le
   reste — sinon les noms de contrepartie ressortent abîmés, et **chiffrés abîmés**, donc
   irrattrapables.
2. **Les montants** sont en millièmes, sur quinze positions, avec un indicateur débit/crédit
   séparé. Un `floatval` naïf donne des montants mille fois trop grands, plausibles et faux.
3. **Les enregistrements se continuent.** Une communication, une contrepartie, un nom s'étalent sur
   plusieurs enregistrements liés qu'il faut recoudre avant de produire une `StatementLine`.
4. **La clé de déduplication n'existe pas telle quelle.** Elle se compose, et doit rester stable
   d'une année sur l'autre : le jeu de données de référence répète exprès la fin de chaque année en
   tête de la suivante pour l'éprouver. `TransactionRepository::insertOrSkip()` s'appuie
   entièrement dessus.
5. **Un fichier couvre plusieurs comptes et plusieurs relevés.** C'est précisément ce qu'IT-01 a
   préparé.

### Un bénéfice à ne pas gâcher

Le CODA livre la **communication structurée dans son propre champ**, propre, là où le CSV BNP la
noie dans du texte libre. `StructuredCommunicationService` et le rapprochement des paiements de
familles y gagnent sans rien changer chez eux — à condition que l'analyseur la range dans le bon
champ plutôt que de la concaténer dans `extraDetails`.

---

## IT-03 — Le solde, le rapport, la documentation

### Le solde — **Décidé**

L'écran réclame aujourd'hui un solde saisi à la main : obligatoire au premier import d'un compte,
facultatif ensuite, où il sert à détecter un écart avec le grand livre. Avec la répartition
multi-comptes, la saisie devient intenable — un fichier couvrant trois comptes demanderait trois
soldes à un formulaire qui n'en connaît qu'un.

**Le solde vient du fichier quand le format le donne** — le CODA porte le solde final de chaque
relevé, et `StatementLine` a déjà un champ `balanceAfter`, laissé inutilisé « pour de futurs
analyseurs ». **Le champ manuel ne subsiste que pour un format qui ne le donne pas** (le CSV BNP
aujourd'hui), et seulement au premier import d'un compte.

Le contrôle d'écart existant ne change pas de nature : il compare simplement à une valeur qui vient
désormais du fichier.

### Le rapport de fin

Par compte : lignes entrées, lignes déjà connues, lignes ignorées. Plus, s'il y a lieu, la liste des
IBAN présents dans le fichier mais inconnus du site, avec la marche à suivre pour les ajouter.

### La documentation à reprendre

`specifications.md` (§ module Finances — import), `ARCHITECTURE.md` si le contrat d'analyseur y est
décrit, le sujet d'aide de l'écran d'import, et le `README.md` du jeu de données de référence.

### La clôture

1. **Commente #399** avec ce qui a été livré (CODA, répartition par IBAN, solde depuis le fichier)
   **et les trois constats sur Ponto** consignés plus haut — tarif annoncé par les intégrateurs,
   réautorisation à 90 jours, et le blocage du certificat Ibanity, qui demande une réponse d'Isabel.
2. **Ouvre une issue neuve** pour Ponto seul, reprenant ces trois constats.
3. **Clôture #399.**

---

## Écarté, explicitement

- **Ponto dans ce chantier**, pour les trois raisons ci-dessus.
- **Créer automatiquement l'année scoute manquante** depuis une date lue dans un extrait.
- **Un récapitulatif à valider avant écriture.**
- **Garder un choix de compte cible** à l'import.
- **Garder la liste des formats** en permanence à l'écran.
- **Créer un compte automatiquement** pour un IBAN inconnu trouvé dans un fichier.
