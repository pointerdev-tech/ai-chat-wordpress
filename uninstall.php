<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$pointerdevai_chat_runtime_keys = get_option('pointerai_chat_runtime_keys', []);
if (!is_array($pointerdevai_chat_runtime_keys)) {
    $pointerdevai_chat_runtime_keys = [];
}

foreach ($pointerdevai_chat_runtime_keys as $pointerdevai_chat_runtime_key) {
    if (is_string($pointerdevai_chat_runtime_key) && $pointerdevai_chat_runtime_key !== '') {
        delete_transient($pointerdevai_chat_runtime_key);
    }
}

delete_option('pointerai_chat_settings');
delete_option('pointerai_chat_runtime_keys');

if (is_multisite()) {
    delete_site_option('pointerai_chat_settings');
    delete_site_option('pointerai_chat_runtime_keys');
}
