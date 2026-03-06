<?php

if (!defined('ABSPATH')) {
    exit;
}

class PointerAI_Plugin
{
    private const OPTION_KEY = 'pointerai_chat_settings';
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

        add_shortcode('pointerai_chat', [$this, 'render_chat_shortcode']);

        add_action('wp_ajax_pointerai_chat_send', [$this, 'ajax_chat_send']);
        add_action('wp_ajax_nopriv_pointerai_chat_send', [$this, 'ajax_chat_send']);
        add_action('wp_ajax_pointerai_widget_token', [$this, 'ajax_widget_token']);
    }

    public function register_admin_menu(): void
    {
        add_options_page(
            'PointerAI Chat',
            'PointerAI Chat',
            'manage_options',
            'pointerai-chat',
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
            'PointerAI Connection',
            static function (): void {
                echo '<p>Use your agent project ID and publishable key from PointerAI.</p>';
            },
            'pointerai-chat'
        );

        add_settings_field('base_url', 'API Base URL', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
            'key' => 'base_url',
            'placeholder' => 'https://pointerdev.ai',
        ]);

        add_settings_field('project_id', 'Project ID', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
            'key' => 'project_id',
            'placeholder' => 'project-uuid',
        ]);

        add_settings_field('publishable_key', 'Publishable Key', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
            'key' => 'publishable_key',
            'placeholder' => 'pk_...',
        ]);

        add_settings_field('secret_key', 'Secret Key', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
            'key' => 'secret_key',
            'placeholder' => 'sk_...',
            'type' => 'password',
        ]);

        add_settings_field('auth_mode', 'Auth Mode', [$this, 'render_auth_mode_field'], 'pointerai-chat', 'pointerai_chat_main');

        add_settings_field('metadata_source', 'Metadata Source', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
            'key' => 'metadata_source',
            'placeholder' => 'wordpress-plugin',
        ]);

        add_settings_field('token_ttl_minutes', 'End-user token TTL (min)', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
            'key' => 'token_ttl_minutes',
            'placeholder' => '60',
        ]);

        add_settings_field('widget_script_url', 'Widget Script URL', [$this, 'render_text_field'], 'pointerai-chat', 'pointerai_chat_main', [
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
        echo '<h1>PointerAI Chat</h1>';
        echo '<p>Shortcode: <code>[pointerai_chat]</code></p>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections('pointerai-chat');
        submit_button('Save Settings');
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
        $incoming_secret = isset($raw['secret_key']) ? sanitize_text_field((string) $raw['secret_key']) : '';
        $secret_key = $incoming_secret;
        if ($incoming_secret === '' && isset($existing['secret_key']) && is_scalar($existing['secret_key'])) {
            // Keep existing secret when password field is left blank on save.
            $secret_key = (string) $existing['secret_key'];
        }

        $settings = [
            'base_url' => isset($raw['base_url']) ? sanitize_text_field((string) $raw['base_url']) : $defaults['base_url'],
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

        if ($settings['base_url'] === '') {
            $settings['base_url'] = $defaults['base_url'];
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
            echo '<p class="description">Leave blank to keep current value.</p>';
        }
    }

    public function render_auth_mode_field(): void
    {
        $settings = $this->get_settings();
        $mode = (string) ($settings['auth_mode'] ?? 'auto');

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[auth_mode]">';
        echo '<option value="auto" ' . selected($mode, 'auto', false) . '>Auto (follow project behavior)</option>';
        echo '<option value="anonymous" ' . selected($mode, 'anonymous', false) . '>Anonymous users</option>';
        echo '<option value="end_user" ' . selected($mode, 'end_user', false) . '>End-user token required</option>';
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
        $auth_mode = (string) ($settings['auth_mode'] ?? 'auto');
        $script_url = (string) ($settings['widget_script_url'] ?? '');

        $widget_config = [
            'apiBaseUrl' => (string) ($settings['base_url'] ?? ''),
            'projectId' => (string) ($settings['project_id'] ?? ''),
            'publishableKey' => (string) ($settings['publishable_key'] ?? ''),
            'title' => 'PointerAI Assistant',
            'subtitle' => 'WordPress integration',
            'launcherLabel' => 'Chat',
            'metadata' => [
                'source' => (string) ($settings['metadata_source'] ?? 'wordpress-plugin'),
                'channel' => 'wordpress',
            ],
        ];

        $should_use_server_token = $auth_mode === 'end_user' || ($auth_mode === 'auto' && is_user_logged_in());

        ob_start();
        ?>
        <div id="<?php echo esc_attr($root_id); ?>" class="pointerai-chat-widget"></div>
        <script>
            (function() {
                var config = <?php echo wp_json_encode($widget_config); ?> || {};
                var shouldUseServerToken = <?php echo wp_json_encode($should_use_server_token); ?>;

                if (shouldUseServerToken) {
                    config.getEndUserToken = async function() {
                        var body = new URLSearchParams();
                        body.set('action', 'pointerai_widget_token');
                        body.set('nonce', <?php echo wp_json_encode($token_nonce); ?>);

                        var response = await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: body.toString(),
                            credentials: 'same-origin'
                        });
                        var json = await response.json();
                        if (!json || !json.success || !json.data || !json.data.token) {
                            var message = (json && json.data && json.data.message) ? json.data.message : 'Token mint failed.';
                            throw new Error(message);
                        }
                        return json.data.token;
                    };
                }

                var initWidget = function() {
                    if (!window.PointerAIWidget || typeof window.PointerAIWidget.init !== 'function') {
                        return;
                    }
                    window.PointerAIWidget.init(config).catch(function(err) {
                        if (window.console && typeof window.console.error === 'function') {
                            window.console.error('PointerAI widget init failed', err);
                        }
                    });
                };

                if (window.PointerAIWidget && typeof window.PointerAIWidget.init === 'function') {
                    initWidget();
                    return;
                }

                var existing = document.querySelector('script[data-pointerai-widget-loader="1"]');
                if (!existing) {
                    var script = document.createElement('script');
                    script.src = <?php echo wp_json_encode($script_url); ?>;
                    script.defer = true;
                    script.setAttribute('data-pointerai-widget-loader', '1');
                    script.onload = function() {
                        document.dispatchEvent(new CustomEvent('pointerai_widget_loaded'));
                        initWidget();
                    };
                    document.head.appendChild(script);
                } else {
                    document.addEventListener('pointerai_widget_loaded', initWidget, { once: true });
                }
            })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public function ajax_chat_send(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash((string) $_POST['message'])) : '';
        if ($message === '') {
            wp_send_json_error(['message' => 'Message is required.'], 400);
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
                'message' => 'This connection requires an end-user token. Provide it via the pointerai_end_user_token filter.',
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
            wp_send_json_error(['message' => 'Authentication required.'], 401);
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
                'Secret key and project ID are required for login-required mode.',
                ['status' => 500]
            );
        }

        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $project_id)) {
            return new WP_Error(
                'pointerai_invalid_project_id',
                'Project ID must be a UUID.',
                ['status' => 400]
            );
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return new WP_Error('pointerai_user_missing', 'Unable to resolve current WordPress user.', ['status' => 401]);
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
                'Failed to encode end-user token.',
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
    }

    private function clear_runtime_session_state(string $key): void
    {
        delete_transient($key);
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

    /**
     * @return array<string, mixed>
     */
    private function get_default_settings(): array
    {
        return [
            'base_url' => 'https://pointerdev.ai',
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
            setcookie(self::ANON_COOKIE, $anon_uid, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }

        return $anon_uid;
    }
}
