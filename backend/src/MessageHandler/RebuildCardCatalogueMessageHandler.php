<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RebuildCardCatalogueMessage;
use App\Repository\UserRepository;
use App\Service\Card\CardCatalogueBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rescores a catalogue off the request path.
 *
 * Idempotent by construction: the builder is a full recompute followed by an upsert, so a
 * redelivered envelope produces the same rows rather than a second copy of them — and
 * because ownership columns are absent from every SET list, running it twice cannot cost
 * anybody a card.
 *
 * An account deleted between dispatch and delivery is a no-op rather than a failure: a
 * message can outlive the state it was queued for.
 */
#[AsMessageHandler]
final class RebuildCardCatalogueMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CardCatalogueBuilder $builder,
    ) {
    }

    public function __invoke(RebuildCardCatalogueMessage $message): void
    {
        $user = $this->users->find($message->userId);
        if (null === $user) {
            return;
        }

        $this->builder->rebuild($user);
    }
}
