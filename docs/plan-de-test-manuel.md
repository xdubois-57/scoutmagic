# Plan de test manuel — branche `claude/refactor-ux-analysis-dc4itj`

Ce que la machine ne peut pas voir. 9 030 tests PHP, 1 173 tests
JavaScript et 38 scénarios de navigateur passent sur cette branche ; ce
qu'ils ne prouvent pas, c'est qu'un écran est **agréable**, qu'une phrase
est **juste**, et qu'un enchaînement a du sens pour quelqu'un qui ne l'a
pas écrit. C'est cela qu'on vient vérifier ici.

Le plan est ordonné par risque décroissant : si vous n'avez qu'une heure,
faites la partie 1. Chaque point dit **ce qu'on fait**, **ce qu'on doit
voir**, et — quand ce n'est pas évident — **pourquoi ça vaut la peine
d'être regardé**.

Comptez environ 2 h 30 pour tout, 1 h pour la partie 1 seule.

## Avant de commencer

- **Prenez une instance de test.** La partie 1.1 remplace la façon dont
  les jetons de suivi des locations sont stockés : les réservations
  existantes gardent un lien qui n'ouvre plus rien. C'est attendu — vous
  aviez prévu de supprimer les biens configurés.
- **Ayez une boîte mail à portée.** Cinq des vérifications regardent un
  email réellement parti. Si l'instance a le module « Outils de test »
  actif, sa boîte de réception intégrée suffit.
- **Testez sur un téléphone au moins une fois** (partie 5). La moitié des
  décisions de ce chantier sont des décisions de petit écran.

---

## 1. Les trous fonctionnels comblés (risque le plus élevé)

C'est du comportement neuf : ce sont ces points qui peuvent être faux
sans qu'aucun test le dise.

### 1.1 Location — le locataire est prévenu de vos décisions

**Préparation** : un bien configuré, public, avec un tarif. Faites une
demande depuis la page publique avec une adresse email que vous relevez.

Pour **chacune** des décisions ci-dessous, sur la fiche de la
réservation :

| Vous cliquez | Le dialogue doit dire | L'email doit |
|---|---|---|
| **Confirmée** | « … recevra la confirmation par email » | annoncer la confirmation **et porter le lien de suivi** |
| **Refusée** | « Les dates seront libérées et le locataire en sera informé » | annoncer le refus et **ne porter aucun lien** |
| **Informations demandées** | « Il la recevra par email » | poser la question **et porter le lien** |
| **Annulée** | « … en sera informé par email » | annoncer l'annulation, **sans lien** |

À vérifier à chaque fois :

- [ ] Le dialogue propose **un cadre de texte** sous la question. Écrivez
      une phrase avec des apostrophes et des accents (« le gîte est déjà
      pris ce week-end-là ») — elle doit arriver **telle quelle** dans
      l'email, pas transformée en `week-end-l&#224;`.
- [ ] Cette phrase est **détachée** du texte automatique (citée, en
      retrait), pas fondue dedans.
- [ ] **Sans rien écrire**, l'email part quand même et dit ce qui s'est
      passé.
- [ ] Après la décision, le bandeau vert se termine par « Le locataire a
      été prévenu par email. »
- [ ] Le lien de suivi reçu **ouvre bien la page de suivi** (c'est le
      changement de stockage du jeton : s'il ne marche pas, rien d'autre
      ne marchera).

Puis, les cas qui doivent rester **silencieux** :

- [ ] Passer une demande en **« En cours d'examen »** : aucun dialogue,
      aucun email, rien dans le bandeau à propos du locataire.
- [ ] **Proposer d'autres dates** (bloc « Proposition ») : le champ
      « Message au locataire » indique désormais sous lui « Envoyé avec la
      proposition. » L'email part, porte les dates proposées et le lien.
      Le bandeau ne dit plus « Proposition envoyée » quand rien n'est
      parti — il dit « Proposition enregistrée » plus ce qui est arrivé à
      l'email.
- [ ] **Accepter / refuser une demande de modification du locataire** :
      les deux boutons demandent confirmation, avec un cadre de texte.
      Sur une acceptation, l'email doit citer **les nouvelles dates**, pas
      les anciennes.

**Le point délicat** : lisez les quatre emails **comme un locataire**.
Est-ce qu'un parent qui n'a jamais vu ce site comprend ce qu'on lui dit
et ce qu'il doit faire ? C'est la seule chose qu'aucun test ne mesure.

### 1.2 Inscription — la page de confirmation

- [ ] Inscrivez un enfant. Vous atterrissez sur « Demande envoyée » avec
      son prénom.
- [ ] **Appuyez sur F5.** La page se réaffiche. Le navigateur ne doit
      **plus jamais** demander « Confirmer le renvoi du formulaire ? »
- [ ] Vérifiez qu'il n'y a **qu'une seule** demande côté staff.
- [ ] Le fil d'Ariane affiche « Inscriptions » comme un **lien**
      cliquable qui ramène à la page d'inscription.
- [ ] Ouvrez `/inscriptions/envoyee` dans un onglet privé : vous devez
      être renvoyé vers `/inscriptions`, pas voir un « Merci ! » qui ne
      vous concerne pas.

### 1.3 Envoi groupé — le décompte avant l'envoi

- [ ] Préparez un email vers une liste que vous connaissez, passez-le en
      test, puis cliquez « Lancer l'envoi ».
- [ ] Le dialogue doit **commencer par le nombre** : « Cet email partira
      à N personnes. » Vérifiez que N correspond à ce que vous attendez.
- [ ] Sur un publipostage Excel, il doit dire « N lignes du fichier ».
- [ ] Sur une liste vide : « Cette liste ne désigne actuellement
      personne. »
- [ ] **Le test qui compte** : choisissez une liste manifestement trop
      large (toute l'unité au lieu d'une section). Le nombre doit vous
      faire hésiter. C'est à ça qu'il sert.

### 1.4 Groupes — l'effectif sur les cartes

- [ ] La liste des groupes affiche « Section · N membres · Activité … ».
- [ ] Pour un groupe de section, N compte **toute la section**, pas
      seulement les gens invités individuellement.
- [ ] Pour un groupe sur invitation, N compte les invités.
- [ ] Un groupe d'une personne dit « 1 membre », pas « 1 membres ».
- [ ] Onglet **Archives** : l'effectif est celui de **l'année du groupe**,
      pas celui d'aujourd'hui.

---

## 2. Les trois arbitrages

### 2.1 SOS — le numéro par défaut

- [ ] Sur `Espace chefs d'U > SOS`, le bouton **« Enregistrer »** à côté
      du sélecteur est **grisé** à l'ouverture.
- [ ] Changez la personne : le bouton s'active.
- [ ] Remettez la personne d'origine : il se re-grise.
- [ ] Enregistrez : toast de confirmation, bouton re-grisé.
- [ ] **Changez sans enregistrer, puis rechargez la page** : l'ancienne
      valeur est toujours là. C'est tout l'objet du changement.

### 2.2 Mise à jour — le fichier fautif

Difficile à provoquer volontairement. Si vous pouvez rendre un fichier
non inscriptible (`chmod` sur `core/` par exemple) et lancer une mise à
jour :

- [ ] Le message nomme un chemin **relatif** (`core/View/…`), jamais
      `/var/www/…` ni le nom de votre hébergement.
- [ ] Il dit quoi vérifier (droits d'écriture, espace disque).
- [ ] Aucun avertissement PHP brut en anglais.

Sinon, sautez : c'est couvert par les tests, l'intérêt ici est seulement
de relire la phrase.

### 2.3 S3 — « Expliquer avec l'IA »

Nécessite le module `llm_connector` configuré.

- [ ] Configurez un stockage S3 avec un **nom de bucket faux**. Testez la
      connexion : message rouge français.
- [ ] Cliquez « Expliquer avec l'IA ». La réponse doit parler du **bucket**
      — pas donner des conseils génériques sur les identifiants.
- [ ] Recommencez avec une **clé secrète fausse** : la réponse doit cette
      fois parler de la signature ou de la clé.
- [ ] Ces deux réponses doivent être **différentes**. Avant, elles ne
      pouvaient pas l'être : le modèle ne recevait que la phrase française
      « vérifiez vos identifiants ».
- [ ] Rechargez la page et cliquez « Expliquer avec l'IA » sans avoir
      relancé de test : le bouton doit être masqué, ou répondre « Lancez
      d'abord un test de connexion. »

---

## 3. Le dialogue de confirmation, partout

Il a remplacé 75 boîtes natives. Il est maintenant sur **toutes** les
pages, et il porte parfois un champ de texte.

Prenez cinq suppressions au hasard dans cinq modules différents
(un reçu en Finances, une photo en Galerie, un message en Groupes, un
article en Actualités, un blocage en Locations) et pour chacune :

- [ ] Le dialogue ressemble au site (pas de « 127.0.0.1:8000 dit : »).
- [ ] Le bouton dangereux est **rouge**, « Annuler » est à **gauche** et
      **c'est lui qui a le focus** — une frappe sur Entrée par réflexe ne
      doit rien détruire.
- [ ] **Échap** annule. Un clic sur le fond annule.
- [ ] Après « Annuler », **rien** ne s'est passé.
- [ ] Enchaînez deux confirmations rapidement : la deuxième s'ouvre
      normalement (pas de fond gris resté à l'écran qui avale les clics —
      c'était un vrai bug trouvé en écrivant les tests de navigateur).

Et le cas nouveau :

- [ ] Sur une décision de location, tapez **plusieurs lignes** dans le
      cadre : la touche **Entrée** doit passer à la ligne, **pas** valider.
- [ ] Sur un dialogue à une seule ligne (insertion d'un lien dans un
      éditeur de texte), Entrée **valide**. Les deux comportements sont
      voulus.

---

## 4. Ce qui a été refactoré (vérification de non-régression visuelle)

Rien de fonctionnel ici : on cherche des écrans **cassés**, pas des
écrans faux.

- [ ] **États vides** : créez un compte neuf ou une section vide et
      parcourez Actualités, Galerie, Finances, Groupes, Locations. Chaque
      « il n'y a rien ici » doit être dessiné **de la même façon**.
- [ ] **En-têtes de page** : le titre, la même taille, au même endroit,
      sur dix pages au hasard. Un seul bouton bleu par écran.
- [ ] **Pagination** : les huit listes paginées (journal, membres,
      mouvements, reçus, articles, réservations, emails, transitions SOS)
      doivent avoir la même pagination.
- [ ] **Badges d'état** : une réservation « Confirmée », un email
      « Envoyé », une demande d'inscription « Acceptée » — même dessin,
      mêmes couleurs de famille.
- [ ] **Modales** : ouvrez-en cinq. Le titre est présent, la croix ferme,
      Échap ferme.
- [ ] **Champs de formulaire** : les 101 champs migrés vers le partial —
      libellé au-dessus, aide en dessous, erreur en rouge liée au champ.
      Regardez surtout les formulaires Finances et Locations.

---

## 5. Mobile et mode sombre

À faire sur un vrai téléphone, pas seulement dans les outils du
navigateur.

- [ ] **Mode sombre** : basculez (menu du compte). Parcourez dix pages.
      Cherchez du texte gris sur fond gris, une bordure invisible, une
      carte blanche restée blanche.
- [ ] Basculez **pendant** qu'une modale est ouverte.
- [ ] Rechargez : le choix est retenu, et il n'y a **pas de flash blanc**
      au chargement.
- [ ] **Cibles tactiles** : les boutons de la grille SOS, les cartes de
      mouvements en Finances, les boutons de décision d'une réservation —
      tous atteignables au pouce, aucun à moins de 44 px.
- [ ] **Fil d'Ariane** : sur mobile, il est la seule navigation vers le
      haut. Vérifiez qu'il est présent et juste sur les pages profondes
      (une réservation, un groupe, un album).
- [ ] **PWA / hors ligne** : coupez le réseau sur une page déjà visitée.
      Le bandeau « Hors ligne — lecture seule » doit apparaître, et les
      pages en cache doivent encore fonctionner (le dialogue de
      confirmation est maintenant dans le cache applicatif — vérifiez
      qu'une confirmation s'ouvre encore hors ligne).

---

## 6. Ce que les tests ne couvrent volontairement pas

À regarder si vous avez le temps, sans que ce soit bloquant :

- Le **ton** des emails de décision (partie 1.1) — c'est le seul endroit
  où le site parle à quelqu'un qui n'en est pas membre.
- La **cohérence du vocabulaire** : « membre », « chef », « responsable »,
  « gestionnaire », « staff d'U ». Le lexique a été unifié au début du
  chantier ; un mot qui détonne est un vrai défaut.
- Les **26 gabarits** qui portent encore du JavaScript en ligne (liste
  dans `docs/ux-convergence-handoff.md`) : ils fonctionnent, ils sont
  simplement moins testables. Si l'un d'eux se comporte bizarrement,
  c'est le premier endroit à regarder.

---

## Si quelque chose ne va pas

Notez **la page, le geste, ce que vous attendiez, ce qui s'est passé**.
Les trois quarts des défauts de ce chantier ont été trouvés comme ça —
en regardant un écran plutôt qu'un test.
