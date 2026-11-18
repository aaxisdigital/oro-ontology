<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Form\Type;

use Aaxis\Bundle\OntologyBundle\Form\Type\DbUsageType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

class DbUsageTypeTest extends TestCase
{
    private const array TABLES = [
        'aaxis_ontology_data',
        'aaxis_ontology_data_history',
        'aaxis_ontology_flow',
        'aaxis_ontology_flow_history',
        'aaxis_ontology_flow_events',
        'aaxis_ontology_flow_runs',
    ];

    /**
     * @param callable(string): mixed $sizeOf answers for each table's fetchOne()
     *
     * @return array<int, array{table: string, size: string}>
     */
    private function usage(callable $sizeOf): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use ($sizeOf) {
            self::assertSame('SELECT pg_total_relation_size(to_regclass(?))', $sql);

            return $sizeOf($params[0]);
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        $view = new FormView();
        (new DbUsageType($registry))->buildView($view, $this->createMock(FormInterface::class), []);

        return $view->vars['db_usage'];
    }

    public function testItListsEveryTrackedTableInOrder(): void
    {
        $usage = $this->usage(static fn (): int => 2048);

        self::assertSame(self::TABLES, array_column($usage, 'table'));
    }

    /**
     * @dataProvider sizeProvider
     */
    public function testSizesAreHumanFormatted(mixed $bytes, string $expected): void
    {
        self::assertSame($expected, $this->usage(static fn () => $bytes)[0]['size']);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function sizeProvider(): array
    {
        return [
            'zero' => [0, '0.0 kb'],
            'half a kb' => [512, '0.5 kb'],
            'numeric string as the driver returns it' => ['1536', '1.5 kb'],
            'just below a mb' => [1024 * 1024 - 1024, '1,023.0 kb'],
            'exactly a mb' => [1024 * 1024, '1.0 mb'],
            'megabytes' => [5 * 1024 * 1024 + 512 * 1024, '5.5 mb'],
            'exactly a gb' => [1024 ** 3, '1.00 gb'],
            'gigabytes' => [3 * 1024 ** 3 + 256 * 1024 ** 2, '3.25 gb'],
        ];
    }

    /**
     * @dataProvider missingProvider
     */
    public function testAMissingTableOrAFailedQueryRendersADash(mixed $answer): void
    {
        $usage = $this->usage(static function () use ($answer) {
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });

        self::assertSame(['—', '—', '—', '—', '—', '—'], array_column($usage, 'size'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function missingProvider(): array
    {
        return [
            'null (table does not exist)' => [null],
            'false (no row)' => [false],
            'database error' => [new \RuntimeException('connection lost')],
        ];
    }

    public function testEachTableIsResolvedIndependently(): void
    {
        $usage = $this->usage(static fn (string $table) => $table === 'aaxis_ontology_flow' ? null : 1024);

        self::assertSame(['1.0 kb', '1.0 kb', '—', '1.0 kb', '1.0 kb', '1.0 kb'], array_column($usage, 'size'));
    }

    public function testItIsATextFieldWithItsOwnBlockPrefix(): void
    {
        $type = new DbUsageType($this->createMock(ManagerRegistry::class));

        self::assertSame(TextType::class, $type->getParent());
        self::assertSame('aaxis_ontology_db_usage', $type->getBlockPrefix());
    }
}
