<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * The database arm of {@see ConnectorTester}: 1) TCP socket to server:port (default 5432);
 * 2) open a real PDO connection to the informed database as the informed user (reports the server
 * version); 3) only when a schema is configured — it exists and the user has USAGE on it.
 * PostgreSQL only for now ({@see ConnectorTester::ENGINE_POSTGRESQL}).
 *
 * Credentials are passed as PDO constructor arguments, never in the DSN, and any password is
 * scrubbed from an exception text before it is reported. The PDO driver sits behind protected
 * one-call methods so a test can stand in for a database.
 */
class DatabaseConnectorProbe extends ConnectorProbe
{
    private const int POSTGRES_DEFAULT_PORT = 5432;

    /**
     * @param array<string, mixed> $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    public function test(array $config): array
    {
        // Absent engine = a config written before the field existed; PostgreSQL is the only value.
        $engine = trim((string) ($config['engine'] ?? ConnectorTester::ENGINE_POSTGRESQL));
        if ($engine !== '' && $engine !== ConnectorTester::ENGINE_POSTGRESQL) {
            return $this->result([$this->step(
                'Configuration',
                false,
                sprintf('Only PostgreSQL is supported for now — "%s" cannot be tested.', $engine)
            )]);
        }

        [$host, $port] = $this->resolveEndpoint($config);
        $problem = $this->endpointProblem($host, $port);
        if ($problem !== null) {
            return $problem;
        }

        [$steps, $reachable] = $this->socketSteps($host, $port, false, 'Connection');
        if ($reachable) {
            $steps = [...$steps, ...$this->databaseSteps($host, $port, $config)];
        }

        return $this->result($steps);
    }

    /**
     * The Connection step and — only when a schema is configured — the Schema step.
     *
     * @param array<string, mixed> $config
     *
     * @return list<array{label: string, success: bool, message: string}>
     */
    private function databaseSteps(string $host, int $port, array $config): array
    {
        [$connected, $connectionMessage, $pdo] = $this->connect($host, $port, $config);
        $steps = [$this->step('Connection', $connected, $connectionMessage)];

        // The schema is optional — only report on it when one is configured.
        $schema = trim((string) ($config['schema'] ?? ''));
        if ($schema !== '') {
            $steps[] = $this->step('Schema', ...($pdo instanceof \PDO
                ? $this->schemaCheck($pdo, $schema)
                : [false, 'Skipped — no connection to the database.']));
        }

        return $steps;
    }

    /**
     * Opens a real connection with the informed credentials and reports the server version.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string, 2: \PDO|null}
     */
    private function connect(string $host, int $port, array $config): array
    {
        $database = trim((string) ($config['database'] ?? ''));
        $user = trim((string) ($config['user'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        $unusable = $this->preconditionError($host, $database, $user);
        if ($unusable !== null) {
            return [false, $unusable, null];
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
            $host,
            $port,
            $database,
            self::SOCKET_TIMEOUT
        );

        try {
            // Credentials go as constructor arguments, never inside the DSN — a failure message
            // built from the DSN would otherwise carry them.
            $pdo = $this->openPdo($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => self::SOCKET_TIMEOUT,
            ]);
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return [
                true,
                sprintf(
                    'Connected to database "%s" as "%s"%s.',
                    $database,
                    $user,
                    $version === '' ? '' : ' — PostgreSQL ' . $version
                ),
                $pdo,
            ];
        } catch (\Throwable $e) {
            return [false, 'Connection failed: ' . $this->withoutSecret($e->getMessage(), $password), null];
        }
    }

    /**
     * Why a connection cannot even be attempted, null when it can. A ";" in the server or database
     * is refused because PDO splits the pgsql DSN on it BEFORE libpq sees it, so the value would
     * inject another connection parameter — no quoting can rescue that.
     */
    private function preconditionError(string $host, string $database, string $user): ?string
    {
        return match (true) {
            $database === '' => 'No database configured.',
            $user === '' => 'No user configured.',
            !\in_array('pgsql', $this->availableDrivers(), true) => 'The pdo_pgsql PHP extension is not installed on the server.',
            str_contains($host, ';') => 'The server must not contain ";".',
            str_contains($database, ';') => 'The database must not contain ";".',
            default => null,
        };
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function schemaCheck(\PDO $pdo, string $schema): array
    {
        try {
            // pg_namespace, NOT information_schema.schemata: the latter only lists schemas the
            // current user OWNS, so an existing schema the user merely reads would look missing.
            // The ::int cast avoids depending on how the driver hands back a boolean.
            $statement = $pdo->prepare(
                'SELECT has_schema_privilege(nspname, \'USAGE\')::int FROM pg_catalog.pg_namespace WHERE nspname = :schema'
            );
            $statement->execute(['schema' => $schema]);
            $usable = $statement->fetchColumn();

            return match (true) {
                $usable === false => [false, sprintf('Schema "%s" does not exist in this database.', $schema)],
                (int) $usable === 1 => [true, sprintf('Schema "%s" exists and is accessible.', $schema)],
                default => [false, sprintf('Schema "%s" exists but the user has no USAGE permission on it.', $schema)],
            };
        } catch (\Throwable $e) {
            return [false, 'The schema check failed: ' . $e->getMessage()];
        }
    }

    /**
     * Resolves host/port for a database endpoint: a bare host, or "host:port" — no scheme is
     * involved, unlike the HTTP-based connectors.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: string, 1: int|null}
     */
    private function resolveEndpoint(array $config): array
    {
        $host = trim((string) ($config['server'] ?? ''));
        $hostPort = null;
        if (str_contains($host, ':')) {
            [$host, $rawPort] = explode(':', $host, 2);
            $hostPort = (int) $rawPort;
        }

        return [$host, $this->normalizePort($config['port'] ?? null, $hostPort ?? self::POSTGRES_DEFAULT_PORT)];
    }

    /** Belt and braces: strips a secret from a message built by code we do not control. */
    private function withoutSecret(string $message, string $secret): string
    {
        return $secret === '' ? $message : str_replace($secret, ConnectorConfigSecrets::SENTINEL, $message);
    }

    // I/O boundaries to the PDO driver: protected, one call each.

    /**
     * @return array<int, string>
     */
    protected function availableDrivers(): array
    {
        return \PDO::getAvailableDrivers();
    }

    /**
     * @param array<int, mixed> $options
     */
    protected function openPdo(string $dsn, string $user, string $password, array $options): \PDO
    {
        return new \PDO($dsn, $user, $password, $options);
    }
}
