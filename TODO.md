# TODO

> La lib est extraite : `packages/userforged/shop-engine/`, namespace `Userforged\ShopEngine\`, reliée par un path repository. Deux gardes-fous, à lancer ensemble avant toute modification du paquet — leur histoire et les 5 occurrences tolérées sont documentées dans son `CLAUDE.md` :
> ```
> L=packages/userforged/shop-engine
> grep -rn  'App\\'                            $L/src/ $L/config/   → 0
> grep -rniE 'player|advance|empire|\bgame\b'  $L/src/ $L/config/   → 5
> ```

---

## 🧭 Direction de fond

- [ ] **Convertir tous les enregistrements d'états en journaux d'actions.** Aux échecs on n'enregistre jamais la position des pièces, on enregistre les coups — la position s'en déduit. Un journal se charge en mémoire, se lit d'un fichier ou d'une base indifféremment, se rejoue et s'annule ; une position, non. C'est le préalable à tout moteur de jeu.
  Deux amorces existent : le `creditLedger` de `Player` est un journal partiel (boutique seule), et `CreateGame` comme les commandes de `shop-engine` sont déjà des actions nommées passées par un bus.

---

## 🐞 Bugs connus, à corriger

- [ ] **Shop — `EraseOrdersHandler` paie une requête pour un simple contrôle d'existence.** Il appelle `buyerFor()` uniquement pour reproduire le `\RuntimeException('Player not found.')`, plus lourd que le `find()` qu'il remplace. Accepté délibérément (erase est rare). La réponse honnête à long terme est peut-être que ce contrôle n'a jamais rien porté, et que le handler devrait être un simple no-op pour un buyer inconnu.

- [ ] **AST — plus de scroll horizontal sur mobile.** Depuis la suppression des wrappers (`<table class="ast">` racine du composant), la table rétrécit sous les 2,5 rem par case au lieu de scroller sur viewport étroit. Le mobile compte : trouver un compromis (conteneur de scroll réintroduit autrement, media query, autre approche) **sans** réintroduire les 2 div wrappers refusés.

- [ ] **Shop — le layout rend le bouton de commande inatteignable.** Mesuré sur `/{game}/player/{player}/shop`, viewport 1280×900 : la page fait **7647 px** et le bouton est à **7538 px**, après les 51 cartes produit. `position: sticky` sur `.shop__cart` (`assets/styles/shop.css`) est **inopérant** — l'élément collant fait lui-même 7214 px, plus haut que le viewport, donc rien ne se fige. Et `.shop__layout` déclare `grid-template-columns: 856px 320px` alors qu'il n'a **qu'un seul enfant** : la colonne de 320 px est morte, vestige d'un design à deux colonnes. Piste : deux vrais enfants de grille — catalogue d'un côté, sidebar panier réellement collante et scrollable indépendamment.

---

## 🚧 Chantiers

|    #     | Chantier                                        | Contenu                                                                                                                                                                                                                                                                                                                                                                                              |  Taille  |  Bloqué par  |
|:--------:|:------------------------------------------------|:-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|:--------:|:------------:|
| **Dedup** | Extraire le `add()` dupliqué                    | `Shop::add()` et `PlayerOrders::add()` sont deux copies manuelles du même enchaînement de gardes, divergeant sur un garde de verrouillage et une clé de panier. Les tests « symétriques » qui en découlent ne sont **pas** des doublons : ils couvrent les deux copies. Extraire d'abord, effondrer les ~4 paires de tests ensuite, jamais l'inverse. | Moyenne  | rien |
| **i18n** | Étendre la traduction à l'UI                    | Seules les exceptions sont traduites (10 clés, domaine `shop`). Tout le reste de l'interface est de l'anglais en dur dans les templates (`Submit my order`, `Clear cart`, `Free gift:`…). Décider si on veut une vraie UI traduisible, et si oui quelles locales — le catalogue actuel est `en` seul, délibérément.                                                    | Moyenne  | ta décision |
| **Hist** | Historique des crédits sur la console opérateur | Chaque `CreditEntry` porte son tour, sa portée, sa valeur signée, sa **source** (`shop`, `scenario`, `special ability`) et sa raison. La vue peut donc distinguer « acheté » de « mise en place ». **C'est cet usage qui justifie le JSON plutôt qu'une entité.** ⚠️ Elle ne montrera **rien des commandes annulées** — leurs écritures sont retirées, pas compensées : ne pas la présenter comme un journal d'audit. | Petite   | rien |
| **Tech** | Micro-reliquats                                 | `REFACTOR-WHEN` : split du moteur de promotions en classes d'action — **toujours sous son seuil** (3 familles, se déclenche à la 4ᵉ). · `src/Component/` est à **15 fichiers**, borne basse du seuil de mirroring atomic design : à surveiller, pas encore à agir.                                                                                    | Micro    | rien |

---

## ❓ Questions jamais tranchées

- [ ] **Le panier doit-il signaler une remise non utilisée ?** Son *comportement* est livré (une ligne cadeau porte son propre picker d'allocation), mais la question d'**affichage** ne l'a jamais été. Si le joueur met Anatomy au panier sans choisir de cadeau, le panier se tait — il pourrait perdre son bonus en silence. Ta formulation : est-ce qu'un shop « gentil » dit au client comment économiser, ou est-ce qu'un shop « dark pattern » le lui cache pour maximiser la marge ? C'est une option du pattern, à définir côté lib.

- [ ] **`OrderSold` n'a aucun consommateur.** Délibéré : `ShopMercurePublisher` l'ignore parce qu'une vente directe passe par `OrderValidator`, qui publie déjà `OrderValidated`. L'event reste comme signal métier « une vente POS a eu lieu », distinct de « une commande a été validée ». À garder ou à supprimer — mais à décider, sinon quelqu'un le supprimera comme code mort.

---

## 🔍 Observations relevées en session (aucune urgence)

- **`PromotionEngine::applyGifts()` avale en silence un produit source disparu du catalogue.** `findProduct()` renvoie `null` → `continue`, sans exception ni journal : la ligne cadeau s'évapore au re-cotage et personne n'est prévenu. Pas urgent — aucun scénario de production ne retire un produit du catalogue en cours de partie — mais c'est une défaillance muette, à connaître avant d'ouvrir le catalogue à de la configuration dynamique.
- **`Dto\Product::$owned` est mort sur son chemin d'appel.** `ProductCatalog::productsFor()` le câble en dur à `false` et il ne peut jamais valoir autre chose, les produits possédés étant filtrés une ligne plus haut.
- **`enabled_locales` n'est pas configuré** dans `config/packages/translation.yaml` : `bin/console lint:translations` sans argument répond « No translation files » et exige `--locale=en`.
- **`Order::$createdAt` n'est lu nulle part** — ni dans `src/`, ni dans `tests/`, ni dans `templates/`. Écrit seulement.
- **`initial_marking: pending`** dans le workflow est inerte : `Order` pré-initialise déjà son statut.
- **`setMarking()` accepte un `$context` qu'il jette.**
- **Les tests de `tests/Integration/ShopFlow/` construisent les handlers à la main** dans `setUp()`. Les commentaires qui justifiaient ça (« le conteneur les inline, on ne peut pas les récupérer ») étaient **faux** : les services privés sont accessibles via `test.private_services_locator`. Pure convention héritée, qui mériterait d'être revue.
- **Un fichier de tests est à cheval sur deux étages.** `tests/Component/PlayerBoardTest` mêle trois tests qui pilotent du HTTP réel et quatre qui rendent le composant. La réponse honnête est de le scinder — ce qui crée un fichier, donc ce n'était pas du ressort d'un lot de déménagement.

---

## 📦 Avant de publier la lib

Rien de tout ceci n'est nécessaire tant qu'Empires est le seul consommateur.

- `LICENSE`, `CHANGELOG`, un `.gitattributes` excluant `config/tools/` de la distribution (mais **gardant** `config/{services,workflow,messenger}.yaml`, chargés à l'exécution), et un job CI faisant un `composer install` **dans** le package puis lançant sa suite.
- **Dette résiduelle** : la lib ne nomme plus l'hôte ni en code ni en config, mais **5 docblocks de `src/` citent encore des classes hôtes en exemple** (`BuyerInterface`, `BuyerProviderInterface`, `FacetProviderInterface`, `Promotion/OptionCredits`, `Exception/ShopExceptionReason`), plus `Service/OrderValidator.php:37` qui cite `$player->advances`. Références pendantes pour tout consommateur qui n'est pas Empires. Le README a le droit : c'est un document de conception qui assume de documenter le cas Empires.
- L'extraction vers un repo Git séparé (`git subtree split`) reste optionnelle — l'historique la supporte déjà, git ayant détecté les renommages du déménagement.

---

## 🚫 Décisions tranchées — ne pas rouvrir

- **La lib est un _moteur de domaine_, pas un _shop encastrable_.** Elle ne livre ni entité, ni mapping, ni migration — la persistance est un port, l'hôte écrit son entité et génère son schéma. Livrer une migration serait une régression, pas un progrès. La FK `orders.player_id → player` reste chez l'hôte.
- **Les Live Components ne descendent pas dans la lib.** Un moteur de domaine ne livre pas de couche de présentation, et tant que Symfony UX ne permet pas des LC headless, les y mettre réintroduirait Twig/UX dans une lib devenue host-free.
- **Les mécaniques d'expansion** (Four Arts, Primal Philosophy, Mechanical Clock, famille `payment`) ne concernent que **Far East, prévue pour 2027** : elles vivent dans le `TODO.md` de `feat/far-east`. Ne pas les ré-inscrire ici.
