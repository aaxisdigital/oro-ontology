<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Manager\DatabaseQueryRunner;
use PHPUnit\Framework\TestCase;

/**
 * The runner opens a PostgreSQL connection; the test swaps the two protected seams
 * (openPdo / availableDrivers) so the statements run against an in-memory SQLite database
 * while everything the runner does with the connection stays real.
 */
class DatabaseQueryRunnerTest extends TestCase
{
    private RecordingPdo $pdo;

    protected function setUp(): void
    {
        $this->pdo = new RecordingPdo('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->realExec('CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT, qty INTEGER)');
        $this->pdo->realExec("INSERT INTO item (id, name, qty) VALUES (1, 'apple', 3), (2, 'pear', 5)");
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connector(array $config, string $type = OntologyConnector::TYPE_DATABASE): OntologyConnector
    {
        return (new OntologyConnector())->setName('warehouse')->setType($type)->setConfig($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        return ['server' => 'db.internal', 'port' => 5433, 'database' => 'erp', 'user' => 'svc', 'password' => 'pw'];
    }

    private function runner(array $drivers = ['pgsql', 'sqlite'], ?\PDOException $connectFailure = null): FakeConnectionRunner
    {
        return new FakeConnectionRunner($this->pdo, $drivers, $connectFailure);
    }

    public function testRejectsAConnectorOfAnotherType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connector "warehouse" is not a database connector');

        $this->runner()->run($this->connector([], OntologyConnector::TYPE_SFTP), 'SELECT 1');
    }

    public function testRejectsAnEmptySql(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the SQL expression resolved to an empty text');

        $this->runner()->run($this->connector($this->validConfig()), "  \n ");
    }

    public function testAnObjectBindingRunsOnceAndReturnsTheRowsAsAList(): void
    {
        $rows = $this->runner()->run(
            $this->connector($this->validConfig()),
            'SELECT id, name FROM item WHERE qty > :min ORDER BY id',
            ['min' => 0]
        );

        self::assertSame([['id' => 1, 'name' => 'apple'], ['id' => 2, 'name' => 'pear']], $rows);
    }

    public function testNoBindingsRunsOnceWithoutParameters(): void
    {
        foreach ([null, '', []] as $empty) {
            $rows = $this->runner()->run($this->connector($this->validConfig()), 'SELECT COUNT(*) AS n FROM item', $empty);

            self::assertSame([['n' => 2]], $rows);
        }
    }

    public function testDmlYieldsTheAffectedRowCount(): void
    {
        $result = $this->runner()->run(
            $this->connector($this->validConfig()),
            'UPDATE item SET qty = qty + 1 WHERE qty >= :from',
            ['from' => 1]
        );

        self::assertSame(['affected' => 2], $result);
    }

    public function testAListBindingRunsThePreparedStatementOncePerElementAndReturnsAListOfResults(): void
    {
        $result = $this->runner()->run(
            $this->connector($this->validConfig()),
            'INSERT INTO item (id, name, qty) VALUES (:id, :name, :qty)',
            [
                ['id' => 3, 'name' => 'plum', 'qty' => 7],
                ['id' => 4, 'name' => null, 'qty' => 0],
            ]
        );

        self::assertSame([['affected' => 1], ['affected' => 1]], $result);
        $stored = $this->pdo->query('SELECT id, name, qty FROM item WHERE id > 2 ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['id' => 3, 'name' => 'plum', 'qty' => 7], ['id' => 4, 'name' => null, 'qty' => 0]], $stored);
    }

    public function testBindsBooleansAsBooleansAndTextAsText(): void
    {
        $rows = $this->runner()->run(
            $this->connector($this->validConfig()),
            'SELECT :flag AS flag, :text AS text, :num AS num, cast(:nul AS integer) AS nul',
            ['flag' => true, 'text' => '007', 'num' => 42, 'nul' => null]
        );

        // A string stays a string ('007' would be 7 had it been bound as an int).
        self::assertSame([['flag' => 1, 'text' => '007', 'num' => 42, 'nul' => null]], $rows);
    }

    public function testDoubledColonCastsQuotedLiteralsAndRepeatedPlaceholdersAreHandled(): void
    {
        // ":skipped" sits inside a string literal / quoted identifier and "::text" is a cast: none is a
        // placeholder, and :name used twice must be bound only once (SQLite reuses the named parameter).
        $rows = $this->runner()->run(
            $this->connector($this->validConfig()),
            "SELECT ':skipped' AS lit, :name AS a, :name AS b, 'it''s :quoted' AS esc, 1 AS \"col:quoted\"",
            ['name' => 'x']
        );

        self::assertSame([['lit' => ':skipped', 'a' => 'x', 'b' => 'x', 'esc' => "it's :quoted", 'col:quoted' => 1]], $rows);
    }

    public function testExtraBindingKeysAreIgnored(): void
    {
        $rows = $this->runner()->run($this->connector($this->validConfig()), 'SELECT :a AS a', ['a' => 1, 'unused' => 2]);

        self::assertSame([['a' => 1]], $rows);
    }

    /**
     * @dataProvider badBindingProvider
     */
    public function testBadBindingsFailBeforeTheDatabaseIsTouched(mixed $bindings, string $message): void
    {
        $runner = $this->runner();
        try {
            $runner->run($this->connector($this->validConfig()), 'SELECT :a', $bindings);
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame(0, $runner->opened, 'the bindings must be validated before a connection is opened');
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badBindingProvider(): array
    {
        return [
            'scalar' => ['nope', 'the bindings must resolve to an object or an array of objects, got string'],
            'number' => [12, 'the bindings must resolve to an object or an array of objects, got int'],
            'list with a scalar' => [[['a' => 1], 'x'], 'bindings run #2 must be an object of parameter values, got string'],
            'list with a nested list' => [[[1, 2]], 'bindings run #1 must be an object of parameter values, got array'],
        ];
    }

    public function testAMissingBindingKeyIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the SQL uses :b but the bindings carry no such key');

        $this->runner()->run($this->connector($this->validConfig()), 'SELECT :a, :b', ['a' => 1]);
    }

    public function testAMissingBindingKeyNamesTheOffendingRunOfAList(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the SQL uses :a but the bindings of run #2 carry no such key');

        $this->runner()->run($this->connector($this->validConfig()), 'SELECT :a', [['a' => 1], ['b' => 2]]);
    }

    public function testANonScalarBindingIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binding "a" must be a scalar or null, got array');

        $this->runner()->run($this->connector($this->validConfig()), 'SELECT :a', ['a' => [1]]);
    }

    public function testANonScalarBindingNamesTheOffendingRunOfAList(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binding "a" of run #2 must be a scalar or null, got stdClass');

        $this->runner()->run($this->connector($this->validConfig()), 'SELECT :a', [['a' => 1], ['a' => new \stdClass()]]);
    }

    public function testASqlThatCannotBePreparedIsReportedWithTheDriverMessage(): void
    {
        try {
            $this->runner()->run($this->connector($this->validConfig()), 'SELEKT nothing');
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('the SQL could not be prepared: ', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testAFailingExecutionIsReportedAndNamesTheRunOfAList(): void
    {
        try {
            $this->runner()->run(
                $this->connector($this->validConfig()),
                'INSERT INTO item (id, name, qty) VALUES (:id, :name, :qty)',
                [['id' => 9, 'name' => 'ok', 'qty' => 1], ['id' => 9, 'name' => 'dup', 'qty' => 1]]
            );
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('the query failed on run #2: ', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testAFailingSingleExecutionCarriesNoRunNumber(): void
    {
        try {
            $this->runner()->run($this->connector($this->validConfig()), 'INSERT INTO item (id) VALUES (:id)', ['id' => 1]);
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('the query failed: ', $e->getMessage());
        }
    }

    public function testConnectsWithTheConfiguredCredentialsOutsideTheDsn(): void
    {
        $runner = $this->runner();
        $runner->run($this->connector($this->validConfig()), 'SELECT 1 AS one');

        self::assertSame('pgsql:host=db.internal;port=5433;dbname=erp;connect_timeout=10', $runner->dsn);
        self::assertSame('svc', $runner->user);
        self::assertSame('pw', $runner->password);
        self::assertSame([\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION], $runner->options);
        self::assertStringNotContainsString('pw', $runner->dsn);
        self::assertSame([], $this->pdo->executed, 'no schema configured: search_path must stay untouched');
    }

    public function testDefaultsTheEngineAndPortAndTrimsTheValues(): void
    {
        $runner = $this->runner();
        $runner->run(
            $this->connector(['engine' => ' ', 'server' => ' db ', 'database' => ' erp ', 'user' => ' svc ']),
            'SELECT 1 AS one'
        );

        self::assertSame('pgsql:host=db;port=5432;dbname=erp;connect_timeout=10', $runner->dsn);
        self::assertSame('svc', $runner->user);
        self::assertSame('', $runner->password);
    }

    public function testAnExplicitPostgresqlEngineIsAccepted(): void
    {
        $runner = $this->runner();
        $runner->run($this->connector($this->validConfig() + ['engine' => 'postgresql']), 'SELECT 1 AS one');

        self::assertSame(1, $runner->opened);
    }

    public function testAConnectorWithoutConfigIsReportedAsMissingCredentials(): void
    {
        $connector = (new OntologyConnector())->setName('warehouse')->setType(OntologyConnector::TYPE_DATABASE);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the connector has no server/database/user configured');

        $this->runner()->run($connector, 'SELECT 1');
    }

    /**
     * @dataProvider unusableConfigProvider
     *
     * @param array<string, mixed> $config
     */
    public function testUnusableConfigurationsAreRefused(array $config, string $message): void
    {
        try {
            $this->runner()->run($this->connector($config), 'SELECT 1');
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusableConfigProvider(): array
    {
        $ok = ['server' => 'db', 'database' => 'erp', 'user' => 'svc'];

        return [
            'other engine' => [$ok + ['engine' => 'mysql'], 'unsupported database engine "mysql" (PostgreSQL only for now)'],
            'no server' => [['server' => ' '] + $ok, 'the connector has no server/database/user configured'],
            'no database' => [['database' => ''] + $ok, 'the connector has no server/database/user configured'],
            'no user' => [['user' => null] + $ok, 'the connector has no server/database/user configured'],
            'semicolon in server' => [['server' => 'db;sslmode=disable'] + $ok, 'the server must not contain ";"'],
            'semicolon in database' => [['database' => 'erp;host=evil'] + $ok, 'the database must not contain ";"'],
        ];
    }

    public function testRefusesWhenThePgsqlDriverIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the pdo_pgsql PHP extension is not installed on the server');

        $this->runner(['sqlite'])->run($this->connector($this->validConfig()), 'SELECT 1');
    }

    public function testAConnectionFailureIsWrappedWithoutLosingTheCause(): void
    {
        $cause = new \PDOException('connection refused');

        try {
            $this->runner(['pgsql'], $cause)->run($this->connector($this->validConfig()), 'SELECT 1');
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertSame('could not connect to the database: connection refused', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    public function testTheSchemaBecomesAQuotedSearchPath(): void
    {
        $this->runner()->run(
            $this->connector($this->validConfig() + ['schema' => ' erp"x ']),
            'SELECT 1 AS one'
        );

        self::assertSame(['SET search_path TO "erp""x"'], $this->pdo->executed);
    }
}

/**
 * A PDO whose exec() only records — SQLite has no `SET search_path`.
 */
class RecordingPdo extends \PDO
{
    /** @var array<int, string> */
    public array $executed = [];

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        return 0;
    }

    public function realExec(string $statement): int|false
    {
        return parent::exec($statement);
    }
}

class FakeConnectionRunner extends DatabaseQueryRunner
{
    public int $opened = 0;
    public string $dsn = '';
    public string $user = '';
    public string $password = '';

    /** @var array<int, mixed> */
    public array $options = [];

    /**
     * @param array<int, string> $drivers
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly array $drivers,
        private readonly ?\PDOException $connectFailure,
    ) {
    }

    protected function availableDrivers(): array
    {
        return $this->drivers;
    }

    protected function openPdo(string $dsn, string $user, string $password, array $options): \PDO
    {
        ++$this->opened;
        [$this->dsn, $this->user, $this->password, $this->options] = [$dsn, $user, $password, $options];
        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        return $this->pdo;
    }
}
