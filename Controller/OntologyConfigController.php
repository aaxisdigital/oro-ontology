<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowHistoryPurgeTopic;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyFlowRunsRebuildTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * System Configuration helpers (no page of its own). bucketTestAction backs the Bucket section's
 * "Test connection" button: it probes the CURRENT form values through the same ConnectorTester
 * bucket arm as the connector Configure popup's Test (TCP socket + SigV4-signed one-object list),
 * so the two features cannot drift. Untouched key fields arrive as the
 * OroEncodedPlaceholderPasswordType placeholder (a run of '*') and are resolved from the SAVED
 * config values, decrypted with the same crypter that form type encrypts with
 * (oro_security.encoder.default).
 */
class OntologyConfigController extends AbstractController
{
    #[Route(path: '/config/api/bucket-test', name: 'aaxis_ontology_config_bucket_test', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    #[CsrfProtection]
    public function bucketTestAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $config = \is_array($payload) && \is_array($payload['config'] ?? null) ? $payload['config'] : [];

        $configManager = $this->container->get(ConfigManager::class);
        $crypter = $this->container->get(SymmetricCrypterInterface::class);
        foreach (['access_key', 'secret_key'] as $key) {
            $value = (string) ($config[$key] ?? '');
            if ($value !== '' && strspn($value, '*') === \strlen($value)) {
                $stored = (string) ($configManager->get('aaxis_ontology.bucket_' . $key) ?? '');
                $config[$key] = $stored === '' ? '' : (string) $crypter->decryptData($stored);
            }
        }

        return new JsonResponse($this->container->get(ConnectorTester::class)->test(
            OntologyConnector::TYPE_BUCKET,
            [
                // The config page holds ONE "Endpoint URL" (like the DevTools Bucket Viewer);
                // the tester's resolveHttpEndpoint parses scheme://host[:port] out of `server`
                // and defaults the port from the scheme, so no separate port travels.
                'server' => trim((string) ($config['endpoint_url'] ?? '')),
                'access_key' => (string) ($config['access_key'] ?? ''),
                'secret_key' => (string) ($config['secret_key'] ?? ''),
                'bucket_name' => trim((string) ($config['bucket_name'] ?? '')),
            ]
        ));
    }

    /**
     * Settings → "Rebuild run index": queues the rebuild (it runs in the background — on a bucket
     * it lists days of keys); the page's run index status shows the outcome once it is done.
     */
    #[Route(path: '/config/api/flow-runs-rebuild', name: 'aaxis_ontology_config_flow_runs_rebuild', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    #[CsrfProtection]
    public function flowRunsRebuildAction(): JsonResponse
    {
        try {
            $this->container->get(MessageProducerInterface::class)->send(OntologyFlowRunsRebuildTopic::getName(), []);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false], 500);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Settings → "Clean up now": queues the retention purge the daily job runs (history older than
     * "Flow execution history days", table and bucket alike); the page's "last cleanup" line
     * shows the outcome once it is done.
     */
    #[Route(path: '/config/api/flow-history-purge', name: 'aaxis_ontology_config_flow_history_purge', options: ['expose' => true], methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    #[CsrfProtection]
    public function flowHistoryPurgeAction(): JsonResponse
    {
        try {
            $this->container->get(MessageProducerInterface::class)->send(OntologyFlowHistoryPurgeTopic::getName(), []);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false], 500);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ConnectorTester::class,
            ConfigManager::class,
            SymmetricCrypterInterface::class,
            MessageProducerInterface::class,
        ]);
    }
}
