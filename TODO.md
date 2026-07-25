# TODO

- [ ] **AST — scroll horizontal mobile** : depuis la suppression des wrappers (`<table class="ast">` racine du composant), plus de conteneur de scroll horizontal sur viewport étroit — la table rétrécit sous les 2,5rem par case au lieu de scroller. Le mobile compte : trouver un compromis (conteneur de scroll réintroduit autrement, media query, ou autre approche) sans réintroduire les 2 div wrappers refusés.
- [ ] **Component/ — seuil de mirroring atomic design** : si `src/Component/` dépasse 15-20 fichiers, envisager un sous-découpage miroir de l'atomic design des templates (`templates/{atoms,molecules,organisms}/`) plutôt qu'un dossier plat. Actuellement 15 fichiers, seuil bas atteint.
- [ ] **Shop — layout : sticky inerte et colonne de grille morte** (mesuré sur `/{game}/player/{player}/shop`, viewport 1280×900) : la page fait 7647px et le bouton de commande est à 7538px, après les 51 cartes produit. `position: sticky` sur `.shop__cart` (`assets/styles/shop.css`) est **inopérant** car l'élément collant fait lui-même 7214px — plus haut que le viewport, donc rien ne se fige. `.shop__layout` déclare `grid-template-columns: 856px 320px` mais n'a **qu'un seul enfant** : la colonne de 320px est morte, vestige d'un design à deux colonnes. Piste : scinder en deux vrais enfants de grille — catalogue d'un côté, sidebar panier réellement collante et scrollable indépendamment de l'autre. Indépendant du placement du bouton (déjà traité) : même dans `Cart`, le bouton reste après `ProductGrid` dans la même `aside`.

## Doctrine de délégation à deux flux (actée avec le PO)

Deux délégations **Sonnet** séquentielles, à contextes ISOLÉS — l'agent principal est la seule passerelle :

**Délégation 1 — agent LIB (`src/Shop/` & tests).** Prompt rédigé en **langage pattern exclusivement** : state machine canon (places/transitions), ...

**Délégation 2 — agent CONNECTEUR (`src/Game/Shop/` & tests fonctionnels).** Prompt rédigé en **langage métier** : ...

## A modifier au retour de Fable

1. ~~Les lignes d'émission d'events dans les commandhandlers doivent être déplacées.
Trouver un moyen de se plug à la fin de cette commande.
Doit elle émettre un event messenger ?~~ → Tranché, documenté dans `src/Shop/README.md`.

2. src/Component/Shop.php fait 400 lignes, on doit pouvoir réduire. 
Scinder ? Avoir un LC cart, un LC catalog ?

3. Déplacer les LC dans le rep Shop/ ?

## Question en cours

### La question

Arbitrage 1 — La preview pendant une allocation incomplète
Scénario. Panchou met au panier : Library (220), Mathematics (250), Monument (180). Il n'a pas encore réparti les 20 points de Monument. Pendant ce temps, son panier affiche… les prix bruts : pas de « −40 library » sur Mathematics, total 650. Dès qu'il finit d'allouer les 20 points, la remise apparaît et le total corrige à 610.

Pourquoi ça se produit. Le moteur de promotions est autoritaire : face à une allocation incomplète, il refuse de coter (c'est la garde qui bloque la soumission — voulue, c'est ta décision « option obligatoire »). Pour que l'affichage ne plante pas pendant que le joueur clique ses ±5, l'implémenteur a ajouté un repli : en cas d'allocation incomplète, on affiche le panier sans passer par le moteur du tout. Conséquence collatérale : toutes les promotions disparaissent de l'affichage, pas seulement celle en cours de réglage.

Le point qui rend l'arbitrage simple, à mon sens : l'allocation de Monument n'a aucun effet sur les prix du panier en cours (elle nourrit le pricing futur, via les commandes validées). La remise Library est donc calculable et exacte, que l'allocation soit finie ou non. Le repli actuel affiche un total (650) que le joueur ne paiera jamais — pour une raison purement technique, pas métier.

Options : (a) statu quo — le bouton submit est de toute façon désactivé, l'affichage faux est transitoire ; coût zéro. (b) preview clémente — le moteur applique tout ce qu'il peut (remise, gift) et saute seulement la validation d'option à l'affichage, la garde stricte restant à la soumission ; ~30 lignes, et conceptuellement c'est la distinction que notre propre spec nomme déjà (« evaluated at cart display, re-evaluated authoritatively at confirmation »).

Arbitrage 2 — Le cadeau qui porte une option
Scénario. Panchou achète Anatomy (270). Le cadeau d'Anatomy : un advance science à coût de base < 100, offert. Parmi les candidats : Written Record (60, science) — choix légitime et attractif. Or Written Record porte elle-même une option : « 10 points de crédits à répartir librement ». Problème : une ligne giftée n'a pas d'intent propre (elle naît du choix posé sur la ligne Anatomy) → le picker d'allocation ne s'affiche jamais pour elle → à la validation, Panchou possède Written Record mais ses 10 points d'option sont perdus en silence. La carte dit pourtant « Upon acquisition » — peu importe comment on l'acquiert, l'option est due.

Ampleur réelle : aujourd'hui, exactement un cas concret existe (Written Record via Anatomy — seule carte à option sous le seuil de 100). Après la fusion de l'expansion, le yaml décidera s'il y en a d'autres.

Options : (a) exclure les cartes à option des candidats gift — une ligne de filtre dans giftCandidates, le trou est bouché, mais on s'écarte des règles (rien n'interdit ce choix) et on prive le joueur d'un bon cadeau. (b) fidélité complète — la ligne giftée gagne son picker d'allocation ; correct, mais ça enrichit la forme des intents (gift devient {key, allocation}), coût moyen. (c) accepter la perte — coût nul, mais violation silencieuse du texte de la carte, et un joueur attentif le verra.

Mon regard d'architecte sur la (b) : F2-① (elective benefits config-driven) va précisément retravailler les formes d'intent et de config d'option — c'est le moment naturel pour la fidélité complète, plutôt qu'un chantier isolé maintenant.

### Ma réponse

On ne va parler que de l'arbitrage 2, car c'est un bon cas d'école. A toi de découper ce que je vais dire en 2 parties, ce qui est de la responsabilité de la lib Shop, et ce qui est de la responsabilité de notre moteur de jeu. Je reprend coté "ce que je veux en UX" :
- Si le joueur a choisi d'abord une advance science qui coute < 100, et qu'il choisi ensuite anatomy, alors au moment où Anatomy arrive dans le panier, le calculateur de prix détecte l'advance science < 100, applique la remise dessus, et update le prix en conséquence --> coté UX, c'est parfait. Et dans notre scénario de written record, l'option à choisir avec a déjà été choisie avant (au moment où on a mis written record au panier, voir il y a le sélecteur d'option en dessous), donc parfait.
- Si le joueur choisi d'abord Anatomy, on a 2 choix : soit le panier ne dit rien à l'utilisateur, et celui ci pourrait silencieusement "perdre son bonus", soit le panier a une ligne "cadeau panier non fullfilled". Néanmoins, cela n'est qu'une question visuelle, pas comportementale. Le joueur peut très bien acheter Anatomy tout seul, ou choisir une autre carte. Ce n'est pas à moi de faire le choix entre ces 2 cas, mais une question de cannon du pattern; est ce qu'un Shop, avec des remises panier, affiche dans le panier les remises utilisées/non utilisées ? Je suppose que c'est paramétrable (true/false), si notre shop est gentil, il dira à l'utilisateur qu'il peut économiser en faisant X. Si notre shop est un peu "dark pattern", il va cacher à l'utilisateur la meilleure manière d'économiser, afin de maximiser le profit de son magasin. Bref, c'est à définir coté pattern. Ensuite, le joeuur ajoute written record dans son panier; très classiquement, il lui est permis de sélectionner son option sur cette advance, exactement comme si Anatomy n'était pas dans le panier. Lors du calcul de la valeur panier, notre calculateur voit que anatomy est associée à une advance science qui fait < 100, et donc le prix est adapté.

Comprends tu les scénarios ? Vois-tu en quoi c'est une question de connecteur métier d'un coté, et de conformité au pattern de l'autre ?

## Sujets restants

|    #    | Chantier                                    |                                                                                                                                                                               Contenu                                                                                                                                                                                | Taille  |          Bloqué par           |
|:-------:|:--------------------------------------------|:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------:|:-------:|:-----------------------------:|
|   F2    | Ports & extraction lib (doctrine deux-flux) | ① ~~ElectiveBenefit config-driven — buckets via connecteur, Game\Category sort de Shop/, option: {budget, step}~~ → livré, sweep vocabulaire « facette » inclus · ② ~~ProductInterface/BuyerInterface/ProductProviderInterface~~ → livré, périmètre honnête : le côté **lecture** de `src/Shop/` (`Dto/`, `Service/`, `Promotion/`) est host-free — `App\Game\Dto\Advance implements ProductInterface` (zéro accesseur), `App\Game\Shop\PlayerBuyer implements BuyerInterface` (construit par `ShopConnector::buyerFor()`), `App\Game\Shop\AdvanceProductProvider implements ProductProviderInterface` (enveloppe `AdvanceCatalog`) ; le côté **écriture** (`CommandHandler/`, `OrderValidator`) reste couplé à l'hôte — cycle Game↔Shop **non rompu**, voir F2-⑥ · ③ port Fulfillment (ownAdvances) · ④ ~~port événements~~ → livré : Mercure sort de `src/Shop/` (plus aucun import Mercure dans la lib) ; la lib publie 5 events scalaires sur `shop.event.bus` (`OrderSubmitted`, `OrderValidated`, `OrderRejected`, `OrderSold`, `OrdersErased` — le 5ᵉ, nouveau, couvre `OrderValidator::validate()` appelé hors commande) ; `App\Game\Shop\ShopMercurePublisher` est l'unique publisher hôte des updates Mercure d'origine shop, il traduit vers `order-updated`/`player-updated`. Le cycle Game↔Shop en écriture n'est **pas rompu** pour autant — ça reste F2-⑥ · ⑤ traduction d'exceptions type→copie + i18n · ⑥ port persistance & rupture du cycle côté écriture — `EntityManagerInterface` (5 fichiers : `OrderValidator` + les 4 `CommandHandler`) ; `App\Repository\OrderRepository` (5 fichiers) ; `App\Repository\PlayerRepository` (4 fichiers, dépendance inverse qu'un `BuyerInterface` seul ne supprime pas → nécessite un `BuyerProviderInterface`) ; `App\Entity\Order` (5 fichiers) + `new Order(...)` dans `SubmitOrderHandler`/`SellDirectHandler` → `OrderInterface` ; `src/Shop/config/workflow.yaml:6` `supports: [App\Entity\Order]` ; les 3 `use App\Game\Shop\ShopConnector;` résiduels (`OrderValidator`, `SubmitOrderHandler`, `SellDirectHandler`) = le cycle Game↔Shop réel — c'est ce chantier, et lui seul, qui permettra d'écrire « cycle Game↔Shop rompu » | Grande  | Ta décision de lancer la lib  |
|  Exp.   | Mécaniques expansion (feat/far-east)        |                                                                                                    Four Arts = une ligne de yaml après F2-① · Primal Philosophy = première rule du moteur (gift conditionnel) · TODO Mechanical Clock · famille payment dormante                                                                                                     | Moyenne |             F2-①              |
|  Tech.  | Micro-reliquats                             |                                                                                                                                                                       REFACTOR-WHEN split moteur en classes d'action (toujours sous son seuil : 3 familles de promotion, se déclenche à la 4ᵉ)                                                                                                                                                                       |  Micro  |             rien              |

## Analyse des chantiers

Pour analyser la faisabilité, on veut : état actuel, en quoi ça pose problème avec le canon "shop", ce que tu proposes pour régler cela, ce qu'on y gagnerait/perdrait niveau élégance/LOC/clarté du code (attention on ne note pas un "adaptateur" comme mauvais si lui même est élégant clair et bien scopé),

## Passer notre Shop en lib indépendant 

C'est la pratique standard, documentée par Composer lui-même (pas une convention maison), utilisée aussi bien par des monorepos internes que par Symfony/symfony pendant son propre développement. En résumé :

packages/<vendor>/<lib>/ — un dossier sibling de src/, avec son propre composer.json (nom vendor/lib, autoload PSR-4 dans un namespace détaché de App\, et surtout sa propre liste de dépendances — pas celle de l'app).
Path repository dans le composer.json racine de l'app :

"repositories": [{"type": "path", "url": "packages/vendor/lib"}],
"require": {"vendor/lib": "*@dev"}
Composer symlink automatiquement vendor/vendor/lib → packages/vendor/lib (symlink par défaut sur POSIX, pas besoin de "options": {"symlink": true} sauf pour forcer sur un OS qui copierait par défaut).
Un git par défaut, plusieurs possibles — le dossier reste dans le même repo/historique tant que ça sert le développement en parallèle ; l'extraction vers un repo Git séparé (via git subtree split) n'est qu'une étape ultérieure et optionnelle, quand/si la lib doit vivre ailleurs (autre projet, open-source, Packagist privé).
Bundle Symfony (AbstractBundle) pour que la lib embarque sa propre config (services, bus Messenger, mapping Doctrine) au lieu de la laisser fuiter dans config/packages/*.yaml de l'app hôte.
Le seul vrai prérequis, comme on l'a vu, c'est que la lib n'importe plus directement les classes Doctrine de l'app (App\Entity\Order, etc.) — sinon le symlink fonctionne mais le package reste cassé pour tout autre consommateur. D'où l'intérêt de finir F2 (les ports) avant de faire l'extraction physique.