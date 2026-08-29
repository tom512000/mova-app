<?php

declare(strict_types=1);

namespace App\Entity\Concern;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The identity every entity in this app carries: a UUIDv7, assigned by PHP in the
 * constructor rather than by the database on insert.
 *
 * Version 7 rather than 4 because the first 48 bits are a millisecond timestamp: rows
 * sort by creation order the way the old auto-increment integers did, and a B-tree index
 * keeps inserting at its right edge instead of scattering writes across the whole index
 * the way random v4 keys do.
 *
 * Assigning in the constructor is the part that changes how the rest of the code reads.
 * With IDENTITY columns an entity had no id until it was flushed, which meant a freshly
 * created object could not be used as a query parameter or compared to a stored one —
 * a trap this codebase has already fallen into once (see ReviewsImporter, which had to
 * guard on `null !== $movie->getId()` before looking a film up by its viewings). An id
 * that exists from `new` onwards removes that whole class of bug, and it is also what
 * lets a batch build a graph of related objects and flush it in one go.
 *
 * One thing to watch, and the reason getId() returns an object rather than a string:
 * two Uuid instances holding the same value are not `===` each other. Compare identities
 * with `->equals()`, or cast both sides to string — never with `===`.
 */
trait HasUuid
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Every entity's constructor must call this. It is deliberately not a constructor
     * itself: the entities here have their own, with required arguments.
     */
    private function initialiseUuid(): void
    {
        $this->id = new UuidV7();
    }
}
