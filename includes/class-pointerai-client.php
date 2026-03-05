<?php

if (!defined('ABSPATH')) {
    exit;
}

class PointerAI_Client
{
    private string $base_url;
    private string $project_id;
    private string $publishable_key;
    private int $timeout;
    private ?string $session_token = null;
    private ?string $session_expires_at = null;
    private ?string $session_refresh_available_at = null;
    private ?string $session_id = null;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->base_url = rtrim(trim((string) ($settings['base_url'] ?? 'http://localhost:8000')), '/');
        $this->project_id = trim((string) ($settings['project_id'] ?? ''));
        $this->publishable_key = trim((string) ($settings['publishable_key'] ?? ''));
        $timeout = (int) ($settings['timeout'] ?? 20);
        $this->timeout = $timeout > 0 ? $timeout : 20;
    }

    public function set_session_token(
        ?string $token,
        ?string $expires_at = null,
        ?string $refresh_available_at = null,
        ?string $session_id = null
    ): void {
        $this->session_token = $this->trim_or_null($token);
        $this->session_expires_at = $this->trim_or_null($expires_at);
        $this->session_refresh_available_at = $this->trim_or_null($refresh_available_at);
        $this->session_id = $this->trim_or_null($session_id);
    }

    public function clear_session_token(): void
    {
        $this->session_token = null;
        $this->session_expires_at = null;
        $this->session_refresh_available_at = null;
        $this->session_id = null;
    }

    /**
     * @return array<string, string|null>
     */
    public function get_session_token_state(): array
    {
        return [
            'token' => $this->session_token,
            'expires_at' => $this->session_expires_at,
            'refresh_available_at' => $this->session_refresh_available_at,
            'session_id' => $this->session_id,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function exchange_session_token(array $options = [])
    {
        $end_user_token = isset($options['end_user_token']) && is_scalar($options['end_user_token'])
            ? $this->trim_or_null((string) $options['end_user_token'])
            : null;
        if ($end_user_token === null) {
            return new WP_Error(
                'pointerai_missing_end_user_token',
                'end_user_token is required for session exchange.'
            );
        }

        $payload = [];
        if (isset($options['session_id']) && is_scalar($options['session_id'])) {
            $session_id = $this->trim_or_null((string) $options['session_id']);
            if ($session_id !== null) {
                $payload['session_id'] = $session_id;
            }
        }

        $response = $this->request(
            '/api/runtime/sessions',
            $payload,
            $end_user_token,
            null,
            'end_user',
            false
        );
        if (is_wp_error($response)) {
            return $response;
        }

        return $this->apply_session_token_response($response);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function refresh_session_token(array $options = [])
    {
        $persist = !isset($options['persist']) || (bool) $options['persist'];
        $token = isset($options['token']) && is_scalar($options['token'])
            ? $this->trim_or_null((string) $options['token'])
            : $this->session_token;
        if ($token === null) {
            return new WP_Error(
                'pointerai_missing_session_token',
                'Session token is required for refresh.'
            );
        }

        $response = $this->request(
            '/api/runtime/sessions/refresh',
            ['token' => $token],
            null,
            null,
            'none',
            false
        );
        if (is_wp_error($response)) {
            return $response;
        }

        if ($persist) {
            return $this->apply_session_token_response($response);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $options
     * @return true|WP_Error
     */
    public function revoke_session_token(array $options = [])
    {
        $clear_session = !isset($options['clear_session']) || (bool) $options['clear_session'];
        $override_token = isset($options['token']) && is_scalar($options['token'])
            ? $this->trim_or_null((string) $options['token'])
            : null;
        $token = $override_token ?? $this->session_token;
        if ($token === null) {
            if ($clear_session) {
                $this->clear_session_token();
            }
            return true;
        }

        $response = $this->request(
            '/api/runtime/sessions/revoke',
            ['token' => $token],
            null,
            null,
            'none',
            false
        );
        if (is_wp_error($response)) {
            return $response;
        }

        if ($clear_session && ($override_token === null || $override_token === $this->session_token)) {
            $this->clear_session_token();
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function create_session(array $options = [])
    {
        $payload = [
            'metadata' => isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
        ];

        if (isset($options['anon_uid']) && is_scalar($options['anon_uid'])) {
            $anon_uid = trim((string) $options['anon_uid']);
            if ($anon_uid !== '') {
                $payload['anon_uid'] = $anon_uid;
            }
        }

        $token = isset($options['end_user_token']) && is_scalar($options['end_user_token'])
            ? trim((string) $options['end_user_token'])
            : null;
        $session_token = isset($options['session_token']) && is_scalar($options['session_token'])
            ? trim((string) $options['session_token'])
            : null;

        return $this->request(
            '/api/chat/sessions',
            $payload,
            $token,
            $session_token,
            'auto',
            true
        );
    }

    /**
     * @param string $message
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function chat(string $message, array $options = [])
    {
        $message = trim($message);
        if ($message === '') {
            return new WP_Error('pointerai_invalid_message', 'Message is required.');
        }

        $payload = [
            'message' => $message,
            'metadata' => isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
        ];

        if (isset($options['session_uid']) && is_scalar($options['session_uid'])) {
            $session_uid = trim((string) $options['session_uid']);
            if ($session_uid !== '') {
                $payload['session_uid'] = $session_uid;
            }
        }

        if (isset($options['anon_uid']) && is_scalar($options['anon_uid'])) {
            $anon_uid = trim((string) $options['anon_uid']);
            if ($anon_uid !== '') {
                $payload['anon_uid'] = $anon_uid;
            }
        }

        $token = isset($options['end_user_token']) && is_scalar($options['end_user_token'])
            ? trim((string) $options['end_user_token'])
            : null;
        $session_token = isset($options['session_token']) && is_scalar($options['session_token'])
            ? trim((string) $options['session_token'])
            : null;

        return $this->request(
            '/api/chat',
            $payload,
            $token,
            $session_token,
            'auto',
            true
        );
    }

    /**
     * @param string $path
     * @param array<string, mixed> $payload
     * @param string|null $end_user_token
     * @param string|null $session_token
     * @param string $auth_mode
     * @param bool $retry_on_auth_failure
     * @return array<string, mixed>|WP_Error
     */
    private function request(
        string $path,
        array $payload,
        ?string $end_user_token = null,
        ?string $session_token = null,
        string $auth_mode = 'auto',
        bool $retry_on_auth_failure = true
    ) {
        if ($this->project_id === '' || $this->publishable_key === '') {
            return new WP_Error(
                'pointerai_missing_config',
                'PointerAI project ID and publishable key are required in plugin settings.'
            );
        }

        $resolved = $this->resolve_auth_token($auth_mode, $end_user_token, $session_token);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Project-Id' => $this->project_id,
            'X-Project-Key' => $this->publishable_key,
        ];

        if ($resolved['token'] !== null) {
            $headers['Authorization'] = 'Bearer ' . $resolved['token'];
        }

        $response = wp_remote_post($this->base_url . $path, [
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            if ($this->should_refresh_and_retry($path, $status, $resolved['source'], $retry_on_auth_failure, $session_token)) {
                $refresh_token = $this->trim_or_null($session_token) ?? $this->session_token;
                $refresh = $this->refresh_session_token([
                    'token' => $refresh_token,
                    'persist' => true,
                ]);
                if (!is_wp_error($refresh)) {
                    return $this->request(
                        $path,
                        $payload,
                        $end_user_token,
                        null,
                        $auth_mode,
                        false
                    );
                }
            }

            $detail = is_array($decoded) && isset($decoded['detail']) && is_scalar($decoded['detail'])
                ? (string) $decoded['detail']
                : ($body !== '' ? $body : 'PointerAI request failed.');

            return new WP_Error('pointerai_api_error', $detail, [
                'status' => $status,
                'body' => $body,
                'data' => $decoded,
            ]);
        }

        if ($status === 204 || trim($body) === '') {
            return [];
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'raw' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|WP_Error
     */
    private function apply_session_token_response(array $response)
    {
        $token = isset($response['token']) && is_scalar($response['token'])
            ? $this->trim_or_null((string) $response['token'])
            : null;
        if ($token === null) {
            return new WP_Error(
                'pointerai_invalid_session_response',
                'Session token response did not include token.'
            );
        }

        $expires_at = isset($response['expires_at']) && is_scalar($response['expires_at'])
            ? (string) $response['expires_at']
            : null;
        $refresh_available_at = isset($response['refresh_available_at']) && is_scalar($response['refresh_available_at'])
            ? (string) $response['refresh_available_at']
            : null;
        $session_id = isset($response['session_id']) && is_scalar($response['session_id'])
            ? (string) $response['session_id']
            : null;

        $this->set_session_token($token, $expires_at, $refresh_available_at, $session_id);

        return $response;
    }

    /**
     * @return array{token: string|null, source: string}
     */
    private function resolve_auth_token(
        string $auth_mode,
        ?string $end_user_token,
        ?string $session_token
    ): array {
        $mode = strtolower(trim($auth_mode));
        $session_candidate = $this->trim_or_null($session_token) ?? $this->session_token;
        $end_user_candidate = $this->trim_or_null($end_user_token);

        if ($mode === 'none') {
            return ['token' => null, 'source' => 'none'];
        }

        if ($mode === 'session') {
            return [
                'token' => $session_candidate,
                'source' => $session_candidate === null ? 'none' : 'session',
            ];
        }

        if ($mode === 'end_user') {
            return [
                'token' => $end_user_candidate,
                'source' => $end_user_candidate === null ? 'none' : 'end_user',
            ];
        }

        if ($session_candidate !== null) {
            return ['token' => $session_candidate, 'source' => 'session'];
        }

        if ($end_user_candidate !== null) {
            return ['token' => $end_user_candidate, 'source' => 'end_user'];
        }

        return ['token' => null, 'source' => 'none'];
    }

    private function should_refresh_and_retry(
        string $path,
        int $status,
        string $token_source,
        bool $retry_on_auth_failure,
        ?string $session_token
    ): bool {
        if (!$retry_on_auth_failure) {
            return false;
        }

        if ($status !== 401) {
            return false;
        }

        if ($token_source !== 'session') {
            return false;
        }

        if ($path === '/api/runtime/sessions/refresh' || $path === '/api/runtime/sessions/revoke') {
            return false;
        }

        $candidate = $this->trim_or_null($session_token) ?? $this->session_token;

        return $candidate !== null;
    }

    private function trim_or_null(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
