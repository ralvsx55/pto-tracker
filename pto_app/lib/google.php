<?php
declare(strict_types=1);

/**
 * Google Calendar API v3 client for a service account (SPEC section 14.1).
 *
 * - Credentials: PTO_DATA/google-service-account.json (test override: $GLOBALS['google_service_account'] array).
 * - Token: RS256 JWT signed with openssl_sign, exchanged at oauth2.googleapis.com/token, cached in settings
 *   (google_token_cache / google_token_expires_at) for 55 minutes.
 * - Transport is swappable: $GLOBALS['google_transport'] = callable(method, url, headers[], ?body): [status, jsonBody].
 *   The default is curl (never file_get_contents on a URL).
 * - Write guard: google_writes_allowed(). Every write helper checks it; reads are always allowed.
 */

const GOOGLE_API_BASE    = 'https://www.googleapis.com/calendar/v3/';
const GOOGLE_TOKEN_URL   = 'https://oauth2.googleapis.com/token';
const GOOGLE_SCOPE       = 'https://www.googleapis.com/auth/calendar.events';
const GOOGLE_TOKEN_TTL   = 55 * 60;                    // cache a 3600 s token for 55 minutes
const GOOGLE_LIST_MIN    = '2021-01-01T00:00:00Z';
const GOOGLE_LIST_MAX    = '2040-01-01T00:00:00Z';
const GOOGLE_LIST_FIELDS = 'nextPageToken,items(id,summary,start,end,extendedProperties,status)';

/** Thrown for any API error that is not retried away. status 0 = transport (curl) / local failure. */
class GoogleApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $message)
    {
        parent::__construct($message, $status);
    }
}

/** Where the service-account key file lives. */
function google_key_path(): string
{
    return PTO_DATA . '/google-service-account.json';
}

/** The decoded key file (client_email, private_key, ...) or null when missing/unreadable. */
function google_key(): ?array
{
    if (isset($GLOBALS['google_service_account']) && is_array($GLOBALS['google_service_account'])) {
        $k = $GLOBALS['google_service_account'];
    } else {
        $path = google_key_path();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $k = $raw === false ? null : json_decode($raw, true);
    }
    if (!is_array($k) || empty($k['client_email']) || empty($k['private_key'])) {
        return null;
    }
    return $k;
}

/**
 * SPEC 14.1 write guard: production environment AND (sync_mode live OR an explicit admin action).
 * Locally nothing is ever written. $explicit is used only by Test connection, Force, Adopt and Wipe-and-regenerate.
 */
function google_writes_allowed(bool $explicit = false): bool
{
    if (config('environment', 'local') !== 'production') {
        return false;
    }
    return $explicit || setting('sync_mode', 'dry_run') === 'live';
}

/**
 * Setup status for Admin > Calendars and the dashboard strip (SPEC 14.1 / 14.5).
 * 'configured' (= 'key_file') is true when the key file exists and parses; 'token_ok' only after a probe.
 * $probeToken = false skips the network round trip (the dashboard only needs "is it configured").
 */
function google_status(bool $probeToken = true): array
{
    $st = [
        'configured'     => false,                       // key file present and parses (client_email + private_key)
        'key_file'       => false,
        'key_path'       => google_key_path(),
        'client_email'   => null,
        'token_ok'       => false,
        'error'          => null,
        'environment'    => (string) config('environment', 'local'),
        'sync_mode'      => (string) setting('sync_mode', 'dry_run'),
        'writes_allowed' => google_writes_allowed(),
        'message'        => '',
    ];
    $key = google_key();
    if ($key === null) {
        $st['message'] = 'Service account key file not found (' . $st['key_path'] . ').';
        return $st;
    }
    $st['key_file'] = true;
    $st['configured'] = true;
    $st['client_email'] = (string) $key['client_email'];
    if ($probeToken) {
        try {
            google_access_token();
            $st['token_ok'] = true;
            $st['message'] = 'Key file present, access token OK. Share each calendar with ' . $st['client_email'] . ' ("Make changes to events").';
        } catch (Throwable $e) {
            $st['error'] = $e->getMessage();
            $st['message'] = 'Key file present but no access token: ' . $e->getMessage();
        }
    } else {
        $st['message'] = 'Key file present (' . $st['client_email'] . ').';
    }
    if (!$st['writes_allowed']) {
        $st['message'] .= $st['environment'] !== 'production'
            ? ' Environment is not production: sync is dry-run only.'
            : ' sync_mode is dry_run: nothing is written until it is switched to live.';
    }
    return $st;
}

// --- transport ---------------------------------------------------------------------------------------

/** One HTTP call through the swappable transport: [status, decodedJsonBody]. */
function google_transport(string $method, string $url, array $headers, ?string $body): array
{
    $t = $GLOBALS['google_transport'] ?? null;
    if (is_callable($t)) {
        $r = $t($method, $url, $headers, $body);
        return [(int) ($r[0] ?? 0), is_array($r[1] ?? null) ? $r[1] : []];
    }
    return google_transport_curl($method, $url, $headers, $body);
}

/** The real transport (curl only, SPEC 3). Throws GoogleApiError(0, 'curl', ...) when the request never completes. */
function google_transport_curl(string $method, string $url, array $headers, ?string $body): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new GoogleApiError(0, 'curl', 'curl_init failed');
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new GoogleApiError(0, 'curl', 'HTTP request failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = $raw === '' ? [] : json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => substr((string) $raw, 0, 500)];
    }
    return [$status, $decoded];
}

/** Back-off sleep; $GLOBALS['google_sleep'] (callable(int)) lets tests skip the wait. */
function google_sleep(int $seconds): void
{
    $fn = $GLOBALS['google_sleep'] ?? null;
    if (is_callable($fn)) {
        $fn($seconds);
        return;
    }
    sleep($seconds);
}

// --- token -------------------------------------------------------------------------------------------

function google_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** RS256 JWT for the service account (SPEC 14.1). */
function google_jwt(array $key): string
{
    $now = time();
    $header = google_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = google_b64url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => GOOGLE_SCOPE,
        'aud'   => GOOGLE_TOKEN_URL,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ], JSON_THROW_ON_ERROR));
    $input = $header . '.' . $claims;
    $sig = '';
    $pkey = openssl_pkey_get_private((string) $key['private_key']);
    if ($pkey === false || !openssl_sign($input, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new GoogleApiError(0, 'jwt', 'Cannot sign the JWT with the service-account private key: ' . (string) openssl_error_string());
    }
    return $input . '.' . google_b64url($sig);
}

/** A valid access token: from the settings cache when fresh, else exchanged and cached for 55 minutes. */
function google_access_token(bool $forceRefresh = false): string
{
    if (!$forceRefresh) {
        $cached = setting('google_token_cache');
        $exp = (int) (setting('google_token_expires_at') ?? '0');
        if ($cached !== null && $cached !== '' && $exp > time()) {
            return $cached;
        }
    }
    $key = google_key();
    if ($key === null) {
        throw new GoogleApiError(0, 'no_key', 'Service account key file not found: ' . google_key_path());
    }
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => google_jwt($key),
    ]);
    [$status, $json] = google_transport('POST', GOOGLE_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
    $token = $json['access_token'] ?? null;
    if ($status !== 200 || !is_string($token) || $token === '') {
        $desc = (string) ($json['error_description'] ?? $json['error'] ?? $json['raw'] ?? 'no access_token in response');
        throw new GoogleApiError($status, 'token', "Token exchange failed (HTTP $status): $desc");
    }
    setting_set('google_token_cache', $token);
    setting_set('google_token_expires_at', (string) (time() + GOOGLE_TOKEN_TTL));
    return $token;
}

// --- requests ----------------------------------------------------------------------------------------

/**
 * One Calendar API call (SPEC 14.1). $path is relative to https://www.googleapis.com/calendar/v3/.
 * 401 -> refresh the token once; 403 rateLimitExceeded / 429 / 5xx -> back off 1, 2, 4, 8 s; anything else throws.
 * Returns the decoded JSON body ([] for 204).
 */
function google_request(string $method, string $path, ?array $query = null, ?array $body = null): array
{
    $url = GOOGLE_API_BASE . ltrim($path, '/');
    if ($query !== null && $query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $backoff = [1, 2, 4, 8];
    $attempt = 0;
    $refreshed = false;
    while (true) {
        $headers = ['Authorization: Bearer ' . google_access_token(), 'Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        [$status, $json] = google_transport($method, $url, $headers, $payload);
        if ($status >= 200 && $status < 300) {
            return $json;
        }
        $reason = (string) ($json['error']['errors'][0]['reason'] ?? $json['error']['status'] ?? '');
        $message = (string) ($json['error']['message'] ?? $json['raw'] ?? "HTTP $status");
        if ($status === 401 && !$refreshed) {
            $refreshed = true;
            google_access_token(true);
            continue;
        }
        $rateLimited = $status === 429 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true));
        if (($rateLimited || $status >= 500) && $attempt < count($backoff)) {
            google_sleep($backoff[$attempt]);
            $attempt++;
            continue;
        }
        throw new GoogleApiError($status, $reason, "$method $path: HTTP $status" . ($reason !== '' ? " $reason" : '') . ": $message");
    }
}

/** Refuse a write unless google_writes_allowed(). Every write helper calls this first. */
function google_require_writes(string $what, bool $explicit = false): void
{
    if (!google_writes_allowed($explicit)) {
        throw new GoogleApiError(0, 'writes_disabled', "Refusing to $what: Google writes are not allowed in this environment/mode.");
    }
}

/**
 * All-day event body (SPEC 14.1 event shape): summary, start.date, end.date (exclusive),
 * extendedProperties.private.lsp = key, optional description ("Birthday").
 */
function google_event_body(string $title, string $startDate, string $endExclusive, string $lspKey, ?string $description = null): array
{
    $ev = [
        'summary'            => $title,
        'start'              => ['date' => $startDate],
        'end'                => ['date' => $endExclusive],
        'extendedProperties' => ['private' => ['lsp' => $lspKey]],
    ];
    if ($description !== null) {
        $ev['description'] = $description;
    }
    return $ev;
}

/**
 * Every event on a calendar (singleEvents, 2021..2040, 2500 per page, limited fields, follows nextPageToken).
 * $lspKey restricts the listing to events whose private property lsp equals it. Cancelled events are dropped.
 */
function google_list_events(string $calendarId, ?string $lspKey = null): array
{
    $items = [];
    $pageToken = null;
    $path = 'calendars/' . rawurlencode($calendarId) . '/events';
    do {
        $query = [
            'singleEvents' => 'true',
            'timeMin'      => GOOGLE_LIST_MIN,
            'timeMax'      => GOOGLE_LIST_MAX,
            'maxResults'   => 2500,
            'fields'       => GOOGLE_LIST_FIELDS,
        ];
        if ($lspKey !== null) {
            $query['privateExtendedProperty'] = 'lsp=' . $lspKey;
        }
        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }
        $json = google_request('GET', $path, $query);
        foreach ($json['items'] ?? [] as $ev) {
            if (!is_array($ev) || !isset($ev['id'])) {
                continue;
            }
            if (($ev['status'] ?? 'confirmed') === 'cancelled') {
                continue;
            }
            $items[] = $ev;
        }
        $pageToken = isset($json['nextPageToken']) && $json['nextPageToken'] !== '' ? (string) $json['nextPageToken'] : null;
    } while ($pageToken !== null);
    return $items;
}

/** INSERT; returns the created event (Google generates the id). Explicit admin writes pass $explicit = true. */
function google_insert_event(string $calendarId, array $event, bool $explicit = false): array
{
    google_require_writes('insert an event', $explicit);
    return google_request('POST', 'calendars/' . rawurlencode($calendarId) . '/events', null, $event);
}

/** PATCH; throws GoogleApiError(404) when the event is gone. */
function google_patch_event(string $calendarId, string $eventId, array $patch, bool $explicit = false): array
{
    google_require_writes('patch an event', $explicit);
    return google_request('PATCH', 'calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId), null, $patch);
}

/** DELETE; a 404/410 (already gone) counts as done (SPEC 14.1). */
function google_delete_event(string $calendarId, string $eventId, bool $explicit = false): void
{
    google_require_writes('delete an event', $explicit);
    try {
        google_request('DELETE', 'calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId));
    } catch (GoogleApiError $e) {
        if ($e->status === 404 || $e->status === 410) {
            return;
        }
        throw $e;
    }
}

/** GET one event, or null when it does not exist. */
function google_get_event(string $calendarId, string $eventId): ?array
{
    try {
        $ev = google_request('GET', 'calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId));
    } catch (GoogleApiError $e) {
        if ($e->status === 404 || $e->status === 410) {
            return null;
        }
        throw $e;
    }
    if (($ev['status'] ?? 'confirmed') === 'cancelled') {
        return null;
    }
    return $ev;
}
