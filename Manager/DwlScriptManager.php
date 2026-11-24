<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyDwlScript;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * The Data Playground's saved scripts ({@see OntologyDwlScript}): who sees what, and the rules a
 * script and its metadata must meet to be saved. Scripts are PRIVATE to their owner unless
 * PUBLIC; only the owner changes or deletes one (the controller enforces that with
 * {@see OntologyDwlScript::isOwnedBy()}).
 */
class DwlScriptManager
{
    /** A row limit above this is a typo, not a choice (no limit = null). */
    public const int MAX_LIMIT = 1000000;

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * The user's own scripts plus everyone's public ones, by name.
     *
     * @return OntologyDwlScript[]
     */
    public function visibleTo(User $user): array
    {
        return $this->manager()
            ->createQuery(
                'SELECT s FROM ' . OntologyDwlScript::class . ' s'
                . ' WHERE s.owner = :user OR s.public = true ORDER BY s.name ASC, s.id ASC'
            )
            ->setParameter('user', $user)
            ->getResult();
    }

    /** The script when this user may see it (own, or public); null otherwise. */
    public function findVisible(int $id, ?User $user): ?OntologyDwlScript
    {
        $script = $this->doctrine->getRepository(OntologyDwlScript::class)->find($id);
        if (!$script instanceof OntologyDwlScript) {
            return null;
        }

        return $script->isPublic() || $script->isOwnedBy($user) ? $script : null;
    }

    /**
     * Applies `{name, script, public, inputs, limit}` to the script and stores it (inputs:
     * `[{entityId, name}]`, {@see DwlInputs}; limit: the default max rows per input, null = all).
     * Returns null when saved, else the reason as a translation key suffix
     * (`aaxis.ontology.data_playground.<reason>`; `name_too_long` takes `%max%`).
     *
     * @param array<string, mixed> $body
     */
    public function save(OntologyDwlScript $script, array $body): ?string
    {
        $fields = $this->readFields($body);
        $owner = $script->getOwner();
        $refusal = match (true) {
            \is_string($fields) => $fields,
            $owner === null => 'not_owner',
            $this->nameTaken($fields['name'], $owner, $script->getId()) => 'name_taken',
            default => null,
        };
        if ($refusal !== null || \is_string($fields)) {
            return $refusal;
        }

        $script->setName($fields['name'])
            ->setScript($fields['script'])
            ->setPublic($fields['public'])
            ->setInputs($fields['inputs'])
            ->setRowLimit($fields['limit'])
            ->touch();
        try {
            $manager = $this->manager();
            $manager->persist($script);
            $manager->flush();

            return null;
        } catch (UniqueConstraintViolationException) {
            // Two saves racing for the same name: the unique index has the last word.
            return 'name_taken';
        }
    }

    public function delete(OntologyDwlScript $script): void
    {
        $manager = $this->manager();
        $manager->remove($script);
        $manager->flush();
    }

    /**
     * The client's view of a script; `mine` tells the page whether the user may change it.
     *
     * @return array<string, mixed>
     */
    public function serialize(OntologyDwlScript $script, User $user): array
    {
        $owner = $script->getOwner();

        return [
            'id' => $script->getId(),
            'name' => $script->getName(),
            'script' => $script->getScript(),
            'public' => $script->isPublic(),
            'mine' => $script->isOwnedBy($user),
            'owner' => $owner !== null ? self::userName($owner) : '',
            'inputs' => $script->getInputs(),
            'limit' => $script->getRowLimit(),
            'updatedAt' => $script->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{name: string, script: string, public: bool, inputs: list<array{entityId: int, name: string}>, limit: ?int}|string
     *         the fields, or the reason they cannot be saved
     */
    private function readFields(array $body): array|string
    {
        $name = \is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $limit = $body['limit'] ?? null;
        $inputs = DwlInputs::parse(
            $body['inputs'] ?? null,
            fn (int $id): ?OntologyEntity => $this->doctrine->getRepository(OntologyEntity::class)->find($id)
        );

        return match (true) {
            $name === '' => 'name_required',
            mb_strlen($name) > OntologyDwlScript::NAME_MAX_LENGTH => 'name_too_long',
            !\is_string($body['script'] ?? null) => 'script_invalid',
            !\is_bool($body['public'] ?? false) => 'public_invalid',
            $limit !== null && (!\is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) => 'limit_invalid',
            \is_string($inputs) => $inputs,
            default => [
                'name' => $name,
                'script' => $body['script'],
                'public' => (bool) ($body['public'] ?? false),
                'inputs' => DwlInputs::toStored($inputs),
                'limit' => $limit,
            ],
        };
    }

    private function nameTaken(string $name, User $owner, ?int $exceptId): bool
    {
        $count = $this->manager()
            ->createQuery(
                'SELECT COUNT(s.id) FROM ' . OntologyDwlScript::class . ' s'
                . ' WHERE s.owner = :owner AND s.name = :name AND s.id <> :except'
            )
            ->setParameter('owner', $owner)
            ->setParameter('name', $name)
            ->setParameter('except', $exceptId ?? 0)
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    private static function userName(User $user): string
    {
        $name = trim($user->getFirstName() . ' ' . $user->getLastName());

        return $name !== '' ? $name : $user->getUserIdentifier();
    }

    /**
     * @return ObjectManager&\Doctrine\ORM\EntityManagerInterface
     */
    private function manager(): ObjectManager
    {
        return $this->doctrine->getManagerForClass(OntologyDwlScript::class);
    }
}
