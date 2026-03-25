<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class LegalMentionsController extends AbstractController
{
    public function index(): Response
    {
        return $this->render('Shop/Static/legal_mentions.html.twig');
    }
}
