# Chantier — Formulaire d'inscription

Journal d'implémentation du document de chantier
`docs/chantiers/CHANTIER-formulaire-inscription.md` (issue #843, itérations
IT-00 à IT-11). Une section par itération : ce qui a été fait, les décisions
prises en autonomie, les divergences constatées entre le document de
chantier et le dépôt réel, et ce qui a été reporté. Même format que
`docs/chantiers/aide-contextuelle.md`.

La maquette est `docs/chantiers/maquettes/maquette-formulaire-inscription.jsx`
(v5) ; elle fait foi pour la hiérarchie des écrans, les libellés et les états
d'interaction.

---

## IT-00 — Dépôt des pièces

**Livré.**

- Le document de chantier, déposé tel qu'il a été arrêté avec le mainteneur,
  en `docs/chantiers/CHANTIER-formulaire-inscription.md`.
- La maquette, en `docs/chantiers/maquettes/maquette-formulaire-inscription.jsx`,
  et sa ligne dans `docs/chantiers/maquettes/README.md`.
- Ce journal.
- Le corps de l'issue #843 remplacé par la description reprise avec le
  mainteneur ; le corps d'origine est conservé mot pour mot en bas de page.

**Point de départ.** Le document a été écrit sur `e203dfd` ; `main` est sur ce
même commit au démarrage de l'exécution, il n'y a donc encore aucun écart à
noter. Chaque itération vérifie les faits qu'elle cite avant de s'appuyer
dessus et note ici ce qui a bougé.

**Vérification externe préalable.** `php scripts/check-external-sources.php`
a tourné avant le démarrage : 34 sources vérifiées, 34 conformes, aucune
divergence, donc aucune issue à ouvrir.

**Environnement.** Le conteneur de la session fournissait PHP 8.3, alors que
`composer.json` exige `^8.4` : `vendor/bin/phpunit` et `vendor/bin/phpstan`
refusaient de démarrer, et le hook de démarrage l'annonçait seulement par
« phpunit: unavailable ». PHP 8.4 a été installé à la main (paquets Sury, mêmes
extensions que la 8.3, plus `imap`) avant la première itération de code.

**Divergences entre le document et la maquette** (relevées à la revue de la PR
d'IT-00 ; le document dit lui-même que la maquette fait foi pour la hiérarchie
de l'écran, les libellés et les états d'interaction : c'est la maquette qui
l'emporte, et les passages du document concernés ont été alignés sur elle dans
la même PR, pour qu'un lecteur du seul document ne construise pas l'inverse) :

- **La ligne verrouillée « Limite : le nombre de places de l'année de
  parcours ».** D5 et IT-03 la disent « en tête du classement » ; la maquette
  la place **après** la zone « Classement », avec « Toujours appliquée, en fin
  de classement ». C'est aussi ce que fait le moteur de la file : le
  classement d'abord, la limite de places mord à la fin. IT-03 suit la
  maquette ; D5 et IT-03 le disent désormais.
- **L'exemple d'explication d'IT-03** (« Oui — 6 places restent en 1re année
  Louveteaux. Fratrie déjà présente dans l'unité. ») raisonne en places
  restantes, alors que le modèle raisonne en pourcentage d'occupation et
  qu'une demande « Toujours Oui » ne cite aucun chiffre de capacité. Les
  explications suivent celles que construit `recommend()` dans la maquette
  (« Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité. »,
  « 1re demande classée sur 3 pour … Avant elle, N places sur C seraient
  occupées (P %) … »). L'exemple du document est remplacé par deux exemples
  construits comme ceux de la maquette, chiffres recalculés sur ses données
  (Louveteaux · 1re année : la fratrie #416 passe d'abord, puis 4 demandes
  classées, la première jugée sur 7 places sur 15, soit 47 %).
- **Le fil d'Ariane.** Le document écrivait « Configuration › … › Demande
  n° … » ; la maquette et le `module.json` actuel ont « Espace chefs d'U » à
  la racine, et la maquette « Demande #… ». Le document suit la maquette.
- **L'acceptation automatique et un seuil « Oui » au-delà de 100 %.** Le
  seuil « Oui » va jusqu'à 120 %, et IT-10 « ne dépasse jamais la capacité
  sauf règle « Toujours Oui » ». Les deux tiennent ensemble à une condition,
  désormais écrite dans IT-10 et la définition de terminé : une demande
  « Oui » qui ferait dépasser la capacité n'est pas acceptée
  automatiquement, elle reste à décider à la main.
- Corrections dans la maquette elle-même : les identifiants de la page
  « fiche » passent en anglais (`detailId`, `RequestDetail`) ; une phrase de
  confirmation qui disait « Les 1 décision déjà prise » est accordée ; vider un
  champ de seuil ne fait plus sauter la valeur à une borne (la saisie vide est
  ignorée — le produit fera de même) ; l'exemple chiffré des seuils dit en
  clair quand la bande « Peut-être » ne contient aucun nombre de places ; la
  ligne « Limite » décrit ce que fait vraiment la borne incluse (à 100 %
  d'occupation, « Peut-être » et non « Non ») ; le bouton de simulation de la
  maquette coupe les deux interrupteurs quand il repasse les gabarits « À
  rédiger ».

**Faits à trancher en cours de route** (section « Faits vérifiés et pièges »
du document) : R1 dans IT-02, R2 dans IT-02, R3 dans IT-05, R4 dans IT-03, R5
dans IT-06, R6 dans IT-05, R7 dans IT-05, R8 à chaque itération qui touche
une action. Leur conclusion est notée dans la section de l'itération qui les
traite.
