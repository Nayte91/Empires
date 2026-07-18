# TODO

- [ ] **AST — scroll horizontal mobile** : depuis la suppression des wrappers (`<table class="ast">` racine du composant), plus de conteneur de scroll horizontal sur viewport étroit — la table rétrécit sous les 2,5rem par case au lieu de scroller. Le mobile compte : trouver un compromis (conteneur de scroll réintroduit autrement, media query, ou autre approche) sans réintroduire les 2 div wrappers refusés.
- [ ] **Component/ — seuil de mirroring atomic design** : si `src/Component/` dépasse 15-20 fichiers, envisager un sous-découpage miroir de l'atomic design des templates (`templates/{atoms,molecules,organisms}/`) plutôt qu'un dossier plat. Actuellement 9 fichiers, sous le seuil.
