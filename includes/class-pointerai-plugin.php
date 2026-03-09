<?php

if (!defined('ABSPATH')) {
    exit;
}

class PointerAI_Plugin
{
    private const OPTION_KEY = 'pointerai_chat_settings';
    private const RUNTIME_KEYS_OPTION = 'pointerai_chat_runtime_keys';
    private const SETTINGS_GROUP = 'pointerai_chat_settings_group';
    private const NONCE_ACTION = 'pointerai_chat_send';
    private const NONCE_WIDGET_TOKEN = 'pointerai_widget_token';
    private const ANON_COOKIE = 'pointerai_chat_anon_uid';
    private const RUNTIME_REFRESH_LEEWAY_SECONDS = 5;

    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter(
            'plugin_action_links_' . plugin_basename(POINTERAI_CHAT_PLUGIN_DIR . 'pointerdev-ai-chat.php'),
            [$this, 'add_plugin_action_links']
        );

        add_shortcode('pointerai_chat', [$this, 'render_chat_shortcode']);

        add_action('wp_ajax_pointerai_chat_send', [$this, 'ajax_chat_send']);
        add_action('wp_ajax_nopriv_pointerai_chat_send', [$this, 'ajax_chat_send']);
        add_action('wp_ajax_pointerai_widget_token', [$this, 'ajax_widget_token']);
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=pointerdev-ai-chat')),
            esc_html__('Settings', 'pointerdev-ai-chat')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    public function register_admin_menu(): void
    {
        add_options_page(
            __('PointerDev AI Chat', 'pointerdev-ai-chat'),
            __('PointerDev AI Chat', 'pointerdev-ai-chat'),
            'manage_options',
            'pointerdev-ai-chat',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            [$this, 'sanitize_settings']
        );

        add_settings_section(
            'pointerai_chat_main',
            __('PointerAI Connection', 'pointerdev-ai-chat'),
            static function (): void {
                echo '<p>' . esc_html__('Use your agent project ID and publishable key from PointerAI.', 'pointerdev-ai-chat') . '</p>';
            },
            'pointerdev-ai-chat'
        );

        add_settings_field('base_url', __('API Base URL', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'base_url',
            'placeholder' => 'https://api.yourdomain.com',
        ]);

        add_settings_field('project_id', __('Project ID', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'project_id',
            'placeholder' => 'project-uuid',
        ]);

        add_settings_field('publishable_key', __('Publishable Key', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'publishable_key',
            'placeholder' => 'pk_...',
        ]);

        add_settings_field('secret_key', __('Secret Key', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'secret_key',
            'placeholder' => 'sk_...',
            'type' => 'password',
        ]);

        add_settings_field('auth_mode', __('Auth Mode', 'pointerdev-ai-chat'), [$this, 'render_auth_mode_field'], 'pointerdev-ai-chat', 'pointerai_chat_main');

        add_settings_field('metadata_source', __('Metadata Source', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'metadata_source',
            'placeholder' => 'wordpress-plugin',
        ]);

        add_settings_field('token_ttl_minutes', __('End-user token TTL (min)', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'token_ttl_minutes',
            'placeholder' => '60',
        ]);

        add_settings_field('widget_script_url', __('Widget Script URL', 'pointerdev-ai-chat'), [$this, 'render_text_field'], 'pointerdev-ai-chat', 'pointerai_chat_main', [
            'key' => 'widget_script_url',
            'placeholder' => 'https://cdn.jsdelivr.net/npm/@pointerdev/pointerai-widget@latest/dist/pointerai-widget.js',
        ]);
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('PointerDev AI Chat', 'pointerdev-ai-chat') . '</h1>';
        echo '<p>Shortcode: <code>[pointerai_chat]</code></p>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections('pointerdev-ai-chat');
        submit_button(__('Save Settings', 'pointerdev-ai-chat'));
        echo '</form>';
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function sanitize_settings(array $raw): array
    {
        $defaults = $this->get_default_settings();
        $existing = $this->get_settings();
        $base_url = isset($raw['base_url']) ? esc_url_raw((string) $raw['base_url']) : '';
        $incoming_secret = isset($raw['secret_key']) ? sanitize_text_field((string) $raw['secret_key']) : '';
        $secret_key = $incoming_secret;
        if ($incoming_secret === '' && isset($existing['secret_key']) && is_scalar($existing['secret_key'])) {
            // Keep existing secret when password field is left blank on save.
            $secret_key = (string) $existing['secret_key'];
        }

        $settings = [
            'base_url' => $this->normalize_base_url($base_url, (string) $defaults['base_url']),
            'project_id' => isset($raw['project_id']) ? sanitize_text_field((string) $raw['project_id']) : '',
            'publishable_key' => isset($raw['publishable_key']) ? sanitize_text_field((string) $raw['publishable_key']) : '',
            'secret_key' => $secret_key,
            'auth_mode' => isset($raw['auth_mode']) ? sanitize_text_field((string) $raw['auth_mode']) : 'auto',
            'metadata_source' => isset($raw['metadata_source']) ? sanitize_text_field((string) $raw['metadata_source']) : 'wordpress-plugin',
            'token_ttl_minutes' => isset($raw['token_ttl_minutes']) ? (int) $raw['token_ttl_minutes'] : (int) $defaults['token_ttl_minutes'],
            'widget_script_url' => isset($raw['widget_script_url']) ? esc_url_raw((string) $raw['widget_script_url']) : $defaults['widget_script_url'],
            'timeout' => $defaults['timeout'],
        ];

        if (!in_array($settings['auth_mode'], ['auto', 'anonymous', 'end_user'], true)) {
            $settings['auth_mode'] = 'auto';
        }

        if ($settings['metadata_source'] === '') {
            $settings['metadata_source'] = 'wordpress-plugin';
        }

        if ($settings['token_ttl_minutes'] < 1) {
            $settings['token_ttl_minutes'] = 60;
        }
        if ($settings['token_ttl_minutes'] > 24 * 60) {
            $settings['token_ttl_minutes'] = 24 * 60;
        }

        if ($settings['widget_script_url'] === '') {
            $settings['widget_script_url'] = $defaults['widget_script_url'];
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function render_text_field(array $args): void
    {
        $settings = $this->get_settings();
        $key = (string) ($args['key'] ?? '');
        $placeholder = (string) ($args['placeholder'] ?? '');
        $type = (string) ($args['type'] ?? 'text');
        $value = isset($settings[$key]) ? (string) $settings[$key] : '';
        if ($type === '') {
            $type = 'text';
        }
        if ($type === 'password') {
            // Do not render secrets back into HTML.
            $value = '';
        }

        printf(
            '<input type="%5$s" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" autocomplete="off"/>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_attr($value),
            esc_attr($placeholder),
            esc_attr($type)
        );
        if ($type === 'password') {
            echo '<p class="description">' . esc_html__('Leave blank to keep current value.', 'pointerdev-ai-chat') . '</p>';
        }
    }

    public function render_auth_mode_field(): void
    {
        $settings = $this->get_settings();
        $mode = (string) ($settings['auth_mode'] ?? 'auto');

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[auth_mode]">';
        echo '<option value="auto" ' . selected($mode, 'auto', false) . '>' . esc_html__('Auto (follow project behavior)', 'pointerdev-ai-chat') . '</option>';
        echo '<option value="anonymous" ' . selected($mode, 'anonymous', false) . '>' . esc_html__('Anonymous users', 'pointerdev-ai-chat') . '</option>';
        echo '<option value="end_user" ' . selected($mode, 'end_user', false) . '>' . esc_html__('End-user token required', 'pointerdev-ai-chat') . '</option>';
        echo '</select>';
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function render_chat_shortcode(array $atts = []): string
    {
        $settings = $this->get_settings();
        $root_id = 'pointerai-chat-' . wp_generate_uuid4();
        $token_nonce = wp_create_nonce(self::NONCE_WIDGET_TOKEN);
        $chat_nonce = wp_create_nonce(self::NONCE_ACTION);
        $auth_mode = (string) ($settings['auth_mode'] ?? 'auto');
        $script_url = (string) ($settings['widget_script_url'] ?? '');
        $script_url = esc_url_raw($script_url);

        $widget_config = [
            'apiBaseUrl' => (string) ($settings['base_url'] ?? ''),
            'projectId' => (string) ($settings['project_id'] ?? ''),
            'publishableKey' => (string) ($settings['publishable_key'] ?? ''),
            'title' => __('PointerAI Assistant', 'pointerdev-ai-chat'),
            'subtitle' => __('WordPress integration', 'pointerdev-ai-chat'),
            'launcherLabel' => __('Chat', 'pointerdev-ai-chat'),
            'metadata' => [
                'source' => (string) ($settings['metadata_source'] ?? 'wordpress-plugin'),
                'channel' => 'wordpress',
            ],
        ];

        $should_use_server_token = $auth_mode === 'end_user' || ($auth_mode === 'auto' && is_user_logged_in());

        if (!wp_http_validate_url($script_url)) {
            if (current_user_can('manage_options')) {
                return '<p>' . esc_html__('PointerDev AI Chat widget URL is invalid. Please update the plugin settings.', 'pointerdev-ai-chat') . '</p>';
            }
            return '';
        }

        $widget_handle = 'pointerdev-ai-chat-widget';
        wp_enqueue_script($widget_handle, $script_url, [], POINTERAI_CHAT_PLUGIN_VERSION, true);

        $inline_payload = [
            'config' => $widget_config,
            'shouldUseServerToken' => $should_use_server_token,
            'tokenAction' => 'pointerai_widget_token',
            'tokenNonce' => $token_nonce,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'messages' => [
                'tokenMintFailed' => __('Token mint failed.', 'pointerdev-ai-chat'),
                'initFailed' => __('PointerAI widget init failed', 'pointerdev-ai-chat'),
            ],
        ];

        $inline_script = '(function(){'
            . 'var payload=' . wp_json_encode($inline_payload) . '||{};'
            . 'var config=payload.config||{};'
            . 'if(payload.shouldUseServerToken){'
                . 'config.getEndUserToken=async function(){'
                    . 'var body=new URLSearchParams();'
                    . 'body.set("action",payload.tokenAction||"pointerai_widget_token");'
                    . 'body.set("nonce",payload.tokenNonce||"");'
                    . 'var response=await fetch(payload.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString(),credentials:"same-origin"});'
                    . 'var json=null;'
                    . 'try{json=await response.json();}catch(e){json=null;}'
                    . 'if(!json||!json.success||!json.data||!json.data.token){'
                        . 'var message=(json&&json.data&&json.data.message)?json.data.message:(payload.messages&&payload.messages.tokenMintFailed?payload.messages.tokenMintFailed:"Token mint failed.");'
                        . 'throw new Error(message);'
                    . '}'
                    . 'return json.data.token;'
                . '};'
            . '}'
            . 'if(!window.PointerAIWidget||typeof window.PointerAIWidget.init!=="function"){return;}'
            . 'window.PointerAIWidget.init(config).catch(function(err){'
                . 'if(window.console&&typeof window.console.error==="function"){'
                    . 'var msg=(payload.messages&&payload.messages.initFailed)?payload.messages.initFailed:"PointerAI widget init failed";'
                    . 'window.console.error(msg,err);'
                . '}'
            . '});'
        . '})();';
        wp_add_inline_script($widget_handle, $inline_script, 'after');

        return sprintf(
            '<div id="%1$s" class="pointerai-chat-widget" data-pointerai-chat-ajax-url="%2$s" data-pointerai-chat-send-action="pointerai_chat_send" data-pointerai-chat-send-nonce="%3$s"></div>',
            esc_attr($root_id),
            esc_url(admin_url('admin-ajax.php')),
            esc_attr($chat_nonce)
        );
    }

    public function ajax_chat_send(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash((string) $_POST['message'])) : '';
        if ($message === '') {
            wp_send_json_error(['message' => __('Message is required.', 'pointerdev-ai-chat')], 400);
        }

        $anon_uid = isset($_POST['anon_uid']) ? sanitize_text_field(wp_unslash((string) $_POST['anon_uid'])) : '';
        if ($anon_uid === '') {
            $anon_uid = $this->get_or_create_anon_uid();
        }

        $settings = $this->get_settings();
        $client = new PointerAI_Client($settings);

        $token = $this->resolve_end_user_token($settings);
        $mint_error = null;
        if ($token === '' && is_user_logged_in()) {
            $minted = $this->mint_end_user_token_for_current_user($settings);
            if (is_wp_error($minted)) {
                $mint_error = $minted;
            } elseif (isset($minted['token']) && is_scalar($minted['token'])) {
                $token = trim((string) $minted['token']);
            }
        }
        $auth_mode = (string) ($settings['auth_mode'] ?? 'auto');

        $use_end_user = $auth_mode === 'end_user' || ($auth_mode === 'auto' && $token !== '');

        if ($auth_mode === 'end_user' && $token === '') {
            if ($mint_error instanceof WP_Error) {
                $status = 500;
                $error_data = $mint_error->get_error_data();
                if (is_array($error_data) && isset($error_data['status']) && is_numeric($error_data['status'])) {
                    $status = (int) $error_data['status'];
                }
                wp_send_json_error([
                    'message' => $mint_error->get_error_message(),
                    'details' => $error_data,
                ], $status);
            }
            wp_send_json_error([
                'message' => __('This connection requires an end-user token. Provide it via the pointerai_end_user_token filter.', 'pointerdev-ai-chat'),
            ], 401);
        }

        $payload = [
            'metadata' => [
                'source' => (string) ($settings['metadata_source'] ?? 'wordpress-plugin'),
            ],
        ];

        $runtime_state_key = null;

        if (!$use_end_user) {
            $payload['anon_uid'] = $anon_uid;
        } else {
            $runtime_state_key = $this->runtime_state_key($settings, $anon_uid);
            $state = $this->load_runtime_session_state($runtime_state_key);
            $client->set_session_token(
                isset($state['token']) && is_scalar($state['token']) ? (string) $state['token'] : null,
                isset($state['expires_at']) && is_scalar($state['expires_at']) ? (string) $state['expires_at'] : null,
                isset($state['refresh_available_at']) && is_scalar($state['refresh_available_at']) ? (string) $state['refresh_available_at'] : null,
                isset($state['session_id']) && is_scalar($state['session_id']) ? (string) $state['session_id'] : null
            );

            if ($this->should_refresh_runtime_state($state)) {
                $refresh = $client->refresh_session_token([
                    'token' => isset($state['token']) && is_scalar($state['token']) ? (string) $state['token'] : null,
                    'persist' => true,
                ]);
                if (is_wp_error($refresh)) {
                    $this->clear_runtime_session_state($runtime_state_key);
                    $client->clear_session_token();
                } else {
                    $this->persist_runtime_session_state($runtime_state_key, $client->get_session_token_state());
                }
            }

            $session_state = $client->get_session_token_state();
            $session_token = isset($session_state['token']) && is_scalar($session_state['token'])
                ? trim((string) $session_state['token'])
                : '';
            if ($session_token === '') {
                $exchange = $client->exchange_session_token([
                    'end_user_token' => $token,
                    'session_id' => isset($state['session_id']) && is_scalar($state['session_id']) ? (string) $state['session_id'] : null,
                ]);
                if (is_wp_error($exchange)) {
                    $status = 500;
                    $error_data = $exchange->get_error_data();
                    if (is_array($error_data) && isset($error_data['status']) && is_numeric($error_data['status'])) {
                        $status = (int) $error_data['status'];
                    }
                    wp_send_json_error([
                        'message' => $exchange->get_error_message(),
                        'details' => $error_data,
                    ], $status);
                }
                $this->persist_runtime_session_state($runtime_state_key, $client->get_session_token_state());
            }
        }

        $result = $client->chat($message, $payload);

        if (is_wp_error($result)) {
            if ($runtime_state_key !== null) {
                $error_data = $result->get_error_data();
                $status = is_array($error_data) && isset($error_data['status']) && is_numeric($error_data['status'])
                    ? (int) $error_data['status']
                    : 0;
                if ($status === 401 || $status === 403) {
                    $this->clear_runtime_session_state($runtime_state_key);
                    $client->clear_session_token();
                }
            }

            $status = 500;
            $error_data = $result->get_error_data();
            if (is_array($error_data) && isset($error_data['status']) && is_numeric($error_data['status'])) {
                $status = (int) $error_data['status'];
            }

            wp_send_json_error([
                'message' => $result->get_error_message(),
                'details' => $error_data,
            ], $status);
        }

        if ($runtime_state_key !== null) {
            $this->persist_runtime_session_state($runtime_state_key, $client->get_session_token_state());
        }

        $answer = isset($result['answer']) && is_scalar($result['answer'])
            ? (string) $result['answer']
            : wp_json_encode($result);

        wp_send_json_success([
            'answer' => $answer,
            'response' => $result,
        ]);
    }

    public function ajax_widget_token(): void
    {
        check_ajax_referer(self::NONCE_WIDGET_TOKEN, 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required.', 'pointerdev-ai-chat')], 401);
        }

        $settings = $this->get_settings();
        $override_token = $this->resolve_end_user_token($settings);
        if ($override_token !== '') {
            wp_send_json_success([
                'token' => $override_token,
                'source' => 'filter',
            ]);
        }

        $result = $this->mint_end_user_token_for_current_user($settings);
        if (is_wp_error($result)) {
            $status = 500;
            $error_data = $result->get_error_data();
            if (is_array($error_data) && isset($error_data['status']) && is_numeric($error_data['status'])) {
                $status = (int) $error_data['status'];
            }
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'details' => $error_data,
            ], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|WP_Error
     */
    private function mint_end_user_token_for_current_user(array $settings)
    {
        $secret_key = trim((string) ($settings['secret_key'] ?? ''));
        $project_id = trim((string) ($settings['project_id'] ?? ''));
        if ($secret_key === '' || $project_id === '') {
            return new WP_Error(
                'pointerai_missing_secret',
                __('Secret key and project ID are required for login-required mode.', 'pointerdev-ai-chat'),
                ['status' => 500]
            );
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $project_id)) {
            return new WP_Error(
                'pointerai_invalid_project_id',
                __('Project ID must be a UUID.', 'pointerdev-ai-chat'),
                ['status' => 400]
            );
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return new WP_Error(
                'pointerai_user_missing',
                __('Unable to resolve current WordPress user.', 'pointerdev-ai-chat'),
                ['status' => 401]
            );
        }

        $ttl_minutes = (int) ($settings['token_ttl_minutes'] ?? 60);
        if ($ttl_minutes < 1) {
            $ttl_minutes = 60;
        }
        if ($ttl_minutes > 24 * 60) {
            $ttl_minutes = 24 * 60;
        }

        $issued_at = time();
        $expires_at = $issued_at + ($ttl_minutes * 60);
        $subject_seed_default = get_current_blog_id() . ':' . (string) $user->ID;
        // Allows integrators to align identity mapping across SDKs/platforms.
        $subject_seed = apply_filters(
            'pointerai_end_user_subject_seed',
            $subject_seed_default,
            $user,
            $settings
        );
        $subject_seed_value = is_scalar($subject_seed) && trim((string) $subject_seed) !== ''
            ? trim((string) $subject_seed)
            : $subject_seed_default;
        $subject = $this->deterministic_uuid(
            'pointerai:' . $project_id,
            $subject_seed_value
        );

        $roles = [];
        if (is_array($user->roles)) {
            foreach ($user->roles as $role) {
                if (is_scalar($role) && trim((string) $role) !== '') {
                    $roles[] = trim((string) $role);
                }
            }
        }

        $payload = [
            'sub' => $subject,
            'project_id' => $project_id,
            'type' => 'end_user',
            'iat' => $issued_at,
            'exp' => $expires_at,
            'iss' => 'pointerai',
            'aud' => 'pointerai:project:' . $project_id,
            'email' => is_email($user->user_email) ? $user->user_email : null,
            'name' => (string) $user->display_name,
            'roles' => count($roles) > 0 ? $roles : null,
            'metadata' => [
                'source' => 'wordpress-plugin',
                'wp_user_id' => (int) $user->ID,
                'blog_id' => (int) get_current_blog_id(),
            ],
        ];

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $token = $this->encode_jwt($header, $payload, $secret_key);
        if ($token === '') {
            return new WP_Error(
                'pointerai_token_encode_failed',
                __('Failed to encode end-user token.', 'pointerdev-ai-chat'),
                ['status' => 500]
            );
        }

        return [
            'token' => $token,
            'expires_at' => gmdate('c', $expires_at),
            'end_user_id' => $subject,
        ];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function encode_jwt(array $header, array $payload, string $secret_key): string
    {
        $header_json = wp_json_encode($header);
        $payload_json = wp_json_encode($payload);
        if (!is_string($header_json) || !is_string($payload_json)) {
            return '';
        }

        $header_b64 = $this->base64url_encode($header_json);
        $payload_b64 = $this->base64url_encode($payload_json);
        $signing_input = $header_b64 . '.' . $payload_b64;
        $signature = hash_hmac('sha256', $signing_input, $secret_key, true);

        return $signing_input . '.' . $this->base64url_encode($signature);
    }

    private function base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function deterministic_uuid(string $namespace, string $name): string
    {
        $hex = sha1($namespace . '|' . $name);
        $bytes = hex2bin(substr($hex, 0, 32));
        if ($bytes === false || strlen($bytes) !== 16) {
            return wp_generate_uuid4();
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $buffer = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($buffer, 0, 8),
            substr($buffer, 8, 4),
            substr($buffer, 12, 4),
            substr($buffer, 16, 4),
            substr($buffer, 20, 12)
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolve_end_user_token(array $settings): string
    {
        $resolved = apply_filters('pointerai_end_user_token', '', $settings);

        return is_scalar($resolved) ? trim((string) $resolved) : '';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function runtime_state_key(array $settings, string $anon_uid): string
    {
        $project_id = trim((string) ($settings['project_id'] ?? ''));
        if (is_user_logged_in()) {
            $subject = 'user:' . (string) get_current_blog_id() . ':' . (string) get_current_user_id();
        } elseif ($anon_uid !== '') {
            $subject = 'anon:' . $anon_uid;
        } else {
            $subject = 'anon:unknown';
        }

        return 'pointerai_runtime_' . md5($project_id . '|' . $subject);
    }

    /**
     * @return array<string, mixed>
     */
    private function load_runtime_session_state(string $key): array
    {
        $state = get_transient($key);
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function persist_runtime_session_state(string $key, array $state): void
    {
        $token = isset($state['token']) && is_scalar($state['token']) ? trim((string) $state['token']) : '';
        if ($token === '') {
            delete_transient($key);
            $this->unregister_runtime_session_key($key);
            return;
        }

        $expires_at = isset($state['expires_at']) && is_scalar($state['expires_at']) ? (string) $state['expires_at'] : null;
        $refresh_available_at = isset($state['refresh_available_at']) && is_scalar($state['refresh_available_at']) ? (string) $state['refresh_available_at'] : null;
        $session_id = isset($state['session_id']) && is_scalar($state['session_id']) ? (string) $state['session_id'] : null;

        $ttl_seconds = HOUR_IN_SECONDS;
        if ($expires_at !== null) {
            $expires_ts = strtotime($expires_at);
            if ($expires_ts !== false) {
                $ttl_delta = $expires_ts - time();
                if ($ttl_delta <= 0) {
                    delete_transient($key);
                    return;
                }
                $ttl_seconds = max(MINUTE_IN_SECONDS, $ttl_delta);
            }
        }

        set_transient($key, [
            'token' => $token,
            'expires_at' => $expires_at,
            'refresh_available_at' => $refresh_available_at,
            'session_id' => $session_id,
        ], $ttl_seconds);
        $this->register_runtime_session_key($key);
    }

    private function clear_runtime_session_state(string $key): void
    {
        delete_transient($key);
        $this->unregister_runtime_session_key($key);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function should_refresh_runtime_state(array $state): bool
    {
        $token = isset($state['token']) && is_scalar($state['token']) ? trim((string) $state['token']) : '';
        $refresh_available_at = isset($state['refresh_available_at']) && is_scalar($state['refresh_available_at'])
            ? trim((string) $state['refresh_available_at'])
            : '';
        if ($token === '' || $refresh_available_at === '') {
            return false;
        }

        $ts = strtotime($refresh_available_at);
        if ($ts === false) {
            return false;
        }

        return time() >= ($ts - self::RUNTIME_REFRESH_LEEWAY_SECONDS);
    }

    private function normalize_base_url(string $base_url, string $fallback): string
    {
        $candidate = trim($base_url);
        if ($candidate === '' || !wp_http_validate_url($candidate)) {
            $candidate = $fallback;
        }

        return rtrim($candidate, '/');
    }

    private function register_runtime_session_key(string $key): void
    {
        $keys = get_option(self::RUNTIME_KEYS_OPTION, []);
        if (!is_array($keys)) {
            $keys = [];
        }

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::RUNTIME_KEYS_OPTION, $keys, false);
        }
    }

    private function unregister_runtime_session_key(string $key): void
    {
        $keys = get_option(self::RUNTIME_KEYS_OPTION, []);
        if (!is_array($keys)) {
            $keys = [];
        }

        $keys = array_values(array_filter($keys, static function ($candidate) use ($key): bool {
            return is_string($candidate) && $candidate !== $key;
        }));

        if ($keys === []) {
            delete_option(self::RUNTIME_KEYS_OPTION);
            return;
        }

        update_option(self::RUNTIME_KEYS_OPTION, $keys, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function get_default_settings(): array
    {
        return [
            'base_url' => 'https://pointerdev.ai/',
            'project_id' => '',
            'publishable_key' => '',
            'secret_key' => '',
            'auth_mode' => 'auto',
            'metadata_source' => 'wordpress-plugin',
            'token_ttl_minutes' => 60,
            'widget_script_url' => 'https://cdn.jsdelivr.net/npm/@pointerdev/pointerai-widget@latest/dist/pointerai-widget.js',
            'timeout' => 20,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_settings(): array
    {
        $raw = get_option(self::OPTION_KEY, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        return wp_parse_args($raw, $this->get_default_settings());
    }

    private function get_or_create_anon_uid(): string
    {
        if (isset($_COOKIE[self::ANON_COOKIE])) {
            $existing = sanitize_text_field(wp_unslash((string) $_COOKIE[self::ANON_COOKIE]));
            if ($existing !== '') {
                return $existing;
            }
        }

        $anon_uid = 'wp-' . wp_generate_uuid4();
        if (!headers_sent()) {
            setcookie(self::ANON_COOKIE, $anon_uid, [
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $anon_uid;
    }
}
