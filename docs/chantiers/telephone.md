# Chantier — Numéros de téléphone

Journal d'exécution du document de chantier
[`CHANTIER-telephone.md`](CHANTIER-telephone.md) (itérations IT-01 à IT-04,
issue #757). Une section par itération : ce qui a été fait, les décisions
prises en autonomie, les écarts constatés entre le document et le dépôt, et
ce qui a été reporté. Même format que `aide-contextuelle.md`.

---

## IT-01 — Le numéro : conversion, affichage, nature

**Livré.** `Core\Phone\PhoneNumber::parse()` lit un numéro saisi une seule
fois et rend son numéro international (`+32478123456`), son texte affiché
(`+32 478 12 34 56`) et sa nature (`canReceiveMessages`, D7).
`TextNormalizerService::normalizePhone()` ne fait plus sa propre analyse :
il rend `PhoneNumber::parse($raw)->display`, et ses deux aides privées
(`formatBelgian()`, `groupDigits()`) ont disparu avec elle.
`Core\Phone\CountryCallingCodes` porte la table des indicatifs.

**Les deux défauts annoncés, confirmés et corrigés.**

- `0033 6 12 34 56 78` était lu comme un numéro belge. Un `00` initial vaut
  désormais un `+`.
- `+352 691 123 456` devenait `+35 …`. L'indicatif est lu par recherche du
  plus long préfixe dans la table de l'UIT.

**Le point à vérifier : confirmé.** `formatBelgian()` ne connaissait que
`02` comme zone à un chiffre ; Anvers, Liège et Gand (`03`, `04`, `09`)
s'affichaient `+32 31 23 45 67`. Le test a été écrit d'abord, il échouait ;
les quatre zones à un chiffre s'affichent maintenant `+32 3 123 45 67`.

**La source de la table.** « List of ITU-T Recommendation E.164 assigned
country codes », annexe au Bulletin d'exploitation de l'UIT n° 1114 du
15 décembre 2016 (`T-SP-E.164D-2016`), lue dans le PDF publié par l'UIT :
217 indicatifs, les codes « Spare » et « Reserved » écartés. C'est la
dernière édition complète publiée de cette liste ; un indicatif attribué
après elle tombe dans le cas « absent de la table » (affiché `+` suivi des
chiffres, sans groupement). Un test vérifie que la table reste sans préfixe
commun, la propriété dont dépend la recherche.

**Décisions autonomes.**

- **Le groupement des numéros étrangers.** Le document ne garantit que la
  séparation de l'indicatif. Deux pays voisins reçoivent leur groupement
  réel — la France (`+33 6 12 34 56 78`) et le Luxembourg
  (`+352 691 123 456`, comme la maquette) ; les autres gardent le groupement
  par paires d'avant ; un indicatif absent de la table n'est pas groupé.
- **Le seul test existant dont l'attente change** est celui d'un numéro
  français (`+33 123 45 67 89` devient `+33 1 23 45 67 89`). Aucune attente
  belge ne change, ni dans `TextNormalizerServiceTest` ni dans
  `MemberExportRowBuilderTest`.
- **Le paramètre `$defaultCountry` de `normalizePhone()` est retiré.** Aucun
  appelant ne le passait, et sa seule autre valeur produisait un numéro sans
  indicatif.
- **Un fixe belge spécial (`0800 …`) ne reçoit pas de message**, par la même
  règle que tout numéro belge hors `4[5-9]` sur neuf chiffres.
