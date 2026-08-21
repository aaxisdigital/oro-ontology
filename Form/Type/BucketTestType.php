<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * System Configuration → Bucket: the "Test connection" button. A `ui_only` field that renders no
 * input — its widget block (Form/fields.html.twig) mounts bucket-config-test-component, which
 * collects the section's CURRENT field values and probes them through
 * OntologyConfigController::bucketTestAction. Constructor-less on purpose: Symfony's FormRegistry
 * instantiates argument-free types directly, so no service registration is needed.
 */
class BucketTestType extends AbstractType
{
    #[\Override]
    public function getParent(): string
    {
        return TextType::class;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'aaxis_ontology_bucket_test';
    }
}
