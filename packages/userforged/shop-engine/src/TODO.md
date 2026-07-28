# Améliorations pour la lib

- Tous les connecteurs (ports) de la lib vers son hote doivent avoir une stratégie :
  - tous dans un répertoire connectors ?
  - tous à la racine ?
  - tous avec un nom clair (suffix Connector ou Port plutot que Interface) ?
- Tous les connecteurs doivent être self documented : pas pour le dev de la lib, mais pour l'utilisateur externe.
On doit trouver en dockblock à quoi sert ce connecteur, comment s'en servir, quoi injecter (ça c'est un code bien typé et bien nommé qui le dira en partie).
On s'autorise des docblocks sur les méthodes ou les propriétés, pour zoomer la doc.
- La première version de cette lib (v1 ? milestone ? release ? Quel est le terme git ?) doit être 8.5. 
- Nos interfaces ont tout à fait le droit de porter des propriétés, pas que des méthodes. On en a déjà, mais on peut peut-etre aller plus loin :
  - ProductProviderInterface::products ? un array ?
  - ProductProviderInterface::productsByKeys ? un array ?
  - FacetProviderInterface::facets ? un array ?
  - Bref y en a plein, de manière générale si c'est un nom commun c'est une propriété, si c'est un verbe c'est une méthode.
- Chaque version de notre lib doit être taggé par rapport à la version de PHP dans laquelle elle peut se plug.
- On fera "8.5.1" si on a besoin de rajouter une feature (ce qui devrait peu arriver car un shop engine est je suppose feature complete depuis des années).
- On fera "8.5.0.1" si on fait des améliorations majeures (refacto, bugfix, perfs, ...)