<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/redis_session_config.php';

function redis_config_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}

$savePath = redis_session_save_path('rediss://default:p%40ss%2Bword@redis.example.test:6380/2', true);
$parts = parse_url($savePath);
$parameters = [];
parse_str((string) ($parts['query'] ?? ''), $parameters);
redis_config_test_assert(($parts['scheme'] ?? '') === 'tls', 'rediss URLs use the TLS transport');
redis_config_test_assert(($parts['host'] ?? '') === 'redis.example.test', 'Redis host is retained');
redis_config_test_assert(($parts['port'] ?? 0) === 6380, 'Redis port is retained');
redis_config_test_assert(($parameters['database'] ?? '') === '2', 'Redis database is retained');
redis_config_test_assert(
    ($parameters['auth'] ?? []) === ['default', 'p@ss+word'],
    'URL-encoded Redis credentials are decoded and passed as ACL authentication'
);
redis_config_test_assert(
    redis_session_save_path('redis://localhost') === 'tcp://localhost:6379?timeout=2.5&read_timeout=2.5&database=0&prefix=smartlib_session%3A',
    'Local Redis defaults to TCP, database zero, and an isolated session prefix'
);

$passwordOnlyPath = redis_session_save_path('rediss://:p%40ssword@redis.example.test:6380/0', true);
$passwordOnlyParts = parse_url($passwordOnlyPath);
$passwordOnlyParams = [];
parse_str((string) ($passwordOnlyParts['query'] ?? ''), $passwordOnlyParams);
redis_config_test_assert(($passwordOnlyParts['scheme'] ?? '') === 'tls', 'Password-only rediss uses TLS transport');
redis_config_test_assert(($passwordOnlyParams['auth'] ?? '') === 'p@ssword', 'Password-only auth is passed as a string parameter');

$localPasswordPath = redis_session_save_path('redis://:localsecret@127.0.0.1:6379');
$localPasswordParts = parse_url($localPasswordPath);
$localPasswordParams = [];
parse_str((string) ($localPasswordParts['query'] ?? ''), $localPasswordParams);
redis_config_test_assert(($localPasswordParts['scheme'] ?? '') === 'tcp', 'Local password-only Redis uses TCP transport');
redis_config_test_assert(($localPasswordParams['auth'] ?? '') === 'localsecret', 'Local password-only auth is passed as a string parameter');

$ipv6Path = redis_session_save_path('rediss://default:pass@[::1]:6380', true);
$ipv6Parts = parse_url($ipv6Path);
redis_config_test_assert(($ipv6Parts['host'] ?? '') === '[::1]', 'IPv6 host is bracketed properly');

foreach ([
    ['https://redis.example.test', false],
    ['redis://redis.example.test', true],
    ['rediss://user@redis.example.test', false],
    ['rediss://user:@redis.example.test:6380', false],
    ['redis://user:@localhost:6379', false],
    ['rediss://:@redis.example.test:6380', true],
    ['rediss://redis.example.test:70000', false],
    ['rediss://redis.example.test', true],
    ['rediss://redis.example.test/1?database=2', false],
    ['rediss://redis.example.test?database=1&database=2', false],
] as [$url, $requireTls]) {
    $thrown = false;
    try {
        redis_session_save_path($url, $requireTls);
    } catch (InvalidArgumentException $exception) {
        $thrown = true;
    }
    redis_config_test_assert($thrown, 'Invalid Redis URLs are rejected');
}

echo "Redis session config tests passed.\n";
