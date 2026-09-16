<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\Badge\BadgeDto;
use App\Entity\Enum\BadgeCategory;
use App\Service\Badge\BadgeService;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The shelf.
 *
 * One endpoint for both surfaces. The strip on the profile wants the first five and the
 * total; the shelf page wants a page of them, optionally narrowed to one category. Those
 * are the same question with different bounds, and splitting them would mean two things to
 * keep in step over one list.
 *
 * Read-only, so it reports on the *viewed* profile: a shared profile's shelf is that
 * profile's shelf, which is most of the point of showing somebody your badges.
 */
#[Route('/api/badges')]
final class BadgeController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly BadgeService $badgeService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, BadgeService $badgeService): JsonResponse
    {
        $query = $request->query;

        // Unknown values fall back to the whole shelf rather than raising a 422: these
        // arrive from the address bar, and a stale bookmark should still show the badges.
        $category = BadgeCategory::tryFrom((string) $query->get('category', ''));
        $page = max(1, (int) $query->get('page', 1));
        $perPage = min(200, max(1, (int) $query->get('perPage', 48)));

        $badges = $badgeService->getBadges($this->profileResolver->getViewedUser(), $category);

        return new JsonResponse([
            'items' => \array_slice($badges, ($page - 1) * $perPage, $perPage),
            'total' => \count($badges),
            'page' => $page,
            'perPage' => $perPage,
            // What the category filter can offer, counted. Computed from the same pass
            // rather than from a second query, and only when nothing is being filtered —
            // a narrowed request has no business reporting on the categories it excluded.
            'counts' => null === $category ? $this->countsByCategory($badges) : null,
        ]);
    }

    /**
     * @param list<BadgeDto> $badges
     *
     * @return array<string, int>
     */
    private function countsByCategory(array $badges): array
    {
        $counts = [];
        foreach ($badges as $badge) {
            $counts[$badge->category->value] = ($counts[$badge->category->value] ?? 0) + 1;
        }

        return $counts;
    }
}
