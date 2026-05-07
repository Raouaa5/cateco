<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OdooJsonRpcClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class OdooController
{
    public function __construct(
        private readonly OdooJsonRpcClient $odoo,
    ) {}

    #[Route('/api/odoo/partner', name: 'api_odoo_partner', methods: ['POST'])]
    public function createOrUpdatePartner(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Exception) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $name  = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? '')) ?: null;

        if ($name === '' || $email === '') {
            return new JsonResponse([
                'status'  => 'error',
                'message' => '"name" and "email" are required.',
            ], 422);
        }

        try {
            $partnerId = $this->odoo->createOrUpdatePartner($name, $email, $phone);

            return new JsonResponse([
                'status'     => 'success',
                'source'     => 'API',
                'partner_id' => $partnerId,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}