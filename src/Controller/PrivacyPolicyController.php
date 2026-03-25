<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class PrivacyPolicyController extends AbstractController
{
    public function index(): Response
    {
        return $this->render('Shop/Static/privacy_policy.html.twig');
    }
}
