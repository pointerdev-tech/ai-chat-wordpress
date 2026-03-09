<?php
/**
 * Plugin Name: PointerDev AI Chat
 * Plugin URI: https://pointerdev.ai/integration/wordpress
 * Description: Connect WordPress to PointerDev AI chat APIs using your agent publishable key.
 * Version: 0.1.0
 * Author: PointerDev
 * Author URI: https://pointer.dev
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: pointerdev-ai-chat
 */

if (!defined('ABSPATH')) {
    exit;
}

define('POINTERDEVAI_CHAT_PLUGIN_VERSION', '0.1.0');
define('POINTERDEVAI_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POINTERDEVAI_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Keep legacy constant names available for existing custom code.
if (!defined('POINTERAI_CHAT_PLUGIN_VERSION')) {
    define('POINTERAI_CHAT_PLUGIN_VERSION', POINTERDEVAI_CHAT_PLUGIN_VERSION);
}
if (!defined('POINTERAI_CHAT_PLUGIN_DIR')) {
    define('POINTERAI_CHAT_PLUGIN_DIR', POINTERDEVAI_CHAT_PLUGIN_DIR);
}
if (!defined('POINTERAI_CHAT_PLUGIN_URL')) {
    define('POINTERAI_CHAT_PLUGIN_URL', POINTERDEVAI_CHAT_PLUGIN_URL);
}

require_once POINTERDEVAI_CHAT_PLUGIN_DIR . 'includes/class-pointerai-client.php';
require_once POINTERDEVAI_CHAT_PLUGIN_DIR . 'includes/class-pointerai-plugin.php';

PointerDevAI_Plugin::boot();
