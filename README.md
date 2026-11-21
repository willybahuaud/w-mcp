# W-MCP

Plugin WordPress qui encadre l'accès MCP d'un site client.

Le client pilote son site avec une IA (Claude Code, claude.ai). W-MCP décide ce que chaque personne a le droit de faire, garde la trace de ce qui a été fait, et fait remonter au prestataire ce que le client n'a pas pu faire.

Il ne fournit pas le serveur MCP : il s'appuie sur les briques officielles (Abilities API du core, plugin MCP Adapter) et ajoute ce qu'elles ne font pas. Pour l'authentification, il embarque un serveur OAuth (voir plus bas) le temps que le core ou l'adapter en fournissent un.

## Ce que fait le plugin

- **Droits par utilisateur.** Une matrice (utilisateur → profil → abilities autorisées) poussée par WBoard. Refus par défaut.
- **Journal.** Chaque écriture et chaque refus laissent une ligne, les lectures un compteur. WBoard collecte.
- **Remontée de besoin.** Quand l'IA bute sur une limite, elle ne contourne pas : elle transmet l'intention du client avec `wmcp/request-capability`.
- **Abilities d'écriture bridées.** Contenus, termes, médias, blocs, compositions, modèles.

## Prérequis

| Élément | Version |
|---|---|
| WordPress | 7.1+ |
| PHP | 8.2+ |
| [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) | 0.7.2+, installé comme plugin |
| [wboard-connector](https://github.com/willybahuaud/wboard-connector) | toute version exposant `WBoard_Connector_Security` |

S'il en manque un, W-MCP ne charge rien et affiche une notice. Le connector sert à signer les routes de pilotage : le plugin est pensé pour être piloté depuis WBoard, pas en autonome.

## Comment ça marche

```
Client MCP ──► MCP Adapter ──► Abilities API ──► ability
                   │                 │
            WMCP_Adapter_Bridge   WMCP_Guard ──► WMCP_Policy_Resolver (matrice)
            (contexte MCP,        WMCP_Logger ─► tables wmcp_log, wmcp_read_counts
             porte d'entrée)

WBoard ──(HMAC du connector)──► /wmcp/v1/*  : matrice, pause, collecte
```

- **Contexte MCP.** La matrice et le journal ne s'appliquent qu'aux exécutions venues de l'adapter. Une ability appelée par un plugin, WP-CLI ou l'éditeur garde son comportement normal.
- **Le garde ne fait que restreindre.** Il se branche sur `wp_ability_permission_result` et ne transforme jamais un refus de WordPress en accord.
- **Un seul point de contact avec l'adapter** : `WMCP_Adapter_Bridge`. C'est le fichier à mettre à jour quand l'adapter entrera dans le core.
- **Le reste ne dépend que du core** (Abilities API, capabilities, révisions).

## Authentification (OAuth)

claude.ai ne se connecte qu'en OAuth. L'adapter publié n'en fournit pas encore, alors W-MCP embarque le module de la PR officielle (WordPress/mcp-adapter#437) dans `lib/wp-oauth`, en attendant qu'il arrive au core.

- **Code tiers vendoré, pas le nôtre.** Il vit dans `lib/wp-oauth`, à part du reste, sans style imposé par nos conventions (voir [lib/wp-oauth/VENDORED.md](lib/wp-oauth/VENDORED.md) pour l'origine exacte et le suivi de la PR). Il s'auto-neutralise si l'adapter finit par fournir OAuth, et se coupe en définissant `WMCP_OAUTH` à `false` avant le chargement du plugin.
- **Connexion d'une IA.** On crée un client OAuth public pour claude.ai (URI de retour `https://claude.ai/api/mcp/auth_callback`), puis on colle le `client_id` et l'URL du serveur MCP dans claude.ai. WBoard automatise ce couplage (route signée `POST /oauth-client`), mais rien n'empêche de créer le client à la main.
- **Toute app doit être approuvée** par un administrateur, et seuls les utilisateurs présents dans la matrice peuvent autoriser une connexion. Un jeton émis est confiné aux routes MCP : il est refusé partout ailleurs dans l'API REST.

## La matrice

```json
{
  "version": 12,
  "paused": false,
  "profiles": {
    "redaction": {
      "levels": ["read", "write"],
      "allow": ["wmcp/content-*", "wmcp/terms-query"],
      "deny": ["wmcp/content-update-block"],
      "can_publish": false
    }
  },
  "users": {
    "42": { "profile": "redaction", "allow": [], "deny": [] }
  }
}
```

- **Niveaux** : `read`, `write`, `destructive`, déduits des annotations de l'ability. Sans annotation : `destructive`.
- **Motifs** : nom exact ou préfixe avec joker final (`woocommerce/*`, `wmcp/content-*`).
- **Ordre** : exceptions de l'utilisateur (`deny` puis `allow`), puis profil (`deny`, puis `allow` et niveau).
- **Exception `allow` d'un utilisateur** : elle ignore le niveau, c'est son rôle.
- **`can_publish: false`** : une demande de publication devient « en attente », et ce qui est déjà en ligne n'est plus modifiable (contenus, compositions, termes, médias, modèles).
- **Toujours autorisées** pour un utilisateur de la matrice : `wmcp/request-capability`, `wmcp/my-permissions`, `wmcp/site-context`, et les trois outils relais `mcp-adapter/*`.
- **Abilities tierces** (WooCommerce, extension custom) : la matrice travaille sur le nom, peu importe le fournisseur.

Une matrice invalide est rejetée en bloc, l'ancienne reste en place. Une version inférieure à celle en place est refusée.

## Routes de pilotage

Namespace `wmcp/v1`, toutes en `POST`, signées par le HMAC de wboard-connector. Aucun compte WordPress n'y donne accès.

| Route | Corps | Rôle |
|---|---|---|
| `/status` | — | Versions, matrice en place, catalogue des abilities, utilisateurs candidats, santé du journal |
| `/policy` | `{ policy, site_context }` | Remplace la matrice et le document de cadrage |
| `/pause` | `{ paused }` | Coupe ou rétablit l'accès MCP |
| `/log` | `{ after_id, limit }` | Journal après le curseur |
| `/requests` | `{ after_id, limit }` | Demandes de fonctionnalité après le curseur |
| `/read-counts` | `{ since }` | Compteurs de lecture depuis une date (YYYY-MM-DD) |

La signature du connector ne couvre que le corps de la requête, pas la route ni un usage unique. Chaque corps porte donc deux champs que le plugin contrôle :

- `_route` : la route visée. Une requête signée pour `/status` est refusée sur `/log`.
- `_id` : 32 caractères hexadécimaux, à usage unique. Une requête rejouée est refusée.

Autres règles :

- La collecte se fait par curseur : le `after_id` envoyé par le board vaut accusé de réception de tout ce qui le précède.
- Une matrice doit porter une version strictement supérieure à celle en place.
- Aucune limite de débit sur ces routes : un visiteur ne doit pas pouvoir bloquer la coupure d'accès en saturant un compteur.

## Abilities fournies

| Ability | Niveau | Rôle |
|---|---|---|
| `wmcp/request-capability` | write | Remonte un besoin non couvert |
| `wmcp/site-context` | read | Document de cadrage du site |
| `wmcp/my-permissions` | read | Droits de l'utilisateur courant |
| `wmcp/content-query` | read | Recherche et lecture de contenus |
| `wmcp/content-create` | write | Création |
| `wmcp/content-update` | write | Modification, avec révision |
| `wmcp/content-trash` | destructive | Corbeille, jamais de suppression définitive |
| `wmcp/terms-query` | read | Recherche de termes |
| `wmcp/term-create`, `wmcp/term-update` | write | Création et modification de termes |
| `wmcp/content-set-terms` | write | Assignation de termes |
| `wmcp/media-query` | read | Recherche de médias |
| `wmcp/media-update` | write | Titre, légende, texte alternatif |
| `wmcp/block-types-query` | read | Blocs disponibles sur le site |
| `wmcp/patterns-query` | read | Compositions du thème et enregistrées |
| `wmcp/content-get-blocks` | read | Plan des blocs d'un contenu, un chemin par bloc |
| `wmcp/content-update-block` | write | Modifie un bloc par son chemin, sans réécrire le reste |
| `wmcp/pattern-create`, `wmcp/pattern-update` | write | Compositions enregistrées |
| `wmcp/templates-query` | read | Modèles et éléments de modèle |
| `wmcp/template-update`, `wmcp/template-reset` | destructive | Modification et retour à la version du thème |

`content-query`, `content-create`, `content-update` et `content-trash` ne s'enregistrent plus dès que leur équivalent `core/` existe sur le site.

Tout contenu écrit est validé et filtré :

- un bloc inconnu du site ou du HTML hors bloc est refusé ;
- les blocs de HTML brut (`core/html`, `core/freeform`, `core/missing`, `core/shortcode`) sont refusés ;
- le HTML passe par `wp_kses_post()` quel que soit le rôle. WordPress ne filtre pas les éditeurs et les administrateurs, mais par le MCP c'est une IA qui écrit.

### Ajouter une ability métier

Deux façons, toutes deux soumises à la matrice et au journal :

- l'enregistrer normalement avec `wp_register_ability()` dans le thème ou une extension du client, avec `meta.mcp.public` à `true` et des annotations ;
- hériter de `WMCP_Ability` et ajouter la classe par le filtre `wmcp_ability_classes`.

## Filtres

| Filtre | Rôle |
|---|---|
| `wmcp_ability_classes` | Classes d'abilities enregistrées par le plugin |
| `wmcp_writable_post_types` | Types de contenu ouverts aux abilities (publics par défaut) |
| `wmcp_writable_taxonomies` | Taxonomies ouvertes aux abilities |
| `wmcp_log_redacted_keys` | Fragments de clés masqués dans le journal |
| `wmcp_forbidden_blocks` | Blocs refusés à l'écriture |
| `wmcp_client_label` | Libellé du client MCP dans le journal |

## Mise à jour

Le plugin se met à jour depuis les [GitHub Releases](https://github.com/willybahuaud/w-mcp/releases) de ce dépôt, dans l'écran natif de WordPress — même mécanique que wboard-connector. Pas de token : le dépôt public suffit.

La dernière release est lue au plus une fois toutes les 6 heures (l'API GitHub non authentifiée est limitée à 60 requêtes/heure/IP, un budget partagé sur un hébergement mutualisé). Ce délai s'ajuste avec le filtre `wmcp_update_cache_duration`.

## Développement

Le code distribuable et les releases sont dans ce dépôt. Les outils (tests, lint, scénarios d'intégration) et les specs vivent dans le dépôt de développement.

```bash
composer install
composer test              # tests unitaires (logique pure, sans WordPress)
composer lint              # conventions de code WordPress
tests/integration/run.sh   # scénarios HTTP sur un WordPress jetable
```

Les scénarios d'intégration lancent un WordPress 7.1 dans [Playground](https://wordpress.github.io/wordpress-playground/) (Node, pas de Docker), y montent le MCP Adapter, wboard-connector et W-MCP, puis jouent le rôle du board et de plusieurs utilisateurs en HTTP réel. Le connector est cherché dans `../wboard/plugin/wboard-connector`, ou au chemin passé en argument.

## Limites connues

- **La matrice gouverne le MCP, pas le reste.** Un jeton OAuth est confiné aux routes MCP, mais un mot de passe d'application, lui, ouvre toute l'API REST de son utilisateur. Un compte piloté par mot de passe d'application ne doit pas avoir un rôle WordPress plus large que ce que son profil autorise.
- **OAuth est une brique vendorée et transitoire.** Le module OAuth vient d'une PR amont non mergée (`lib/wp-oauth`). Il est là en attendant un OAuth natif (core ou adapter) ; le jour venu, on le retire.
- **Un autre filtre peut passer après le garde.** Il s'accroche en priorité 999 sur `wp_ability_permission_result` ; un callback plus tardif pourrait ré-accorder. Le filtre `wp_pre_execute_ability` permet aussi de court-circuiter les permissions : `/status` et la page d'état listent ce qui y est accroché.
- **Le niveau d'une ability tierce vient de ses propres annotations.** Une ability mal annotée « lecture seule » serait traitée comme une lecture. À relire avant de l'ouvrir dans un profil.
- **Le journal est plafonné** à 600 lignes détaillées par utilisateur et par heure. Au-delà, les lignes sont comptées (`log.unwritten` dans `/status`) mais plus écrites.
- **Un utilisateur absent de la matrice est refusé sans ligne de journal** : il est arrêté avant toute ability.
- **Serveur MCP propre à une extension** : hors de portée.
- **Multisite** : non géré.
