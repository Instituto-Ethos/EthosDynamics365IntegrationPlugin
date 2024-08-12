<?php
/**
 * Plugin Name:       Ethos Dynamics 365 Integration
 * Plugin URI:        https://hacklab.com.br/
 * Description:       Turn integration with Dynamics 365.
 * Version:           0.3.1
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Hacklab/
 * Author URI:        https://hacklab.com.br/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://hacklab.com.br/plugins/ethos-crm-integration
 * Text Domain:       hacklabr
 * Domain Path:       /languages
 */

function ethos_d365i_plugin_activate() {
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'ethos_d365i_plugin_activate' );

define( 'ETHOS_D365I_INTEGRATION_VERSION', '0.3.1' );
define( 'ETHOS_D365I_INTEGRATION_PATH', plugins_url( '/', __FILE__ ) );

require_once( 'vendor/autoload.php' );
require_once( 'includes/check-plugin-dependencies.php' );

if ( hacklabr\check_plugin_dependencies() ) {
    require_once( 'includes/helpers.php' );
    require_once( 'includes/events.php' );
    require_once( 'includes/admin-menu.php' );
    require_once( 'includes/dynamics365-data-sender.php' );
    require_once( 'includes/cron.php' );
}

