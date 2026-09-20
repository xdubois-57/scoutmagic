# Gabarits officiels

Les PDF de ce dossier sont les formulaires de **Les Scouts ASBL**, repris tels quels. Ils sont le
fond sur lequel le module écrit ; ils ne sont jamais modifiés, ni ici ni à l'exécution.

| Fichier | Source dans le pack fédéral | Pages | SHA-256 |
|---|---|---|---|
| `autorisation-parentale.pdf` | `4.Les_Scouts_Autoristation_parentale.pdf` | 1 | `7bdc1f93c7ef3f659522a19955928dd34e0fe429a21999214e13acc84a9ad928` |
| `fiche-sante.pdf` | `2.Les_Scouts_Fiche_sante_formulaire.pdf` | 2 | `26b573fa86f2658aaff238d329634dbae701a07c53a88fc1a031e4569559a73f` |

Les deux ont déjà été convertis en PDF 1.4 (voir ci-dessous) et portent la mention « Version 2026 »
de la fédération.

## Mettre un gabarit à jour

La fédération republie ses formulaires — ils portent une mention « Version &lt;année&gt; » en haut à
droite. Quand une nouvelle version sort :

**1. Convertir le fichier reçu.** FPDI dans sa version libre ne lit que les tables de références
croisées classiques, soit les PDF jusqu'à la version 1.4. Les fichiers de la fédération sont en 1.7,
parfois avec flux d'objets. La conversion se fait **sur un poste de développement**, jamais sur le
serveur :

```bash
qpdf --object-streams=disable --force-version=1.4 \
     <fichier-reçu>.pdf \
     modules/official_documents/templates/<nom-du-gabarit>.pdf
```

Elle conserve les pages, les champs et la mise en page ; elle ne change que la façon dont le fichier
est structuré en interne.

**2. Lancer les tests.** Celui qui épingle les SHA-256 va échouer : c'est voulu, c'est le seul
garde-fou contre un gabarit remplacé en silence.

**3. Recaler les coordonnées.** Les textes sont écrits à des positions fixes en millimètres. Une
marge déplacée de 3 mm dans le nouveau formulaire fait tomber tous les textes à côté sans qu'aucune
erreur ne le signale. Produis le gabarit avec sa grille millimétrée :

```bash
php scripts/pdf-template-grid.php modules/official_documents/templates/<nom-du-gabarit>.pdf
```

puis ajuste la carte de coordonnées du document, et **regarde le PDF produit**. Un test ne peut pas
vérifier qu'un texte est en face de la bonne ligne pointillée.

**4. Mettre à jour la constante de SHA-256**, une fois et une seule, quand le rendu est correct —
dans le test, et dans le tableau ci-dessus.

```bash
shasum -a 256 modules/official_documents/templates/<nom-du-gabarit>.pdf
```

## Ce qu'il ne faut pas faire

- **Ne remplace pas un gabarit sans faire l'étape 3.** Le site continuerait à produire des
  documents, mais illisibles, et personne ne s'en apercevrait avant qu'un parent ne le signale.
- **N'écris rien sur ces PDF.** Pas de tampon, pas de mention ajoutée, pas de logo d'unité. Ce sont
  des documents officiels ; le module écrit dedans, il ne les réécrit pas.
- **Ne convertis pas à l'exécution.** `qpdf` n'est pas une dépendance du projet et n'existe pas sur
  un hébergement mutualisé.
