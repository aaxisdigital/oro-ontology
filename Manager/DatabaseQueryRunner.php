<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\DatabaseQueryFailure;

/**
 * Executes the "SQL Query" flow step against a DATABASE connector (PostgreSQL only, like
 * {@see ConnectorTester} — the two keep the same connection rules deliberately).
 *
 * The SQL may carry NAMED placeholders (`:name`), bound per run from the step's Bindings result:
 *  - null            → ONE run, no parameters;
 *  - an object       → ONE run, its keys feeding the placeholders;
 *  - a LIST          → N runs, one per element (each an object), same prepared statement.
 *
 * Result contract: a resultset-producing statement (SELECT, INSERT … RETURNING) yields its rows
 * as a LIST of objects; anything else yields `{affected: <row count>}`. With a LIST binding the
 * step's destination receives the ARRAY of per-run results, in order.
 *
 * PostgreSQL `::type` casts are NOT placeholders — both the extraction here and PDO itself skip
 * doubled colons; placeholders inside string literals/quoted identifiers are ignored too.
 */
class DatabaseQueryRunner
{
    private const int CONNECT_TIMEOUT = 10;

    /**
     * @param mixed $bindings null, one associative array, or a list of associative arrays
     *
     * @return mixed rows / {affected} — or the list of per-run results for a LIST binding
     *
     * @throws DatabaseQueryFailure (a \RuntimeException) with a user-readable message (the executor prefixes the step name)
     */
    public function run(OntologyConnector $connector, string $sql, mixed $bindings = null): mixed
    {
        if ($connector->getType() !== OntologyConnector::TYPE_DATABASE) {
            throw new DatabaseQueryFailure(sprintf('connector "%s" is not a database connector', (string) $connector->getName()));
        }
        $sql = trim($sql);
        if ($sql === '') {
            throw new DatabaseQueryFailure('the SQL expression resolved to an empty text');
        }

        // Normalize the binding shapes up front so a bad one fails before touching the database.
        $runs = $this->bindingRuns($bindings);
        $isList = $runs['list'];

        $pdo = $this->connect($connector->getConfig() ?? []);
        $placeholders = $this->placeholders($sql);
        $statement = $this->prepare($pdo, $sql);

        $results = [];
        foreach ($runs['sets'] as $index => $set) {
            $runNumber = $isList ? $index + 1 : null;
            $this->bindRun($statement, $placeholders, $set, $runNumber);
            $results[] = $this->execute($statement, $runNumber);
        }

        return $isList ? $results : $results[0];
    }

    private function prepare(\PDO $pdo, string $sql): \PDOStatement
    {
        try {
            return $pdo->prepare($sql);
        } catch (\PDOException $e) {
            throw new DatabaseQueryFailure('the SQL could not be prepared: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Binds one run's parameter set to the statement. $runNumber is the 1-based position in a LIST
     * binding (named in the error messages), null for a single run.
     *
     * @param array<int, string>   $placeholders
     * @param array<string, mixed> $set
     */
    private function bindRun(\PDOStatement $statement, array $placeholders, array $set, ?int $runNumber): void
    {
        foreach ($placeholders as $name) {
            if (!\array_key_exists($name, $set)) {
                throw new DatabaseQueryFailure(sprintf(
                    'the SQL uses :%s but the bindings%s carry no such key',
                    $name,
                    $runNumber !== null ? sprintf(' of run #%d', $runNumber) : ''
                ));
            }
            $value = $set[$name];
            if (!\is_scalar($value) && $value !== null) {
                throw new DatabaseQueryFailure(sprintf(
                    'binding "%s"%s must be a scalar or null, got %s',
                    $name,
                    $runNumber !== null ? sprintf(' of run #%d', $runNumber) : '',
                    get_debug_type($value)
                ));
            }
            $statement->bindValue(':' . $name, $value, $this->parameterType($value));
        }
    }

    private function parameterType(mixed $value): int
    {
        return match (true) {
            $value === null => \PDO::PARAM_NULL,
            \is_bool($value) => \PDO::PARAM_BOOL,
            \is_int($value) => \PDO::PARAM_INT,
            default => \PDO::PARAM_STR,
        };
    }

    /**
     * A resultset (SELECT / RETURNING) yields rows; a plain DML yields the affected count.
     *
     * @return array<int, mixed>
     */
    private function execute(\PDOStatement $statement, ?int $runNumber): array
    {
        try {
            $statement->execute();
        } catch (\PDOException $e) {
            throw new DatabaseQueryFailure(sprintf(
                'the query failed%s: %s',
                $runNumber !== null ? sprintf(' on run #%d', $runNumber) : '',
                $e->getMessage()
            ), 0, $e);
        }

        return $statement->columnCount() > 0
            ? $statement->fetchAll(\PDO::FETCH_ASSOC)
            : ['affected' => $statement->rowCount()];
    }

    /**
     * @return array{list: bool, sets: array<int, array<string, mixed>>}
     */
    private function bindingRuns(mixed $bindings): array
    {
        if ($bindings === null || $bindings === '' || $bindings === []) {
            return ['list' => false, 'sets' => [[]]];
        }
        if (!\is_array($bindings)) {
            throw new DatabaseQueryFailure(sprintf(
                'the bindings must resolve to an object or an array of objects, got %s',
                get_debug_type($bindings)
            ));
        }
        if (!array_is_list($bindings)) {
            return ['list' => false, 'sets' => [$bindings]];
        }
        foreach ($bindings as $i => $set) {
            if (!\is_array($set) || array_is_list($set)) {
                throw new DatabaseQueryFailure(sprintf(
                    'bindings run #%d must be an object of parameter values, got %s',
                    $i + 1,
                    get_debug_type($set)
                ));
            }
        }

        return ['list' => true, 'sets' => $bindings];
    }

    /**
     * The named placeholders the SQL uses, string literals / quoted identifiers stripped first so
     * a ":something" inside quotes is not mistaken for one, and `::type` casts skipped.
     *
     * @return array<int, string>
     */
    private function placeholders(string $sql): array
    {
        $bare = preg_replace(["/'(?:[^']|'')*'/", '/"[^"]*"/'], ' ', $sql) ?? $sql;
        preg_match_all('/(?<![:\w]):([a-zA-Z_]\w*)/', $bare, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Opens the PDO connection the connector's config describes — same rules as
     * {@see ConnectorTester}'s Test button (engine gate, pgsql driver check, the pgsql-DSN ";"
     * refusal), so a connector that tests green runs here too.
     *
     * @param array<string, mixed> $config
     */
    private function connect(array $config): \PDO
    {
        $engine = trim((string) ($config['engine'] ?? ConnectorTester::ENGINE_POSTGRESQL));
        if ($engine !== '' && $engine !== ConnectorTester::ENGINE_POSTGRESQL) {
            throw new DatabaseQueryFailure(sprintf('unsupported database engine "%s" (PostgreSQL only for now)', $engine));
        }
        $host = trim((string) ($config['server'] ?? ''));
        $port = (int) ($config['port'] ?? 5432);
        $database = trim((string) ($config['database'] ?? ''));
        $user = trim((string) ($config['user'] ?? ''));
        if ($host === '' || $database === '' || $user === '') {
            throw new DatabaseQueryFailure('the connector has no server/database/user configured');
        }
        if (!\in_array('pgsql', $this->availableDrivers(), true)) {
            throw new DatabaseQueryFailure('the pdo_pgsql PHP extension is not installed on the server');
        }
        // PDO splits the pgsql DSN on ";" BEFORE libpq sees it — a value containing one would
        // inject another connection parameter, so it is refused (same as the connector Test).
        foreach (['server' => $host, 'database' => $database] as $label => $value) {
            if (str_contains($value, ';')) {
                throw new DatabaseQueryFailure(sprintf('the %s must not contain ";"', $label));
            }
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d', $host, $port, $database, self::CONNECT_TIMEOUT);
        try {
            $pdo = $this->openPdo($dsn, $user, (string) ($config['password'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            throw new DatabaseQueryFailure('could not connect to the database: ' . $e->getMessage(), 0, $e);
        }

        $schema = trim((string) ($config['schema'] ?? ''));
        if ($schema !== '') {
            // Identifier-quoted; a double quote inside doubles per SQL rules.
            $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
        }

        return $pdo;
    }

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
