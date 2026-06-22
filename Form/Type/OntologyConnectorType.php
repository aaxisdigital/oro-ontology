<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for the Ontology "Connector" entity. The per-type configuration is entered as
 * JSON and stored as an array.
 */
class OntologyConnectorType extends AbstractType
{
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
                'attr' => ['rows' => 8, 'class' => 'aaxis-ontology-json'],
            ]);

        // The "config" model value is an array; render/edit it as pretty JSON.
        $builder->get('config')->addModelTransformer(new CallbackTransformer(
            static fn (?array $value): string => $value === null || $value === [] ? '' : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            static function (?string $value): ?array {
                $value = trim((string) $value);
                if ($value === '') {
                    return null;
                }
                $decoded = json_decode($value, true);
                if (!\is_array($decoded)) {
                    throw new TransformationFailedException('The configuration must be a valid JSON object.');
                }

                return $decoded;
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
