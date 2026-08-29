<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds-or-creates the Tag behind a name off a CSV row. The tag counterpart to MovieUpserter,
 * and it exists for the same two reasons.
 *
 * The first is a bug the private helper it replaces was carrying. An Importer batch flushes
 * once at the end (see AbstractCsvImporter), and a repository lookup only sees flushed rows —
 * so two diary entries both tagged "rétrospective" would each miss the lookup, each persist
 * their own Tag, and the pair would hit uniq_tag_name at flush time. Never observed here only
 * because the export in hand has one diary row and no tags at all.
 *
 * The second is that two importers need it. diary.csv owns tags, but reviews.csv carries the
 * column too and is the only source for a viewing when it is uploaded without its diary.csv.
 *
 * Same lifecycle caveat as MovieUpserter: this is a singleton that outlives one batch in the
 * worker process, and Doctrine clears its EntityManager after every handled message — so a
 * Tag cached during an earlier batch would be a detached, stale object by the time a later
 * one reused it. reset() must be called at the start of every import().
 */
final class TagUpserter
{
    /** @var array<string, Tag> */
    private array $cache = [];

    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    public function upsert(string $name): Tag
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $tag = $this->tagRepository->findOneByName($name);
        if (null === $tag) {
            $tag = new Tag($name);
            $this->entityManager->persist($tag);
        }

        return $this->cache[$name] = $tag;
    }
}
