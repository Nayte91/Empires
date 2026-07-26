# TODO

> **État : la lib est extraite.** `src/Shop/` n'existe plus — le code vit dans `packages/userforged/shop-engine/`, sous le namespace `Userforged\ShopEngine\`, relié à l'application par un path repository que Composer symlinke. Deux gardes-fous, à lancer ensemble :
> ```
> grep -rn '^use App\\' packages/userforged/shop-engine/src/                  → 0
> grep -rniE 'player|advance|empire' packages/userforged/shop-engine/src/     → 5
> ```
> **Le second existe parce que le premier ne suffisait pas** : il ne teste que les *imports*, et a longtemps certifié « host-free » un code qui portait ~117 mots du jeu — dont `playerId` sur les 9 classes de l'API publique, pendant qu'`OrderInterface` disait `buyerId` juste à côté. Les 5 occurrences restantes sont délibérées : des énumérations qui illustrent l'agnosticisme (« a license, a game advance, stock ») et deux commentaires nommant des classes hôtes réelles.
>
> Spécification de la lib : `packages/userforged/shop-engine/README.md`. Ce qui suit est ce qui reste.

---

## 🐞 Bugs connus, à corriger

- [ ] **Shop — deux handlers paient une requête pour un simple contrôle d'existence.** `RejectOrderHandler` et `EraseOrdersHandler` appellent `buyerFor()` uniquement pour reproduire le `\RuntimeException('Player not found.')`, plus lourd que le `find()` qu'il remplace. Accepté délibérément (reject est inatteignable en production, erase est rare). La réponse honnête à long terme est peut-être que ce contrôle n'a jamais rien porté, et que les deux handlers devraient être de simples no-op pour un buyer inconnu — ce qui permettrait de supprimer le test « unknown player » de `RejectOrderTest`.

- [ ] **AST — plus de scroll horizontal sur mobile.** Depuis la suppression des wrappers (`<table class="ast">` racine du composant), la table rétrécit sous les 2,5 rem par case au lieu de scroller sur viewport étroit. Le mobile compte : trouver un compromis (conteneur de scroll réintroduit autrement, media query, autre approche) **sans** réintroduire les 2 div wrappers refusés.

- [ ] **Shop — le layout rend le bouton de commande inatteignable.** Mesuré sur `/{game}/player/{player}/shop`, viewport 1280×900 : la page fait **7647 px** et le bouton est à **7538 px**, après les 51 cartes produit. `position: sticky` sur `.shop__cart` (`assets/styles/shop.css`) est **inopérant** — l'élément collant fait lui-même 7214 px, plus haut que le viewport, donc rien ne se fige. Et `.shop__layout` déclare `grid-template-columns: 856px 320px` alors qu'il n'a **qu'un seul enfant** : la colonne de 320 px est morte, vestige d'un design à deux colonnes. Piste : deux vrais enfants de grille — catalogue d'un côté, sidebar panier réellement collante et scrollable indépendamment. Indépendant du placement du bouton (déjà traité) : même porté par `Cart`, il reste après `ProductGrid` dans la même `aside`.

---

## 🚧 Chantiers

|   #    | Chantier                          | Contenu                                                                                                                                                                                                                                                                                                                          | Taille  | Bloqué par |
|:------:|:----------------------------------|:---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|:-------:|:----------:|
| ~~**Pkg**~~ | ~~Passer le Shop en lib installable~~ | **Terminé.** Les 7 blocages de l'inventaire sont à zéro : extraction, namespace, vocabulaire, bundle, outillage et tests propres, manifeste publiable. Ne reste que ce qui n'a d'utilité qu'avec un second consommateur (licence, changelog, `.gitattributes`, CI) — détail en fin de fichier. | — | terminé |
| **i18n** | Étendre la traduction à l'UI      | F2-⑤ n'a traduit **que les exceptions** (10 clés, domaine `shop`). Tout le reste de l'interface est de l'anglais en dur dans les templates (`Submit my order`, `Clear cart`, `Free gift:`…). Décider si on veut une vraie UI traduisible, et si oui quelles locales — le catalogue actuel est `en` seul, délibérément. | Moyenne | ta décision |
| **Hist** | Historique des crédits sur la console opérateur | Chaque `CreditEntry` porte son tour, sa portée, sa valeur signée, sa **source** (`shop`, `scenario`, `special ability`) et sa raison. La vue peut donc distinguer « acheté » de « mise en place ». **C'est cet usage qui justifie le JSON plutôt qu'une entité** : un historique lisible suffit, personne n'auditera ça en SQL. ⚠️ Elle ne montrera **rien des commandes annulées** — leurs écritures sont retirées, pas compensées : ne pas la présenter comme un journal d'audit. | Petite | rien |
| **Tech** | Micro-reliquats                   | `REFACTOR-WHEN` : split du moteur de promotions en classes d'action — **toujours sous son seuil** (3 familles, se déclenche à la 4ᵉ). · `src/Component/` est à **15 fichiers**, borne basse du seuil de mirroring atomic design (`templates/{atoms,molecules,organisms}/`) : à surveiller, pas encore à agir.        | Micro   | rien |

---

## ❓ Questions jamais tranchées

- [ ] **Le panier doit-il signaler une remise non utilisée ?** Reste de l'arbitrage 2 : son *comportement* est livré (une ligne cadeau porte désormais son propre picker d'allocation), mais la question d'**affichage** ne l'a jamais été. Si le joueur met Anatomy au panier sans choisir de cadeau, le panier se tait — il pourrait perdre son bonus en silence. Ta formulation : est-ce qu'un shop « gentil » dit au client comment économiser, ou est-ce qu'un shop « dark pattern » le lui cache pour maximiser la marge ? C'est une option du pattern, à définir côté lib.

- [ ] **`OrderSold` n'a aucun consommateur.** Délibéré : `ShopMercurePublisher` l'ignore parce qu'une vente directe passe par `OrderValidator`, qui publie déjà `OrderValidated`. L'event reste comme signal métier « une vente POS a eu lieu », distinct de « une commande a été validée ». À garder ou à supprimer — mais à décider, sinon quelqu'un le supprimera comme code mort.

---

## 🔍 Observations relevées en session (aucune urgence)

- **`PromotionEngine::applyGifts()` avale en silence un produit source disparu du catalogue.** `findProduct()` renvoie `null` → `continue`, sans exception ni journal : la ligne cadeau s'évapore au re-cotage et personne n'est prévenu. Découvert en écrivant le test de régression du bug `grant()`/`revoke()` (soldé en Phase 0), qui exploite précisément ce chemin. Pas urgent — aucun scénario de production ne retire un produit du catalogue en cours de partie — mais c'est une défaillance muette, à connaître avant d'ouvrir le catalogue à de la configuration dynamique.
- **`RejectOrderHandler` est inatteignable en production.** `App\Game\Shop\OrderWorkflowPolicy` bloque le guard `reject` inconditionnellement — `can()` renvoie toujours faux et le handler lève avant d'agir. Ses tests ne passent que parce que `tests/Support/Workflow/ShopOrderStateMachine::create()` construit une state machine **sans guard**. Voulu (Mega Civilization n'a pas de commande refusée), mais bon à savoir avant de déboguer ce chemin.
- **`tests/Support/Workflow/ShopOrderStateMachine` duplique la table de transitions** de `packages/userforged/shop-engine/config/workflow.yaml`. Depuis que `OrderInterface` fige les accesseurs `getMarking()`/`setMarking()`, cette duplication est devenue *contractuelle* et non plus incidente : les deux doivent rester synchronisés à la main.
- **`enabled_locales` n'est pas configuré** dans `config/packages/translation.yaml` : `bin/console lint:translations` sans argument répond « No translation files » et exige `--locale=en`.
- **`Order::$createdAt` n'est lu nulle part** — ni dans `src/`, ni dans `tests/`, ni dans `templates/`. Écrit seulement.
- **`initial_marking: pending`** dans le workflow est inerte : `Order` pré-initialise déjà son statut.
- **`setMarking()` accepte un `$context` qu'il jette.**
- **Les 4 tests de `tests/Shop/` construisent les handlers à la main** dans `setUp()`. Les commentaires qui justifiaient ça (« le conteneur les inline, on ne peut pas les récupérer ») étaient **faux** et ont été corrigés : les services privés sont accessibles via `test.private_services_locator`. La vraie raison ne subsiste que pour `RejectOrderTest` (state machine sans guard) ; ailleurs c'est de la convention héritée, qui mériterait d'être revue.

---

## 📦 Passer le Shop en lib indépendant

**Terminé.** Cette section est conservée comme trace des décisions, pas comme liste de travail.

> **Tranché : la lib est un _moteur de domaine_, pas un _shop encastrable_.** Elle ne livre ni entité, ni mapping, ni migration — la persistance est un port, l'hôte écrit son entité et génère son schéma (`doctrine:migrations:diff`). C'est ce qui a fait sauter l'ancien point 5 de cette liste (« la lib n'embarque aucune migration ») : livrer une migration serait une régression, pas un progrès. La FK `orders.player_id → player` reste chez l'hôte, où elle appartient. Ne pas ré-inscrire ce point.
>
> **Corollaire, tranché aussi : les Live Components ne descendent pas dans la lib.** Un moteur de domaine ne livre pas de couche de présentation — et tant que Symfony UX ne permet pas des LC headless, les y mettre réintroduirait Twig/UX dans une lib devenue host-free. Ils restent hôte, où ils tiennent lieu de brouillon d'un futur anneau de présentation, si celui-ci voit jamais le jour.

### Soldé — les 7 blocages sont à zéro

- ~~**Zéro définition DI**~~ · ~~**config atteinte par des shims hôte**~~ · ~~**`supports: [App\Entity\Order]` en dur**~~ — les trois réglés d'un coup par `UserforgedShopEngineBundle` (`AbstractBundle`). Il enregistre ses propres services, embarque sa state machine et son bus d'events, et expose une clé `order_class` que l'hôte renseigne. L'injection dans `framework.workflows` a lieu en `prependExtension()` : c'est la seule phase où contribuer à `framework.*` fonctionne encore, `FrameworkExtension::load()` ayant déjà consommé sa configuration ensuite — y contribuer plus tard serait **silencieusement ignoré**. Corollaire : à ce stade la config n'est pas encore validée, donc `isRequired()` ne protège rien et `order_class` est vérifié à la main.
  **Effet de bord à connaître** : séparer les services de la lib dans un chargeur distinct casse l'aliasing automatique des interfaces à implémentation unique — il n'opère qu'à l'intérieur d'un même scan. Les 6 liaisons port→adaptateur sont donc déclarées explicitement dans le `config/services.yaml` de l'app. C'est plus lisible que ce que ça remplace : la liste des adaptateurs que cette application fournit.
- ~~**Manifeste impubliable**~~ — `_comment_require` n'était pas au schéma Composer (`composer validate --strict` en erreur de publication). Supprimé : sa justification vit dans le §6 du README du package, qui la dit mieux — et le commentaire la **contredisait** depuis, en qualifiant `doctrine/*` de transitionnel alors que c'est une dépendance permanente du tier adaptateur.

- ~~**Pas de bloc `autoload`**~~ — le package a son PSR-4 (`Userforged\ShopEngine\` → `src/`), déclaré dans le `composer.json` racine via un path repository. `vendor/userforged/shop-engine` est un vrai symlink.
- ~~**La lib n'embarque aucune migration**~~ — **annulé, pas résolu.** C'est le design : moteur de domaine, la persistance est un port. Ne pas ré-inscrire.
- ~~**La lib parle le vocabulaire du jeu**~~ — `playerId` → `buyerId` sur les 9 classes de la façade d'écriture, `ShopExceptionReason::AdvanceAlreadyOwned` → `ProductAlreadyOwned`, `findAdvance()` → `findProduct()`. La clé de traduction reste `error.advance_already_owned` : la copie s'adresse à un joueur qui achète un advance, c'est du domaine de l'hôte. Garde-fou ajouté en tête de fichier.
- ~~**`CartRepository` câblé à la session HTTP**~~ — `CartStorageInterface` est le port, `App\Game\Shop\SessionCartStorage` l'adaptateur hôte, `symfony/http-foundation` a quitté le `require` de la lib.
- ~~**Le package ne savait pas se vérifier lui-même**~~ — il possède ses outils (`config/tools/` : phpunit, phpstan, rector, php-cs-fixer, aux mêmes niveaux et règles que l'app) et ses tests purs (`tests/`, 60 tests). L'outillage de l'hôte ne pointe plus dans le package ; `make quality` enchaîne les deux pipelines et échoue si l'un échoue. Répartition rector/phpcs/phpstan sur `tests/` reconduite à l'identique des deux côtés.

### Répartition des tests, et pourquoi

| Emplacement | Contenu | Base |
|:--|:--|:--|
| `packages/…/tests/` | **50** tests du moteur — panier, cotation, promotions | `TestCase` pur, aucun kernel |
| `tests/Shop/` | **34** tests du **câblage** — flux de commande, vente directe, effacement, rejet | `WebTestCase`, bootent l'app |
| `tests/Game/Shop/` | **22** tests des adaptateurs — stockage panier, résolveur de prix, journal de crédits | hôte, pas lib |

Le critère n'est pas le sujet mais la dépendance : **un test du moteur qui a besoin d'un kernel ne teste pas le moteur, il teste l'intégration** — donc il appartient à l'hôte. Total actuel : **318 côté app + 50 côté package = 368**.

### Après le bundle

Le package est monorepo, relié par path repository, et **rien n'est publié**. L'extraction vers un repo Git séparé (`git subtree split`) reste une étape ultérieure et optionnelle — l'historique la supporte déjà, git ayant détecté les 51 renommages du déménagement, donc `git log --follow` traverse.

Publier demanderait encore : `LICENSE`, `CHANGELOG`, un `.gitattributes` excluant `config/tools/` de la distribution (mais gardant `config/{services,workflow,messenger}.yaml`, chargés à l'exécution), et un job CI faisant un `composer install` **dans** le package puis lançant sa suite. Les dépendances de dev du package sont déjà déclarées ; son `config/tools/bootstrap.php` cherche déjà son `vendor/` propre avant de se replier sur celui de la racine. L'installation isolée est donc à une commande près — on ne l'a pas faite pour ne pas dupliquer un `vendor/` sans travail réel à lui donner. Rien de tout ça n'est nécessaire tant qu'Empires est le seul consommateur.

**Dette résiduelle mineure**, signalée non traitée : la lib ne nomme plus l'hôte ni en code ni en config (`grep -rn 'App\\Entity' packages/` → 0), mais **5 docblocks de `src/` citent encore des classes hôtes en exemple** — `BuyerInterface`, `BuyerProviderInterface`, `FacetProviderInterface`, `Promotion/OptionCredits`, `Exception/ShopExceptionReason`, tous en « see `App\Game\Shop\…` ». Plus `Service/OrderValidator.php:37`, commentaire historique citant `$player->advances`. Sans effet sur le comportement, mais ce sont des références pendantes pour tout consommateur qui n'est pas Empires : à nettoyer avant publication, pas avant. (Le README, lui, a le droit : c'est un document de conception qui assume de documenter le cas Empires.)


## après l'extraction

> **Hors périmètre de cette branche.** Les mécaniques d'expansion (Four Arts, Primal Philosophy, Mechanical Clock, famille `payment`) ne concernent que **Far East, prévue pour 2027** : elles sont déchargées vers le `TODO.md` de `feat/far-east`. Ne pas les ré-inscrire ici.
>
> ~~**CLAUDE.md de la lib**~~ — livré : `packages/userforged/shop-engine/CLAUDE.md` (contrat de travail, agent-centric) et son `README.md` §11 (positionnement, état de l'art, stratégies de persistance) se partagent le sujet selon un critère explicite — ce qu'un agent ne peut pas deviner d'un côté, ce qui convainc un humain de l'autre.

### ~~couleurs d'advance~~ — livré

La question : la « couleur » d'une advance fusionnait-elle deux concepts, sa **catégorie** et la **dénomination des crédits** qu'elle octroie ? Oui, et la donnée l'a tranché sans appel — **49 advances sur 51 accordent des crédits hors de leurs propres catégories**. Le modèle mental « les cartes bleues donnent du crédit bleu » était faux 96 % du temps.

Ce qui en est sorti :

- **Le prix est un port** (`PriceResolverInterface`). La règle « meilleure facette + crédits nommés » est une règle de Mega Civilization, pas du pattern shop : elle vit chez l'hôte, dans `AdvancePriceResolver`.
- **Les crédits sont un journal** de `CreditEntry` sur `Player` — tour, portée, valeur signée, source, raison. Une portée est une couleur **ou** une clé de carte, opaque au calcul : c'est ce qui fait que les crédits nommés traversent toute la chaîne sans traitement particulier.
- **Ajout pour un fait de jeu, retrait pour une annulation.** Un forfait est toujours un ajout en négatif, jamais un retrait.
- **Le solde se déroule chronologiquement**, plafonné à chaque pas — ni somme brute, ni somme suivie d'un plancher, deux variantes fausses gardées par des tests.
- Les credits de départ de `scenarios.yaml` **s'appliquent enfin**, après avoir été lus et testés sans jamais être branchés.

Sur le nommage canonique : aucun terme unique n'existe. Le commerce fait de la **sélection de prix** par segment (commercetools, Medusa) — énumérable, là où le nôtre est cumulatif. Le cousin le plus proche est la **remise arrière B2B** (SAP, Oracle) : même cycle de vie, mais elle se solde. D'où la définition retenue — **un entitlement est un rebate qui ne se solde jamais**. Détail en §11 du README du package.