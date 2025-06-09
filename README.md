# Plugin-Wordpress-serveur-MCP

Ce dépôt contient un squelette de plugin WordPress permettant de créer un serveur MCP (Master Control Program) afin d'automatiser WordPress à l'aide de ChatGPT.

## Installation

1. Copiez le dossier `wordpress-mcp` dans le répertoire `wp-content/plugins` de votre site WordPress.
2. Activez le plugin depuis l'interface d'administration.

## Fonctionnalités

- Point de terminaison REST `/wp-json/mcp/v1/command` pour exécuter des commandes (créer, modifier, supprimer).
- Authentification prévue via OAuth2 (à implémenter).
- Confirmation requise pour les actions destructrices.

Ce plugin est fourni à titre de démonstration et nécessite des développements complémentaires pour être utilisé en production.

## Configuration OAuth

Une fois le plugin activé, assurez-vous que l'URL suivante renvoie bien une configuration JSON :

```
/wp-json/mcp-oauth/v1/config
```

Un alias est également disponible en :

```
/wp-json/mcp/v1/config
```

Si cette URL retourne une erreur (404 ou autre), vérifiez que le plugin est correctement installé et qu'aucun système de sécurité ne bloque l'accès aux routes REST.
