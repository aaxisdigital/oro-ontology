<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

/**
 * Stands for an OroCommerce entity behind an internal-system ontology entity (its Doctrine
 * metadata is built by hand in the tests — no ORM attributes, no database).
 */
class InternalProduct
{
    public ?int $id = null;
    public ?string $sku = null;
    public ?string $name = null;
    public ?string $price = null;
    public ?int $qty = null;
    public ?bool $active = null;
    public ?float $weight = null;
    public ?\DateTimeInterface $createdAt = null;
    public ?\DateTimeInterface $releaseDate = null;
    public ?\DateTimeInterface $openingTime = null;

    /** @var array<int, string>|null */
    public ?array $tags = null;

    /** @var array<string, mixed>|null */
    public ?array $meta = null;

    public ?InternalCategory $category = null;
    public ?InternalOwner $owner = null;

    /** @var array<int, InternalCategory> a to-many association: never part of a flat payload */
    public array $variants = [];
}
