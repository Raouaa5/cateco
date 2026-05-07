<?php
require '/srv/sylius/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv('/srv/sylius/.env');
$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');

$conn = $em->getConnection();

echo "Starting order totals recalculation...\n";

// Recalculate total for ALL orders based on the REMAINING items
$sql = "
    UPDATE sylius_order o
    LEFT JOIN (
        SELECT order_id, COALESCE(SUM(total), 0) as calc_total
        FROM sylius_order_item
        GROUP BY order_id
    ) i ON i.order_id = o.id
    SET o.items_total = COALESCE(i.calc_total, 0),
        o.total = COALESCE(i.calc_total, 0)
";

$affected = $conn->executeStatement($sql);
echo "Recalculated items_total and total for $affected orders.\n";

// Also delete orders that now have exactly 0 items because their only items were fixture clothes!
// (This cleans up ghost carts and empty ghost orders)
$emptyOrdersSql = "
    DELETE o FROM sylius_order o
    LEFT JOIN sylius_order_item oi ON oi.order_id = o.id
    WHERE oi.id IS NULL
";
$deleted = $conn->executeStatement($emptyOrdersSql);
echo "Deleted $deleted empty ghost orders/carts that had 0 items.\n";

echo "Database synchronization complete.\n";
