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

**Faits à trancher en cours de route** (section « Faits vérifiés et pièges »
du document) : R1 dans IT-02, R2 dans IT-02, R3 dans IT-05, R4 dans IT-03, R5
dans IT-06, R6 dans IT-05, R7 dans IT-05, R8 à chaque itération qui touche
une action. Leur conclusion est notée dans la section de l'itération qui les
traite.
