<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgreSqlConcurrencyTest extends TestCase
{
    private const TEST_SCHEMA = 'ecommerce_qa_concurrency';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run this integration suite with PostgreSQL.');
        }

        if (config('database.connections.pgsql.search_path') !== self::TEST_SCHEMA) {
            $this->markTestSkipped('Set DB_SEARCH_PATH to the dedicated ecommerce_qa_concurrency schema.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_concurrent_order_confirmations_cannot_oversell_a_shared_balance(): void
    {
        [$variant, $balance] = $this->variantAndBalance(onHand: 1);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $firstOrder = $this->orderFor($variant, 1);
        $secondOrder = $this->orderFor($variant, 1);

        $results = $this->runConcurrently([
            ['action' => 'confirm', 'order_id' => $firstOrder->id, 'actor_id' => $manager->id],
            ['action' => 'confirm', 'order_id' => $secondOrder->id, 'actor_id' => $manager->id],
        ]);

        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === 'confirmed')));
        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === 'rejected')));
        $this->assertSame(1, $balance->fresh()->reserved);
        $this->assertSame(1, StockReservation::query()->where('status', 'active')->sum('quantity'));
        $this->assertSame(1, Order::query()->where('order_status', OrderStatus::Confirmed)->count());
        $this->assertSame(1, Order::query()->where('order_status', OrderStatus::New)->count());
    }

    public function test_concurrent_orders_with_opposite_item_order_do_not_deadlock(): void
    {
        [$firstVariant, $firstBalance] = $this->variantAndBalance(onHand: 1);
        [$secondVariant, $secondBalance] = $this->variantAndBalance(onHand: 1);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $firstOrder = $this->orderWithItems([$firstVariant, $secondVariant], 1);
        $secondOrder = $this->orderWithItems([$secondVariant, $firstVariant], 1);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION concurrency_pause_stock_reservation() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.reserved > OLD.reserved THEN
                    PERFORM pg_sleep(0.5);
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
        DB::unprepared(
            'CREATE TRIGGER concurrency_pause_stock_reservation '
            .'BEFORE UPDATE OF reserved ON stock_balances '
            .'FOR EACH ROW EXECUTE FUNCTION concurrency_pause_stock_reservation()',
        );

        $results = $this->runConcurrently([
            ['action' => 'confirm', 'order_id' => $firstOrder->id, 'actor_id' => $manager->id],
            ['action' => 'confirm', 'order_id' => $secondOrder->id, 'actor_id' => $manager->id],
        ]);

        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === 'confirmed')));
        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === 'rejected')));
        $this->assertSame(1, $firstBalance->fresh()->reserved);
        $this->assertSame(1, $secondBalance->fresh()->reserved);
        $this->assertSame(2, StockReservation::query()->where('status', 'active')->count());
    }

    public function test_concurrent_reservation_and_reconciliation_preserve_the_stock_invariant(): void
    {
        [$variant, $balance] = $this->variantAndBalance(onHand: 3);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $operator = User::factory()->create(['role' => UserRole::WarehouseOperator]);
        $order = $this->orderFor($variant, 3);

        $results = $this->runConcurrently([
            ['action' => 'confirm', 'order_id' => $order->id, 'actor_id' => $manager->id],
            ['action' => 'reconcile', 'variant_id' => $variant->id, 'actor_id' => $operator->id, 'counted_on_hand' => 2],
        ]);

        $this->assertCount(1, array_filter($results, fn (string $result): bool => $result === 'rejected'));
        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => in_array($result, ['confirmed', 'reconciled'], true))));
        $this->assertGreaterThanOrEqual($balance->fresh()->reserved, $balance->fresh()->on_hand);
        $this->assertSame(
            (int) $balance->fresh()->reserved,
            (int) StockReservation::query()->where('status', 'active')->sum('quantity'),
        );
    }

    public function test_concurrent_receipt_and_reconciliation_keep_the_movement_ledger_balanced(): void
    {
        [$variant, $balance] = $this->variantAndBalance(onHand: 5);
        $operator = User::factory()->create(['role' => UserRole::WarehouseOperator]);

        $results = $this->runConcurrently([
            ['action' => 'receive', 'variant_id' => $variant->id, 'actor_id' => $operator->id, 'quantity' => 3],
            ['action' => 'reconcile', 'variant_id' => $variant->id, 'actor_id' => $operator->id, 'counted_on_hand' => 7],
        ]);

        $this->assertEqualsCanonicalizing(['received', 'reconciled'], $results);

        $freshBalance = $balance->fresh();
        $movementDelta = (int) StockMovement::query()
            ->where('product_variant_id', $variant->id)
            ->sum('quantity_delta');

        $this->assertSame(5 + $movementDelta, $freshBalance->on_hand);
        $this->assertSame(2, StockMovement::query()->where('product_variant_id', $variant->id)->count());
        $this->assertGreaterThanOrEqual($freshBalance->reserved, $freshBalance->on_hand);
    }

    /**
     * @param  list<array<string, int|string>>  $workers
     * @return list<string>
     */
    private function runConcurrently(array $workers): array
    {
        Schema::create('concurrency_test_barriers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedInteger('ready_count')->default(0);
            $table->boolean('released')->default(false);
        });

        $barrierId = bin2hex(random_bytes(16));
        DB::table('concurrency_test_barriers')->insert([
            'id' => $barrierId,
            'ready_count' => 0,
            'released' => false,
        ]);

        $scriptPath = tempnam(sys_get_temp_dir(), 'ecommerce-pg-concurrency-');
        if ($scriptPath === false) {
            throw new \RuntimeException('Could not create the PostgreSQL concurrency worker script.');
        }

        $script = <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$barrierId = getenv('CONCURRENCY_BARRIER_ID');
DB::table('concurrency_test_barriers')->where('id', $barrierId)->increment('ready_count');
$deadline = microtime(true) + 15;

while (! DB::table('concurrency_test_barriers')->where('id', $barrierId)->value('released')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the concurrency barrier.');
    }

    usleep(10000);
}

$actor = App\Models\User::query()->findOrFail((int) getenv('CONCURRENCY_ACTOR_ID'));

try {
    switch (getenv('CONCURRENCY_ACTION')) {
        case 'confirm':
            app(App\Services\OrderWorkflow::class)->transition(
                App\Models\Order::query()->findOrFail((int) getenv('CONCURRENCY_ORDER_ID')),
                App\Enums\OrderStatus::Confirmed,
                $actor,
                'PostgreSQL concurrency integration test',
            );
            echo 'confirmed';
            break;
        case 'receive':
            $request = Illuminate\Http\Request::create('/api/v1/admin/warehouse/receipts', 'POST', [
                'variant_id' => (int) getenv('CONCURRENCY_VARIANT_ID'),
                'quantity' => (int) getenv('CONCURRENCY_QUANTITY'),
                'note' => 'PostgreSQL concurrency integration test receipt',
            ]);
            $request->setUserResolver(fn () => $actor);
            $response = app(App\Http\Controllers\Api\V1\AdminWarehouseController::class)
                ->receive($request, app(App\Services\LowStockAlertService::class));
            if ($response->getStatusCode() !== 201) {
                throw new RuntimeException('The concurrent receipt did not return HTTP 201.');
            }
            echo 'received';
            break;
        case 'reconcile':
            $request = Illuminate\Http\Request::create('/api/v1/admin/warehouse/reconciliations', 'POST', [
                'variant_id' => (int) getenv('CONCURRENCY_VARIANT_ID'),
                'counted_on_hand' => (int) getenv('CONCURRENCY_COUNTED_ON_HAND'),
                'note' => 'PostgreSQL concurrency integration test reconciliation',
            ]);
            $request->setUserResolver(fn () => $actor);
            $response = app(App\Http\Controllers\Api\V1\AdminWarehouseController::class)
                ->reconcile($request, app(App\Services\LowStockAlertService::class));
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('The concurrent reconciliation did not return HTTP 200.');
            }
            echo 'reconciled';
            break;
        default:
            throw new RuntimeException('Unknown PostgreSQL concurrency worker action.');
    }
} catch (Illuminate\Validation\ValidationException) {
    echo 'rejected';
}
PHP;

        if (file_put_contents($scriptPath, $script) === false) {
            @unlink($scriptPath);
            throw new \RuntimeException('Could not write the PostgreSQL concurrency worker script.');
        }

        $processes = [];

        try {
            foreach ($workers as $worker) {
                $process = new Process(
                    [...$this->childPhpCommand(), $scriptPath],
                    base_path(),
                    $this->childEnvironment($worker, $barrierId),
                    timeout: 30,
                );
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 20;
            while ((int) DB::table('concurrency_test_barriers')->where('id', $barrierId)->value('ready_count') < count($workers)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning() && ! $process->isSuccessful()) {
                        throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
                    }
                }

                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Concurrent PostgreSQL workers did not reach the barrier.');
                }

                usleep(10000);
            }

            DB::table('concurrency_test_barriers')->where('id', $barrierId)->update(['released' => true]);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim($process->getOutput());
            }

            return $results;
        } finally {
            DB::table('concurrency_test_barriers')->where('id', $barrierId)->update(['released' => true]);
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            @unlink($scriptPath);
        }
    }

    /**
     * @return list<string>
     */
    private function childPhpCommand(): array
    {
        $command = [PHP_BINARY];
        $extensions = PHP_OS_FAMILY === 'Windows'
            ? ['php_pgsql.dll', 'php_pdo_pgsql.dll']
            : ['pgsql', 'pdo_pgsql'];
        $extensionDirectory = rtrim((string) ini_get('extension_dir'), DIRECTORY_SEPARATOR);

        foreach ($extensions as $extension) {
            $command[] = '-d';
            $command[] = 'extension='.$extensionDirectory.DIRECTORY_SEPARATOR.$extension;
        }

        return $command;
    }

    /**
     * @param  array<string, int|string>  $worker
     * @return array<string, string>
     */
    private function childEnvironment(array $worker, string $barrierId): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];

        return array_merge($environment, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'DB_SEARCH_PATH' => self::TEST_SCHEMA,
            'DB_URL' => '',
            'CONCURRENCY_BARRIER_ID' => $barrierId,
            'CONCURRENCY_ACTION' => (string) $worker['action'],
            'CONCURRENCY_ACTOR_ID' => (string) $worker['actor_id'],
            'CONCURRENCY_ORDER_ID' => (string) ($worker['order_id'] ?? ''),
            'CONCURRENCY_VARIANT_ID' => (string) ($worker['variant_id'] ?? ''),
            'CONCURRENCY_QUANTITY' => (string) ($worker['quantity'] ?? ''),
            'CONCURRENCY_COUNTED_ON_HAND' => (string) ($worker['counted_on_hand'] ?? ''),
        ]);
    }

    /**
     * @return array{ProductVariant, StockBalance, Warehouse}
     */
    private function variantAndBalance(int $onHand): array
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->for($category)->create(['is_published' => true]);
        $variant = ProductVariant::factory()->for($product)->create(['is_active' => true]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'main'],
            ['name' => 'Main warehouse', 'is_active' => true],
        );
        $balance = StockBalance::factory()->for($warehouse)->for($variant, 'variant')->create([
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$variant, $balance, $warehouse];
    }

    private function orderFor(ProductVariant $variant, int $quantity): Order
    {
        return $this->orderWithItems([$variant], $quantity);
    }

    /**
     * @param  list<ProductVariant>  $variants
     */
    private function orderWithItems(array $variants, int $quantity): Order
    {
        $subtotal = collect($variants)->sum(fn (ProductVariant $variant): int => $variant->price_kzt * $quantity);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::New,
            'subtotal_kzt' => $subtotal,
            'delivery_fee_kzt' => 0,
            'total_kzt' => $subtotal,
        ]);

        foreach ($variants as $variant) {
            $order->items()->create([
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $variant->product->name,
                'variant_name_snapshot' => $variant->name,
                'sku_snapshot' => $variant->sku,
                'variant_attributes_snapshot' => $variant->attributes,
                'unit_price_kzt' => $variant->price_kzt,
                'quantity' => $quantity,
                'line_total_kzt' => $variant->price_kzt * $quantity,
            ]);
        }

        return $order;
    }
}
