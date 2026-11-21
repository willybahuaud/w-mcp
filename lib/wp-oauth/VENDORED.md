# Module OAuth vendoré

Ce dossier est une copie **non modifiée** du module `oauth/` de la PR OAuth 2.1
du MCP Adapter officiel. On l'embarque le temps que le support OAuth arrive dans
l'adapter publié ou dans le core (visé 7.3).

- Source : https://github.com/WordPress/mcp-adapter/pull/437
- Branche : `codex/oauth-server` (base `trunk`)
- Commit épinglé : `5bfeb72`
- Vendoré le : 2026-11-21
- Licence : GPL-2.0-or-later (même licence que W-MCP)

## Pourquoi vendoré plutôt qu'installer la PR

On garde l'adapter publié (0.7.2) intact et on ajoute seulement cette brique
isolée. Le module ne référence jamais l'adapter : tout le lien se fait côté
W-MCP dans `includes/class-wmcp-oauth.php`, qui lui déclare les routes MCP à
protéger et impose trois garde-fous repérés à l'audit (voir ce fichier).

## Ne pas éditer ici

Le code de ce dossier doit rester une copie fidèle de la source, pour que la
mise à jour reste un simple remplacement. Toute adaptation se fait par les hooks
du module, depuis W-MCP.

## Procédure de mise à jour

La PR bouge : c'est une veille manuelle, pas un abonnement.

1. Récupérer le nouvel état de la PR #437.
2. Relire **le diff** depuis le commit épinglé ci-dessus (pas tout le module).
3. Re-dérouler l'audit sur ce diff.
4. Tester de bout en bout sur le site pilote.
5. Remplacer `src/`, `templates/`, `load.php`, `README.md`, puis mettre à jour
   le commit et la date ci-dessus.

## Le jour où OAuth arrive dans l'adapter publié ou le core

`load.php` s'auto-neutralise si une classe `WP\OAuth\Server` existe déjà
(garde `class_exists`). On pourra alors supprimer ce dossier et ne garder que
les garde-fous de `WMCP_OAuth`, à réaccorder si les noms de hooks ont changé.
