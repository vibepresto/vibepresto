<?php

namespace VibePresto;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Auth_Store
{
    private const OPTION_KEY = 'vibepresto_auth_state';
    private const DEVICE_TTL = 600;
    private const COMPLETION_TTL = 900;
    private const ACCESS_TTL = 900;
    private const REFRESH_TTL = 2592000;
    private const POLL_INTERVAL = 5;
    private const DEVICE_REQUEST_WINDOW = 300;
    private const MAX_DEVICE_REQUESTS_PER_IP = 5;
    private const MAX_ACTIVE_DEVICES = 100;
    private const MAX_ACTIVE_DEVICES_PER_IP = 5;
    private const PENDING_DEVICE_PRUNE_THRESHOLD = 75;

    public function create_device_authorization(string $client_name, string $machine_name, array $scope = [], string $request_ip = '')
    {
        $state = $this->load_state();
        $now = time();
        $request_ip = $this->normalize_request_ip($request_ip);

        $state = $this->cleanup_state($state, $now);
        $this->prune_oldest_pending_devices($state, self::PENDING_DEVICE_PRUNE_THRESHOLD);

        $recent_requests = $this->recent_request_timestamps($state['request_log'][$request_ip] ?? [], $now);
        if (count($recent_requests) >= self::MAX_DEVICE_REQUESTS_PER_IP) {
            return new WP_Error('rate_limited', __('Too many device authorization requests were made from this IP. Please wait and try again.', 'vibepresto'), [
                'retry_after' => self::DEVICE_REQUEST_WINDOW,
            ]);
        }

        $active_devices = $this->active_device_requests($state['devices']);
        if (count($active_devices) >= self::MAX_ACTIVE_DEVICES) {
            return new WP_Error('device_request_limit_reached', __('Too many pending CLI authorization requests already exist. Please wait and try again.', 'vibepresto'));
        }

        $active_devices_for_ip = 0;
        foreach ($active_devices as $device) {
            if (($device['request_ip'] ?? '') === $request_ip) {
                $active_devices_for_ip++;
            }
        }

        if ($active_devices_for_ip >= self::MAX_ACTIVE_DEVICES_PER_IP) {
            return new WP_Error('rate_limited', __('This IP already has too many active CLI authorization requests. Please finish an existing approval or wait for it to expire.', 'vibepresto'), [
                'retry_after' => self::DEVICE_TTL,
            ]);
        }

        $device_code = bin2hex(random_bytes(24));
        $user_code = $this->generate_user_code();

        $state['devices'][$device_code] = [
            'device_code' => $device_code,
            'user_code' => $user_code,
            'client_name' => $client_name,
            'machine_name' => $machine_name,
            'scope' => array_values(array_unique(array_filter(array_map('sanitize_text_field', $scope)))),
            'status' => 'pending',
            'created_at' => $now,
            'expires_at' => $now + self::DEVICE_TTL,
            'interval' => self::POLL_INTERVAL,
            'last_poll_at' => 0,
            'approved_user_id' => 0,
            'approved_at' => 0,
            'denied_at' => 0,
            'session_id' => '',
            'completion_code' => '',
            'completion_expires_at' => 0,
            'exchanged_at' => 0,
            'request_ip' => $request_ip,
        ];
        $recent_requests[] = $now;
        $state['request_log'][$request_ip] = $recent_requests;
        $this->prune_oldest_pending_devices($state, self::PENDING_DEVICE_PRUNE_THRESHOLD, $device_code);

        $this->save_state($state);

        return $state['devices'][$device_code];
    }

    public function get_device_authorization(string $device_code): ?array
    {
        $state = $this->load_state();
        return $state['devices'][$device_code] ?? null;
    }

    public function approve_device_authorization(string $device_code, int $user_id)
    {
        $state = $this->load_state();
        if (empty($state['devices'][$device_code])) {
            return new WP_Error('invalid_device_code', __('That authorization request could not be found.', 'vibepresto'));
        }

        $device = $state['devices'][$device_code];
        if ($device['expires_at'] < time()) {
            $state['devices'][$device_code]['status'] = 'expired';
            $this->save_state($state);
            return new WP_Error('expired_token', __('That authorization request has expired.', 'vibepresto'));
        }

        if ($device['status'] === 'completed') {
            return new WP_Error('already_completed', __('That authorization request has already been completed.', 'vibepresto'));
        }

        $now = time();
        $state['devices'][$device_code]['status'] = 'approved';
        $state['devices'][$device_code]['approved_user_id'] = $user_id;
        $state['devices'][$device_code]['approved_at'] = $now;
        $state['devices'][$device_code]['completion_code'] = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $state['devices'][$device_code]['completion_expires_at'] = $now + self::COMPLETION_TTL;
        $this->save_state($state);

        return $state['devices'][$device_code];
    }

    public function deny_device_authorization(string $device_code)
    {
        $state = $this->load_state();
        if (empty($state['devices'][$device_code])) {
            return new WP_Error('invalid_device_code', __('That authorization request could not be found.', 'vibepresto'));
        }

        $state['devices'][$device_code]['status'] = 'denied';
        $state['devices'][$device_code]['denied_at'] = time();
        $this->save_state($state);

        return $state['devices'][$device_code];
    }

    public function exchange_device_authorization(?string $device_code, ?string $completion_code)
    {
        $state = $this->load_state();
        $index = $this->find_device_index($state['devices'], $device_code, $completion_code);
        if ($index === null) {
            return new WP_Error('invalid_grant', __('The authorization code is invalid.', 'vibepresto'));
        }

        $device = $state['devices'][$index];
        $now = time();

        if ($device['expires_at'] < $now) {
            $state['devices'][$index]['status'] = 'expired';
            $this->save_state($state);
            return new WP_Error('expired_token', __('The authorization request has expired.', 'vibepresto'));
        }

        if ($device['status'] === 'denied') {
            return new WP_Error('access_denied', __('The authorization request was denied.', 'vibepresto'));
        }

        if ($device['status'] === 'pending') {
            if ($device['last_poll_at'] > 0 && ($now - $device['last_poll_at']) < $device['interval']) {
                $state['devices'][$index]['last_poll_at'] = $now;
                $this->save_state($state);
                return new WP_Error('slow_down', __('Poll less frequently and try again.', 'vibepresto'));
            }

            $state['devices'][$index]['last_poll_at'] = $now;
            $this->save_state($state);
            return new WP_Error('authorization_pending', __('The authorization request is still pending.', 'vibepresto'));
        }

        if ($device['status'] === 'completed') {
            return new WP_Error('invalid_grant', __('The authorization code has already been used.', 'vibepresto'));
        }

        if ($completion_code !== null) {
            if ($device['completion_code'] === '' || ! hash_equals($device['completion_code'], $completion_code)) {
                return new WP_Error('invalid_grant', __('The completion code is invalid.', 'vibepresto'));
            }

            if ($device['completion_expires_at'] < $now) {
                return new WP_Error('expired_token', __('The completion code has expired.', 'vibepresto'));
            }
        }

        $session = $this->create_session((int) $device['approved_user_id'], $device['client_name'], $device['machine_name'], $device['scope']);
        $state['devices'][$index]['status'] = 'completed';
        $state['devices'][$index]['session_id'] = $session['session_id'];
        $state['devices'][$index]['exchanged_at'] = $now;
        $state['devices'][$index]['completion_code'] = '';
        $state['devices'][$index]['completion_expires_at'] = 0;
        $state['sessions'][$session['session_id']] = $session['record'];
        $this->save_state($state);

        return $session['payload'];
    }

    public function refresh_access_token(string $refresh_token)
    {
        $state = $this->load_state();
        $session_id = $this->find_session_id_by_hash($state['sessions'], $this->token_hash($refresh_token), 'refresh_token_hash');
        if ($session_id === null) {
            return new WP_Error('invalid_grant', __('The refresh token is invalid.', 'vibepresto'));
        }

        $session = $state['sessions'][$session_id];
        $now = time();

        if (! empty($session['revoked_at'])) {
            return new WP_Error('invalid_grant', __('That session has been revoked.', 'vibepresto'));
        }

        if (($session['refresh_expires_at'] ?? 0) < $now) {
            return new WP_Error('expired_token', __('The refresh token has expired.', 'vibepresto'));
        }

        $access_token = $this->generate_token('vp_at');
        $state['sessions'][$session_id]['access_token_hash'] = $this->token_hash($access_token);
        $state['sessions'][$session_id]['access_expires_at'] = $now + self::ACCESS_TTL;
        $state['sessions'][$session_id]['last_used_at'] = $now;
        $this->save_state($state);

        return $this->build_token_payload($session_id, $state['sessions'][$session_id], $access_token, $refresh_token);
    }

    public function authenticate_access_token(string $access_token)
    {
        $state = $this->load_state();
        $session_id = $this->find_session_id_by_hash($state['sessions'], $this->token_hash($access_token), 'access_token_hash');
        if ($session_id === null) {
            return new WP_Error('invalid_token', __('The access token is invalid.', 'vibepresto'));
        }

        $session = $state['sessions'][$session_id];
        $now = time();

        if (! empty($session['revoked_at'])) {
            return new WP_Error('invalid_token', __('That session has been revoked.', 'vibepresto'));
        }

        if (($session['access_expires_at'] ?? 0) < $now) {
            return new WP_Error('expired_token', __('The access token has expired.', 'vibepresto'));
        }

        $user = get_user_by('id', (int) $session['user_id']);
        if (! $user) {
            return new WP_Error('invalid_token', __('The authorized WordPress user no longer exists.', 'vibepresto'));
        }

        $state['sessions'][$session_id]['last_used_at'] = $now;
        $this->save_state($state);

        return [
            'session_id' => $session_id,
            'session' => $state['sessions'][$session_id],
            'user' => $user,
        ];
    }

    public function revoke_session(string $session_id): bool
    {
        $state = $this->load_state();
        if (empty($state['sessions'][$session_id])) {
            return false;
        }

        $state['sessions'][$session_id]['revoked_at'] = time();
        $this->save_state($state);

        return true;
    }

    public function get_sessions(): array
    {
        $state = $this->load_state();
        $sessions = [];

        foreach ($state['sessions'] as $session_id => $session) {
            $user = get_user_by('id', (int) $session['user_id']);
            $sessions[] = [
                'session_id' => $session_id,
                'client_name' => $session['client_name'],
                'machine_name' => $session['machine_name'],
                'scope' => $session['scope'],
                'created_at' => $session['created_at'],
                'last_used_at' => $session['last_used_at'],
                'access_expires_at' => $session['access_expires_at'],
                'refresh_expires_at' => $session['refresh_expires_at'],
                'revoked_at' => $session['revoked_at'],
                'user_id' => $session['user_id'],
                'user_display_name' => $user ? $user->display_name : __('Unknown user', 'vibepresto'),
            ];
        }

        usort($sessions, static function (array $left, array $right): int {
            return ($right['created_at'] ?? 0) <=> ($left['created_at'] ?? 0);
        });

        return $sessions;
    }

    public function cleanup_expired(): void
    {
        $state = $this->cleanup_state($this->load_state(), time());
        $this->save_state($state);
    }

    private function create_session(int $user_id, string $client_name, string $machine_name, array $scope): array
    {
        $session_id = bin2hex(random_bytes(16));
        $access_token = $this->generate_token('vp_at');
        $refresh_token = $this->generate_token('vp_rt');
        $now = time();

        $record = [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'client_name' => $client_name,
            'machine_name' => $machine_name,
            'scope' => $scope,
            'created_at' => $now,
            'last_used_at' => $now,
            'access_token_hash' => $this->token_hash($access_token),
            'access_expires_at' => $now + self::ACCESS_TTL,
            'refresh_token_hash' => $this->token_hash($refresh_token),
            'refresh_expires_at' => $now + self::REFRESH_TTL,
            'revoked_at' => 0,
        ];

        return [
            'session_id' => $session_id,
            'record' => $record,
            'payload' => $this->build_token_payload($session_id, $record, $access_token, $refresh_token),
        ];
    }

    private function build_token_payload(string $session_id, array $session, string $access_token, string $refresh_token): array
    {
        $user = get_user_by('id', (int) $session['user_id']);

        return [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'token_type' => 'Bearer',
            'expires_in' => max(0, (int) $session['access_expires_at'] - time()),
            'scope' => $session['scope'],
            'site_url' => home_url('/'),
            'user_display_name' => $user ? $user->display_name : '',
            'session_id' => $session_id,
        ];
    }

    private function load_state(): array
    {
        $state = get_option(self::OPTION_KEY, [
            'devices' => [],
            'sessions' => [],
            'request_log' => [],
        ]);

        if (! is_array($state)) {
            $state = [];
        }

        $state['devices'] = is_array($state['devices'] ?? null) ? $state['devices'] : [];
        $state['sessions'] = is_array($state['sessions'] ?? null) ? $state['sessions'] : [];
        $state['request_log'] = is_array($state['request_log'] ?? null) ? $state['request_log'] : [];

        return $state;
    }

    private function save_state(array $state): void
    {
        update_option(self::OPTION_KEY, $state, false);
    }

    private function find_device_index(array $devices, ?string $device_code, ?string $completion_code): ?string
    {
        if ($device_code !== null && isset($devices[$device_code])) {
            return $device_code;
        }

        if ($completion_code === null) {
            return null;
        }

        foreach ($devices as $candidate_code => $device) {
            if (($device['completion_code'] ?? '') !== '' && hash_equals($device['completion_code'], $completion_code)) {
                return $candidate_code;
            }
        }

        return null;
    }

    private function find_session_id_by_hash(array $sessions, string $hash, string $field): ?string
    {
        foreach ($sessions as $session_id => $session) {
            if (! empty($session[$field]) && hash_equals($session[$field], $hash)) {
                return $session_id;
            }
        }

        return null;
    }

    private function generate_user_code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 8; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    private function generate_token(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(32));
    }

    private function token_hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private function cleanup_state(array $state, int $now): array
    {
        foreach ($state['devices'] as $device_code => $device) {
            $device_expired = ($device['expires_at'] ?? 0) < ($now - self::COMPLETION_TTL);
            $completed_old = ($device['status'] ?? '') === 'completed' && ($device['exchanged_at'] ?? 0) < ($now - self::COMPLETION_TTL);
            $denied_old = ($device['status'] ?? '') === 'denied' && ($device['denied_at'] ?? 0) < ($now - self::COMPLETION_TTL);

            if ($device_expired || $completed_old || $denied_old) {
                unset($state['devices'][$device_code]);
            }
        }

        foreach ($state['sessions'] as $session_id => $session) {
            $refresh_expired = ($session['refresh_expires_at'] ?? 0) < $now;
            $revoked_old = ! empty($session['revoked_at']) && $session['revoked_at'] < ($now - self::COMPLETION_TTL);
            if ($refresh_expired || $revoked_old) {
                unset($state['sessions'][$session_id]);
            }
        }

        foreach ($state['request_log'] as $request_ip => $timestamps) {
            $recent = $this->recent_request_timestamps(is_array($timestamps) ? $timestamps : [], $now);
            if ($recent) {
                $state['request_log'][$request_ip] = $recent;
                continue;
            }

            unset($state['request_log'][$request_ip]);
        }

        return $state;
    }

    private function recent_request_timestamps(array $timestamps, int $now): array
    {
        $threshold = $now - self::DEVICE_REQUEST_WINDOW;

        return array_values(array_filter($timestamps, static function ($timestamp) use ($threshold): bool {
            return is_numeric($timestamp) && (int) $timestamp >= $threshold;
        }));
    }

    private function active_device_requests(array $devices): array
    {
        $active = [];

        foreach ($devices as $device) {
            if (! is_array($device)) {
                continue;
            }

            if ($this->is_active_device($device)) {
                $active[] = $device;
            }
        }

        return $active;
    }

    private function is_active_device(array $device): bool
    {
        return in_array($device['status'] ?? '', ['pending', 'approved'], true);
    }

    private function prune_oldest_pending_devices(array &$state, int $threshold, string $protected_device_code = ''): void
    {
        $pending = [];

        foreach ($state['devices'] as $device_code => $device) {
            if (! is_array($device) || ($device['status'] ?? '') !== 'pending' || $device_code === $protected_device_code) {
                continue;
            }

            $pending[$device_code] = (int) ($device['created_at'] ?? 0);
        }

        if (count($pending) <= $threshold) {
            return;
        }

        asort($pending, SORT_NUMERIC);
        $removeCount = count($pending) - $threshold;
        $deviceCodes = array_slice(array_keys($pending), 0, $removeCount);

        foreach ($deviceCodes as $deviceCode) {
            unset($state['devices'][$deviceCode]);
        }
    }

    private function normalize_request_ip(string $request_ip): string
    {
        $request_ip = trim($request_ip);

        if ($request_ip === '' || strlen($request_ip) > 64) {
            return 'unknown';
        }

        return $request_ip;
    }
}
