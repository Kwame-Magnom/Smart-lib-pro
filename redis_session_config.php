<?php
declare(strict_types=1);

function redis_session_save_path(string $redisUrl, bool $requireTls = false): string
{
    $parts = parse_url($redisUrl);
    if (!is_array($parts)) {
        throw new InvalidArgumentException('REDIS_URL is not a valid Redis connection URL.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['redis', 'rediss'], true)) {
        throw new InvalidArgumentException('REDIS_URL must use the redis:// or rediss:// scheme.');
    }
    if ($requireTls && $scheme !== 'rediss') {
        throw new InvalidArgumentException('Vercel Redis sessions require a TLS rediss:// URL.');
    }

    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        throw new InvalidArgumentException('REDIS_URL must include a host.');
    }
    if (strpos($host, ':') !== false && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : 6379;
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('REDIS_URL contains an invalid port.');
    }

    $database = null;
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && $path !== '/') {
        $pathDatabase = substr($path, 1);
        if ($pathDatabase === '' || !ctype_digit($pathDatabase) || strpos($pathDatabase, '/') !== false) {
            throw new InvalidArgumentException('REDIS_URL path must contain only a Redis database number.');
        }
        $database = (int) $pathDatabase;
    }

    $rawQuery = (string) ($parts['query'] ?? '');
    $query = [];
    parse_str($rawQuery, $query);
    if (array_diff(array_keys($query), ['database']) !== []) {
        throw new InvalidArgumentException('REDIS_URL supports only the database query parameter.');
    }
    $databaseQueryCount = 0;
    foreach (explode('&', $rawQuery) as $queryPart) {
        $queryKey = explode('=', $queryPart, 2)[0];
        if (rawurldecode($queryKey) === 'database') {
            $databaseQueryCount++;
        }
    }
    if ($databaseQueryCount > 1) {
        throw new InvalidArgumentException('REDIS_URL must not repeat the database query parameter.');
    }
    if (array_key_exists('database', $query)) {
        if ($database !== null || !is_string($query['database']) || !ctype_digit($query['database'])) {
            throw new InvalidArgumentException('REDIS_URL contains an invalid or repeated database number.');
        }
        $database = (int) $query['database'];
    }
    $database = $database ?? 0;

    $username = rawurldecode((string) ($parts['user'] ?? ''));
    $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null;
    if ($username !== '' && ($password === null || $password === '')) {
        throw new InvalidArgumentException('REDIS_URL must include a password when a username is provided.');
    }
    if ($requireTls && ($password === null || $password === '')) {
        throw new InvalidArgumentException('Vercel Redis sessions require an authenticated Redis URL.');
    }

    $queryParts = [
        'timeout=2.5',
        'read_timeout=2.5',
        'database=' . $database,
        'prefix=' . rawurlencode('smartlib_session:'),
    ];
    if ($password !== null && $password !== '') {
        if ($username !== '') {
            $queryParts[] = 'auth[]=' . rawurlencode($username);
            $queryParts[] = 'auth[]=' . rawurlencode($password);
        } else {
            $queryParts[] = 'auth=' . rawurlencode($password);
        }
    }

    $transport = $scheme === 'rediss' ? 'tls' : 'tcp';
    return $transport . '://' . $host . ':' . $port . '?' . implode('&', $queryParts);
}
