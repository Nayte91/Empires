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
| **Pkg** | Passer le Shop en lib installable | **En cours.** Extraction faite, namespace détaché, vocabulaire purgé. Il reste le **bundle Symfony**, qui règle d'un coup les 3 derniers points de l'inventaire (DI, config embarquée, `supports:` fourni par l'hôte) et retire la béquille posée dans `config/services.yaml`. Inventaire en fin de fichier. | Moyenne | rien (décisions prises) |
| **Exp** | Mécaniques expansion (`feat/far-east`) | Four Arts = une ligne de yaml (débloqué par F2-①) · Primal Philosophy = première *rule* du moteur (gift conditionnel) · Mechanical Clock · famille `payment` dormante. **Le seul chantier qui produit du jeu jouable plutôt que de la dette résorbée.**                                                                    | Moyenne | rien (débloqué) |
| **i18n** | Étendre la traduction à l'UI      | F2-⑤ n'a traduit **que les exceptions** (10 clés, domaine `shop`). Tout le reste de l'interface est de l'anglais en dur dans les templates (`Submit my order`, `Clear cart`, `Free gift:`…). Décider si on veut une vraie UI traduisible, et si oui quelles locales — le catalogue actuel est `en` seul, délibérément. | Moyenne | ta décision |
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

**Les ports sont finis — il ne reste que l'emballage.**

> **Tranché : la lib est un _moteur de domaine_, pas un _shop encastrable_.** Elle ne livre ni entité, ni mapping, ni migration — la persistance est un port, l'hôte écrit son entité et génère son schéma (`doctrine:migrations:diff`). C'est ce qui a fait sauter l'ancien point 5 de cette liste (« la lib n'embarque aucune migration ») : livrer une migration serait une régression, pas un progrès. La FK `orders.player_id → player` reste chez l'hôte, où elle appartient. Ne pas ré-inscrire ce point.
>
> **Corollaire, tranché aussi : les Live Components ne descendent pas dans la lib.** Un moteur de domaine ne livre pas de couche de présentation — et tant que Symfony UX ne permet pas des LC headless, les y mettre réintroduirait Twig/UX dans une lib devenue host-free. Ils restent hôte, où ils tiennent lieu de brouillon d'un futur anneau de présentation, si celui-ci voit jamais le jour.

### Reste à faire — les 3 points que le bundle règle

1. **Zéro définition DI.** Les 47 classes du package ne sont des services que grâce à un **glob de secours** ajouté à `config/services.yaml` lors de l'extraction (`Userforged\ShopEngine\: resource: '../packages/…/src/'`). Il porte son propre commentaire de péremption : sans lui, les classes disparaissent du conteneur **en silence** — l'autoload continue de les charger, seul le conteneur les perd. C'est la béquille à retirer, pas à consolider.
2. **La config de la lib est encore atteinte par des shims hôte.** `config/packages/{workflow,messenger}.yaml` importent `../../packages/userforged/shop-engine/config/…`. Ça marche, mais ça veut dire que l'app doit connaître l'arborescence interne du package. Et les deux fichiers sont enracinés sur `framework:`, donc exigent FrameworkBundle.
3. **`config/workflow.yaml` nomme `App\Entity\Order` en dur** (`supports:`). Dernier endroit où la lib nomme l'hôte — dans sa *config*, plus dans son *code*. `getMarking()`/`setMarking()` étant sur `OrderInterface`, n'importe quelle implémentation satisfait le contrat : le correctif est une clé de configuration exposée par le bundle (`order_class`), pas une refonte.

S'y ajoutent `#[AsDbalType('order_lines')]`, `#[AsMessageHandler]` et l'autowiring de `WorkflowInterface $shopOrderStateMachine`, qui reposent tous sur l'autoconfiguration Symfony de l'hôte — le bundle les reprend à son compte.

Un **bundle Symfony** (`AbstractBundle`) est précisément ce qui permet à la lib d'embarquer sa propre config au lieu de la laisser fuiter dans `config/packages/` : il règle les trois points d'un seul coup.

### Soldé

- ~~**Pas de bloc `autoload`**~~ — le package a son PSR-4 (`Userforged\ShopEngine\` → `src/`), déclaré dans le `composer.json` racine via un path repository. `vendor/userforged/shop-engine` est un vrai symlink.
- ~~**La lib n'embarque aucune migration**~~ — **annulé, pas résolu.** C'est le design : moteur de domaine, la persistance est un port. Ne pas ré-inscrire.
- ~~**La lib parle le vocabulaire du jeu**~~ — `playerId` → `buyerId` sur les 9 classes de la façade d'écriture, `ShopExceptionReason::AdvanceAlreadyOwned` → `ProductAlreadyOwned`, `findAdvance()` → `findProduct()`. La clé de traduction reste `error.advance_already_owned` : la copie s'adresse à un joueur qui achète un advance, c'est du domaine de l'hôte. Garde-fou ajouté en tête de fichier.
- ~~**`CartRepository` câblé à la session HTTP**~~ — `CartStorageInterface` est le port, `App\Game\Shop\SessionCartStorage` l'adaptateur hôte, `symfony/http-foundation` a quitté le `require` de la lib.

### Après le bundle

Le package est monorepo, relié par path repository, et **rien n'est publié**. L'extraction vers un repo Git séparé (`git subtree split`) reste une étape ultérieure et optionnelle — l'historique la supporte déjà, git ayant détecté les 51 renommages du déménagement, donc `git log --follow` traverse.

Publier demanderait en plus : suite de tests exécutable **en isolation** (le vrai test de « est-ce une lib »), `LICENSE`, `CHANGELOG`, `.gitattributes`, et un job CI qui installe et teste le package seul. Rien de tout ça n'est nécessaire tant qu'Empires est le seul consommateur.

**Dette résiduelle mineure**, signalée non traitée : la lib ne nomme plus l'hôte ni en code ni en config (`grep -rn 'App\\Entity' packages/` → 0), mais **5 docblocks de `src/` citent encore des classes hôtes en exemple** — `BuyerInterface`, `BuyerProviderInterface`, `FacetProviderInterface`, `Promotion/OptionCredits`, `Exception/ShopExceptionReason`, tous en « see `App\Game\Shop\…` ». Plus `Service/OrderValidator.php:37`, commentaire historique citant `$player->advances`. Sans effet sur le comportement, mais ce sont des références pendantes pour tout consommateur qui n'est pas Empires : à nettoyer avant publication, pas avant. (Le README, lui, a le droit : c'est un document de conception qui assume de documenter le cas Empires.)


## après l'extraction

### CLAUDE.md

maintenant qu'on connait des projets identiques (medusaJS, les anciens packages Sylius autonomes), on peut vérifier ce qu'on fait de bien, de mieux ou de moins bien en se comparant à eux. ça + le canon du pattern (OMS, shop, etc..) va nous permettre de diagnostiquer et challenger notre lib avec précision. Tout cela doit etre consigné dans un CLAUDE.md dans le répertoire de la lib, quitte à ce qu'on scinde notre CLAUDE.md actuel.

### couleurs d'advance

Dans le jeu Mega Empires, le concept de couleurs des advances mix "informatiquement" en fait 2 concepts différents : les advances sont de certaines catégories (par exemple "craft", ou "science & religion", ...), et il y a des coupons de remise, qui sont de ces mêmes couleurs (par exemple 20 de remise en arts, 10 en civic, ...). Si on découple ces 2 concepts, on voit qu'on doit être capable de donner des coupons de remise à un joueur, et simplement de tracer leur origine pour éviter la triche. Es tu d'accord avec ce découpage informatique ? Est ce que notre librairie shop-engine a un équivalent de la gestion des "coupons remise" ou tout autre nom donné officiellement à cette mécanique (par le canon du pattern, par l'OMS, ...) ? 