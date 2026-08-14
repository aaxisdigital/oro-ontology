<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorConfigSecrets;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for the Ontology "Connector" entity. The per-type configuration is stored as an
 * array and shown as read-only JSON — it is authored through the type-specific
 * "Configure" popup (connector-config-component.ts), never typed by hand. Secret values
 * are masked on render and merged back from the stored config on submit
 * ({@see ConnectorConfigSecrets}).
 */
class OntologyConnectorType extends AbstractType
{
    public function __construct(private readonly ConnectorConfigSecrets $secrets)
    {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('system', EntityType::class, [
                'label' => 'aaxis.ontology.connector.system.label',
                'class' => OntologySystem::class,
                'choice_label' => 'name',
                'placeholder' => 'aaxis.ontology.choose_system',
                'required' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'aaxis.ontology.connector.name.label',
                'required' => true,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'aaxis.ontology.connector.type.label',
                'choices' => [
                    'aaxis.ontology.connector.type.sftp' => OntologyConnector::TYPE_SFTP,
                    'aaxis.ontology.connector.type.rest_api' => OntologyConnector::TYPE_REST_API,
                    'aaxis.ontology.connector.type.file_system' => OntologyConnector::TYPE_FILE_SYSTEM,
                ],
                'required' => true,
            ])
            ->add('config', TextareaType::class, [
                'label' => 'aaxis.ontology.connector.config.label',
                'tooltip' => 'aaxis.ontology.connector.config.tooltip',
                'required' => false,
                'attr' => ['rows' => 8, 'class' => 'aaxis-ontology-json', 'readonly' => 'readonly'],
            ]);

        // The stored config is needed to restore masked secrets when the submit comes back.
        $originalConfig = null;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use (&$originalConfig): void {
            $data = $event->getData();
            if ($data instanceof OntologyConnector) {
                $originalConfig = $data->getConfig();
            }
        });

        // The "config" model value is an array; render it as pretty JSON with secrets masked,
        // and on submit decode + merge sentinel-valued secrets back from the stored config.
        $secrets = $this->secrets;
        $builder->get('config')->addModelTransformer(new CallbackTransformer(
            static fn (?array $value): string => $value === null || $value === []
                ? ''
                : (string) json_encode($secrets->mask($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            static function (?string $value) use ($secrets, &$originalConfig): ?array {
                $value = trim((string) $value);
                if ($value === '') {
                    return null;
                }
                $decoded = json_decode($value, true);
                if (!\is_array($decoded)) {
                    throw new TransformationFailedException('The configuration must be a valid JSON object.');
                }

                return $secrets->merge($decoded, $originalConfig);
            }
        ));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OntologyConnector::class]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'aaxis_ontology_connector';
    }
}
