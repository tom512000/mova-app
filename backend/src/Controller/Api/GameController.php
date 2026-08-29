<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\GameKind;
use App\Entity\Enum\GameMode;
use App\Exception\GameException;
use App\Service\Game\FilmGuessGame;
use App\Service\Profile\ViewedProfileResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Playing is a write, and writes belong to the account making them — every action here
 * resolves getAuthenticatedUser(), never getViewedUser(). A forged profileId therefore
 * cannot start or advance a game on somebody else's library, the same rule that keeps
 * import owner-only.
 */
#[Route('/api/games/{game}/{mode}', requirements: ['game' => 'clue|compare|poster|hangman', 'mode' => 'daily|infinite'])]
final class GameController
{
    public function __construct(
        private readonly ViewedProfileResolver $profileResolver,
        private readonly FilmGuessGame $game,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function state(string $game, string $mode): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();
        $session = $this->game->current($user, GameKind::from($game), GameMode::from($mode));

        // Null rather than an empty board: the client decides whether to offer "Jouer" or
        // "Nouvelle partie", and starting a run is an explicit act.
        return new JsonResponse(['session' => null === $session ? null : $this->game->toState($session)]);
    }

    #[Route('/start', methods: ['POST'])]
    public function start(string $game, string $mode): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        try {
            $session = $this->game->start($user, GameKind::from($game), GameMode::from($mode));
        } catch (GameException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['session' => $this->game->toState($session)], Response::HTTP_CREATED);
    }

    #[Route('/guess', methods: ['POST'])]
    public function guess(string $game, string $mode, Request $request): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        $session = $this->game->current($user, GameKind::from($game), GameMode::from($mode));
        if (null === $session) {
            return new JsonResponse(['error' => 'Aucune partie en cours.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $movieId = \is_array($payload) ? (string) ($payload['movieId'] ?? '') : '';
        if (!Uuid::isValid($movieId)) {
            return new JsonResponse(['error' => 'Aucun film proposé.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $session = $this->game->guess($user, $session, $movieId);
        } catch (GameException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['session' => $this->game->toState($session)]);
    }

    /**
     * A letter rather than a film — a route of its own instead of a second shape squeezed
     * into /guess, since only the hangman has anything to do with it.
     */
    #[Route('/letter', methods: ['POST'], requirements: ['game' => 'hangman'])]
    public function letter(string $game, string $mode, Request $request): JsonResponse
    {
        $user = $this->profileResolver->getAuthenticatedUser();

        $session = $this->game->current($user, GameKind::from($game), GameMode::from($mode));
        if (null === $session) {
            return new JsonResponse(['error' => 'Aucune partie en cours.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $letter = \is_array($payload) ? (string) ($payload['letter'] ?? '') : '';

        try {
            $session = $this->game->guessLetter($session, $letter);
        } catch (GameException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['session' => $this->game->toState($session)]);
    }
}
