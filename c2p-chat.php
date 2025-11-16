<?php
/**
 * Plugin Name: C2P Competition Chat
 * Description: Adds an ephemeral match chat to competition pages so players can coordinate games while a match is active.
 * Version: 1.0.0
 * Author: OpenAI Assistant
 * License: GPL-2.0-or-later
 * Text Domain: c2p-chat
 */

defined( 'ABSPATH' ) || exit;

define( 'C2P_CHAT_VERSION', '1.0.0' );
define( 'C2P_CHAT_FILE', __FILE__ );
define( 'C2P_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'C2P_CHAT_URL', plugin_dir_url( __FILE__ ) );

require_once C2P_CHAT_DIR . 'includes/class-c2p-chat-plugin.php';

add_action(
    'plugins_loaded',
    static function () {
        C2P_Chat_Plugin::instance();
    }
);
