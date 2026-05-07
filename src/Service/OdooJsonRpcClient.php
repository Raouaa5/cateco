<?php

declare(strict_types=1);

namespace App\Service;

class OdooJsonRpcClient
{
    private ?int $uid = null;

    public function __construct(
        private readonly string $url      = 'http://host.docker.internal:8069/jsonrpc',
        private readonly string $db       = 'cateco_db',
        private readonly string $username = 'admin',
        private readonly string $password = 'admin',
    ) {}

    // -------------------------------------------------------------------------
    // Core JSON-RPC transport
    // -------------------------------------------------------------------------

    private function call(string $service, string $method, array $args): mixed
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'id'      => random_int(1, PHP_INT_MAX),
            'params'  => compact('service', 'method', 'args'),
        ]);

        $ch = curl_init($this->url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException("Odoo cURL error [{$errno}]: {$error}");
        }

        if ($code !== 200) {
            throw new \RuntimeException("Odoo returned unexpected HTTP {$code}");
        }

        $response = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Odoo returned invalid JSON: ' . json_last_error_msg());
        }

        if (isset($response['error'])) {
            $msg = $response['error']['data']['message']
                ?? $response['error']['message']
                ?? 'Unknown Odoo error';
            throw new \RuntimeException('Odoo error: ' . $msg);
        }

        return $response['result'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Authentication (cached)
    // -------------------------------------------------------------------------

    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $uid = $this->call('common', 'login', [
            $this->db,
            $this->username,
            $this->password,
        ]);

        if (!$uid) {
            throw new \RuntimeException(
                "Odoo authentication failed (db='{$this->db}', user='{$this->username}')."
            );
        }

        $this->uid = (int) $uid;
        return $this->uid;
    }

    private function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->authenticate();
        $kwargs['context'] = array_merge(
            $kwargs['context'] ?? [],
            ['allowed_company_ids' => [1], 'company_id' => 1]
        );

        return $this->call('object', 'execute_kw', [
            $this->db, $uid, $this->password,
            $model, $method,
            $args,
            $kwargs,
        ]);
    }

    // -------------------------------------------------------------------------
    // res.partner — Create or Update
    // -------------------------------------------------------------------------

    public function createOrUpdatePartner(string $name, string $email, ?string $phone = null): int
    {
        $uid = $this->authenticate();

        // 1. Search by exact email
        $results = $this->executeKw(
            'res.partner', 'search_read',
            [[['email', '=', $email]]],
            ['fields' => ['id'], 'limit' => 1]
        );

        if (!empty($results)) {
            // Partner exists -> Update
            $partnerId = (int) $results[0]['id'];
            $updateData = ['name' => $name];
            
            if ($phone !== null && $phone !== '') {
                $updateData['phone'] = $phone;
            }

            $this->executeKw(
                'res.partner', 'write',
                [[$partnerId], $updateData]
            );

            return $partnerId;
        }

        // 2. Partner does NOT exist -> Create
        $createData = [
            'name'          => $name,
            'email'         => $email,
            'customer_rank' => 1,
        ];

        if ($phone !== null && $phone !== '') {
            $createData['phone'] = $phone;
        }

        $partnerId = $this->executeKw(
            'res.partner', 'create',
            [$createData]
        );

        if (!is_int($partnerId) && !ctype_digit((string) $partnerId)) {
            throw new \RuntimeException('Odoo did not return a valid partner ID: ' . json_encode($partnerId));
        }

        return (int) $partnerId;
    }

    // -------------------------------------------------------------------------
    // crm.lead — Create Lead
    // -------------------------------------------------------------------------

    public function createLead(
        string $subject,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $message,
        ?int $partnerId = null
    ): int {
        $uid = $this->authenticate();

        $leadData = [
            'name'         => '[CATECO WEBSITE CONTACT] ' . $subject,
            'contact_name' => $name,
            'type'         => 'lead',
            'team_id'      => 1,
            'description'  => $message ?? 'No message provided',
        ];

        if ($email) {
            $leadData['email_from'] = $email;
        }

        if ($phone) {
            $leadData['phone'] = $phone;
        }

        if ($partnerId !== null) {
            $leadData['partner_id'] = $partnerId;
        }

        $leadId = $this->executeKw(
            'crm.lead', 'create',
            [$leadData]
        );

        if (!is_int($leadId) && !ctype_digit((string) $leadId)) {
            throw new \RuntimeException('Odoo did not return a valid lead ID: ' . json_encode($leadId));
        }

        return (int) $leadId;
    }

    // -------------------------------------------------------------------------
    // sale.order — Create Sale Order
    // -------------------------------------------------------------------------

    public function getCurrencyIdByCode(string $currencyCode): int
    {
        $results = $this->executeKw(
            'res.currency', 'search_read',
            [[['name', '=', strtoupper($currencyCode)]]],
            ['fields' => ['id'], 'limit' => 1]
        );

        if (empty($results)) {
            // Fallback to EUR if somehow currency not found
            return 1;
        }

        return (int) $results[0]['id'];
    }

    public function createSaleOrder(int $partnerId, array $items, float $total, string $currencyCode = 'EUR'): int
    {
        // 1. Resolve Currency ID
        $currencyId = $this->getCurrencyIdByCode($currencyCode);

        // 2. Create Sale Order
        $orderData = [
            'partner_id' => $partnerId,
            'date_order' => date('Y-m-d H:i:s'),
            'state'      => 'draft',
            'note'       => 'Created from Sylius',
            'currency_id' => $currencyId,
        ];

        $orderId = $this->executeKw('sale.order', 'create', [$orderData]);

        if (!is_int($orderId) && !ctype_digit((string) $orderId)) {
            throw new \RuntimeException('Odoo did not return a valid sale.order ID.');
        }

        $orderId = (int) $orderId;

        // 3. Process each line item
        foreach ($items as $item) {
            $sku = $item['sku'] ?? null;
            $name = $item['product_name'] ?? 'Unknown Product';
            $price = $item['unit_price'] ?? 0.0;
            $qty = $item['quantity'] ?? 1;

            if (!$sku) {
                // Cannot sync a product without a SKU safely from Odoo rules
                continue;
            }

            // Search product.product by SKU
            $productResults = $this->executeKw(
                'product.product', 'search_read',
                [[['default_code', '=', $sku]]],
                ['fields' => ['id'], 'limit' => 1]
            );

            if (!empty($productResults)) {
                $productId = (int) $productResults[0]['id'];
            } else {
                // Not found, create product dynamically
                $productData = [
                    'name'         => $name,
                    'default_code' => $sku,
                    'list_price'   => $price,
                    'type'         => 'product', // or 'consu' conceptually depending on setup, 'product' is strict
                ];
                $productId = $this->executeKw('product.product', 'create', [$productData]);
            }

            // Create Order Line natively
            $lineData = [
                'order_id'        => $orderId,
                'product_id'      => (int) $productId,
                'product_uom_qty' => $qty,
                'price_unit'      => $price,
                // Passing name is optional but good practice to ensure matching description
                'name'            => $name,
            ];

            $this->executeKw('sale.order.line', 'create', [$lineData]);
        }

        return $orderId;
    }
}