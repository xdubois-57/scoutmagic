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

---

## IT-02 — Le composant

**Livré.**

- **La fonction Twig `phone(numéro, classes)`** (`Core\View\PhoneExtension`).
  Elle rend un vrai lien `tel:` portant le texte affiché, le numéro
  international, et la nature en `data-*` (`mobile`, `landline` ou
  `foreign`, lue par `PhoneNumber::kind()`). Un numéro vide ne rend rien.
- **La feuille** (`partials/phone_sheet.html.twig`), un `offcanvas-bottom`
  inclus une fois par `base.html.twig`. Elle propose les entrées de D2 avec
  les icônes du chantier ; un fixe belge n'y garde que « Appeler » et
  « Copier le numéro », avec la phrase qui dit pourquoi.
- **`public/assets/js/phone-link.js`.** Sur écran tactile, un tap ouvre la
  feuille. Ailleurs, le lien devient le numéro en texte suivi de « Copier ».
  `linksFor()` est le seul endroit où les formats de D4 existent.
- Le script est précaché par `sw.js` : une page en cache garde ses
  numéros utilisables hors ligne.

**Décisions autonomes.**

- **La nature d'un numéro étranger s'affiche « Numéro étranger »**, pas
  « Mobile » comme dans la maquette. On ne sait pas s'il est fixe (D7) ;
  l'appeler « Mobile » serait affirmer ce qu'on ignore. Toutes les actions
  lui restent proposées.
- **`installed-app-links.js` respecte désormais `rel="noreferrer"`.** Dans
  l'application installée, ce script rouvre tout lien externe par
  `window.open(…, 'noopener')`, qui envoie le `Referer` que D12 interdit.
  Il passe maintenant `noopener,noreferrer` pour un lien qui le demande ;
  un test le vérifie.
- **La copie copie le texte affiché** (`+32 472 54 67 71`), comme la
  maquette. Un refus du presse-papiers s'affiche « Copie impossible » au
  lieu de prétendre avoir copié.
- **La cible tactile de 44 px vit dans le bloc `@media (pointer: coarse)`
  existant d'`app.css`.** `UxConventionsTest` l'exige ; l'itération l'avait
  d'abord mise dans un bloc à elle.
- **`ScoutMagicPhone.enhance(racine)`** est exposé pour un contenu ajouté
  après le chargement ; un numéro n'est traité qu'une fois.

**À tester sur de vrais appareils — non fait.** Cet environnement n'a ni
iOS ni Android. Le comportement de `wa.me` et `signal.me` depuis la PWA
installée, sur iOS Safari et Android Chrome, n'a été vérifié que par la
lecture du code et les tests jsdom. Il reste à essayer sur téléphone, en
onglet et en application installée.

---

## IT-03 — Déploiement sur tous les écrans

**Livré.** Tous les liens `tel:` écrits à la main passent par
`phone()`, et les numéros qui n'avaient aucun lien en reçoivent un :

- **fiches et listes de membres** : fiche d'un membre (vue admin et vue
  normale), liste des staffs, liste d'une section ;
- **camps** : contacts d'un camp, et contact sur l'écran d'effacement ;
- **locations** : locataire d'une réservation, contacts et numéro d'urgence
  sur la page de suivi, numéro d'urgence dans la configuration d'un bien ;
- **trombinoscope** ;
- **listes de l'Encadrement** ;
- **covoiturage** : le conducteur et un demandeur sur la carte d'une
  voiture, le conducteur et les passagers dans la bannière du jour du
  trajet.

**Écarts avec le relevé du document.**

- Le document comptait 11 liens dans 8 gabarits ; il y en avait 12 dans
  9. Le neuvième, `modules/leadership/views/partials/_person_list.html.twig`,
  est arrivé après le commit sur lequel le document a été écrit.
- Deux numéros n'étaient pas relevés : le numéro d'urgence dans la
  configuration d'un bien (`modules/rental/views/config/asset.html.twig`)
  et les deux numéros de la bannière du covoiturage (`show.html.twig`,
  ajoutés par #835, signalés sur l'issue).
- Aucun numéro n'est rendu par JavaScript : rien à faire de ce côté.
- Les e-mails ne changent pas (D13).

**Aucune règle de visibilité ne change.** Chaque gabarit appelle `phone()`
à l'endroit exact où il affichait le numéro, sous les mêmes conditions ; un
numéro que la page masque n'est jamais passé à la fonction. Les tests du
covoiturage qui vérifient qu'une demande en attente ne voit pas le numéro
du conducteur passent sans changement de logique ; seul le texte cherché
suit le nouveau format (`+32 478 12 34 56`), et l'absence est vérifiée sur
les chiffres seuls, ce qui couvre les deux écritures.

**Un champ libre qui n'est pas un numéro s'affiche tel quel.** Le
numéro d'un locataire, d'un contact de camp ou de la page de suivi est
saisi librement : « 0475 12 34 56 ou 02 123 45 67 » y est possible. Lu
chiffre par chiffre, il deviendrait un seul numéro inventé, proposé à
l'appel. `phone()` n'en fait donc un lien que si le texte n'est écrit
qu'avec des chiffres, des espaces et `+ ( ) . / -`, et compte de 8 à 15
chiffres une fois lu ; sinon le texte saisi s'affiche, sans lien — même
sans aucun chiffre, pour qu'un gabarit qui teste la valeur brute n'affiche
jamais une ligne vide. Seul un champ vide ne rend rien. Relevé par la
revue de la PR d'IT-03.

**Le contrôle demandé.** `PhoneNumbersGoThroughThePhoneFunctionTest`
échoue si un `href="tel:` écrit à la main revient dans
`core/View/templates/` ou `modules/*/views/` (e-mails exceptés), et si l'un
des écrans relevés cesse d'afficher son numéro par `phone()`.

**Versions des modules.** Les modules camps (1.18.0), covoiturage (1.3.0),
leadership (1.3.0), rental (1.47.0) et trombinoscope (1.3.0) montent d'une
version mineure : leurs utilisateurs voient le changement.

**Reporté à IT-04.** L'aide du covoiturage dit qui voit quel numéro, pas
comment l'utiliser ; le geste est décrit par le sujet d'aide général
d'IT-04, que les sujets du covoiturage citent.
