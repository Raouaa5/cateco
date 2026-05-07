<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Webmozart\Assert\Assert;

final readonly class DashboardController
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private Environment $templatingEngine,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var ChannelInterface|null $channel */
        $channel = $this->findChannelByCodeOrFindFirstEnabled($request->query->has('channel') ? (string) $request->query->get('channel') : null);

        if (null === $channel) {
            return new RedirectResponse($this->router->generate('sylius_admin_channel_create'));
        }

        return new Response($this->templatingEngine->render('@SyliusAdmin/dashboard/index.html.twig', [
            'channel' => $channel,
        ]));
    }

    private function findChannelByCodeOrFindFirstEnabled(?string $channelCode): ?ChannelInterface
    {
        if (null !== $channelCode) {
            $channel = $this->channelRepository->findOneByCode($channelCode);
            Assert::nullOrIsInstanceOf($channel, ChannelInterface::class);

            return $channel;
        }

        // FIND FIRST ENABLED CHANNEL INSTEAD OF JUST ANY FIRST CHANNEL
        $enabledChannels = $this->channelRepository->findBy(['enabled' => true], ['id' => 'ASC']);
        $channel = $enabledChannels[0] ?? null;
        Assert::nullOrIsInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }
}
