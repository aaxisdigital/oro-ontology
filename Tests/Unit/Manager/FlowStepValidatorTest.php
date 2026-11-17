<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Manager\FlowStepValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class FlowStepValidatorTest extends TestCase
{
    private FlowStepValidator $validator;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $params, string $domain): string {
                self::assertSame('messages', $domain);
                $suffix = $params === [] ? '' : ' ' . json_encode($params, JSON_UNESCAPED_SLASHES);

                return str_replace('aaxis.ontology.flow_manager.', '', $id) . $suffix;
            }
        );
        $this->validator = new FlowStepValidator(new DwlTransformer(), $translator);
    }

    private static function step(string $type, ?array $config, string $name = 'S'): array
    {
        return ['type' => $type, 'name' => $name, 'x' => 0, 'y' => 0, 'config' => $config];
    }

    public function testNormalizeKeepsTheStoredShape(): void
    {
        self::assertSame([], $this->validator->normalize(null));
        self::assertSame(
            [
                ['type' => 'cron', 'name' => 'Nightly', 'x' => 0, 'y' => 12, 'config' => ['expression' => '* * * * *']],
                ['type' => 'logger', 'name' => 'Log', 'x' => 3, 'y' => 0, 'config' => null],
            ],
            $this->validator->normalize([
                ['type' => 'cron', 'name' => '  Nightly ', 'x' => -5, 'y' => '12.7', 'config' => ['expression' => '* * * * *'], 'extra' => 1],
                ['type' => 'logger', 'name' => 'Log', 'x' => 3.2, 'y' => 0],
            ])
        );
    }

    /**
     * @dataProvider malformedStepLists
     */
    public function testNormalizeRejectsMalformedLists(mixed $raw): void
    {
        self::assertNull($this->validator->normalize($raw));
    }

    public static function malformedStepLists(): array
    {
        $ok = ['type' => 'logger', 'name' => 'L', 'x' => 0, 'y' => 0];

        return [
            'not an array' => ['steps'],
            'not a list' => [['a' => $ok]],
            'not a step' => [[$ok, 'junk']],
            'unknown type' => [[['type' => 'teleport'] + $ok]],
            'blank name' => [[['name' => '  '] + $ok]],
            'non-string name' => [[['name' => 5] + $ok]],
            'overlong name' => [[['name' => str_repeat('é', 65)] + $ok]],
            'missing coordinate' => [[['type' => 'logger', 'name' => 'L', 'x' => 0]]],
            'non-numeric coordinate' => [[['x' => 'left'] + $ok]],
            'list config' => [[['config' => [1, 2]] + $ok]],
            'scalar config' => [[['config' => 'x'] + $ok]],
        ];
    }

    public function testNormalizeAcceptsASixtyFourCharacterName(): void
    {
        $name = str_repeat('é', 64);

        self::assertSame($name, $this->validator->normalize([['type' => 'logger', 'name' => $name, 'x' => 0, 'y' => 0]])[0]['name']);
    }

    public function testStructuralErrorsBlockDuplicateNamesAndMissingTriggers(): void
    {
        self::assertSame([], $this->validator->structuralErrors([self::step('cron', null, 'A'), self::step('logger', null, 'B')]));
        self::assertSame(
            ['step_names_unique', 'trigger_required'],
            $this->validator->structuralErrors([self::step('logger', null, 'Same'), self::step('event', null, 'sAME')])
        );
    }

    public function testValidateCombinesStructuralAndPerStepProblems(): void
    {
        self::assertSame(
            ['trigger_required', 'invalid_step_config {"{{ name }}":"Log"}'],
            $this->validator->validate([self::step('logger', [], 'Log')])
        );
        self::assertSame([], $this->validator->validate([self::step('subflow', null, 'Entry')]));
    }

    public function testStepErrorsReportTheFirstProblemPerStep(): void
    {
        $steps = [
            self::step('cron', ['mode' => 'cron', 'expression' => 'not a cron'], 'Bad cron'),
            self::step('dwl_transform', ['code' => '{', 'destination' => 'out'], 'Bad dwl'),
            self::step('dwl_transform', ['code' => '{', 'destination' => 'flowUuid'], 'Both'),
            self::step('dwl_transform', ['code' => 'payload', 'destination' => 'out'], 'Fine'),
        ];

        $errors = $this->validator->stepErrors($steps);

        self::assertSame(['Bad cron', 'Bad dwl', 'Both'], array_keys($errors));
        self::assertSame('invalid_cron {"{{ name }}":"Bad cron"}', $errors['Bad cron']);
        self::assertSame(
            'invalid_dwl {"{{ name }}":"Bad dwl","{{ error }}":"Expected RIGHT_BRACE but got EOF \'\' at line 1, column 2"}',
            $errors['Bad dwl']
        );
        self::assertSame('invalid_step_config {"{{ name }}":"Both"}', $errors['Both']);
        self::assertSame(['Bad cron', 'Bad dwl', 'Both'], $this->validator->invalidStepNames($steps));
    }

    /**
     * @dataProvider configurations
     */
    public function testValidatesEachStepType(string $type, ?array $config, bool $valid): void
    {
        self::assertSame($valid ? [] : ['S'], $this->validator->invalidStepNames([self::step($type, $config)]));
    }

    public static function configurations(): array
    {
        $read = ['destination' => 'rows', 'system' => 'Erp', 'entity' => 'Order'];
        $file = ['destination' => 'f', 'connector' => '3', 'path' => '/in'];
        $http = ['destination' => 'r', 'connector' => 3, 'path' => '/api'];

        return [
            'cron unconfigured' => ['cron', null, true],
            'cron only enabled' => ['cron', ['enabled' => true], true],
            'cron enabled not bool' => ['cron', ['enabled' => 1], false],
            'cron interval' => ['cron', ['mode' => 'interval', 'value' => 5, 'unit' => 'hour'], true],
            'cron interval zero' => ['cron', ['mode' => 'interval', 'value' => 0, 'unit' => 'hour'], false],
            'cron interval string value' => ['cron', ['mode' => 'interval', 'value' => '5', 'unit' => 'hour'], false],
            'cron interval bad unit' => ['cron', ['mode' => 'interval', 'value' => 5, 'unit' => 'second'], false],
            'cron expression' => ['cron', ['mode' => 'cron', 'expression' => '*/5 * * * *'], true],
            'cron legacy expression' => ['cron', ['expression' => '0 1 * * *'], true],
            'cron blank expression' => ['cron', ['mode' => 'cron', 'expression' => ' '], false],
            'cron unknown mode' => ['cron', ['mode' => 'yearly'], false],
            'entity change only enabled' => ['entity_change', ['enabled' => false], true],
            'entity change' => ['entity_change', ['system' => 'Erp', 'entity' => 'Order'], true],
            'entity change without entity' => ['entity_change', ['system' => 'Erp'], false],
            'subflow' => ['subflow', ['enabled' => true], true],
            'subflow bad flag' => ['subflow', ['enabled' => 'yes'], false],
            'endpoint seeded' => ['endpoint', ['enabled' => true, 'response' => '{statusCode: 200}'], true],
            'endpoint' => ['endpoint', ['method' => 'POST', 'path' => '/orders/{id}/v1.2', 'public' => true, 'response' => 'x'], true],
            'endpoint without method' => ['endpoint', ['path' => 'a'], true],
            'endpoint bad method' => ['endpoint', ['method' => 'get', 'path' => 'a'], false],
            'endpoint public not bool' => ['endpoint', ['path' => 'a', 'public' => 'no'], false],
            'endpoint empty path' => ['endpoint', ['path' => ' / '], false],
            'endpoint reserved param' => ['endpoint', ['path' => 'a/{Body}'], false],
            'endpoint invalid param' => ['endpoint', ['path' => 'a/{1x}'], false],
            'endpoint invalid literal' => ['endpoint', ['path' => 'a/b c'], false],
            'endpoint response not text' => ['endpoint', ['path' => 'a', 'response' => ['x']], false],
            'choice' => ['choice', ['expression' => 'true'], true],
            'choice unconfigured' => ['choice', null, false],
            'dwl' => ['dwl_transform', ['code' => '1', 'destination' => 'out'], true],
            'dwl reserved destination' => ['dwl_transform', ['code' => '1', 'destination' => ' Flow-UUID '], false],
            'dwl choiceResults destination' => ['dwl_transform', ['code' => '1', 'destination' => 'choiceresults'], false],
            'dwl no destination' => ['dwl_transform', ['code' => '1'], false],
            'entity read all' => ['entity_read', $read + ['order_by' => 'id', 'order_dir' => 'desc', 'limit' => 100], true],
            'entity read by id' => ['entity_read', $read + ['mode' => 'by_id', 'record_id' => '7'], true],
            'entity read by id without id' => ['entity_read', $read + ['mode' => 'by_id'], false],
            'entity read by attribute' => ['entity_read', $read + ['mode' => 'by_attribute', 'attribute' => 'a', 'attr_value' => 'v'], true],
            'entity read by attribute without value' => ['entity_read', $read + ['mode' => 'by_attribute', 'attribute' => 'a'], false],
            'entity read bad mode' => ['entity_read', $read + ['mode' => 'some'], false],
            'entity read bad order' => ['entity_read', $read + ['order_by' => ['id']], false],
            'entity read bad direction' => ['entity_read', $read + ['order_dir' => 'DESC'], false],
            'entity read bad limit' => ['entity_read', $read + ['limit' => 5], false],
            'entity read without entity' => ['entity_read', ['destination' => 'x', 'system' => 'Erp'], false],
            'entity write' => ['entity_write', ['destination' => 'w', 'system' => 'Erp', 'entity' => 'Order', 'content' => 'rows', 'content_dwl' => false], true],
            'entity write bad toggle' => ['entity_write', ['destination' => 'w', 'system' => 'Erp', 'entity' => 'Order', 'content' => 'rows', 'content_dwl' => 'on'], false],
            'entity write without content' => ['entity_write', ['destination' => 'w', 'system' => 'Erp', 'entity' => 'Order'], false],
            'http request' => ['invoke', $http + ['operation' => 'post', 'body' => 'json', 'body_dwl' => true, 'body_content' => '{a: 1}'], true],
            'http request bad operation' => ['invoke', $http + ['operation' => 'POST'], false],
            'http request bad body' => ['invoke', $http + ['body' => 'form'], false],
            'http request bad toggle' => ['invoke', $http + ['body_dwl' => 1], false],
            'http request without connector' => ['invoke', ['destination' => 'r', 'connector' => '', 'path' => 'x'], false],
            'http request structured connector' => ['invoke', ['destination' => 'r', 'connector' => [3], 'path' => 'x'], false],
            'http request without path' => ['invoke', ['destination' => 'r', 'connector' => 3], false],
            'async http request needs no destination' => ['invoke', ['async' => true, 'connector' => 3, 'path' => 'x'], true],
            'async http request ignores a leftover destination' => ['invoke', ['async' => true, 'destination' => 'flowUuid', 'connector' => 3, 'path' => 'x'], true],
            'sync http request still needs a destination' => ['invoke', ['async' => false, 'connector' => 3, 'path' => 'x'], false],
            'http request bad async flag' => ['invoke', $http + ['async' => 'yes'], false],
            'async http request without connector' => ['invoke', ['async' => true, 'path' => 'x'], false],
            'file read' => ['file_read', $file, true],
            'file list' => ['file_list', $file + ['path_dwl' => false], true],
            'file delete bad toggle' => ['file_delete', $file + ['path_dwl' => 'x'], false],
            'file read without connector' => ['file_read', ['destination' => 'f', 'path' => 'x'], false],
            'file write' => ['file_write', $file + ['content' => 'data'], true],
            'file write without content' => ['file_write', $file, false],
            'file write bad toggle' => ['file_write', $file + ['content' => 'data', 'content_dwl' => 0], false],
            'file rename' => ['file_rename', $file + ['new_name' => 'b.txt'], true],
            'file rename without name' => ['file_rename', $file, false],
            'file rename bad toggle' => ['file_rename', $file + ['new_name' => 'b', 'new_name_dwl' => 'y'], false],
            'call subflow' => ['sub_flow', ['subflow' => 4], true],
            'call subflow unset' => ['sub_flow', ['subflow' => ''], false],
            'foreach' => ['foreach', ['subflow' => '4', 'array' => 'rows', 'item' => 'row'], true],
            'foreach index item' => ['foreach', ['subflow' => '4', 'array' => 'rows', 'item' => ' Index '], false],
            'foreach reserved item' => ['foreach', ['subflow' => '4', 'array' => 'rows', 'item' => 'flowUuid'], false],
            'foreach without array' => ['foreach', ['subflow' => '4', 'item' => 'row'], false],
            'foreach without subflow' => ['foreach', ['array' => 'rows', 'item' => 'row'], false],
            'invoke php' => ['invoke_php', ['destination' => 'r', 'class' => 'A\\B', 'method' => 'm', 'params' => '{}'], true],
            'invoke php without params' => ['invoke_php', ['destination' => 'r', 'class' => 'A\\B', 'method' => 'm'], true],
            'invoke php structured params' => ['invoke_php', ['destination' => 'r', 'class' => 'A\\B', 'method' => 'm', 'params' => []], false],
            'invoke php without method' => ['invoke_php', ['destination' => 'r', 'class' => 'A\\B'], false],
            'logger' => ['logger', ['message' => 'hi', 'message_dwl' => false], true],
            'logger unconfigured' => ['logger', null, false],
            'event' => ['event', ['value' => 'v'], true],
            'event bad toggle' => ['event', ['value' => 'v', 'value_dwl' => 'no'], false],
            'ms teams' => ['ms_teams', ['message' => 'msg', 'webhook' => ' https://prod.powerplatform.com/hook?sig=1 '], true],
            'ms teams plain http' => ['ms_teams', ['message' => 'msg', 'webhook' => 'http://example.com/hook'], false],
            'ms teams not a url' => ['ms_teams', ['message' => 'msg', 'webhook' => 'https://'], false],
            'ms teams structured webhook' => ['ms_teams', ['message' => 'msg', 'webhook' => ['https://x.io']], false],
            'ms teams without message' => ['ms_teams', ['webhook' => 'https://x.io'], false],
            'sql' => ['sql_query', ['destination' => 'q', 'connector' => 2, 'sql' => 'select 1', 'binding' => 'b', 'binding_dwl' => false], true],
            'sql structured binding' => ['sql_query', ['destination' => 'q', 'connector' => 2, 'sql' => 'select 1', 'binding' => []], false],
            'sql bad toggle' => ['sql_query', ['destination' => 'q', 'connector' => 2, 'sql' => 'select 1', 'sql_dwl' => 'y'], false],
            'sql bad binding toggle' => ['sql_query', ['destination' => 'q', 'connector' => 2, 'sql' => 'select 1', 'binding_dwl' => 'y'], false],
            'sql without sql' => ['sql_query', ['destination' => 'q', 'connector' => 2], false],
            'placeholder step' => ['email', null, true],
        ];
    }

    /**
     * @dataProvider dwlFields
     */
    public function testParsesEveryDwlField(string $type, array $config, bool $valid): void
    {
        self::assertSame($valid ? [] : ['S'], $this->validator->invalidStepNames([self::step($type, $config)]));
    }

    public static function dwlFields(): array
    {
        $broken = '{';
        $file = ['destination' => 'f', 'connector' => '3', 'path' => $broken, 'path_dwl' => true];

        return [
            'transform code' => ['dwl_transform', ['code' => $broken, 'destination' => 'o'], false],
            'http body' => ['invoke', ['destination' => 'r', 'connector' => 1, 'path' => 'p', 'body_dwl' => true, 'body_content' => $broken], false],
            'http body as text' => ['invoke', ['destination' => 'r', 'connector' => 1, 'path' => 'p', 'body_content' => $broken], true],
            'entity write content' => ['entity_write', ['destination' => 'w', 'system' => 's', 'entity' => 'e', 'content' => $broken, 'content_dwl' => true], false],
            'file write content' => ['file_write', ['destination' => 'f', 'connector' => 1, 'path' => 'p', 'content' => $broken, 'content_dwl' => true], false],
            'file path' => ['file_read', $file, false],
            'file path as text' => ['file_read', ['path_dwl' => false] + $file, true],
            'file new name' => ['file_rename', ['destination' => 'f', 'connector' => 1, 'path' => 'p', 'new_name' => $broken, 'new_name_dwl' => true], false],
            'choice expression' => ['choice', ['expression' => $broken], false],
            'invoke php params' => ['invoke_php', ['destination' => 'r', 'class' => 'C', 'method' => 'm', 'params' => $broken], false],
            'endpoint response' => ['endpoint', ['path' => 'a', 'response' => $broken], false],
            'event value' => ['event', ['value' => $broken, 'value_dwl' => true], false],
            'event value as text' => ['event', ['value' => $broken], true],
            'logger message' => ['logger', ['message' => $broken, 'message_dwl' => true], false],
            'sql text' => ['sql_query', ['destination' => 'q', 'connector' => 1, 'sql' => $broken, 'sql_dwl' => true], false],
            'sql binding' => ['sql_query', ['destination' => 'q', 'connector' => 1, 'sql' => 's', 'binding' => $broken, 'binding_dwl' => true], false],
            'sql as text' => ['sql_query', ['destination' => 'q', 'connector' => 1, 'sql' => $broken, 'binding' => $broken], true],
        ];
    }
}
