<?php

namespace App\Github\Controller;

use App\Github\Message\Async\GithubPullRequestUpdate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/webhook', name: 'api_webhook_')]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/github/pr_update', name: 'github_pr_update', methods: ['POST'], format: 'json')]
    public function index(Request $request): JsonResponse
    {
        /** @var array $data = ['owner' => string, 'repo' => string, 'prId' => number, 'status' => string] */
        $data = $request->getPayload()->all();
        if (!isset($data['owner'], $data['repo'], $data['prId'], $data['status'])) {
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $this->bus->dispatch(new GithubPullRequestUpdate(
            $data['owner'],
            $data['repo'],
            $data['prId'],
            $data['status'],
        ));

        return new JsonResponse([], Response::HTTP_ACCEPTED);
    }
}
