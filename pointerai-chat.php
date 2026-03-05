<?php
/**
 * Plugin Name: PointerAI Chat
 * Plugin URI: https://github.com/pointerdev/pointerai
 * Description: Connect WordPress to PointerAI chat APIs using your agent publishable key.
 * Version: 0.1.0
 * Author: PointerDev
 * Author URI: https://pointer.dev
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: pointerai-chat
 */

if (!defined('ABSPATH')) {
    exit;
}

define('POINTERAI_CHAT_PLUGIN_VERSION', '0.1.0');
define('POINTERAI_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POINTERAI_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once POINTERAI_CHAT_PLUGIN_DIR . 'includes/class-pointerai-client.php';
require_once POINTERAI_CHAT_PLUGIN_DIR . 'includes/class-pointerai-plugin.php';

PointerAI_Plugin::boot();
