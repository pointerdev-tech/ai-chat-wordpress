<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$pointerai_chat_runtime_keys = get_option('pointerai_chat_runtime_keys', []);
if (!is_array($pointerai_chat_runtime_keys)) {
    $pointerai_chat_runtime_keys = [];
}

foreach ($pointerai_chat_runtime_keys as $pointerai_chat_runtime_key) {
    if (is_string($pointerai_chat_runtime_key) && $pointerai_chat_runtime_key !== '') {
        delete_transient($pointerai_chat_runtime_key);
    }
}

delete_option('pointerai_chat_settings');
delete_option('pointerai_chat_runtime_keys');

if (is_multisite()) {
    delete_site_option('pointerai_chat_settings');
    delete_site_option('pointerai_chat_runtime_keys');
}
