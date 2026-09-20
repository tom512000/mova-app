<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Card\CardDetailDto;
use App\Entity\Enum\CardSetFamily;
use App\Entity\User;
use App\Repository\CardPackOpeningRepository;
use App\Repository\CardRepository;
use App\Service\Card\CardAlbumService;
use App\Service\Card\CardFeatService;
use App\Service\Card\CardMapper;
use App\Service\Card\CardSetService;
use App\Service\Card\CardShowcaseService;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Reading a collection.
 *
 * Everything here resolves getViewedUser(), so a shared profile's album, sets, feats and
 * showcase are that profile's — which is most of the point of the showcase existing at all.
 * The writes live in CardCabinetController and resolve the authenticated account instead.
 *
 * None of these routes enforces the 500-work gate. That is not an oversight: below it they
 * answer 200 with an empty, locked payload, so a client can draw the "pas encore ouvert"
 * state from the same shape it will later draw cards from — and so the read smoke test,
 * whose fixture holds two works, exercises them at all.
 */
#[Route('/api/cards')]
final class CardController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly CardAlbumService $album,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = $request->query;

        // Unknown values fall back to the whole album rather than raising a 422: these
        // arrive from the address bar, and a stale bookmark should still show cards.
        $owned = $query->has('owned') && '' !== $query->get('owned')
            ? filter_var($query->get('owned'), \FILTER_VALIDATE_BOOL)
            : null;

        $response = $this->album->list(
            $this->profileResolver->getViewedUser(),
            [
                'subject' => $this->nullIfBlank($query->get('subject')),
                'rarity' => $this->nullIfBlank($query->get('rarity')),
                'owned' => $owned,
                'q' => (string) $query->get('q', ''),
                'sort' => (string) $query->get('sort', 'rank'),
            ],
            (int) $query->get('page', 1),
            (int) $query->get('perPage', CardAlbumService::DEFAULT_PER_PAGE)
        );

        return new JsonResponse($response);
    }

    #[Route('/facets', methods: ['GET'])]
    public function facets(): JsonResponse
    {
        return new JsonResponse($this->album->facets($this->profileResolver->getViewedUser()));
    }

    #[Route('/sets', methods: ['GET'])]
    public function sets(Request $request, CardSetService $sets): JsonResponse
    {
        $family = CardSetFamily::tryFrom((string) $request->query->get('family', ''));

        return new JsonResponse(['items' => $sets->list($this->profileResolver->getViewedUser(), $family)]);
    }

    /**
     * Ten of them, so unpaged — the same reasoning TrophyController gives for its shelf.
     */
    #[Route('/feats', methods: ['GET'])]
    public function feats(CardFeatService $feats): JsonResponse
    {
        return new JsonResponse($feats->list($this->profileResolver->getViewedUser()));
    }

    /**
     * The six cards an account puts on show — the one part of the Cabinet meant to be seen
     * by somebody else, which is why it reads the viewed profile and says whose it is.
     */
    #[Route('/showcase', methods: ['GET'])]
    public function showcase(CardShowcaseService $showcase): JsonResponse
    {
        $user = $this->profileResolver->getViewedUser();

        return new JsonResponse([
            'slots' => $showcase->get($user),
            'ownerDisplayName' => $user->getDisplayName(),
        ]);
    }

    /**
     * The last few pack openings.
     *
     * Its reason for existing is a dropped connection: the whole pack arrives in one
     * response and the client animates it locally, so a refresh mid-reveal would otherwise
     * lose the cards it already paid for. This hands them back.
     */
    #[Route('/packs/recent', methods: ['GET'])]
    public function recentPacks(Request $request, CardPackOpeningRepository $openings, CardRepository $cards, CardMapper $mapper): JsonResponse
    {
        $user = $this->profileResolver->getViewedUser();
        $limit = min(20, max(1, (int) $request->query->get('limit', 5)));

        $items = [];
        foreach ($openings->recentForUser($user, $limit) as $opening) {
            $items[] = [
                'id' => (string) $opening->getId(),
                'kind' => $opening->getKind(),
                'cost' => $opening->getCost(),
                'jetonsEarned' => $opening->getJetonsEarned(),
                'openedAt' => $opening->getOpenedAt()->format(\DATE_ATOM),
                'cards' => $this->replay($user, $opening->getCards(), $cards, $mapper),
            ];
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID_V7])]
    public function detail(string $id, CardRepository $cards, CardMapper $mapper): JsonResponse
    {
        $user = $this->profileResolver->getViewedUser();
        $card = $cards->findOneForUser($user, Uuid::fromString($id));

        if (null === $card) {
            return new JsonResponse(['error' => 'Cette carte n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(new CardDetailDto(
            card: $mapper->fromEntity($card),
            score: (float) $card->getScore(),
            catalogueRank: $card->getCatalogueRank(),
            firstOwnedAt: $card->getFirstOwnedAt()?->format(\DATE_ATOM),
            // The two tiers disagreeing is not an error to hide — it means the library grew
            // and the card was re-valued, and the face says so rather than pretending.
            revalued: null !== $card->getOwnedRarity() && $card->getOwnedRarity() !== $card->getRarity(),
        ));
    }

    /**
     * Rebuilds a stored opening into cards.
     *
     * Ids are looked up rather than the cards being copied into the opening row, so a replay
     * shows a card as it is *now* — with the copies since collected — rather than as a
     * snapshot that would quietly go stale. A card the catalogue has since dropped simply
     * does not come back.
     *
     * @param list<string> $cardIds
     *
     * @return list<array<string, mixed>>
     */
    private function replay(User $user, array $cardIds, CardRepository $cards, CardMapper $mapper): array
    {
        $found = [];
        foreach ($cards->findOwnedByIds($user, array_map(static fn (string $id) => Uuid::fromString($id), $cardIds)) as $card) {
            $found[(string) $card->getId()] = $mapper->fromEntity($card);
        }

        $ordered = [];
        foreach ($cardIds as $cardId) {
            if (isset($found[$cardId])) {
                $ordered[] = $found[$cardId];
            }
        }

        return $ordered;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = (string) $value;

        return '' === $value ? null : $value;
    }
}
