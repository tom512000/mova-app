# Mova

> **Stats. Films. You.**

Mova prend un export Letterboxd — une poignée de fichiers CSV qui ne contiennent qu'un slug, une
note et une date — et en fait une bibliothèque personnelle complète : affiches, distributions,
pays de production, durées, genres, statistiques, et huit jeux construits sur les films qu'on a
réellement vus.

Tout ce que Letterboxd n'exporte pas est reconstruit en arrière-plan depuis TMDB. Tout ce que
Letterboxd oublie — la note qu'on avait mise avant de renoter un film — est conservé par la base,
qui devient au fil des imports la seule à détenir cet historique.

L'application est mono-utilisateur·rice par nature, multi-comptes par construction : chaque compte
a sa propre bibliothèque, et peut ouvrir la sienne en lecture seule à d'autres via un lien de
partage.

---

## Sommaire

- [Stack technique](#stack-technique)
- [Fonctionnalités](#fonctionnalités)
  - [Bibliothèque](#1-bibliothèque--films-et-séries)
  - [Fiche d'une œuvre](#2-fiche-dune-œuvre)
  - [Dashboard statistique](#3-dashboard-statistique)
  - [Le musée](#4-le-musée)
  - [Watchlist](#5-watchlist--quest-ce-que-je-regarde-ce-soir-)
  - [Import Letterboxd](#6-import-letterboxd)
  - [Enrichissement TMDB](#7-enrichissement-tmdb)
  - [Synchronisation RSS](#8-synchronisation-rss)
  - [Les jeux](#9-les-jeux)
  - [Profils partagés](#10-profils-partagés)
  - [Compte et authentification](#11-compte-et-authentification)
  - [Identité visuelle](#12-identité-visuelle)
- [Modèle de données](#modèle-de-données)
- [Surface d'API](#surface-dapi)
- [Qualité](#qualité)

---

## Stack technique

### Backend

| Composant | Version | Rôle |
|---|---|---|
| PHP | 8.3.33 | `ext-intl`, `ext-ctype`, `ext-iconv`, `gd`, `apcu`, `opcache` |
| Symfony | 7.4.17 | FrameworkBundle, Security, Serializer, Validator, Console, HttpClient, Uid |
| Symfony Messenger | 7.4 | Traitement asynchrone (transport Doctrine) |
| Symfony Scheduler | 7.4 | Tâches récurrentes (synchro RSS horaire) |
| Doctrine ORM | 3.6.8 | Mapping par attributs |
| Doctrine DBAL | 4.4.4 | SQL brut pour toutes les agrégations statistiques |
| Doctrine Migrations | 3.7 | 18 migrations versionnées |
| NelmioCorsBundle | 2.6 | CORS pour le SPA |
| Monolog | 4.0 | Journalisation |
| PHPUnit | 11.5.56 | 292 tests, 1 345 assertions |

### Frontend

| Composant | Version | Rôle |
|---|---|---|
| React | 19.2.8 | |
| TypeScript | 6.0.3 | `strict` |
| Vite | 8.2.2 | Serveur de dev et build |
| React Router | 7.18.2 | Routage SPA |
| TanStack Query | 5.101.4 | Cache et état serveur |
| Tailwind CSS | 4.3.3 | Via `@tailwindcss/vite`, thème par tokens CSS |
| Recharts | 3.10.1 | Graphiques du dashboard |
| Axios | 1.19.0 | Client HTTP, intercepteur de profil |
| lucide-react | 1.33.0 | Icônes |
| CVA + clsx + tailwind-merge | 0.7.1 / 2.1.1 / 3.6.0 | Variantes de composants |
| Oxlint | 1.79.0 | Linter |

### Infrastructure

| Composant | Version |
|---|---|
| PostgreSQL | 16 (alpine) |
| FrankenPHP | `php8.3-alpine` |
| Node | 22 (alpine) |
| Adminer | dernière (profil `dev`) |

### Services externes

- **TMDB** — catalogues films *et* séries, interrogés en `fr-FR`, avec
  `append_to_response=credits,external_ids,release_dates`.
- **Letterboxd** — les pages publiques `letterboxd.com/film/<slug>/` (résolution d'identifiants)
  et le flux RSS du journal.

---

## Fonctionnalités

### 1. Bibliothèque — Films et séries

La liste complète des œuvres vues, paginée par 24, avec une barre de filtres alimentée par des
facettes calculées sur la bibliothèque réelle (aucune option morte n'est proposée).

- **Recherche plein texte** sur le titre **et** le titre original — « Parasite » comme
  « 기생충 » ramènent la même fiche.
- **Filtre par type** : films, séries, ou les deux.
- **Filtre par genre**, alimenté par les genres réellement présents.
- **Filtre par note** : valeur exacte au demi-point (0,5 à 5,0), plus une entrée « Non noté » qui
  n'apparaît que s'il existe au moins un visionnage sans note.
- **Filtre par année de sortie.**
- **Filtre par personne** : cliquer sur un nom depuis une fiche ou depuis le dashboard filtre la
  bibliothèque sur ses crédits, avec ou sans restriction de rôle (réalisation, scénario,
  interprétation, création de série).
- **Sept tris** : titre, note, année, date de visionnage, date d'ajout, durée, aléatoire. Chacun
  porte sa direction naturelle par défaut (alphabétique croissant, note décroissante…) et la
  direction reste inversable.
- **Tri aléatoire ensemencé** : le mélange est stable d'une page à l'autre grâce à une graine
  transmise au serveur, et un bouton dédié permet d'en tirer une nouvelle.
- Chaque carte affiche l'affiche, le titre, l'année, la note personnelle en étoiles et un état
  d'enrichissement quand TMDB n'a pas encore répondu.

### 2. Fiche d'une œuvre

- **En-tête** : affiche, titre, titre original s'il diffère, année (ou plage d'années de diffusion
  pour une série).
- **Badges** : type d'œuvre, nombre de saisons et d'épisodes pour une série, durée — explicitement
  libellée « au total » sur une série, dont `runtimeMinutes` cumule tous les épisodes —, genres,
  pays de production **en français**.
- **Synopsis** composé en lettrine, à la manière d'un article de presse.
- **Crédits** : « Réalisé par » pour un film, « Créé par » pour une série (les deux ne coexistent
  jamais), puis les six premiers noms au générique. Chaque nom est un lien vers la bibliothèque
  filtrée sur cette personne et ce rôle.
- **Notes externes** : moyenne TMDB, lien IMDb.
- **« Ce que j'en pensais avant »** — le bloc de renotation. Quand un film porte plusieurs
  visionnages notés différemment, il affiche l'ancienne note, la nouvelle, l'écart et un verdict.
- **« Mes visionnages »** — un par ligne, avec date, note, étiquettes et un badge distinguant un
  **rewatch déclaré** (« Rewatch ») d'un **rewatch déduit** (« Revu ? »), c'est-à-dire reconstruit
  à partir d'une note qui a bougé sans entrée de journal.
- **Critiques**, avec marqueur de spoiler et repli du texte quand il en contient un.

### 3. Dashboard statistique

Onze agrégations, toutes calculées en SQL sur la base et jamais en mémoire côté client.

**Cartes de synthèse**
- Nombre d'œuvres distinctes, avec le total de visionnages et de rewatches en sous-titre.
- Taille de la watchlist.
- Note moyenne et note médiane.
- Temps total de visionnage, en heures et converti en jours.
- Durée moyenne d'un film.
- Film le plus long et film le plus court — **séries exclues**, puisque leur durée est celle de la
  série entière et rendrait l'extrême absurde.

**Graphiques et sections**
- **Vus au fil du temps** — courbe du nombre de visionnages, du temps passé et de la note moyenne,
  basculable entre granularité annuelle et mensuelle.
- **Distribution des notes** — les dix paliers de demi-étoile, avec moyenne, médiane et écart-type.
- **Genres les plus regardés** — nombre d'œuvres, de visionnages, note moyenne et temps cumulé par
  genre. Le vocabulaire TV de TMDB (« Action & Adventure », « Sci-Fi & Fantasy ») est fusionné vers
  le vocabulaire film, pour ne pas compter deux fois les mêmes concepts.
- **Pays de production** — anneau couvrant *tous* les pays, la traîne étant regroupée dans un
  « Autres » qui n'est honnête que s'il couvre bien tout le reste. Une coproduction compte une fois
  par pays, donc les totaux dépassent volontairement la taille de la bibliothèque.
- **Vus à leur sortie** — les œuvres vues dans les 31 jours suivant leur sortie, dont celles vues
  dans la première semaine, rapportées à un dénominateur honnête (les œuvres pour lesquelles la
  question a un sens). Mesuré depuis la **sortie française en salles** quand TMDB la connaît, avec
  repli sur la sortie primaire sinon. Films uniquement : la date d'une série est celle de son
  premier épisode.
- **Rythme** — jours actifs, amplitude en jours, jour le plus chargé, plus longue série de jours
  consécutifs, répartition par jour de la semaine, et une **carte de chaleur calendaire** avec
  marqueur du jour courant.
- **Quatre classements de personnes** — réalisateur·rice·s, acteur·rice·s, scénaristes,
  créateur·rice·s de séries. Chacun donne le nombre d'œuvres, la note moyenne, la meilleure et la
  pire note attribuées.

### 4. Le musée

Toutes les affiches accrochées sur un mur unique, en perspective, parcouru horizontalement à la
molette ou au glisser.

- Mur sur **trois rangées**, incliné de 16°, avec profondeur CSS.
- **Salles** : tout, films seuls, séries seules.
- **Accrochage** : les mêmes sept tris que la bibliothèque, dont l'aléatoire avec son bouton de
  re-tirage dédié.
- Viser une affiche la **décroche** — elle se soulève et se détache du mur, révélant titre, année
  et note.
- Rendu plafonné à 44 colonnes simultanées, quelle que soit la largeur de fenêtre.

### 5. Watchlist — « Qu'est-ce que je regarde ce soir ? »

- **Filtre par temps disponible** — « Peu importe », moins d'1 h 30, moins de 2 h, moins de 2 h 30.
  Une œuvre dont la durée est inconnue est **exclue** dès qu'un budget temps est posé : une durée
  inconnue ne répond pas à la question posée.
- **Filtre par genre et par décennie**, alimentés par des facettes calculées sur la watchlist
  elle-même et non sur la bibliothèque.
- **« Choisis pour moi »** — un tirage aléatoire respectant les filtres actifs. Le tirage est fait
  **côté serveur** : le navigateur ne détient qu'une page de la watchlist, un tirage local serait
  biaisé vers ce que le tri a mis en premier.
- **Tri par ancienneté d'ajout**, avec libellés adaptés au sens du tri (« Ajouts récents » /
  « Ajouts anciens »), plus les tris par titre, année et durée.
- **Recherche** par titre.
- Les facettes exposent aussi la durée la plus courte et la plus longue de la watchlist.

### 6. Import Letterboxd

Dépôt d'un `.zip` d'export complet ou d'un `.csv` isolé, jusqu'à 100 Mo. Le fichier est stocké, un
`ImportBatch` est créé, et le traitement part en tâche de fond via Messenger.

**Six types de fichiers pris en charge**, traités dans un ordre imposé :

| Ordre | Fichier | Ce qu'il apporte |
|---|---|---|
| 0 | `diary.csv` | Entrées de journal : date, note, rewatch, étiquettes |
| 1 | `ratings.csv` | Une ligne par film, la note courante |
| 2 | `watched.csv` | Les films vus sans note |
| 3 | `reviews.csv` | Les critiques et leurs étiquettes |
| 4 | `watchlist.csv` | La watchlist |
| 5 | `profile.csv` | Le profil Letterboxd et ses quatre films favoris |

Un septième type — les CSV de listes personnelles — est déclaré dans l'énumération et a sa place
dans l'ordre, mais n'a pas encore d'importeur : un tel fichier est rejeté avec un message nommant
ses colonnes. La reconnaissance se fait sur le nom **et** l'en-tête du fichier, et ajouter un type
ne demande qu'une classe de plus : elle est découverte automatiquement.

L'ordre n'est pas cosmétique : `ratings.csv` et `watched.csv` ne créent un visionnage que si le
film n'en a aucun, donc `diary.csv` — la source la plus détaillée — doit avoir fini avant eux.
`profile.csv` passe en dernier pour retrouver des films déjà nommés plutôt que de créer des ébauches
portant leur slug.

**La renotation.** C'est le point le plus délicat du pipeline. Letterboxd n'écrit une entrée de
journal que si l'on passe par « Log this film ». Renoter un film depuis sa page **réécrit sur place**
l'unique ligne de `ratings.csv` : la note change et la date bouge. L'ancienne note disparaît
définitivement de tous les exports futurs.

`RatingsImporter` compare la date de la ligne à celle du dernier visionnage connu :
- pas de visionnage connu → création normale (`csv_import`) ;
- date postérieure → **second visionnage** enregistré (`csv_rerating`, marqué rewatch déduit) ;
- date antérieure → ligne ignorée ;
- date identique, ou l'une des deux inconnue → mise à jour sur place, sauf si le visionnage
  appartient au journal, qui reste prioritaire.

Conséquence : **la base est la seule à détenir cet historique**, et un repartir-de-zéro depuis un zip
unique le perdrait définitivement.

**Suivi et robustesse**
- Statuts : en attente, en cours, terminé, terminé avec erreurs, échec.
- Comptage des lignes totales, importées, ignorées, en erreur, avec le détail des erreurs ligne à
  ligne (`ImportRowError`).
- Les compteurs sont remis à zéro à chaque passe, pour qu'un message redélivré décrive sa passe et
  non la somme de toutes les tentatives.
- Historique complet des imports consultable, avec suivi en direct de l'import en cours.
- Déduplication par `MovieUpserter` et `TagUpserter`, qui gardent un cache par slug et par nom :
  sans eux, un même film ou une même étiquette apparaissant deux fois dans un lot serait inséré deux
  fois avant le premier `flush`, et violerait la contrainte d'unicité.

### 7. Enrichissement TMDB

Un export Letterboxd ne contient aucun identifiant TMDB. La résolution se fait en trois temps, par
message asynchrone et par film :

1. **Lecture de la page publique Letterboxd** du film. Le slug vient de l'export, donc le lien TMDB
   qu'on y trouve est une correspondance exacte et non une supposition. Il indique aussi de quel
   catalogue relève l'entrée — films ou séries.
2. **Repli sur `/search/movie`** par titre et année, scoré sur la similarité et la concordance
   d'année. Aucun repli sur `/search/tv` : une série que Letterboxd ne lie pas n'est pas
   identifiable avec confiance.
3. **Échec explicite** (`AmbiguousMatchException`) — jamais de correspondance devinée en silence.

> L'ordre a été inversé après coup, et c'est important : la recherche en premier produisait des
> correspondances **confiantes et fausses**. TMDB étant interrogé en `fr-FR`, le titre renvoyé est le
> titre français, jamais le titre international exporté par Letterboxd — « Back to School »
> (slug `back-to-school-2019`) est le film français « La Grande Classe », qui obtenait donc un score
> de similarité proche de zéro pendant qu'un court-métrage sans rapport littéralement nommé
> « Back To School » obtenait 1,0 et gagnait.

**Ce qui est récupéré** : titre et titre original, synopsis, accroche, dates de sortie (dont la
**sortie française en salles**, extraite des types 2 et 3 de `release_dates`), durée, nombre de
saisons et d'épisodes, date de dernière diffusion, langue originale, budget, recettes, popularité,
note et nombre de votes TMDB, affiche, image de fond, genres, pays de production **traduits en
français** via ICU avec une table d'exceptions (Hong Kong, Macao, URSS, Yougoslavie, RDA), studios,
et les crédits complets (réalisation, scénario, création, interprétation).

**Cinq états d'enrichissement** : `pending`, `enriched`, `failed`, `ambiguous`, et `excluded` —
ce dernier étant **terminal**. Une entrée confirmée sans correspondance TMDB n'est jamais réessayée,
ce qui empêche un ré-import de relancer la recherche et de re-choisir un mauvais candidat.

**Sept commandes de maintenance** accompagnent le pipeline : audit des correspondances existantes,
forçage manuel d'un identifiant, remise à l'état ambigu, exclusion définitive, relance des échecs,
rattrapage des studios, rattrapage des dates de sortie françaises.

### 8. Synchronisation RSS

Le flux RSS du journal Letterboxd sert de synchronisation continue entre deux exports.

- **Une tâche récurrente horaire par compte** ayant activé la synchro — pas un job global, puisque
  la synchro est par utilisateur·rice.
- Chaque entrée du flux devient un `Watch`, **idempotent** sur le `guid` de l'item, exactement comme
  les lignes de `diary.csv`.
- Le flux portant déjà un identifiant TMDB, l'enrichissement **saute complètement** la phase de
  résolution par recherche.
- Les **spoilers** sont détectés : Letterboxd ne les exporte dans aucun CSV, mais suffixe le titre
  de l'item RSS par `(contains spoilers)`.
- Déclenchement manuel possible depuis la page Import, avec affichage de l'état et de la date de
  dernière synchronisation.

### 9. Les jeux

Huit jeux, tous construits sur **la bibliothèque de la personne qui joue** — donc sur des films
qu'elle a vraiment vus. Chacun se joue en **mode quotidien** (une grille par jour, la même du premier
essai jusqu'à minuit heure de Paris) ou en **mode infini** (une nouvelle grille à la demande, avec
les vingt dernières réponses écartées).

**Six jeux cachent un film et se jouent en le nommant :**

| Jeu | Principe | Essais |
|---|---|---|
| **Le film mystère** | Un indice de plus à chaque erreur : genre, année, pays, studio, réalisation, second rôles, puis tête d'affiche | autant que d'indices |
| **Le film comparé** | Chaque proposition est posée à côté de la réponse, attribut par attribut | 8 |
| **Le film pixelisé** | L'affiche, agrandie depuis 6 pixels de large, se précise à chaque essai (6 → 9 → 14 → 22 → 34) | 5 |
| **Le décor** | Même principe sur l'image de fond — pas de titre, pas de cadrage vertical autour d'un visage. Échelle plus large pour compenser (9 → 14 → 22 → 34 → 52) | 5 |
| **Le film pendu** | Le titre, lettre par lettre. Une lettre absente ou un film mal nommé coûtent une vie | 7 vies |
| **L'accroche** | La phrase marketing du film, et rien d'autre jusqu'au premier échec | autant que d'indices |

**Deux jeux ne cachent rien et interrogent la bibliothèque elle-même :**

| Jeu | Principe | Format |
|---|---|---|
| **Le duel** | Lequel de ces deux films as-tu noté le plus haut ? | série, historique des 12 dernières manches |
| **La chronologie** | Cinq films à remettre par date de sortie | 3 tentatives |

**Le principe qui gouverne l'ensemble** : rien que la personne n'a pas mérité ne traverse le réseau.

- La **pixellisation est faite côté serveur** — envoyer l'image et demander à CSS de la flouter
  mettrait la réponse dans l'onglet réseau.
- Les **verdicts de comparaison sont calculés côté serveur** — dire au client « la réponse est de
  1998 » pour qu'il colore ses propres cases reviendrait à lui donner la réponse.
- Le **titre du pendu ne sort jamais** tant que la partie est ouverte : ce qui part est une case par
  caractère, contenant le caractère ou `null`.
- La **chronologie ne renvoie qu'un bit par emplacement** : bon, ou pas. Dire lequel y va, ou si un
  film est trop tôt, effondrerait la grille en un coup.

Les listes à valeurs multiples (genres, pays, studios, noms) sont jugées **valeur par valeur** et non
en bloc : un film partageant un genre sur trois a quelque chose à dire sur ce genre.

**Donner sa langue au chat** — un bouton commun aux huit jeux, en **mode infini uniquement**. Il
arrête la partie et ouvre tout ce qu'elle cachait : l'échelle d'indices en entier, la vraie affiche,
le titre épelé, le bon ordre chronologique avec les années. Pour le duel, la paire **reste sur la
table** au lieu d'être balayée comme à toute autre fin, et porte enfin les deux notes — c'est la
seule réponse que ce jeu-là puisse donner. L'issue a son propre statut, distinct d'une défaite :
rien n'a été raté, la partie a été arrêtée, et le record de série du duel n'en est pas entamé. Le
mode quotidien n'a pas cette porte du tout — la restriction est portée par la route, pas par une
vérification — parce qu'il n'y a rien vers quoi enchaîner avant minuit.

Chaque jeu refuse de démarrer avec un message qui lui est propre quand la bibliothèque ne peut pas le
fournir — pas d'accroche connue, pas de titre d'au moins quatre lettres, pas deux films notés
différemment, pas cinq films de cinq années distinctes.

### 10. Profils partagés

- **Un lien de partage durable par compte**, régénérable à volonté.
- Ouvrir le lien ne révèle rien par soi-même : il permet à une personne **déjà connectée** de
  réclamer un accès. Un accès mémorise **qui** l'a reçu.
- Les deux moitiés sont séparées : révoquer l'accès d'une personne n'invalide pas le lien pour les
  autres, et faire tourner le lien ne révoque personne.
- **Sélecteur de profil** dans l'en-tête : soi d'abord, puis les profils partagés.
- La consultation d'un profil tiers est **strictement en lecture seule**. Le paramètre `profileId`
  est ajouté à toutes les requêtes de lecture par un intercepteur axios et résolu par un service
  unique côté serveur — un seul endroit à auditer pour la question « cette personne peut-elle voir
  ces données ? ». Les écritures (import, synchro) n'y passent jamais et visent toujours le compte
  authentifié, même face à un `profileId` forgé.
- Les entrées de menu qui écrivent — Import, Jeux — **disparaissent** pendant la consultation d'un
  profil tiers, plutôt que de faire semblant d'agir dessus.
- Une bannière signale en permanence le profil consulté et son caractère lecture seule.

### 11. Compte et authentification

- Inscription, connexion, déconnexion, changement de mot de passe.
- **Authentification par session** plutôt que par jeton : le SPA et l'API sont sur le même site dans
  tous les déploiements de cette application, et un cookie posé par le navigateur évite de stocker
  une donnée d'authentification là où une XSS pourrait la lire.
- Hachage `auto` (Argon2id quand disponible), abaissé en environnement de test pour la vitesse.
- Seules la connexion et l'inscription sont publiques. **Accepter un lien de partage ne l'est pas** :
  un accès mémorise qui l'a reçu, donc la personne doit être un compte connu.
- **Panneau de profil Letterboxd** : ce que `profile.csv` a dit du compte d'origine, avec ses quatre
  films favoris dans leurs emplacements numérotés. Conservé séparément du compte applicatif : c'est
  un instantané, remplacé en bloc au prochain import, et qui ne doit jamais approcher un identifiant.

### 12. Identité visuelle

Un thème « newsprint » — journal imprimé — appliqué de bout en bout.

- **Deux éditions** : *papier* (encre noire sur blanc cassé chaud) et *Midnight Edition* (noir
  profond, encre crème, rouge plus vif pour tenir sur fond sombre). L'édition sombre est celle par
  défaut.
- **Aucun arrondi**, sans exception — le style est fait de rectangles à 90°, et la règle est imposée
  globalement en CSS plutôt que confiée à chaque `className`, pour qu'aucun composant tiers ne passe
  au travers.
- **Quatre familles typographiques** : Playfair Display (titraille), Lora (corps), Inter (interface),
  JetBrains Mono (labels, chiffres, métadonnées).
- **Tokens sémantiques** (`--paper`, `--ink`, `--accent`, `--surface`, `--subtle`…) consommés
  partout ; aucune couleur brute dans un composant, ce qui garde les deux éditions synchronisées.
- Couleurs de **retour de jeu** (`--match-exact`, `--match-close`) délibérément séparées de l'accent :
  le rouge est une couleur d'interface et ne porte jamais de sens sur les données.
- Texture de trame fine en fond de page et sur les sections majeures.
- **Le logo Mova** en trois déclinaisons — mot-symbole avec filet rouge (en-tête), verrouillage
  complet avec accroche (page de connexion), monogramme M (signature de pied de page et favicon).
  L'artwork étant crème, chaque pièce existe en deux coloris et l'édition en cours choisit en CSS,
  jamais en JS : une permutation de `src` provoquerait un re-téléchargement et un clignotement.
- **Écriture inclusive** dans toute l'interface.

---

## Modèle de données

18 entités, identifiants **UUID v7** (ordonnables dans le temps).

**Bibliothèque** — `Movie` (films et séries dans une seule table, discriminées par `mediaType`),
`Genre`, `Country`, `Studio`, `Person`, `Credit` (la personne, l'œuvre, le rôle, l'ordre au
générique).

**Activité** — `Watch` (un visionnage : date, note, rewatch, critique, spoiler, source, référence
externe, étiquettes), `Tag`, `WatchlistEntry`.

**Import** — `ImportBatch`, `ImportRowError`.

**Letterboxd** — `LetterboxdProfile`, `FavouriteFilm` (quatre emplacements numérotés, contrainte
d'unicité sur le couple profil + position), `LetterboxdSyncState`.

**Comptes et partage** — `User`, `ProfileShareLink`, `ProfileAccess`.

**Jeux** — `GameSession` (jeu, mode, date de grille, propositions, lettres, plateau, manches, statut).

Douze énumérations PHP portent les règles métier au plus près des données : `EnrichmentStatus` sait
si un état mérite une nouvelle tentative, `ImportFileType` sait dans quel ordre les fichiers doivent
passer, `WatchSource` sait si un visionnage a été déclaré ou déduit, `GameKind` sait si un jeu se
joue en nommant un film, `MovieSortField` et `WatchlistSortField` savent dans quel sens un lectorat
s'attend à lire chaque tri.

---

## Surface d'API

Tout est sous `/api`, en JSON, et tout sauf la connexion et l'inscription exige une session.

| Groupe | Points d'entrée |
|---|---|
| **Auth** | `POST /auth/login`, `POST /auth/logout`, `POST /auth/register`, `GET /auth/me`, `PUT /auth/password` |
| **Bibliothèque** | `GET /movies`, `GET /movies/facets`, `GET /movies/posters`, `GET /movies/{id}` |
| **Watchlist** | `GET /watchlist`, `GET /watchlist/facets`, `GET /watchlist/pick` |
| **Statistiques** | `GET /stats/overview`, `/timeline`, `/ratings`, `/genres`, `/directors`, `/creators`, `/actors`, `/writers`, `/countries`, `/activity`, `/at-release` |
| **Import** | `POST /import/letterboxd`, `GET /import`, `GET /import/{id}` |
| **Synchro** | `GET /sync/letterboxd`, `POST /sync/letterboxd` |
| **Profils** | `GET /profiles`, `GET /profiles/letterboxd`, `GET`/`POST /profiles/share-link`, `POST /profiles/share-link/rotate`, `POST /profiles/share-link/{token}/accept`, `DELETE /profiles/{id}/access` |
| **Jeux** | `GET /games/{game}/{mode}`, `POST .../start`, `POST .../guess`, `POST .../reveal` (infini seulement), plus `POST .../letter` (pendu), `.../pick` (duel), `.../order` (chronologie) |

---

## Qualité

- **292 tests, 1 345 assertions**, répartis en trois couches : unitaires (logique pure —
  pixellisation, comparaison, pendu, normalisation de titres, mathématiques statistiques, traduction
  des pays et des genres TV), intégration (importeurs, orchestrateur, synchro RSS, statistiques de
  fenêtre de sortie) et fonctionnels (contrôleurs HTTP de bout en bout, avec transaction annulée
  après chaque test).
- Chaque test fonctionnel s'exécute dans une transaction rejouée en `rollBack`, et le transport
  Messenger bascule en mémoire sous `when@test`.
- `tsc --strict` et Oxlint sur le frontend ; `lint:container` sur le backend, qui valide chaque
  définition de service contre la vraie signature de son constructeur.
- Les commentaires du code expliquent **pourquoi**, pas quoi : plusieurs portent la trace d'un bug
  réel et de la raison pour laquelle la solution évidente ne marchait pas.
