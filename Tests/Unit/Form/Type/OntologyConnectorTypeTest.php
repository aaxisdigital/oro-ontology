<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Form\Type;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Form\Type\OntologyConnectorType;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorConfigSecrets;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OntologyConnectorTypeTest extends TestCase
{
    /**
     * A form factory that knows the type under test plus the two things the platform adds around
     * it: the Doctrine EntityType (never asked for choices here) and Oro's `tooltip` option.
     *
     * @return FormInterface<mixed>
     */
    private function form(OntologyConnector $connector): FormInterface
    {
        $metadata = new ClassMetadata(OntologySystem::class);
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($this->createMock(QueryBuilder::class));
        $em->method('getRepository')->willReturn($repository);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $tooltip = new class () extends AbstractTypeExtension {
            public static function getExtendedTypes(): iterable
            {
                return [FormType::class];
            }

            public function configureOptions(OptionsResolver $resolver): void
            {
                $resolver->setDefined(['tooltip']);
            }
        };

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension(
                [new OntologyConnectorType(new ConnectorConfigSecrets()), new EntityType($registry)],
                [FormType::class => [$tooltip]]
            ))
            ->getFormFactory();

        return $factory->create(OntologyConnectorType::class, $connector);
    }

    private function connector(?array $config): OntologyConnector
    {
        return (new OntologyConnector())->setName('Warehouse')->setType(OntologyConnector::TYPE_DATABASE)->setConfig($config);
    }

    public function testTheBlockPrefixAndDataClass(): void
    {
        $form = $this->form($this->connector(null));

        self::assertSame(OntologyConnector::class, $form->getConfig()->getDataClass());
        self::assertSame('aaxis_ontology_connector', $form->getConfig()->getType()->getBlockPrefix());
        self::assertSame(['system', 'name', 'type', 'config'], array_keys($form->all()));
    }

    public function testTheTypeChoicesAreEveryConnectorType(): void
    {
        $choices = $this->form($this->connector(null))->get('type')->getConfig()->getOption('choices');

        self::assertSame(OntologyConnector::TYPES, array_values($choices));
    }

    public function testTheConfigFieldIsAReadOnlyTextareaBecauseItIsAuthoredInThePopup(): void
    {
        $config = $this->form($this->connector(null))->get('config')->getConfig();

        self::assertFalse($config->getRequired());
        self::assertSame('readonly', $config->getOption('attr')['readonly']);
        self::assertSame('aaxis-ontology-json', $config->getOption('attr')['class']);
    }

    /**
     * @dataProvider emptyConfigProvider
     */
    public function testAnEmptyConfigRendersAsAnEmptyString(?array $config): void
    {
        self::assertSame('', $this->form($this->connector($config))->get('config')->getViewData());
    }

    /**
     * @return array<string, array{array<string, mixed>|null}>
     */
    public static function emptyConfigProvider(): array
    {
        return ['null' => [null], 'empty' => [[]]];
    }

    public function testTheConfigIsRenderedAsPrettyJsonWithSecretsMasked(): void
    {
        $view = $this->form($this->connector(['server' => 'db/x', 'password' => 'hunter2', 'nested' => ['api_key' => 'k']]))
            ->get('config')->getViewData();

        self::assertSame(
            json_encode(['server' => 'db/x', 'password' => '********', 'nested' => ['api_key' => '********']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $view
        );
        self::assertStringNotContainsString('hunter2', $view);
    }

    public function testSubmittingKeepsTheStoredSecretsBehindTheSentinel(): void
    {
        $connector = $this->connector(['server' => 'old', 'password' => 'stored-secret']);
        $form = $this->form($connector);

        $form->submit([
            'name' => 'Warehouse',
            'type' => OntologyConnector::TYPE_DATABASE,
            'config' => '{"server":"new","password":"********"}',
        ], false);

        self::assertTrue($form->get('config')->isSynchronized());
        self::assertSame(['server' => 'new', 'password' => 'stored-secret'], $connector->getConfig());
    }

    public function testSubmittingATypedSecretReplacesIt(): void
    {
        $connector = $this->connector(['password' => 'stored-secret']);
        $form = $this->form($connector);

        $form->submit(['name' => 'W', 'type' => 'database', 'config' => '{"password":"brand-new"}'], false);

        self::assertSame(['password' => 'brand-new'], $connector->getConfig());
    }

    public function testASentinelWithNothingStoredBecomesEmpty(): void
    {
        $connector = $this->connector(null);
        $form = $this->form($connector);

        $form->submit(['name' => 'W', 'type' => 'database', 'config' => '{"password":"********"}'], false);

        self::assertSame(['password' => ''], $connector->getConfig());
    }

    public function testABlankConfigClearsIt(): void
    {
        $connector = $this->connector(['server' => 's']);
        $form = $this->form($connector);

        $form->submit(['name' => 'W', 'type' => 'database', 'config' => "  \n "], false);

        self::assertNull($connector->getConfig());
    }

    /**
     * @dataProvider invalidJsonProvider
     */
    public function testAConfigThatIsNotAJsonObjectIsRejected(string $submitted): void
    {
        $form = $this->form($this->connector(['server' => 's']));

        $form->submit(['name' => 'W', 'type' => 'database', 'config' => $submitted], false);

        self::assertFalse($form->get('config')->isSynchronized());
        self::assertFalse($form->isValid());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJsonProvider(): array
    {
        return ['garbage' => ['{not json'], 'a scalar' => ['"text"'], 'a number' => ['42']];
    }

    public function testTheStoredConfigIsCapturedWhenTheFormIsCreatedNotWhenItIsSubmitted(): void
    {
        $connector = $this->connector(['password' => 'first']);
        $form = $this->form($connector);
        // Whatever happens to the entity afterwards, the secret to restore is the one the form was shown.
        $connector->setConfig(['password' => 'changed-behind-its-back']);

        $form->submit(['name' => 'W', 'type' => 'database', 'config' => '{"password":"********"}'], false);

        self::assertSame(['password' => 'first'], $connector->getConfig());
    }
}
