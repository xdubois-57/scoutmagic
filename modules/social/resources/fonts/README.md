# Police des images publiées

`DejaVuSans-Bold.ttf` — DejaVu Sans Bold, version 2.37
(<https://dejavu-fonts.github.io/>), telle que distribuée par le paquet
Debian `fonts-dejavu-core`.

Elle sert au seul `Modules\Social\Card\CardRenderer`, qui écrit le titre et
l'adresse du site sur les images publiées vers Facebook et Instagram. GD a
besoin d'un fichier TrueType pour écrire du texte, et le dépôt n'en
embarquait aucun.

**Licence** : celle des polices Bitstream Vera et Arev, texte intégral dans
`LICENSE-DejaVu.txt` à côté de ce fichier ; les modifications DejaVu sont
dans le domaine public. Elle permet de redistribuer la police avec un
logiciel, à condition de garder cette notice et de ne pas vendre la police
seule. La police est livrée comme un fichier distinct, lu à l'exécution
pour dessiner des pixels : elle n'est pas combinée au code du projet, qui
reste sous AGPL-3.0-or-later.
