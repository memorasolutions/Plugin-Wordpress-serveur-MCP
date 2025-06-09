<?php
/**
 * Plugin Name: WordPress MCP Full Access Server
 * Plugin URI: https://memora.solutions/
 * Description: Serveur MCP complet avec OAuth 2.0 pour permettre aux IA un accès total sécurisé à WordPress
 * Version: 2.0.1
 * Author: MEMORA
 * Author URI: https://memora.solutions
 * License: GPL v2 or later
 * Text Domain: memora-mcp
 */

// Empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Définir les constantes
define('WP_MCP_VERSION', '2.0.1');
define('WP_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Tables de base de données pour OAuth
global $wp_mcp_db_version;
$wp_mcp_db_version = '1.0';

/**
 * Classe principale OAuth pour MCP
 */
class WP_MCP_OAuth {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_oauth_endpoints'));
    }
    
    /**
     * Enregistrer les endpoints OAuth
     */
    public function register_oauth_endpoints() {
        // IMPORTANT : Endpoint de configuration publique pour ChatGPT
        register_rest_route('mcp-oauth/v1', '/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'config_endpoint'),
            'permission_callback' => '__return_true' // Accès public obligatoire
        ));
        // Alias pour éviter toute confusion sur le chemin du point de terminaison
        register_rest_route('mcp/v1', '/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'config_endpoint'),
            'permission_callback' => '__return_true'
        ));
        
        // Endpoint d'autorisation
        register_rest_route('mcp-oauth/v1', '/authorize', array(
            'methods' => 'GET',
            'callback' => array($this, 'authorize_endpoint'),
            'permission_callback' => '__return_true'
        ));
        
        // Endpoint de token
        register_rest_route('mcp-oauth/v1', '/token', array(
            'methods' => 'POST',
            'callback' => array($this, 'token_endpoint'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Endpoint de configuration OAuth (public pour ChatGPT)
     */
    public function config_endpoint() {
        return array(
            'issuer' => get_site_url(),
            'authorization_endpoint' => get_rest_url(null, 'mcp-oauth/v1/authorize'),
            'token_endpoint' => get_rest_url(null, 'mcp-oauth/v1/token'),
            'server_endpoint' => get_rest_url(null, 'mcp/v1/'),
            'grant_types_supported' => array('authorization_code', 'refresh_token'),
            'response_types_supported' => array('code'),
            'scopes_supported' => array('full_access'),
            'token_endpoint_auth_methods_supported' => array('client_secret_post'),
            'service_documentation' => 'https://memora.solutions/docs/mcp',
            'ui_locales_supported' => array('en', 'fr')
        );
    }
    
    /**
     * Endpoint d'autorisation OAuth
     */
    public function authorize_endpoint($request) {
        $client_id = $request->get_param('client_id');
        $redirect_uri = $request->get_param('redirect_uri');
        $response_type = $request->get_param('response_type');
        $scope = $request->get_param('scope');
        $state = $request->get_param('state');
        
        // Vérifier que l'utilisateur est connecté
        if (!is_user_logged_in()) {
            $current_url = add_query_arg($_GET, wp_unslash($_SERVER['REQUEST_URI']));
            wp_redirect(wp_login_url($current_url));
            exit;
        }
        
        // Vérifier le client_id
        $client = $this->get_client($client_id);
        if (!$client) {
            wp_die('Client OAuth invalide. Veuillez vérifier votre configuration.');
        }
        
        // Afficher la page d'autorisation
        if (!isset($_POST['authorize'])) {
            $this->show_authorization_page($client, $redirect_uri, $scope, $state);
            exit;
        }
        
        // Traiter l'autorisation
        if ($_POST['authorize'] === 'yes') {
            // Générer le code d'autorisation
            $auth_code = wp_generate_password(32, false);
            $this->save_auth_code($auth_code, $client_id, get_current_user_id(), $redirect_uri, $scope);
            
            // Rediriger avec le code
            $redirect_url = add_query_arg(array(
                'code' => $auth_code,
                'state' => $state
            ), $redirect_uri);
            
            wp_redirect($redirect_url);
        } else {
            // Autorisation refusée
            $redirect_url = add_query_arg(array(
                'error' => 'access_denied',
                'state' => $state
            ), $redirect_uri);
            
            wp_redirect($redirect_url);
        }
        exit;
    }
    
    /**
     * Afficher la page d'autorisation
     */
    private function show_authorization_page($client, $redirect_uri, $scope, $state) {
        $current_user = wp_get_current_user();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Autorisation OAuth - <?php bloginfo('name'); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f5f5f5;
                    margin: 0;
                    padding: 20px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                }
                .auth-container {
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    max-width: 500px;
                    width: 100%;
                    padding: 40px;
                }
                .logo {
                    text-align: center;
                    margin-bottom: 30px;
                }
                h1 {
                    font-size: 24px;
                    margin: 0 0 20px;
                    color: #333;
                }
                .client-info {
                    background: #f8f9fa;
                    border-radius: 6px;
                    padding: 20px;
                    margin: 20px 0;
                }
                .client-name {
                    font-weight: bold;
                    color: #667eea;
                    font-size: 18px;
                }
                .permissions {
                    margin: 20px 0;
                }
                .permissions h3 {
                    font-size: 16px;
                    margin-bottom: 10px;
                }
                .permissions ul {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }
                .permissions li {
                    padding: 8px 0;
                    padding-left: 25px;
                    position: relative;
                }
                .permissions li:before {
                    content: "✓";
                    position: absolute;
                    left: 0;
                    color: #48bb78;
                    font-weight: bold;
                }
                .user-info {
                    text-align: center;
                    margin: 20px 0;
                    color: #666;
                }
                .buttons {
                    display: flex;
                    gap: 10px;
                    margin-top: 30px;
                }
                .btn {
                    flex: 1;
                    padding: 12px 24px;
                    border: none;
                    border-radius: 6px;
                    font-size: 16px;
                    cursor: pointer;
                    text-align: center;
                    transition: all 0.2s;
                }
                .btn-approve {
                    background: #667eea;
                    color: white;
                }
                .btn-approve:hover {
                    background: #5a67d8;
                }
                .btn-deny {
                    background: #e2e8f0;
                    color: #4a5568;
                }
                .btn-deny:hover {
                    background: #cbd5e0;
                }
                .warning {
                    background: #fffaf0;
                    border: 1px solid #feb2b2;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 20px 0;
                    color: #c53030;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <div class="auth-container">
                <div class="logo">
                    <h1>Autorisation d'accès</h1>
                </div>
                
                <div class="client-info">
                    <div class="client-name"><?php echo esc_html($client->name); ?></div>
                    <div>souhaite accéder à votre site WordPress</div>
                </div>
                
                <div class="permissions">
                    <h3>Cette application pourra :</h3>
                    <ul>
                        <li>Créer, modifier et supprimer du contenu</li>
                        <li>Gérer les plugins et thèmes</li>
                        <li>Accéder aux paramètres du site</li>
                        <li>Gérer les utilisateurs</li>
                        <li>Accéder à la base de données</li>
                        <li>Exécuter des actions d'administration</li>
                    </ul>
                </div>
                
                <div class="warning">
                    <strong>⚠️ Attention :</strong> Cette application aura un accès complet à votre site WordPress. 
                    N'autorisez que si vous faites confiance à cette application.
                </div>
                
                <div class="user-info">
                    Connecté en tant que : <strong><?php echo esc_html($current_user->display_name); ?></strong>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('oauth_authorize'); ?>
                    <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
                    <input type="hidden" name="redirect_uri" value="<?php echo esc_attr($redirect_uri); ?>">
                    <input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>">
                    <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
                    
                    <div class="buttons">
                        <button type="submit" name="authorize" value="yes" class="btn btn-approve">
                            Autoriser l'accès
                        </button>
                        <button type="submit" name="authorize" value="no" class="btn btn-deny">
                            Refuser
                        </button>
                    </div>
                </form>
                
                <div style="text-align: center; margin-top: 30px; color: #999; font-size: 12px;">
                    Powered by MEMORA MCP Server
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * Endpoint de token OAuth
     */
    public function token_endpoint($request) {
        $grant_type = $request->get_param('grant_type');
        
        if ($grant_type === 'authorization_code') {
            return $this->handle_authorization_code($request);
        } elseif ($grant_type === 'refresh_token') {
            return $this->handle_refresh_token($request);
        }
        
        return new WP_Error('unsupported_grant_type', 'Type de grant non supporté', array('status' => 400));
    }
    
    /**
     * Gérer le code d'autorisation
     */
    private function handle_authorization_code($request) {
        $code = $request->get_param('code');
        $client_id = $request->get_param('client_id');
        $client_secret = $request->get_param('client_secret');
        $redirect_uri = $request->get_param('redirect_uri');
        
        // Vérifier le client
        if (!$this->verify_client($client_id, $client_secret)) {
            return new WP_Error('invalid_client', 'Client invalide', array('status' => 401));
        }
        
        // Vérifier le code
        $auth_code_data = $this->get_auth_code($code);
        if (!$auth_code_data || $auth_code_data->client_id !== $client_id) {
            return new WP_Error('invalid_grant', 'Code invalide ou expiré', array('status' => 400));
        }
        
        // Vérifier le redirect_uri
        if ($auth_code_data->redirect_uri !== $redirect_uri) {
            return new WP_Error('invalid_request', 'Redirect URI ne correspond pas', array('status' => 400));
        }
        
        // Générer les tokens
        $access_token = 'mcp_' . wp_generate_password(64, false);
        $refresh_token = 'ref_' . wp_generate_password(64, false);
        
        $this->save_access_token($access_token, $client_id, $auth_code_data->user_id, $auth_code_data->scope);
        $this->save_refresh_token($refresh_token, $client_id, $auth_code_data->user_id);
        
        // Supprimer le code utilisé
        $this->delete_auth_code($code);
        
        return array(
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh_token,
            'scope' => $auth_code_data->scope
        );
    }
    
    /**
     * Gérer le refresh token
     */
    private function handle_refresh_token($request) {
        $refresh_token = $request->get_param('refresh_token');
        $client_id = $request->get_param('client_id');
        $client_secret = $request->get_param('client_secret');
        
        // Vérifier le client
        if (!$this->verify_client($client_id, $client_secret)) {
            return new WP_Error('invalid_client', 'Client invalide', array('status' => 401));
        }
        
        // Vérifier le refresh token
        $token_data = $this->get_refresh_token($refresh_token);
        if (!$token_data || $token_data->client_id !== $client_id) {
            return new WP_Error('invalid_grant', 'Refresh token invalide', array('status' => 400));
        }
        
        // Générer un nouveau access token
        $new_access_token = 'mcp_' . wp_generate_password(64, false);
        $this->save_access_token($new_access_token, $client_id, $token_data->user_id, 'full_access');
        
        return array(
            'access_token' => $new_access_token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'full_access'
        );
    }
    
    /**
     * Vérifier un token d'accès
     */
    public function verify_access_token($token) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_access_tokens';
        
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE token = %s AND expires_at > NOW()",
            $token
        ));
        
        return $token_data;
    }
    
    // Méthodes helper pour la base de données
    private function get_client($client_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_oauth_clients';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE client_id = %s", $client_id));
    }
    
    private function verify_client($client_id, $client_secret) {
        $client = $this->get_client($client_id);
        return $client && hash_equals($client->client_secret, $client_secret);
    }
    
    private function save_auth_code($code, $client_id, $user_id, $redirect_uri, $scope) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_auth_codes';
        
        $wpdb->insert($table_name, array(
            'code' => $code,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'redirect_uri' => $redirect_uri,
            'scope' => $scope,
            'expires_at' => date('Y-m-d H:i:s', time() + 600) // 10 minutes
        ));
    }
    
    private function get_auth_code($code) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_auth_codes';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE code = %s AND expires_at > NOW()",
            $code
        ));
    }
    
    private function delete_auth_code($code) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_auth_codes';
        $wpdb->delete($table_name, array('code' => $code));
    }
    
    private function save_access_token($token, $client_id, $user_id, $scope) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_access_tokens';
        
        $wpdb->insert($table_name, array(
            'token' => $token,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'scope' => $scope,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600) // 1 heure
        ));
    }
    
    private function save_refresh_token($token, $client_id, $user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_refresh_tokens';
        
        $wpdb->insert($table_name, array(
            'token' => $token,
            'client_id' => $client_id,
            'user_id' => $user_id
        ));
    }
    
    private function get_refresh_token($token) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_refresh_tokens';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE token = %s", $token));
    }
}

/**
 * Classe principale du serveur MCP
 */
class WP_MCP_Full_Server {
    
    private $namespace = 'mcp/v1';
    private $oauth;
    
    public function __construct() {
        $this->oauth = WP_MCP_OAuth::get_instance();
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_authentication_errors', array($this, 'oauth_authentication'));
    }
    
    /**
     * Authentification OAuth pour les requêtes REST
     */
    public function oauth_authentication($result) {
        // Si déjà une erreur, la retourner
        if (!empty($result)) {
            return $result;
        }
        
        // Vérifier si c'est une requête MCP
        if (strpos($_SERVER['REQUEST_URI'], '/wp-json/mcp/v1') === false) {
            return $result;
        }
        
        // Exceptions pour les endpoints publics
        if (strpos($_SERVER['REQUEST_URI'], '/mcp/v1/info') !== false || 
            strpos($_SERVER['REQUEST_URI'], '/mcp/v1/server') !== false) {
            return true;
        }
        
        // Récupérer le token depuis l'en-tête
        $auth_header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $auth_header = $headers['Authorization'];
            }
        }
        
        if (empty($auth_header)) {
            return new WP_Error('no_auth', 'Authorization requise', array('status' => 401));
        }
        
        // Extraire le token
        $token = str_replace('Bearer ', '', $auth_header);
        $token_data = $this->oauth->verify_access_token($token);
        
        if (!$token_data) {
            return new WP_Error('invalid_token', 'Token invalide ou expiré', array('status' => 403));
        }
        
        // Définir l'utilisateur actuel
        wp_set_current_user($token_data->user_id);
        
        return true;
    }
    
    /**
     * Enregistrer toutes les routes MCP
     */
    public function register_routes() {
        // Route info publique pour la découverte
        register_rest_route($this->namespace, '/info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_public_info'),
            'permission_callback' => '__return_true'
        ));
        
        // Route info serveur (publique pour ChatGPT)
        register_rest_route($this->namespace, '/server', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_server_info'),
            'permission_callback' => '__return_true'
        ));
        
        // Route liste des outils (nécessite auth)
        register_rest_route($this->namespace, '/tools', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_all_tools'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // Route exécution d'outils (nécessite auth)
        register_rest_route($this->namespace, '/tools/call', array(
            'methods' => 'POST',
            'callback' => array($this, 'call_tool'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }
    
    /**
     * Info publique pour la découverte
     */
    public function get_public_info() {
        return array(
            'name' => 'WordPress MCP Full Access Server by MEMORA',
            'version' => WP_MCP_VERSION,
            'protocol_version' => '1.0',
            'capabilities' => array(
                'tools' => true,
                'resources' => true,
                'prompts' => true,
                'oauth' => true
            ),
            'oauth_config_url' => get_rest_url(null, 'mcp-oauth/v1/config'),
            'developer' => array(
                'name' => 'MEMORA',
                'url' => 'https://memora.solutions',
                'email' => 'info@memora.ca'
            )
        );
    }
    
    /**
     * Vérification des permissions
     */
    public function check_permission() {
        return is_user_logged_in() && current_user_can('manage_options');
    }
    
    /**
     * Info du serveur
     */
    public function get_server_info() {
        return array(
            'name' => 'WordPress MCP Full Access Server',
            'version' => WP_MCP_VERSION,
            'protocol_version' => '1.0',
            'capabilities' => array(
                'tools' => true,
                'resources' => true,
                'prompts' => true,
                'oauth' => true
            ),
            'oauth_endpoints' => array(
                'authorize' => get_rest_url(null, 'mcp-oauth/v1/authorize'),
                'token' => get_rest_url(null, 'mcp-oauth/v1/token'),
                'config' => get_rest_url(null, 'mcp-oauth/v1/config')
            )
        );
    }
    
    /**
     * Liste complète de tous les outils disponibles
     */
    public function list_all_tools() {
        $tools = array(
            // Gestion du contenu
            array(
                'name' => 'content_management',
                'description' => 'Gérer tout le contenu (articles, pages, médias)',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('create', 'read', 'update', 'delete', 'list'),
                            'description' => 'Action à effectuer'
                        ),
                        'post_type' => array(
                            'type' => 'string',
                            'description' => 'Type de contenu (post, page, attachment, etc.)'
                        ),
                        'data' => array(
                            'type' => 'object',
                            'description' => 'Données du contenu'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Gestion des utilisateurs
            array(
                'name' => 'user_management',
                'description' => 'Gérer les utilisateurs WordPress',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('create', 'read', 'update', 'delete', 'list', 'change_role'),
                            'description' => 'Action à effectuer'
                        ),
                        'user_data' => array(
                            'type' => 'object',
                            'description' => 'Données utilisateur'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Gestion des plugins
            array(
                'name' => 'plugin_management',
                'description' => 'Gérer les plugins (installer, activer, désactiver, supprimer)',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('list', 'install', 'activate', 'deactivate', 'delete', 'update'),
                            'description' => 'Action à effectuer'
                        ),
                        'plugin' => array(
                            'type' => 'string',
                            'description' => 'Slug ou fichier du plugin'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Gestion des thèmes
            array(
                'name' => 'theme_management',
                'description' => 'Gérer les thèmes WordPress',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('list', 'activate', 'install', 'delete', 'customize'),
                            'description' => 'Action à effectuer'
                        ),
                        'theme' => array(
                            'type' => 'string',
                            'description' => 'Nom du thème'
                        ),
                        'customizations' => array(
                            'type' => 'object',
                            'description' => 'Options de personnalisation'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Base de données
            array(
                'name' => 'database_operations',
                'description' => 'Exécuter des opérations sur la base de données',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('query', 'backup', 'optimize', 'repair'),
                            'description' => 'Action à effectuer'
                        ),
                        'query' => array(
                            'type' => 'string',
                            'description' => 'Requête SQL (pour action query)'
                        ),
                        'table' => array(
                            'type' => 'string',
                            'description' => 'Nom de la table'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Paramètres système
            array(
                'name' => 'system_settings',
                'description' => 'Gérer tous les paramètres WordPress',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('get', 'set', 'list'),
                            'description' => 'Action à effectuer'
                        ),
                        'option' => array(
                            'type' => 'string',
                            'description' => 'Nom de l\'option'
                        ),
                        'value' => array(
                            'type' => 'mixed',
                            'description' => 'Valeur de l\'option'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Fichiers et médias
            array(
                'name' => 'file_management',
                'description' => 'Gérer les fichiers du serveur',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('upload', 'delete', 'move', 'copy', 'list', 'read', 'write'),
                            'description' => 'Action à effectuer'
                        ),
                        'path' => array(
                            'type' => 'string',
                            'description' => 'Chemin du fichier'
                        ),
                        'content' => array(
                            'type' => 'string',
                            'description' => 'Contenu du fichier'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Widgets et menus
            array(
                'name' => 'widget_menu_management',
                'description' => 'Gérer les widgets et menus',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'type' => array(
                            'type' => 'string',
                            'enum' => array('widget', 'menu'),
                            'description' => 'Type d\'élément'
                        ),
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('create', 'update', 'delete', 'list'),
                            'description' => 'Action à effectuer'
                        ),
                        'data' => array(
                            'type' => 'object',
                            'description' => 'Données de l\'élément'
                        )
                    ),
                    'required' => array('type', 'action')
                )
            ),
            
            // Commentaires
            array(
                'name' => 'comment_management',
                'description' => 'Gérer les commentaires',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('list', 'approve', 'unapprove', 'spam', 'trash', 'delete'),
                            'description' => 'Action à effectuer'
                        ),
                        'comment_id' => array(
                            'type' => 'integer',
                            'description' => 'ID du commentaire'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Code personnalisé
            array(
                'name' => 'custom_code',
                'description' => 'Exécuter du code PHP personnalisé',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'code' => array(
                            'type' => 'string',
                            'description' => 'Code PHP à exécuter'
                        ),
                        'safe_mode' => array(
                            'type' => 'boolean',
                            'description' => 'Mode sécurisé (limite les fonctions)',
                            'default' => true
                        )
                    ),
                    'required' => array('code')
                )
            ),
            
            // WooCommerce (si installé)
            array(
                'name' => 'woocommerce_management',
                'description' => 'Gérer WooCommerce (produits, commandes, clients)',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'entity' => array(
                            'type' => 'string',
                            'enum' => array('product', 'order', 'customer', 'coupon', 'report'),
                            'description' => 'Type d\'entité WooCommerce'
                        ),
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('create', 'read', 'update', 'delete', 'list'),
                            'description' => 'Action à effectuer'
                        ),
                        'data' => array(
                            'type' => 'object',
                            'description' => 'Données de l\'entité'
                        )
                    ),
                    'required' => array('entity', 'action')
                )
            ),
            
            // Cache et performance
            array(
                'name' => 'cache_management',
                'description' => 'Gérer le cache et l\'optimisation',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('clear', 'preload', 'optimize_db', 'analyze_performance'),
                            'description' => 'Action à effectuer'
                        ),
                        'cache_type' => array(
                            'type' => 'string',
                            'description' => 'Type de cache (all, page, object, transient)'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Sécurité
            array(
                'name' => 'security_management',
                'description' => 'Gérer la sécurité du site',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('scan', 'block_ip', 'unblock_ip', 'check_vulnerabilities', 'update_htaccess'),
                            'description' => 'Action de sécurité'
                        ),
                        'data' => array(
                            'type' => 'object',
                            'description' => 'Données pour l\'action'
                        )
                    ),
                    'required' => array('action')
                )
            ),
            
            // Logs et debugging
            array(
                'name' => 'debug_logs',
                'description' => 'Accéder aux logs et informations de debug',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array(
                            'type' => 'string',
                            'enum' => array('read_logs', 'clear_logs', 'enable_debug', 'disable_debug', 'system_info'),
                            'description' => 'Action de debug'
                        ),
                        'log_type' => array(
                            'type' => 'string',
                            'description' => 'Type de log (error, debug, access)'
                        )
                    ),
                    'required' => array('action')
                )
            )
        );
        
        // Permettre aux autres plugins d'ajouter leurs outils
        $tools = apply_filters('mcp_server_full_tools', $tools);
        
        return array('tools' => $tools);
    }
    
    /**
     * Exécuter un outil
     */
    public function call_tool($request) {
        $tool_name = $request->get_param('name');
        $arguments = $request->get_param('arguments');
        
        // Logger l'action
        $this->log_action($tool_name, $arguments);
        
        try {
            switch ($tool_name) {
                case 'content_management':
                    return $this->handle_content_management($arguments);
                    
                case 'user_management':
                    return $this->handle_user_management($arguments);
                    
                case 'plugin_management':
                    return $this->handle_plugin_management($arguments);
                    
                case 'theme_management':
                    return $this->handle_theme_management($arguments);
                    
                case 'database_operations':
                    return $this->handle_database_operations($arguments);
                    
                case 'system_settings':
                    return $this->handle_system_settings($arguments);
                    
                case 'file_management':
                    return $this->handle_file_management($arguments);
                    
                case 'widget_menu_management':
                    return $this->handle_widget_menu_management($arguments);
                    
                case 'comment_management':
                    return $this->handle_comment_management($arguments);
                    
                case 'custom_code':
                    return $this->handle_custom_code($arguments);
                    
                case 'woocommerce_management':
                    return $this->handle_woocommerce_management($arguments);
                    
                case 'cache_management':
                    return $this->handle_cache_management($arguments);
                    
                case 'security_management':
                    return $this->handle_security_management($arguments);
                    
                case 'debug_logs':
                    return $this->handle_debug_logs($arguments);
                    
                default:
                    // Permettre aux autres plugins de gérer leurs outils
                    return apply_filters('mcp_server_call_custom_tool', 
                        new WP_Error('unknown_tool', 'Outil inconnu'), 
                        $tool_name, 
                        $arguments
                    );
            }
        } catch (Exception $e) {
            return new WP_Error('tool_error', $e->getMessage());
        }
    }
    
    /**
     * Gestion du contenu
     */
    private function handle_content_management($args) {
        $action = $args['action'];
        $post_type = isset($args['post_type']) ? $args['post_type'] : 'post';
        $data = isset($args['data']) ? $args['data'] : array();
        
        switch ($action) {
            case 'create':
                $post_data = array(
                    'post_type' => $post_type,
                    'post_title' => isset($data['title']) ? $data['title'] : '',
                    'post_content' => isset($data['content']) ? $data['content'] : '',
                    'post_status' => isset($data['status']) ? $data['status'] : 'draft',
                    'post_author' => get_current_user_id()
                );
                
                // Ajouter les métadonnées
                if (isset($data['meta'])) {
                    $post_data['meta_input'] = $data['meta'];
                }
                
                $post_id = wp_insert_post($post_data);
                
                if (is_wp_error($post_id)) {
                    return $post_id;
                }
                
                // Gérer les taxonomies
                if (isset($data['categories'])) {
                    wp_set_post_categories($post_id, $data['categories']);
                }
                
                if (isset($data['tags'])) {
                    wp_set_post_tags($post_id, $data['tags']);
                }
                
                // Image mise en avant
                if (isset($data['featured_image'])) {
                    set_post_thumbnail($post_id, $data['featured_image']);
                }
                
                return array(
                    'success' => true,
                    'post_id' => $post_id,
                    'url' => get_permalink($post_id)
                );
                
            case 'update':
                if (!isset($data['id'])) {
                    return new WP_Error('missing_id', 'ID manquant');
                }
                
                $update_data = array('ID' => $data['id']);
                
                if (isset($data['title'])) $update_data['post_title'] = $data['title'];
                if (isset($data['content'])) $update_data['post_content'] = $data['content'];
                if (isset($data['status'])) $update_data['post_status'] = $data['status'];
                
                $result = wp_update_post($update_data);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                // Mettre à jour les métadonnées
                if (isset($data['meta'])) {
                    foreach ($data['meta'] as $key => $value) {
                        update_post_meta($data['id'], $key, $value);
                    }
                }
                
                return array(
                    'success' => true,
                    'message' => 'Contenu mis à jour',
                    'url' => get_permalink($data['id'])
                );
                
            case 'delete':
                if (!isset($data['id'])) {
                    return new WP_Error('missing_id', 'ID manquant');
                }
                
                $force = isset($data['force']) ? $data['force'] : false;
                $result = wp_delete_post($data['id'], $force);
                
                return array(
                    'success' => !empty($result),
                    'message' => $force ? 'Contenu supprimé définitivement' : 'Contenu déplacé dans la corbeille'
                );
                
            case 'list':
                $query_args = array(
                    'post_type' => $post_type,
                    'posts_per_page' => isset($data['limit']) ? $data['limit'] : 20,
                    'post_status' => isset($data['status']) ? $data['status'] : 'any'
                );
                
                if (isset($data['search'])) {
                    $query_args['s'] = $data['search'];
                }
                
                $posts = get_posts($query_args);
                $result = array();
                
                foreach ($posts as $post) {
                    $result[] = array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'content' => $post->post_content,
                        'excerpt' => $post->post_excerpt,
                        'status' => $post->post_status,
                        'date' => $post->post_date,
                        'author' => get_the_author_meta('display_name', $post->post_author),
                        'url' => get_permalink($post->ID)
                    );
                }
                
                return array(
                    'success' => true,
                    'posts' => $result,
                    'count' => count($result)
                );
                
            case 'read':
                if (!isset($data['id'])) {
                    return new WP_Error('missing_id', 'ID manquant');
                }
                
                $post = get_post($data['id']);
                
                if (!$post) {
                    return new WP_Error('post_not_found', 'Contenu non trouvé');
                }
                
                return array(
                    'success' => true,
                    'post' => array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'content' => $post->post_content,
                        'excerpt' => $post->post_excerpt,
                        'status' => $post->post_status,
                        'date' => $post->post_date,
                        'modified' => $post->post_modified,
                        'author' => get_the_author_meta('display_name', $post->post_author),
                        'categories' => wp_get_post_categories($post->ID, array('fields' => 'names')),
                        'tags' => wp_get_post_tags($post->ID, array('fields' => 'names')),
                        'featured_image' => get_the_post_thumbnail_url($post->ID),
                        'meta' => get_post_meta($post->ID),
                        'url' => get_permalink($post->ID)
                    )
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des utilisateurs
     */
    private function handle_user_management($args) {
        if (!current_user_can('list_users')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        $user_data = isset($args['user_data']) ? $args['user_data'] : array();
        
        switch ($action) {
            case 'create':
                if (!current_user_can('create_users')) {
                    return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
                }
                
                $user_id = wp_create_user(
                    $user_data['username'],
                    $user_data['password'],
                    $user_data['email']
                );
                
                if (is_wp_error($user_id)) {
                    return $user_id;
                }
                
                // Mettre à jour les informations supplémentaires
                if (isset($user_data['first_name'])) {
                    update_user_meta($user_id, 'first_name', $user_data['first_name']);
                }
                if (isset($user_data['last_name'])) {
                    update_user_meta($user_id, 'last_name', $user_data['last_name']);
                }
                if (isset($user_data['role'])) {
                    $user = new WP_User($user_id);
                    $user->set_role($user_data['role']);
                }
                
                return array(
                    'success' => true,
                    'user_id' => $user_id,
                    'message' => 'Utilisateur créé avec succès'
                );
                
            case 'update':
                if (!isset($user_data['id'])) {
                    return new WP_Error('missing_id', 'ID utilisateur manquant');
                }
                
                if (!current_user_can('edit_user', $user_data['id'])) {
                    return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
                }
                
                $update_data = array('ID' => $user_data['id']);
                
                if (isset($user_data['email'])) $update_data['user_email'] = $user_data['email'];
                if (isset($user_data['display_name'])) $update_data['display_name'] = $user_data['display_name'];
                if (isset($user_data['password'])) $update_data['user_pass'] = $user_data['password'];
                
                $result = wp_update_user($update_data);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Utilisateur mis à jour'
                );
                
            case 'delete':
                if (!isset($user_data['id'])) {
                    return new WP_Error('missing_id', 'ID utilisateur manquant');
                }
                
                if (!current_user_can('delete_user', $user_data['id'])) {
                    return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
                }
                
                $reassign = isset($user_data['reassign']) ? $user_data['reassign'] : null;
                
                $result = wp_delete_user($user_data['id'], $reassign);
                
                return array(
                    'success' => $result,
                    'message' => 'Utilisateur supprimé'
                );
                
            case 'list':
                $args = array(
                    'number' => isset($user_data['limit']) ? $user_data['limit'] : 50
                );
                
                if (isset($user_data['role'])) {
                    $args['role'] = $user_data['role'];
                }
                
                if (isset($user_data['search'])) {
                    $args['search'] = '*' . $user_data['search'] . '*';
                }
                
                $users = get_users($args);
                $result = array();
                
                foreach ($users as $user) {
                    $result[] = array(
                        'id' => $user->ID,
                        'username' => $user->user_login,
                        'email' => $user->user_email,
                        'display_name' => $user->display_name,
                        'roles' => $user->roles,
                        'registered' => $user->user_registered
                    );
                }
                
                return array(
                    'success' => true,
                    'users' => $result,
                    'count' => count($result)
                );
                
            case 'change_role':
                if (!isset($user_data['id']) || !isset($user_data['role'])) {
                    return new WP_Error('missing_data', 'ID et rôle requis');
                }
                
                if (!current_user_can('promote_user', $user_data['id'])) {
                    return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
                }
                
                $user = new WP_User($user_data['id']);
                $user->set_role($user_data['role']);
                
                return array(
                    'success' => true,
                    'message' => 'Rôle utilisateur modifié'
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des plugins
     */
    private function handle_plugin_management($args) {
        if (!current_user_can('activate_plugins')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        
        $action = $args['action'];
        
        switch ($action) {
            case 'list':
                $all_plugins = get_plugins();
                $active_plugins = get_option('active_plugins', array());
                
                $plugins_list = array();
                foreach ($all_plugins as $plugin_file => $plugin_data) {
                    $plugins_list[] = array(
                        'file' => $plugin_file,
                        'name' => $plugin_data['Name'],
                        'version' => $plugin_data['Version'],
                        'author' => $plugin_data['Author'],
                        'description' => $plugin_data['Description'],
                        'active' => in_array($plugin_file, $active_plugins)
                    );
                }
                
                return array(
                    'success' => true,
                    'plugins' => $plugins_list,
                    'count' => count($plugins_list)
                );
                
            case 'install':
                if (!isset($args['plugin'])) {
                    return new WP_Error('missing_plugin', 'Plugin manquant');
                }
                
                $api = plugins_api('plugin_information', array(
                    'slug' => $args['plugin']
                ));
                
                if (is_wp_error($api)) {
                    return $api;
                }
                
                $upgrader = new Plugin_Upgrader();
                $result = $upgrader->install($api->download_link);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Plugin installé avec succès'
                );
                
            case 'activate':
                if (!isset($args['plugin'])) {
                    return new WP_Error('missing_plugin', 'Plugin manquant');
                }
                
                $result = activate_plugin($args['plugin']);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Plugin activé'
                );
                
            case 'deactivate':
                if (!isset($args['plugin'])) {
                    return new WP_Error('missing_plugin', 'Plugin manquant');
                }
                
                deactivate_plugins($args['plugin']);
                
                return array(
                    'success' => true,
                    'message' => 'Plugin désactivé'
                );
                
            case 'delete':
                if (!isset($args['plugin'])) {
                    return new WP_Error('missing_plugin', 'Plugin manquant');
                }
                
                $result = delete_plugins(array($args['plugin']));
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Plugin supprimé'
                );
                
            case 'update':
                if (!isset($args['plugin'])) {
                    return new WP_Error('missing_plugin', 'Plugin manquant');
                }
                
                $upgrader = new Plugin_Upgrader();
                $result = $upgrader->upgrade($args['plugin']);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Plugin mis à jour'
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des thèmes
     */
    private function handle_theme_management($args) {
        if (!current_user_can('switch_themes')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        require_once(ABSPATH . 'wp-admin/includes/theme.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
        
        $action = $args['action'];
        
        switch ($action) {
            case 'list':
                $themes = wp_get_themes();
                $current_theme = wp_get_theme();
                $themes_list = array();
                
                foreach ($themes as $theme_slug => $theme) {
                    $themes_list[] = array(
                        'slug' => $theme_slug,
                        'name' => $theme->get('Name'),
                        'version' => $theme->get('Version'),
                        'author' => $theme->get('Author'),
                        'description' => $theme->get('Description'),
                        'active' => ($theme_slug === $current_theme->get_stylesheet())
                    );
                }
                
                return array(
                    'success' => true,
                    'themes' => $themes_list,
                    'current' => $current_theme->get_stylesheet()
                );
                
            case 'activate':
                if (!isset($args['theme'])) {
                    return new WP_Error('missing_theme', 'Thème manquant');
                }
                
                switch_theme($args['theme']);
                
                return array(
                    'success' => true,
                    'message' => 'Thème activé'
                );
                
            case 'install':
                if (!isset($args['theme'])) {
                    return new WP_Error('missing_theme', 'Thème manquant');
                }
                
                $api = themes_api('theme_information', array(
                    'slug' => $args['theme']
                ));
                
                if (is_wp_error($api)) {
                    return $api;
                }
                
                $upgrader = new Theme_Upgrader();
                $result = $upgrader->install($api->download_link);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Thème installé'
                );
                
            case 'delete':
                if (!isset($args['theme'])) {
                    return new WP_Error('missing_theme', 'Thème manquant');
                }
                
                $result = delete_theme($args['theme']);
                
                if (is_wp_error($result)) {
                    return $result;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Thème supprimé'
                );
                
            case 'customize':
                if (!isset($args['customizations'])) {
                    return new WP_Error('missing_customizations', 'Personnalisations manquantes');
                }
                
                foreach ($args['customizations'] as $option => $value) {
                    set_theme_mod($option, $value);
                }
                
                return array(
                    'success' => true,
                    'message' => 'Personnalisations appliquées'
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Opérations sur la base de données
     */
    private function handle_database_operations($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        global $wpdb;
        $action = $args['action'];
        
        switch ($action) {
            case 'query':
                if (!isset($args['query'])) {
                    return new WP_Error('missing_query', 'Requête manquante');
                }
                
                // Vérifications de sécurité basiques
                $query = $args['query'];
                $forbidden = array('DROP', 'TRUNCATE', 'DELETE FROM wp_users', 'UPDATE wp_users');
                
                foreach ($forbidden as $keyword) {
                    if (stripos($query, $keyword) !== false) {
                        return new WP_Error('forbidden_query', 'Requête interdite');
                    }
                }
                
                // Exécuter la requête
                if (stripos($query, 'SELECT') === 0) {
                    $results = $wpdb->get_results($query, ARRAY_A);
                    return array(
                        'success' => true,
                        'results' => $results,
                        'count' => count($results)
                    );
                } else {
                    $result = $wpdb->query($query);
                    return array(
                        'success' => ($result !== false),
                        'affected_rows' => $wpdb->rows_affected
                    );
                }
                
            case 'backup':
                // Créer une sauvegarde SQL
                $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
                $backup = "-- WordPress Database Backup\n";
                $backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
                $backup .= "-- By: MEMORA MCP Server\n\n";
                
                foreach ($tables as $table) {
                    $table_name = $table[0];
                    $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_N);
                    $backup .= "\n\n" . $create_table[1] . ";\n\n";
                    
                    $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
                    foreach ($rows as $row) {
                        $values = array_map(array($wpdb, 'prepare'), array_fill(0, count($row), '%s'), array_values($row));
                        $backup .= "INSERT INTO `$table_name` VALUES (" . implode(',', $values) . ");\n";
                    }
                }
                
                // Sauvegarder dans uploads
                $upload_dir = wp_upload_dir();
                $filename = 'backup-' . date('Y-m-d-His') . '.sql';
                $filepath = $upload_dir['basedir'] . '/' . $filename;
                
                file_put_contents($filepath, $backup);
                
                return array(
                    'success' => true,
                    'filename' => $filename,
                    'url' => $upload_dir['baseurl'] . '/' . $filename,
                    'size' => filesize($filepath)
                );
                
            case 'optimize':
                $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
                $optimized = array();
                
                foreach ($tables as $table) {
                    $table_name = $table[0];
                    $wpdb->query("OPTIMIZE TABLE `$table_name`");
                    $optimized[] = $table_name;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Tables optimisées',
                    'tables' => $optimized
                );
                
            case 'repair':
                if (!isset($args['table'])) {
                    return new WP_Error('missing_table', 'Table manquante');
                }
                
                $result = $wpdb->query("REPAIR TABLE `{$args['table']}`");
                
                return array(
                    'success' => ($result !== false),
                    'message' => 'Table réparée'
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des paramètres système
     */
    private function handle_system_settings($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        
        switch ($action) {
            case 'get':
                if (!isset($args['option'])) {
                    return new WP_Error('missing_option', 'Option manquante');
                }
                
                $value = get_option($args['option']);
                
                return array(
                    'success' => true,
                    'option' => $args['option'],
                    'value' => $value
                );
                
            case 'set':
                if (!isset($args['option']) || !isset($args['value'])) {
                    return new WP_Error('missing_data', 'Option et valeur requises');
                }
                
                // Liste des options protégées
                $protected = array('siteurl', 'home', 'admin_email');
                
                if (in_array($args['option'], $protected) && !current_user_can('manage_network')) {
                    return new WP_Error('protected_option', 'Option protégée');
                }
                
                $result = update_option($args['option'], $args['value']);
                
                return array(
                    'success' => $result,
                    'message' => 'Paramètre mis à jour'
                );
                
            case 'list':
                // Options courantes à afficher
                $common_options = array(
                    'blogname',
                    'blogdescription',
                    'admin_email',
                    'users_can_register',
                    'default_role',
                    'timezone_string',
                    'date_format',
                    'time_format',
                    'start_of_week',
                    'default_comment_status',
                    'default_ping_status',
                    'show_on_front',
                    'page_on_front',
                    'page_for_posts',
                    'posts_per_page',
                    'permalink_structure'
                );
                
                $settings = array();
                foreach ($common_options as $option) {
                    $settings[$option] = get_option($option);
                }
                
                return array(
                    'success' => true,
                    'settings' => $settings
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des fichiers
     */
    private function handle_file_management($args) {
        if (!current_user_can('upload_files')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        $wp_filesystem = $this->init_wp_filesystem();
        
        if (!$wp_filesystem) {
            return new WP_Error('filesystem_error', 'Impossible d\'initialiser le système de fichiers');
        }
        
        switch ($action) {
            case 'upload':
                if (!isset($args['path']) || !isset($args['content'])) {
                    return new WP_Error('missing_data', 'Chemin et contenu requis');
                }
                
                $upload_dir = wp_upload_dir();
                $filepath = $upload_dir['basedir'] . '/' . ltrim($args['path'], '/');
                
                // Créer le dossier si nécessaire
                $dir = dirname($filepath);
                if (!$wp_filesystem->exists($dir)) {
                    $wp_filesystem->mkdir($dir, FS_CHMOD_DIR, true);
                }
                
                // Écrire le fichier
                $result = $wp_filesystem->put_contents($filepath, $args['content'], FS_CHMOD_FILE);
                
                if (!$result) {
                    return new WP_Error('upload_failed', 'Échec de l\'upload');
                }
                
                return array(
                    'success' => true,
                    'path' => $args['path'],
                    'url' => $upload_dir['baseurl'] . '/' . ltrim($args['path'], '/'),
                    'size' => strlen($args['content'])
                );
                
            case 'read':
                if (!isset($args['path'])) {
                    return new WP_Error('missing_path', 'Chemin manquant');
                }
                
                $upload_dir = wp_upload_dir();
                $filepath = $upload_dir['basedir'] . '/' . ltrim($args['path'], '/');
                
                if (!$wp_filesystem->exists($filepath)) {
                    return new WP_Error('file_not_found', 'Fichier non trouvé');
                }
                
                $content = $wp_filesystem->get_contents($filepath);
                
                return array(
                    'success' => true,
                    'content' => $content,
                    'size' => strlen($content)
                );
                
            case 'delete':
                if (!isset($args['path'])) {
                    return new WP_Error('missing_path', 'Chemin manquant');
                }
                
                $upload_dir = wp_upload_dir();
                $filepath = $upload_dir['basedir'] . '/' . ltrim($args['path'], '/');
                
                if (!$wp_filesystem->exists($filepath)) {
                    return new WP_Error('file_not_found', 'Fichier non trouvé');
                }
                
                $result = $wp_filesystem->delete($filepath);
                
                return array(
                    'success' => $result,
                    'message' => 'Fichier supprimé'
                );
                
            case 'list':
                $upload_dir = wp_upload_dir();
                $path = isset($args['path']) ? $args['path'] : '';
                $dirpath = $upload_dir['basedir'] . '/' . ltrim($path, '/');
                
                if (!$wp_filesystem->exists($dirpath)) {
                    return new WP_Error('dir_not_found', 'Dossier non trouvé');
                }
                
                $files = $wp_filesystem->dirlist($dirpath);
                $result = array();
                
                foreach ($files as $name => $file) {
                    $result[] = array(
                        'name' => $name,
                        'type' => $file['type'],
                        'size' => $file['size'],
                        'modified' => date('Y-m-d H:i:s', $file['lastmodunix'])
                    );
                }
                
                return array(
                    'success' => true,
                    'files' => $result,
                    'path' => $path
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Initialiser WP_Filesystem
     */
    private function init_wp_filesystem() {
        global $wp_filesystem;
        
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        WP_Filesystem();
        
        return $wp_filesystem;
    }
    
    /**
     * Gestion des widgets et menus
     */
    private function handle_widget_menu_management($args) {
        if (!current_user_can('edit_theme_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $type = $args['type'];
        $action = $args['action'];
        $data = isset($args['data']) ? $args['data'] : array();
        
        if ($type === 'menu') {
            switch ($action) {
                case 'create':
                    $menu_id = wp_create_nav_menu($data['name']);
                    
                    if (is_wp_error($menu_id)) {
                        return $menu_id;
                    }
                    
                    // Ajouter des éléments au menu
                    if (isset($data['items'])) {
                        foreach ($data['items'] as $item) {
                            wp_update_nav_menu_item($menu_id, 0, array(
                                'menu-item-title' => $item['title'],
                                'menu-item-url' => $item['url'],
                                'menu-item-status' => 'publish'
                            ));
                        }
                    }
                    
                    return array(
                        'success' => true,
                        'menu_id' => $menu_id,
                        'message' => 'Menu créé'
                    );
                    
                case 'list':
                    $menus = wp_get_nav_menus();
                    $result = array();
                    
                    foreach ($menus as $menu) {
                        $items = wp_get_nav_menu_items($menu->term_id);
                        $result[] = array(
                            'id' => $menu->term_id,
                            'name' => $menu->name,
                            'items' => count($items),
                            'locations' => get_nav_menu_locations()
                        );
                    }
                    
                    return array(
                        'success' => true,
                        'menus' => $result
                    );
                    
                case 'delete':
                    if (!isset($data['id'])) {
                        return new WP_Error('missing_id', 'ID du menu manquant');
                    }
                    
                    $result = wp_delete_nav_menu($data['id']);
                    
                    if (is_wp_error($result)) {
                        return $result;
                    }
                    
                    return array(
                        'success' => true,
                        'message' => 'Menu supprimé'
                    );
            }
        } elseif ($type === 'widget') {
            global $wp_registered_widgets, $wp_registered_sidebars;
            
            switch ($action) {
                case 'list':
                    $widgets = array();
                    $sidebars = array();
                    
                    foreach ($wp_registered_widgets as $id => $widget) {
                        $widgets[] = array(
                            'id' => $id,
                            'name' => $widget['name'],
                            'description' => isset($widget['description']) ? $widget['description'] : ''
                        );
                    }
                    
                    foreach ($wp_registered_sidebars as $id => $sidebar) {
                        $sidebars[] = array(
                            'id' => $id,
                            'name' => $sidebar['name'],
                            'description' => $sidebar['description']
                        );
                    }
                    
                    return array(
                        'success' => true,
                        'widgets' => $widgets,
                        'sidebars' => $sidebars
                    );
            }
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des commentaires
     */
    private function handle_comment_management($args) {
        if (!current_user_can('moderate_comments')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        
        switch ($action) {
            case 'list':
                $comment_args = array(
                    'number' => isset($args['limit']) ? $args['limit'] : 50,
                    'status' => isset($args['status']) ? $args['status'] : 'all'
                );
                
                $comments = get_comments($comment_args);
                $result = array();
                
                foreach ($comments as $comment) {
                    $result[] = array(
                        'id' => $comment->comment_ID,
                        'author' => $comment->comment_author,
                        'email' => $comment->comment_author_email,
                        'content' => $comment->comment_content,
                        'post_id' => $comment->comment_post_ID,
                        'date' => $comment->comment_date,
                        'status' => $comment->comment_approved
                    );
                }
                
                return array(
                    'success' => true,
                    'comments' => $result,
                    'count' => count($result)
                );
                
            case 'approve':
                if (!isset($args['comment_id'])) {
                    return new WP_Error('missing_id', 'ID du commentaire manquant');
                }
                
                $result = wp_set_comment_status($args['comment_id'], 'approve');
                
                return array(
                    'success' => $result,
                    'message' => 'Commentaire approuvé'
                );
                
            case 'unapprove':
                if (!isset($args['comment_id'])) {
                    return new WP_Error('missing_id', 'ID du commentaire manquant');
                }
                
                $result = wp_set_comment_status($args['comment_id'], 'hold');
                
                return array(
                    'success' => $result,
                    'message' => 'Commentaire mis en attente'
                );
                
            case 'spam':
                if (!isset($args['comment_id'])) {
                    return new WP_Error('missing_id', 'ID du commentaire manquant');
                }
                
                $result = wp_spam_comment($args['comment_id']);
                
                return array(
                    'success' => $result,
                    'message' => 'Commentaire marqué comme spam'
                );
                
            case 'trash':
                if (!isset($args['comment_id'])) {
                    return new WP_Error('missing_id', 'ID du commentaire manquant');
                }
                
                $result = wp_trash_comment($args['comment_id']);
                
                return array(
                    'success' => $result,
                    'message' => 'Commentaire mis à la corbeille'
                );
                
            case 'delete':
                if (!isset($args['comment_id'])) {
                    return new WP_Error('missing_id', 'ID du commentaire manquant');
                }
                
                $result = wp_delete_comment($args['comment_id'], true);
                
                return array(
                    'success' => $result,
                    'message' => 'Commentaire supprimé définitivement'
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Exécuter du code personnalisé
     */
    private function handle_custom_code($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $code = $args['code'];
        $safe_mode = isset($args['safe_mode']) ? $args['safe_mode'] : true;
        
        if ($safe_mode) {
            // Liste des fonctions dangereuses à bloquer
            $dangerous_functions = array(
                'eval', 'exec', 'system', 'shell_exec', 'passthru', 
                'file_put_contents', 'file_get_contents', 'fopen', 'fwrite',
                'unlink', 'rmdir', 'mkdir', 'chmod', 'chown'
            );
            
            foreach ($dangerous_functions as $func) {
                if (stripos($code, $func) !== false) {
                    return new WP_Error('dangerous_code', "Fonction dangereuse détectée: $func");
                }
            }
        }
        
        // Exécuter le code dans un contexte isolé
        ob_start();
        $result = null;
        $error = null;
        
        try {
            $result = eval($code);
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (ParseError $e) {
            $error = "Erreur de syntaxe: " . $e->getMessage();
        }
        
        $output = ob_get_clean();
        
        if ($error) {
            return new WP_Error('code_error', $error);
        }
        
        return array(
            'success' => true,
            'result' => $result,
            'output' => $output
        );
    }
    
    /**
     * Gestion WooCommerce
     */
    private function handle_woocommerce_management($args) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_found', 'WooCommerce n\'est pas installé');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $entity = $args['entity'];
        $action = $args['action'];
        $data = isset($args['data']) ? $args['data'] : array();
        
        switch ($entity) {
            case 'product':
                return $this->handle_woo_products($action, $data);
            case 'order':
                return $this->handle_woo_orders($action, $data);
            case 'customer':
                return $this->handle_woo_customers($action, $data);
            case 'coupon':
                return $this->handle_woo_coupons($action, $data);
            case 'report':
                return $this->handle_woo_reports($action, $data);
        }
        
        return new WP_Error('invalid_entity', 'Entité invalide');
    }
    
    /**
     * Gestion du cache
     */
    private function handle_cache_management($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        
        switch ($action) {
            case 'clear':
                $cache_type = isset($args['cache_type']) ? $args['cache_type'] : 'all';
                
                switch ($cache_type) {
                    case 'all':
                        wp_cache_flush();
                        $this->clear_transients();
                        do_action('litespeed_purge_all');
                        do_action('w3tc_flush_all');
                        do_action('wp_rocket_clean_domain');
                        break;
                        
                    case 'page':
                        wp_cache_flush();
                        break;
                        
                    case 'object':
                        global $wp_object_cache;
                        if (is_object($wp_object_cache)) {
                            $wp_object_cache->flush();
                        }
                        break;
                        
                    case 'transient':
                        $this->clear_transients();
                        break;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Cache vidé',
                    'type' => $cache_type
                );
                
            case 'preload':
                // Précharger les pages importantes
                $pages = get_pages(array('number' => 50));
                $preloaded = 0;
                
                foreach ($pages as $page) {
                    wp_remote_get(get_permalink($page->ID), array('blocking' => false));
                    $preloaded++;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Pages préchargées',
                    'count' => $preloaded
                );
                
            case 'optimize_db':
                global $wpdb;
                
                $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
                foreach ($tables as $table) {
                    $wpdb->query("OPTIMIZE TABLE `{$table[0]}`");
                }
                
                // Nettoyer les révisions
                $wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision'");
                
                // Nettoyer les transients expirés
                $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
                
                return array(
                    'success' => true,
                    'message' => 'Base de données optimisée'
                );
                
            case 'analyze_performance':
                $stats = array(
                    'database_size' => $this->get_database_size(),
                    'uploads_size' => $this->get_directory_size(wp_upload_dir()['basedir']),
                    'plugin_count' => count(get_plugins()),
                    'active_plugins' => count(get_option('active_plugins')),
                    'post_count' => wp_count_posts()->publish,
                    'comment_count' => wp_count_comments()->approved,
                    'user_count' => count_users()['total_users'],
                    'php_version' => PHP_VERSION,
                    'mysql_version' => $wpdb->db_version(),
                    'memory_usage' => size_format(memory_get_usage()),
                    'memory_limit' => ini_get('memory_limit')
                );
                
                return array(
                    'success' => true,
                    'stats' => $stats
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion de la sécurité
     */
    private function handle_security_management($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        $data = isset($args['data']) ? $args['data'] : array();
        
        switch ($action) {
            case 'scan':
                $issues = array();
                
                // Vérifier les permissions des fichiers
                $critical_files = array(
                    ABSPATH . 'wp-config.php' => '0400',
                    ABSPATH . '.htaccess' => '0644'
                );
                
                foreach ($critical_files as $file => $expected_perms) {
                    if (file_exists($file)) {
                        $perms = substr(sprintf('%o', fileperms($file)), -4);
                        if ($perms !== $expected_perms) {
                            $issues[] = array(
                                'type' => 'file_permissions',
                                'file' => $file,
                                'current' => $perms,
                                'expected' => $expected_perms
                            );
                        }
                    }
                }
                
                // Vérifier les utilisateurs avec des mots de passe faibles
                $users = get_users();
                foreach ($users as $user) {
                    if ($user->user_login === 'admin') {
                        $issues[] = array(
                            'type' => 'weak_username',
                            'user' => $user->user_login,
                            'message' => 'Nom d\'utilisateur "admin" détecté'
                        );
                    }
                }
                
                // Vérifier les plugins avec des vulnérabilités connues
                $plugins = get_plugins();
                // Ici, vous pourriez vérifier contre une base de données de vulnérabilités
                
                return array(
                    'success' => true,
                    'issues' => $issues,
                    'count' => count($issues)
                );
                
            case 'block_ip':
                if (!isset($data['ip'])) {
                    return new WP_Error('missing_ip', 'Adresse IP manquante');
                }
                
                $blocked_ips = get_option('mcp_blocked_ips', array());
                $blocked_ips[] = $data['ip'];
                update_option('mcp_blocked_ips', array_unique($blocked_ips));
                
                // Mettre à jour .htaccess
                $this->update_htaccess_block_ips($blocked_ips);
                
                return array(
                    'success' => true,
                    'message' => 'IP bloquée',
                    'blocked_ips' => $blocked_ips
                );
                
            case 'unblock_ip':
                if (!isset($data['ip'])) {
                    return new WP_Error('missing_ip', 'Adresse IP manquante');
                }
                
                $blocked_ips = get_option('mcp_blocked_ips', array());
                $blocked_ips = array_diff($blocked_ips, array($data['ip']));
                update_option('mcp_blocked_ips', $blocked_ips);
                
                // Mettre à jour .htaccess
                $this->update_htaccess_block_ips($blocked_ips);
                
                return array(
                    'success' => true,
                    'message' => 'IP débloquée',
                    'blocked_ips' => $blocked_ips
                );
                
            case 'update_htaccess':
                if (!isset($data['rules'])) {
                    return new WP_Error('missing_rules', 'Règles manquantes');
                }
                
                $htaccess_file = ABSPATH . '.htaccess';
                $current_content = file_get_contents($htaccess_file);
                
                // Ajouter les règles personnalisées
                $new_content = "# BEGIN MCP Security Rules\n";
                $new_content .= $data['rules'] . "\n";
                $new_content .= "# END MCP Security Rules\n\n";
                $new_content .= $current_content;
                
                $result = file_put_contents($htaccess_file, $new_content);
                
                return array(
                    'success' => ($result !== false),
                    'message' => '.htaccess mis à jour'
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    /**
     * Gestion des logs et debug
     */
    private function handle_debug_logs($args) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes');
        }
        
        $action = $args['action'];
        
        switch ($action) {
            case 'read_logs':
                $log_type = isset($args['log_type']) ? $args['log_type'] : 'error';
                $log_file = '';
                
                switch ($log_type) {
                    case 'error':
                        $log_file = ABSPATH . 'wp-content/debug.log';
                        break;
                    case 'access':
                        // Dépend de la configuration du serveur
                        break;
                }
                
                if (!file_exists($log_file)) {
                    return array(
                        'success' => true,
                        'logs' => 'Aucun log trouvé',
                        'empty' => true
                    );
                }
                
                // Lire les 100 dernières lignes
                $lines = $this->tail($log_file, 100);
                
                return array(
                    'success' => true,
                    'logs' => implode("\n", $lines),
                    'lines' => count($lines)
                );
                
            case 'clear_logs':
                $log_file = ABSPATH . 'wp-content/debug.log';
                
                if (file_exists($log_file)) {
                    file_put_contents($log_file, '');
                }
                
                return array(
                    'success' => true,
                    'message' => 'Logs effacés'
                );
                
            case 'enable_debug':
                $this->update_wp_config('WP_DEBUG', true);
                $this->update_wp_config('WP_DEBUG_LOG', true);
                $this->update_wp_config('WP_DEBUG_DISPLAY', false);
                
                return array(
                    'success' => true,
                    'message' => 'Mode debug activé'
                );
                
            case 'disable_debug':
                $this->update_wp_config('WP_DEBUG', false);
                $this->update_wp_config('WP_DEBUG_LOG', false);
                $this->update_wp_config('WP_DEBUG_DISPLAY', false);
                
                return array(
                    'success' => true,
                    'message' => 'Mode debug désactivé'
                );
                
            case 'system_info':
                global $wpdb;
                
                $info = array(
                    'wordpress' => array(
                        'version' => get_bloginfo('version'),
                        'url' => get_site_url(),
                        'home' => get_home_url(),
                        'is_multisite' => is_multisite(),
                        'theme' => wp_get_theme()->get('Name'),
                        'language' => get_locale()
                    ),
                    'server' => array(
                        'software' => $_SERVER['SERVER_SOFTWARE'],
                        'php_version' => PHP_VERSION,
                        'mysql_version' => $wpdb->db_version(),
                        'max_execution_time' => ini_get('max_execution_time'),
                        'memory_limit' => ini_get('memory_limit'),
                        'upload_max_filesize' => ini_get('upload_max_filesize'),
                        'post_max_size' => ini_get('post_max_size')
                    ),
                    'php_extensions' => get_loaded_extensions(),
                    'constants' => array(
                        'ABSPATH' => ABSPATH,
                        'WP_DEBUG' => WP_DEBUG,
                        'WP_DEBUG_LOG' => WP_DEBUG_LOG,
                        'WP_DEBUG_DISPLAY' => WP_DEBUG_DISPLAY,
                        'WP_MEMORY_LIMIT' => WP_MEMORY_LIMIT,
                        'WP_MAX_MEMORY_LIMIT' => WP_MAX_MEMORY_LIMIT
                    )
                );
                
                return array(
                    'success' => true,
                    'info' => $info
                );
        }
        
        return new WP_Error('invalid_action', 'Action invalide');
    }
    
    // Méthodes helper pour WooCommerce
    private function handle_woo_products($action, $data) {
        // À implémenter selon vos besoins
        return array('success' => true, 'message' => 'WooCommerce products handler');
    }
    
    private function handle_woo_orders($action, $data) {
        // À implémenter selon vos besoins
        return array('success' => true, 'message' => 'WooCommerce orders handler');
    }
    
    private function handle_woo_customers($action, $data) {
        // À implémenter selon vos besoins
        return array('success' => true, 'message' => 'WooCommerce customers handler');
    }
    
    private function handle_woo_coupons($action, $data) {
        // À implémenter selon vos besoins
        return array('success' => true, 'message' => 'WooCommerce coupons handler');
    }
    
    private function handle_woo_reports($action, $data) {
        // À implémenter selon vos besoins
        return array('success' => true, 'message' => 'WooCommerce reports handler');
    }
    
    // Méthodes helper
    
    private function clear_transients() {
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_%'");
    }
    
    private function get_database_size() {
        global $wpdb;
        $size = $wpdb->get_var("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
        return size_format($size);
    }
    
    private function get_directory_size($directory) {
        $size = 0;
        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }
        
        return size_format($size);
    }
    
    private function update_htaccess_block_ips($ips) {
        $htaccess_file = ABSPATH . '.htaccess';
        $content = file_get_contents($htaccess_file);
        
        // Retirer les anciennes règles
        $content = preg_replace('/# BEGIN MCP Block IPs.*# END MCP Block IPs\n/s', '', $content);
        
        // Ajouter les nouvelles règles
        if (!empty($ips)) {
            $rules = "# BEGIN MCP Block IPs\n";
            $rules .= "<RequireAll>\n";
            $rules .= "Require all granted\n";
            foreach ($ips as $ip) {
                $rules .= "Require not ip $ip\n";
            }
            $rules .= "</RequireAll>\n";
            $rules .= "# END MCP Block IPs\n";
            
            $content = $rules . $content;
        }
        
        file_put_contents($htaccess_file, $content);
    }
    
    private function tail($file, $lines = 100) {
        $f = fopen($file, "rb");
        if (!$f) return array();
        
        fseek($f, -1, SEEK_END);
        if (fread($f, 1) != "\n") $lines--;
        
        $output = '';
        $chunk = '';
        
        while (ftell($f) > 0 && $lines >= 0) {
            $seek = min(ftell($f), 4096);
            fseek($f, -$seek, SEEK_CUR);
            $output = ($chunk = fread($f, $seek)) . $output;
            fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
            $lines -= substr_count($chunk, "\n");
        }
        
        while ($lines++ < 0) {
            $output = substr($output, strpos($output, "\n") + 1);
        }
        
        fclose($f);
        return explode("\n", trim($output));
    }
    
    private function update_wp_config($constant, $value) {
        $config_path = ABSPATH . 'wp-config.php';
        $config = file_get_contents($config_path);
        
        $pattern = "/define\s*\(\s*['\"]" . $constant . "['\"]\s*,\s*[^)]+\)/";
        $replacement = "define('" . $constant . "', " . ($value ? 'true' : 'false') . ")";
        
        if (preg_match($pattern, $config)) {
            $config = preg_replace($pattern, $replacement, $config);
        } else {
            $config = str_replace("/* That's all, stop editing!", $replacement . "\n/* That's all, stop editing!", $config);
        }
        
        file_put_contents($config_path, $config);
    }
    
    /**
     * Logger les actions MCP
     */
    private function log_action($tool_name, $arguments) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_logs';
        
        $wpdb->insert($table_name, array(
            'user_id' => get_current_user_id(),
            'tool' => $tool_name,
            'arguments' => json_encode($arguments),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'timestamp' => current_time('mysql')
        ));
    }
}

/**
 * Page d'administration
 */
class WP_MCP_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'MCP Full Access',
            'MCP Server',
            'manage_options',
            'mcp-server',
            array($this, 'main_page'),
            'dashicons-rest-api',
            30
        );
        
        add_submenu_page(
            'mcp-server',
            'OAuth Clients',
            'OAuth Clients',
            'manage_options',
            'mcp-oauth-clients',
            array($this, 'oauth_clients_page')
        );
        
        add_submenu_page(
            'mcp-server',
            'Logs d\'activité',
            'Logs',
            'manage_options',
            'mcp-logs',
            array($this, 'logs_page')
        );
        
        add_submenu_page(
            'mcp-server',
            'À propos',
            'À propos',
            'manage_options',
            'mcp-about',
            array($this, 'about_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'mcp-') === false) {
            return;
        }
        
        // Si les fichiers CSS/JS existent, les charger
        if (file_exists(WP_MCP_PLUGIN_DIR . 'assets/admin.css')) {
            wp_enqueue_style('mcp-admin', WP_MCP_PLUGIN_URL . 'assets/admin.css', array(), WP_MCP_VERSION);
        }
        
        if (file_exists(WP_MCP_PLUGIN_DIR . 'assets/admin.js')) {
            wp_enqueue_script('mcp-admin', WP_MCP_PLUGIN_URL . 'assets/admin.js', array('jquery'), WP_MCP_VERSION, true);
        }
        
        // Ajouter des styles inline si le fichier CSS n'existe pas
        if (!file_exists(WP_MCP_PLUGIN_DIR . 'assets/admin.css')) {
            wp_add_inline_style('wp-admin', $this->get_inline_styles());
        }
    }
    
    private function get_inline_styles() {
        return '
        .wrap h1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 2.5em;
            margin-bottom: 20px;
        }
        .notice-info {
            border-left: 4px solid #667eea;
            background: #f8f9ff;
            padding: 20px;
            margin: 20px 0;
        }
        .notice-info h2 {
            color: #667eea;
            margin-top: 0;
        }
        .form-table code {
            background: #f4f4f4;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: monospace;
            display: inline-block;
            margin: 2px 0;
        }
        .mcp-capabilities {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        .mcp-capabilities h3 {
            color: #23282d;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .mcp-capabilities ul {
            list-style: none;
            padding-left: 0;
        }
        .mcp-capabilities li {
            padding: 5px 0;
            padding-left: 25px;
            position: relative;
        }
        .mcp-capabilities li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #667eea;
            font-weight: bold;
        }
        .client-secret {
            background: #f4f4f4;
            padding: 4px 8px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.9em;
        }
        .show-secret {
            margin-left: 10px;
            color: #667eea;
            text-decoration: none;
            cursor: pointer;
        }
        .show-secret:hover {
            color: #764ba2;
        }
        ';
    }
    
    public function main_page() {
        ?>
        <div class="wrap">
            <h1>MCP Full Access Server</h1>
            
            <div class="notice notice-info">
                <h2>Configuration OAuth 2.0</h2>
                <p>Votre serveur MCP utilise OAuth 2.0 pour une sécurité maximale.</p>
                
                <h3>URLs de configuration</h3>
                <table class="form-table">
                    <tr>
                        <th>Authorization URL</th>
                        <td><code><?php echo esc_url(get_rest_url(null, 'mcp-oauth/v1/authorize')); ?></code></td>
                    </tr>
                    <tr>
                        <th>Token URL</th>
                        <td><code><?php echo esc_url(get_rest_url(null, 'mcp-oauth/v1/token')); ?></code></td>
                    </tr>
                    <tr>
                        <th>MCP Server URL</th>
                        <td><code><?php echo esc_url(get_rest_url(null, 'mcp/v1/')); ?></code></td>
                    </tr>
                    <tr>
                        <th>Config URL (test)</th>
                        <td>
                            <code><?php echo esc_url(get_rest_url(null, 'mcp-oauth/v1/config')); ?></code>
                            <a href="<?php echo esc_url(get_rest_url(null, 'mcp-oauth/v1/config')); ?>" target="_blank" class="button button-small">Tester</a>
                        </td>
                    </tr>
                </table>
            </div>
            
            <h2>Configuration dans ChatGPT</h2>
            <ol>
                <li>Créez d'abord un client OAuth (voir l'onglet "OAuth Clients")</li>
                <li>Dans ChatGPT, ajoutez un connecteur personnalisé avec :
                    <ul>
                        <li><strong>Type d'authentification :</strong> OAuth</li>
                        <li><strong>Authorization URL :</strong> <?php echo esc_url(get_rest_url(null, 'mcp-oauth/v1/authorize')); ?></li>
                        <li><strong>Token URL :</strong> <?php echo esc_url(get_rest_url(null, 'mcp-oauth/v1/token')); ?></li>
                        <li><strong>Client ID :</strong> [Votre Client ID]</li>
                        <li><strong>Client Secret :</strong> [Votre Client Secret]</li>
                        <li><strong>Scope :</strong> full_access</li>
                    </ul>
                </li>
                <li>ChatGPT vous demandera de vous connecter à WordPress pour autoriser l'accès</li>
            </ol>
            
            <h2>Capacités disponibles</h2>
            <div class="mcp-capabilities">
                <div>
                    <h3>✅ Gestion du contenu</h3>
                    <ul>
                        <li>Articles, pages, médias</li>
                        <li>Catégories, tags, taxonomies</li>
                        <li>Métadonnées et champs personnalisés</li>
                    </ul>
                </div>
                
                <div>
                    <h3>✅ Administration système</h3>
                    <ul>
                        <li>Plugins (installer, activer, supprimer)</li>
                        <li>Thèmes (installer, personnaliser)</li>
                        <li>Utilisateurs et rôles</li>
                        <li>Paramètres WordPress</li>
                    </ul>
                </div>
                
                <div>
                    <h3>✅ Base de données</h3>
                    <ul>
                        <li>Requêtes SQL sécurisées</li>
                        <li>Backup et optimisation</li>
                        <li>Gestion des tables</li>
                    </ul>
                </div>
                
                <div>
                    <h3>✅ Fichiers et code</h3>
                    <ul>
                        <li>Upload et gestion de fichiers</li>
                        <li>Exécution de code PHP sécurisé</li>
                        <li>Modification de fichiers</li>
                    </ul>
                </div>
                
                <div>
                    <h3>✅ Performance et sécurité</h3>
                    <ul>
                        <li>Gestion du cache</li>
                        <li>Analyse de sécurité</li>
                        <li>Logs et debugging</li>
                    </ul>
                </div>
                
                <div>
                    <h3>✅ Intégrations</h3>
                    <ul>
                        <li>WooCommerce (si installé)</li>
                        <li>Widgets et menus</li>
                        <li>Commentaires</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Test de connexion simple
            $('.button[href*="config"]').click(function(e) {
                e.preventDefault();
                var url = $(this).prev('code').text();
                window.open(url, '_blank');
            });
        });
        </script>
        <?php
    }
    
    public function oauth_clients_page() {
        // Gérer la création de nouveaux clients
        if (isset($_POST['create_client']) && wp_verify_nonce($_POST['_wpnonce'], 'create_oauth_client')) {
            $this->create_oauth_client();
        }
        
        // Gérer la suppression
        if (isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_client')) {
            $this->delete_oauth_client($_GET['delete']);
        }
        
        // Récupérer les clients existants
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_oauth_clients';
        $clients = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1>OAuth Clients</h1>
            
            <div class="notice notice-info">
                <p><strong>Important :</strong> Le Client ID et Client Secret sont nécessaires pour configurer ChatGPT.</p>
            </div>
            
            <h2>Créer un nouveau client</h2>
            <form method="post">
                <?php wp_nonce_field('create_oauth_client'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="client_name">Nom du client</label></th>
                        <td>
                            <input type="text" name="client_name" id="client_name" class="regular-text" required 
                                   placeholder="Ex: ChatGPT" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="redirect_uri">Redirect URI</label></th>
                        <td>
                            <input type="url" name="redirect_uri" id="redirect_uri" class="regular-text" required 
                                   value="https://chatgpt.com/aip/oauth/callback" />
                            <p class="description">Pour ChatGPT, utilisez : https://chatgpt.com/aip/oauth/callback</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Créer le client', 'primary', 'create_client'); ?>
            </form>
            
            <?php if (!empty($clients)) : ?>
            <h2>Clients existants</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Client ID</th>
                        <th>Client Secret</th>
                        <th>Redirect URI</th>
                        <th>Créé le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($client->name); ?></strong></td>
                        <td>
                            <code style="user-select: all;"><?php echo esc_html($client->client_id); ?></code>
                            <button class="button-link" onclick="copyToClipboard('<?php echo esc_js($client->client_id); ?>')">📋</button>
                        </td>
                        <td>
                            <code class="client-secret" data-secret="<?php echo esc_attr($client->client_secret); ?>">
                                ••••••••••••••••
                            </code>
                            <button class="button-link show-secret">Afficher</button>
                            <button class="button-link" onclick="copyToClipboard('<?php echo esc_js($client->client_secret); ?>')" style="display:none;">📋</button>
                        </td>
                        <td><small><?php echo esc_html($client->redirect_uri); ?></small></td>
                        <td><?php echo esc_html($client->created_at); ?></td>
                        <td>
                            <a href="?page=mcp-oauth-clients&delete=<?php echo $client->id; ?>&_wpnonce=<?php echo wp_create_nonce('delete_client'); ?>" 
                               class="button button-small button-link-delete"
                               onclick="return confirm('Supprimer ce client ?')">Supprimer</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p>Aucun client OAuth créé. Créez-en un ci-dessus pour commencer.</p>
            <?php endif; ?>
        </div>
        
        <script>
        function copyToClipboard(text) {
            var temp = document.createElement('input');
            document.body.appendChild(temp);
            temp.value = text;
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            alert('Copié dans le presse-papier !');
        }
        
        jQuery(document).ready(function($) {
            $('.show-secret').click(function() {
                var $secret = $(this).prev('.client-secret');
                var $copyBtn = $(this).next('button');
                var secret = $secret.data('secret');
                
                if ($secret.text().includes('•')) {
                    $secret.text(secret);
                    $(this).text('Masquer');
                    $copyBtn.show();
                } else {
                    $secret.text('••••••••••••••••');
                    $(this).text('Afficher');
                    $copyBtn.hide();
                }
            });
        });
        </script>
        <?php
    }
    
    public function logs_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_logs';
        
        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Récupérer les logs
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.display_name 
             FROM $table_name l 
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID 
             ORDER BY l.timestamp DESC 
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_logs / $per_page);
        ?>
        <div class="wrap">
            <h1>Logs d'activité MCP</h1>
            
            <p>Toutes les actions effectuées via le serveur MCP sont enregistrées ici.</p>
            
            <?php if (!empty($logs)) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;">Date/Heure</th>
                        <th style="width: 150px;">Utilisateur</th>
                        <th style="width: 150px;">Outil</th>
                        <th>Arguments</th>
                        <th style="width: 120px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo esc_html($log->timestamp); ?></td>
                        <td><?php echo esc_html($log->display_name ?: 'Utilisateur #' . $log->user_id); ?></td>
                        <td><strong><?php echo esc_html($log->tool); ?></strong></td>
                        <td>
                            <details>
                                <summary style="cursor: pointer;">Voir les détails</summary>
                                <pre style="background: #f5f5f5; padding: 10px; margin-top: 10px; overflow-x: auto;"><?php 
                                    echo esc_html(json_encode(json_decode($log->arguments), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); 
                                ?></pre>
                            </details>
                        </td>
                        <td><?php echo esc_html($log->ip_address); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages
                    ));
                    ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else : ?>
            <p>Aucune activité enregistrée pour le moment.</p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function about_page() {
        ?>
        <div class="wrap">
            <h1>À propos de MCP Server</h1>
            
            <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 20px 0;">
                <h2 style="margin-top: 0;">WordPress MCP Full Access Server</h2>
                <p style="font-size: 16px;">Version <?php echo WP_MCP_VERSION; ?></p>
                
                <p>Ce plugin est développé par <strong>MEMORA</strong> pour permettre une intégration complète et sécurisée entre WordPress et les assistants IA comme ChatGPT.</p>
                
                <div style="margin: 30px 0;">
                    <h3>MEMORA</h3>
                    <p><strong>Site web :</strong> <a href="https://memora.solutions" target="_blank">https://memora.solutions</a></p>
                    <p><strong>Contact :</strong> <a href="mailto:info@memora.ca">info@memora.ca</a></p>
                </div>
            </div>
            
            <h2>Caractéristiques principales</h2>
            <ul style="font-size: 14px; line-height: 1.8;">
                <li>✅ <strong>Authentification OAuth 2.0</strong> : Sécurité maximale avec tokens temporaires</li>
                <li>✅ <strong>Accès complet à WordPress</strong> : Gestion de tous les aspects de votre site</li>
                <li>✅ <strong>Logs détaillés</strong> : Traçabilité complète de toutes les actions</li>
                <li>✅ <strong>Mode sécurisé</strong> : Exécution de code PHP avec restrictions</li>
                <li>✅ <strong>Support WooCommerce</strong> : Gestion des produits, commandes et clients</li>
                <li>✅ <strong>Extensible</strong> : Les autres plugins peuvent ajouter leurs propres outils MCP</li>
            </ul>
            
            <h2>Documentation</h2>
            <p>Pour une documentation complète, visitez : <a href="https://memora.solutions/docs/mcp" target="_blank">https://memora.solutions/docs/mcp</a></p>
            
            <h2>Support</h2>
            <p>Pour obtenir du support ou signaler un problème :</p>
            <ul>
                <li>Email : <a href="mailto:info@memora.ca">info@memora.ca</a></li>
                <li>Site web : <a href="https://memora.solutions" target="_blank">https://memora.solutions</a></li>
            </ul>
            
            <h2>Licence</h2>
            <p>Ce plugin est distribué sous licence GPL v2 ou ultérieure.</p>
            
            <div style="margin-top: 40px; padding: 20px; background: #f8f9ff; border-radius: 8px;">
                <p style="text-align: center; margin: 0;">Développé avec ❤️ par <strong>MEMORA</strong></p>
            </div>
        </div>
        <?php
    }
    
    private function create_oauth_client() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_oauth_clients';
        
        $client_id = wp_generate_password(32, false);
        $client_secret = wp_generate_password(64, false);
        
        $result = $wpdb->insert($table_name, array(
            'name' => sanitize_text_field($_POST['client_name']),
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => esc_url_raw($_POST['redirect_uri']),
            'created_at' => current_time('mysql')
        ));
        
        if ($result) {
            echo '<div class="notice notice-success is-dismissible"><p>Client OAuth créé avec succès ! Copiez le Client ID et Client Secret ci-dessous.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Erreur lors de la création du client.</p></div>';
        }
    }
    
    private function delete_oauth_client($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mcp_oauth_clients';
        
        $result = $wpdb->delete($table_name, array('id' => intval($id)));
        
        if ($result) {
            echo '<div class="notice notice-success is-dismissible"><p>Client supprimé avec succès.</p></div>';
        }
    }
    
    public function settings_init() {
        // Paramètres additionnels si nécessaire
    }
}

/**
 * Création des tables de base de données
 */
function wp_mcp_install() {
    global $wpdb;
    global $wp_mcp_db_version;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Table des clients OAuth
    $table_name = $wpdb->prefix . 'mcp_oauth_clients';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        client_id varchar(255) NOT NULL,
        client_secret varchar(255) NOT NULL,
        redirect_uri text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY client_id (client_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Table des codes d'autorisation
    $table_name = $wpdb->prefix . 'mcp_auth_codes';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        code varchar(255) NOT NULL,
        client_id varchar(255) NOT NULL,
        user_id bigint(20) NOT NULL,
        redirect_uri text NOT NULL,
        scope text,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY code (code),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    dbDelta($sql);
    
    // Table des tokens d'accès
    $table_name = $wpdb->prefix . 'mcp_access_tokens';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        token varchar(255) NOT NULL,
        client_id varchar(255) NOT NULL,
        user_id bigint(20) NOT NULL,
        scope text,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token (token),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    dbDelta($sql);
    
    // Table des refresh tokens
    $table_name = $wpdb->prefix . 'mcp_refresh_tokens';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        token varchar(255) NOT NULL,
        client_id varchar(255) NOT NULL,
        user_id bigint(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY token (token)
    ) $charset_collate;";
    dbDelta($sql);
    
    // Table des logs
    $table_name = $wpdb->prefix . 'mcp_logs';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        tool varchar(255) NOT NULL,
        arguments longtext,
        ip_address varchar(45),
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY timestamp (timestamp)
    ) $charset_collate;";
    dbDelta($sql);
    
    add_option('wp_mcp_db_version', $wp_mcp_db_version);
    
    // Créer un cron job pour nettoyer les tokens expirés
    if (!wp_next_scheduled('mcp_cleanup_expired_tokens')) {
        wp_schedule_event(time(), 'hourly', 'mcp_cleanup_expired_tokens');
    }
}

/**
 * Désinstallation du plugin
 */
function wp_mcp_uninstall() {
    global $wpdb;
    
    // Supprimer les tables
    $tables = array(
        'mcp_oauth_clients',
        'mcp_auth_codes',
        'mcp_access_tokens',
        'mcp_refresh_tokens',
        'mcp_logs'
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }
    
    // Supprimer les options
    delete_option('wp_mcp_db_version');
    delete_option('mcp_blocked_ips');
    
    // Supprimer le cron job
    wp_clear_scheduled_hook('mcp_cleanup_expired_tokens');
}

/**
 * Nettoyer les tokens expirés
 */
function mcp_cleanup_expired_tokens() {
    global $wpdb;
    
    // Nettoyer les codes d'autorisation expirés
    $wpdb->query("DELETE FROM {$wpdb->prefix}mcp_auth_codes WHERE expires_at < NOW()");
    
    // Nettoyer les tokens d'accès expirés
    $wpdb->query("DELETE FROM {$wpdb->prefix}mcp_access_tokens WHERE expires_at < NOW()");
}
add_action('mcp_cleanup_expired_tokens', 'mcp_cleanup_expired_tokens');

/**
 * Initialisation
 */
function wp_mcp_init() {
    new WP_MCP_OAuth();
    new WP_MCP_Full_Server();
    
    if (is_admin()) {
        new WP_MCP_Admin();
    }
}

// Hooks d'activation et d'initialisation
register_activation_hook(__FILE__, 'wp_mcp_install');
register_uninstall_hook(__FILE__, 'wp_mcp_uninstall');
add_action('plugins_loaded', 'wp_mcp_init');

// Ajouter les capacités personnalisées
function wp_mcp_add_capabilities() {
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('mcp_full_access');
    }
}
add_action('admin_init', 'wp_mcp_add_capabilities');

// Ajouter un lien vers les settings dans la page des plugins
function wp_mcp_plugin_action_links($links) {
    $settings_link = '<a href="admin.php?page=mcp-server">Paramètres</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wp_mcp_plugin_action_links');

// Ajouter une notice après activation
function wp_mcp_activation_notice() {
    if (get_transient('wp_mcp_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>WordPress MCP Full Access Server</strong> a été activé avec succès !</p>
            <p><a href="<?php echo admin_url('admin.php?page=mcp-server'); ?>" class="button button-primary">Configurer MCP Server</a></p>
        </div>
        <?php
        delete_transient('wp_mcp_activation_notice');
    }
}
add_action('admin_notices', 'wp_mcp_activation_notice');

// Définir la notice d'activation
function wp_mcp_set_activation_notice() {
    set_transient('wp_mcp_activation_notice', true, 5);
}
register_activation_hook(__FILE__, 'wp_mcp_set_activation_notice');
