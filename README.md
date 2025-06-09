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
