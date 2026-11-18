<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuditAction;
use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\OutboxStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Enums\ShiftStatus;
use App\Enums\StockAdjustmentType;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPayment;
use App\Models\Delivery;
use App\Models\Discount;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryBatch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\PosTerminal;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Principal;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductInventorySetting;
use App\Models\ProductModifier;
use App\Models\ProductOutletSetting;
use App\Models\ProductPriceHistory;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Permissions & Roles
        $roleModels = RbacSeeder::seedCatalogue(true);

        // 2. Organization & Multi-Outlet Setup
        $org = Organization::query()->updateOrCreate(
            ['code' => 'SIBOTOL'],
            [
                'name' => 'SiBotol Coffee & Roastery',
                'legal_name' => 'PT SiBotol Nusantara Sejahtera',
                'tax_number' => '01.234.567.8-012.000',
                'currency' => 'IDR',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
            ],
        );

        $outletSenopati = Outlet::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'JKT-SNP'],
            [
                'name' => 'SiBotol Flagship Senopati',
                'address' => 'Jl. Senopati No. 88, Kebayoran Baru, Jakarta Selatan',
                'phone' => '021-5550188',
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => '11.00',
                'service_charge_rate' => '5.00',
                'max_cashier_discount_percent' => '10.00',
                'allow_negative_stock' => false,
                'qris_merchant_name' => 'SIBOTOL SENOPATI JKT',
                'is_active' => true,
            ],
        );

        $outletDago = Outlet::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'BDG-DGO'],
            [
                'name' => 'SiBotol Heritage Dago',
                'address' => 'Jl. Ir. H. Juanda No. 112, Coblong, Bandung',
                'phone' => '022-4200112',
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => '11.00',
                'service_charge_rate' => '0.00',
                'max_cashier_discount_percent' => '10.00',
                'allow_negative_stock' => false,
                'qris_merchant_name' => 'SIBOTOL DAGO BANDUNG',
                'is_active' => true,
            ],
        );

        // POS Terminals
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletSenopati->id, 'code' => 'POS-SNP-01'],
            ['name' => 'Terminal 01 - Main Espresso Bar', 'device_identifier' => 'IPAD-PRO-SNP01', 'is_active' => true],
        );
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletSenopati->id, 'code' => 'POS-SNP-02'],
            ['name' => 'Terminal 02 - Takeaway & Bottle Counter', 'device_identifier' => 'IPAD-PRO-SNP02', 'is_active' => true],
        );
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletDago->id, 'code' => 'POS-DGO-01'],
            ['name' => 'Terminal 01 - Main Cashier Dago', 'device_identifier' => 'ANDROID-POS-DGO01', 'is_active' => true],
        );
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletDago->id, 'code' => 'POS-DGO-02'],
            ['name' => 'Terminal 02 - Garden Patio Bar', 'device_identifier' => 'ANDROID-POS-DGO02', 'is_active' => true],
        );

        // Operational Shift Templates
        $defaultShifts = [
            [
                'name' => 'Shift Pagi',
                'code' => 'PAGI',
                'start_time' => '07:00',
                'end_time' => '15:00',
                'is_overnight' => false,
                'display_order' => 1,
            ],
            [
                'name' => 'Shift Sore',
                'code' => 'SORE',
                'start_time' => '15:00',
                'end_time' => '23:00',
                'is_overnight' => false,
                'display_order' => 2,
            ],
            [
                'name' => 'Shift Malam',
                'code' => 'MALAM',
                'start_time' => '23:00',
                'end_time' => '07:00',
                'is_overnight' => true,
                'display_order' => 3,
            ],
        ];

        foreach ($defaultShifts as $shiftData) {
            Shift::query()->firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'outlet_id' => null,
                    'code' => $shiftData['code'],
                ],
                [
                    'name' => $shiftData['name'],
                    'start_time' => $shiftData['start_time'],
                    'end_time' => $shiftData['end_time'],
                    'is_overnight' => $shiftData['is_overnight'],
                    'display_order' => $shiftData['display_order'],
                    'is_active' => true,
                ]
            );
        }

        // 3. Users & RBAC Assignments
        $passwordHash = Hash::make('password123');
        $pinHash = Hash::make('123456');

        $superAdmin = User::query()->updateOrCreate(
            ['username' => 'admin'],
            [
                'organization_id' => $org->id,
                'name' => 'Arya Wijaya (Super Admin)',
                'email' => 'admin@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => $pinHash,
                'is_active' => true,
            ],
        );
        $superAdmin->roles()->sync([$roleModels[RoleName::SuperAdmin->value]->id]);
        $superAdmin->outlets()->sync([
            $outletSenopati->id => ['is_default' => true],
            $outletDago->id => ['is_default' => false],
        ]);

        $manager = User::query()->updateOrCreate(
            ['username' => 'manager.jkt'],
            [
                'organization_id' => $org->id,
                'name' => 'Diana Putri (Outlet Manager)',
                'email' => 'manager.jkt@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => $pinHash,
                'is_active' => true,
            ],
        );
        $manager->roles()->sync([$roleModels[RoleName::OutletManager->value]->id]);
        $manager->outlets()->sync([
            $outletSenopati->id => ['is_default' => true],
            $outletDago->id => ['is_default' => false],
        ]);

        $supervisorJkt = User::query()->updateOrCreate(
            ['username' => 'spv.jkt'],
            [
                'organization_id' => $org->id,
                'name' => 'Budi Santoso (Supervisor)',
                'email' => 'spv.jkt@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => $pinHash,
                'is_active' => true,
            ],
        );
        $supervisorJkt->roles()->sync([$roleModels[RoleName::Supervisor->value]->id]);
        $supervisorJkt->outlets()->sync([$outletSenopati->id => ['is_default' => true]]);

        $cashierJkt = User::query()->updateOrCreate(
            ['username' => 'kasir.jkt'],
            [
                'organization_id' => $org->id,
                'name' => 'Nadia Kusuma (Kasir Senopati)',
                'email' => 'kasir.jkt@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => null,
                'is_active' => true,
            ],
        );
        $cashierJkt->roles()->sync([$roleModels[RoleName::Cashier->value]->id]);
        $cashierJkt->outlets()->sync([$outletSenopati->id => ['is_default' => true]]);

        $cashierBdg = User::query()->updateOrCreate(
            ['username' => 'kasir.bdg'],
            [
                'organization_id' => $org->id,
                'name' => 'Rizky Pratama (Kasir Dago)',
                'email' => 'kasir.bdg@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => null,
                'is_active' => true,
            ],
        );
        $cashierBdg->roles()->sync([$roleModels[RoleName::Cashier->value]->id]);
        $cashierBdg->outlets()->sync([$outletDago->id => ['is_default' => true]]);

        // 4. Categories & Subcategories
        $catBottled = Category::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'signature-bottled'],
            ['name' => 'Signature Bottled', 'color' => '#059669', 'sort_order' => 1, 'is_active' => true],
        );
        $catCoffee = Category::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'espresso-bar'],
            ['name' => 'Espresso & Milk Bar', 'color' => '#d97706', 'sort_order' => 2, 'is_active' => true],
        );
        $catNonCoffee = Category::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'non-coffee-matcha'],
            ['name' => 'Non-Coffee & Matcha', 'color' => '#2563eb', 'sort_order' => 3, 'is_active' => true],
        );
        $catPastry = Category::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'pastry-snacks'],
            ['name' => 'Artisan Pastry & Bakery', 'color' => '#db2777', 'sort_order' => 4, 'is_active' => true],
        );

        // 5. Products, Variants, Barcodes & Modifiers
        $catalogBlueprint = [
            [
                'category_id' => $catBottled->id,
                'sku' => 'SBT-AREN',
                'name' => 'SiBotol Kopi Susu Gula Aren',
                'description' => 'Signature house blend Arabica Gayo dengan gula aren organik.',
                'base_price' => '32000.00',
                'unit' => 'btl',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Botol 250ml', 'sku' => 'SBT-AREN-250', 'barcode' => '8997001001011', 'price' => '32000.00', 'is_default' => true, 'stock_jkt' => '85.000', 'stock_bdg' => '60.000', 'override_bdg' => '29000.00'],
                    ['name' => 'Botol 500ml', 'sku' => 'SBT-AREN-500', 'barcode' => '8997001001028', 'price' => '58000.00', 'is_default' => false, 'stock_jkt' => '45.000', 'stock_bdg' => '30.000', 'override_bdg' => '54000.00'],
                    ['name' => 'Family 1 Liter', 'sku' => 'SBT-AREN-1L', 'barcode' => '8997001001035', 'price' => '98000.00', 'is_default' => false, 'stock_jkt' => '30.000', 'stock_bdg' => '25.000', 'override_bdg' => '92000.00'],
                ],
                'modifiers' => [
                    ['group_name' => 'Sugar Level', 'name' => 'Normal Sugar', 'price' => '0.00'],
                    ['group_name' => 'Sugar Level', 'name' => 'Less Sugar (70%)', 'price' => '0.00'],
                    ['group_name' => 'Extra Add-On', 'name' => 'Extra Espresso Shot', 'price' => '8000.00'],
                    ['group_name' => 'Extra Add-On', 'name' => 'Upgrade Oat Milk', 'price' => '12000.00'],
                ],
            ],
            [
                'category_id' => $catBottled->id,
                'sku' => 'SBT-CLDBRW',
                'name' => 'SiBotol Cold Brew Black Flores',
                'description' => '18-hour slow steeped single origin Flores Bajawa.',
                'base_price' => '35000.00',
                'unit' => 'btl',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Botol 250ml', 'sku' => 'SBT-CLDBRW-250', 'barcode' => '8997001002018', 'price' => '35000.00', 'is_default' => true, 'stock_jkt' => '50.000', 'stock_bdg' => '40.000'],
                    ['name' => 'Family 1 Liter', 'sku' => 'SBT-CLDBRW-1L', 'barcode' => '8997001002025', 'price' => '105000.00', 'is_default' => false, 'stock_jkt' => '20.000', 'stock_bdg' => '15.000'],
                ],
                'modifiers' => [],
            ],
            [
                'category_id' => $catBottled->id,
                'sku' => 'SBT-PIST',
                'name' => 'SiBotol Pistachio Cream Latte',
                'description' => 'Rich roasted pistachio paste blended with cold brew & fresh milk.',
                'base_price' => '42000.00',
                'unit' => 'btl',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Botol 250ml', 'sku' => 'SBT-PIST-250', 'barcode' => '8997001003015', 'price' => '42000.00', 'is_default' => true, 'stock_jkt' => '40.000', 'stock_bdg' => '35.000'],
                    ['name' => 'Family 1 Liter', 'sku' => 'SBT-PIST-1L', 'barcode' => '8997001003022', 'price' => '125000.00', 'is_default' => false, 'stock_jkt' => '15.000', 'stock_bdg' => '10.000'],
                ],
                'modifiers' => [
                    ['group_name' => 'Extra Add-On', 'name' => 'Upgrade Oat Milk', 'price' => '12000.00'],
                ],
            ],
            [
                'category_id' => $catCoffee->id,
                'sku' => 'ESP-LATTE',
                'name' => 'Cafe Latte Specialty',
                'description' => 'Doubleristretto house blend with silky micro-foam milk.',
                'base_price' => '36000.00',
                'unit' => 'cup',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Hot Regular 8oz', 'sku' => 'ESP-LATTE-HOT', 'barcode' => '8997001004012', 'price' => '36000.00', 'is_default' => true, 'stock_jkt' => '120.000', 'stock_bdg' => '100.000'],
                    ['name' => 'Iced Large 16oz', 'sku' => 'ESP-LATTE-ICE', 'barcode' => '8997001004029', 'price' => '40000.00', 'is_default' => false, 'stock_jkt' => '120.000', 'stock_bdg' => '100.000'],
                ],
                'modifiers' => [
                    ['group_name' => 'Milk Choice', 'name' => 'Fresh Milk', 'price' => '0.00'],
                    ['group_name' => 'Milk Choice', 'name' => 'Oatside Oat Milk', 'price' => '10000.00'],
                    ['group_name' => 'Extra Add-On', 'name' => 'Extra Espresso Shot', 'price' => '8000.00'],
                    ['group_name' => 'Extra Add-On', 'name' => 'Vanilla Syrup', 'price' => '6000.00'],
                ],
            ],
            [
                'category_id' => $catNonCoffee->id,
                'sku' => 'MTC-UJI',
                'name' => 'Ceremonial Uji Matcha Botol',
                'description' => 'First-harvest Uji Kyoto ceremonial matcha whisked to perfection.',
                'base_price' => '40000.00',
                'unit' => 'btl',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Botol 250ml', 'sku' => 'MTC-UJI-250', 'barcode' => '8997001005019', 'price' => '40000.00', 'is_default' => true, 'stock_jkt' => '60.000', 'stock_bdg' => '45.000'],
                    ['name' => 'Family 1 Liter', 'sku' => 'MTC-UJI-1L', 'barcode' => '8997001005026', 'price' => '118000.00', 'is_default' => false, 'stock_jkt' => '18.000', 'stock_bdg' => '12.000'],
                ],
                'modifiers' => [
                    ['group_name' => 'Sugar Level', 'name' => 'Normal Sugar', 'price' => '0.00'],
                    ['group_name' => 'Sugar Level', 'name' => 'No Sugar', 'price' => '0.00'],
                    ['group_name' => 'Extra Add-On', 'name' => 'Upgrade Oat Milk', 'price' => '12000.00'],
                ],
            ],
            [
                'category_id' => $catNonCoffee->id,
                'sku' => 'CHO-VAL',
                'name' => 'Belgian Dark Chocolate Botol',
                'description' => '70% Belgian dark couverture chocolate drink.',
                'base_price' => '38000.00',
                'unit' => 'btl',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Botol 250ml', 'sku' => 'CHO-VAL-250', 'barcode' => '8997001006016', 'price' => '38000.00', 'is_default' => true, 'stock_jkt' => '55.000', 'stock_bdg' => '40.000'],
                ],
                'modifiers' => [],
            ],
            [
                'category_id' => $catPastry->id,
                'sku' => 'PST-CROIS',
                'name' => 'French Butter Croissant',
                'description' => 'Baked fresh daily with French AOP Elle & Vire butter.',
                'base_price' => '28000.00',
                'unit' => 'pcs',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Classic Butter', 'sku' => 'PST-CROIS-BUT', 'barcode' => '8997001007013', 'price' => '28000.00', 'is_default' => true, 'stock_jkt' => '30.000', 'stock_bdg' => '25.000'],
                    ['name' => 'Almond Frangipane', 'sku' => 'PST-CROIS-ALM', 'barcode' => '8997001007020', 'price' => '35000.00', 'is_default' => false, 'stock_jkt' => '22.000', 'stock_bdg' => '18.000'],
                ],
                'modifiers' => [
                    ['group_name' => 'Serving', 'name' => 'Warm in Oven', 'price' => '0.00'],
                ],
            ],
            [
                'category_id' => $catPastry->id,
                'sku' => 'PST-KOUIGN',
                'name' => 'Cinnamon Kouign-Amann',
                'description' => 'Caramelized flaky Breton laminated pastry.',
                'base_price' => '32000.00',
                'unit' => 'pcs',
                'track_stock' => true,
                'variants' => [
                    ['name' => 'Regular', 'sku' => 'PST-KOUIGN-REG', 'barcode' => '8997001008010', 'price' => '32000.00', 'is_default' => true, 'stock_jkt' => '24.000', 'stock_bdg' => '20.000'],
                ],
                'modifiers' => [],
            ],
        ];

        foreach ($catalogBlueprint as $pData) {
            $product = Product::query()->withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $pData['sku']],
                [
                    'category_id' => $pData['category_id'],
                    'name' => $pData['name'],
                    'description' => $pData['description'],
                    'base_price' => $pData['base_price'],
                    'unit' => $pData['unit'],
                    'track_stock' => $pData['track_stock'],
                    'is_active' => true,
                ],
            );

            foreach ($pData['variants'] as $sortIdx => $vData) {
                $variant = ProductVariant::query()->withoutGlobalScopes()->updateOrCreate(
                    ['organization_id' => $org->id, 'sku' => $vData['sku']],
                    [
                        'product_id' => $product->id,
                        'name' => $vData['name'],
                        'barcode' => $vData['barcode'],
                        'price' => $vData['price'],
                        'is_default' => $vData['is_default'],
                        'is_active' => true,
                        'sort_order' => $sortIdx + 1,
                    ],
                );

                // Outlet Settings & Inventories for Senopati
                ProductOutletSetting::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outletSenopati->id, 'product_variant_id' => $variant->id],
                    [
                        'product_id' => $product->id,
                        'is_available' => true,
                        'price_override' => null,
                    ],
                );

                OutletInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outletSenopati->id, 'product_variant_id' => $variant->id],
                    [
                        'product_id' => $product->id,
                        'quantity' => $vData['stock_jkt'],
                        'reorder_level' => '10.000',
                    ],
                );

                // Outlet Settings & Inventories for Dago (with custom regional price overrides)
                ProductOutletSetting::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outletDago->id, 'product_variant_id' => $variant->id],
                    [
                        'product_id' => $product->id,
                        'is_available' => true,
                        'price_override' => $vData['override_bdg'] ?? null,
                    ],
                );

                OutletInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outletDago->id, 'product_variant_id' => $variant->id],
                    [
                        'product_id' => $product->id,
                        'quantity' => $vData['stock_bdg'],
                        'reorder_level' => '10.000',
                    ],
                );
            }

            foreach ($pData['modifiers'] as $mIdx => $mData) {
                ProductModifier::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'group_name' => $mData['group_name'],
                        'name' => $mData['name'],
                    ],
                    [
                        'price' => $mData['price'],
                        'is_required' => false,
                        'is_multiple' => true,
                        'is_active' => true,
                        'sort_order' => $mIdx + 1,
                    ],
                );
            }
        }

        $this->seedSampleOperationalHistory($org, $outletSenopati, $cashierJkt, $supervisorJkt, $manager);

        $this->seedSubDistributorEcosystem(
            org: $org,
            outletJakarta: $outletSenopati,
            outletBandung: $outletDago,
            roleModels: $roleModels,
            superAdmin: $superAdmin,
            manager: $manager,
            passwordHash: $passwordHash,
            pinHash: $pinHash,
        );

        $this->call(AlcoholicBeverageCatalogSeeder::class);
    }

    private function seedSampleOperationalHistory(
        Organization $org,
        Outlet $outlet,
        User $cashier,
        User $supervisor,
        User $manager,
    ): void {
        if (Order::query()->withoutGlobalScopes()->exists()) {
            return;
        }

        $terminal1 = PosTerminal::query()->where('outlet_id', $outlet->id)->where('code', 'POS-SNP-01')->firstOrFail();
        $terminal2 = PosTerminal::query()->where('outlet_id', $outlet->id)->where('code', 'POS-SNP-02')->firstOrFail();

        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->with('product')->firstOrFail();
        $aren1L = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-1L')->with('product')->firstOrFail();
        $matcha250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'MTC-UJI-250')->with('product')->firstOrFail();
        $croissant = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'PST-CROIS-ALM')->with('product')->firstOrFail();

        $t0 = CarbonImmutable::now()->subHours(5);
        $sequences = app(SequenceService::class);

        // 1. Morning Shift (Closed with full reconciliation report)
        $morningShift = CashierShift::query()->withoutGlobalScopes()->create([
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal1->id,
            'user_id' => $cashier->id,
            'shift_number' => $sequences->next($outlet, 'SHF'),
            'status' => ShiftStatus::Closed,
            'opening_cash' => '500000.00',
            'opening_notes' => 'Modal awal laci kasir pagi (pecahan Rp 50rb & Rp 20rb)',
            'opened_at' => $t0,
            'expected_cash' => '645000.00',
            'actual_cash' => '645000.00',
            'cash_difference' => '0.00',
            'total_sales' => '250583.00',
            'total_cash_sales' => '180000.00',
            'total_non_cash_sales' => '70583.00',
            'total_paid_in' => '100000.00',
            'total_paid_out' => '35000.00',
            'total_cash_refunds' => '0.00',
            'transaction_count' => 1,
            'closing_summary' => [
                'discrepancy_status' => 'balanced',
                'breakdown_by_method' => [
                    'cash' => '180000.00',
                    'qris' => '70583.00',
                    'debit_card' => '0.00',
                    'credit_card' => '0.00',
                    'e_wallet' => '0.00',
                    'bank_transfer' => '0.00',
                ],
                'formula' => 'opening_cash + cash_sales + paid_in - paid_out - cash_refunds',
            ],
            'closing_notes' => 'Shift pagi selesai, kas sesuai fisik.',
            'closed_by' => $cashier->id,
            'closed_at' => $t0->addHours(3),
        ]);

        CashMovement::query()->withoutGlobalScopes()->create([
            'cashier_shift_id' => $morningShift->id,
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal1->id,
            'user_id' => $cashier->id,
            'type' => CashMovementType::PaidIn,
            'amount' => '100000.00',
            'reason' => 'Tambahan uang kembalian receh Rp 5.000',
            'notes' => 'Dari brankas outlet',
            'authorized_by' => $supervisor->id,
        ]);

        CashMovement::query()->withoutGlobalScopes()->create([
            'cashier_shift_id' => $morningShift->id,
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal1->id,
            'user_id' => $cashier->id,
            'type' => CashMovementType::PaidOut,
            'amount' => '35000.00',
            'reason' => 'Pembelian es batu kristal darurat 2 kantong',
            'notes' => 'Nota terlampir di laci',
            'authorized_by' => $supervisor->id,
        ]);

        // 2. Sample Completed Order with Split Payment (Cash + QRIS) & Supervisor Discount Override
        $completedOrder = Order::query()->withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal1->id,
            'cashier_shift_id' => $morningShift->id,
            'user_id' => $cashier->id,
            'order_number' => $sequences->next($outlet, 'INV'),
            'status' => OrderStatus::Completed,
            'customer_name' => 'hendra.wijaya',
            'customer_phone' => '081299887766',
            'crm_customer_ref' => 'CRM-MEM-9081',
            'table_label' => 'Dine-In T02',
            'subtotal' => '254000.00',
            'discount_type' => 'percent',
            'discount_value' => '15.00',
            'discount_total' => '38100.00',
            'discount_reason' => 'VIP Partner Corporate Discount 15%',
            'discount_authorized_by' => $supervisor->id,
            'service_charge_rate' => '5.00',
            'service_charge_total' => '10795.00',
            'tax_rate' => '11.00',
            'tax_total' => '24938.00',
            'grand_total' => '251633.00',
            'paid_total' => '270583.00',
            'change_total' => '20000.00',
            'notes' => 'Botol 1L minta dipacking ice gel.',
            'completed_at' => $t0->addMinutes(45),
        ]);

        $completedOrder->items()->createMany([
            [
                'product_id' => $aren1L->product_id,
                'product_variant_id' => $aren1L->id,
                'product_name' => $aren1L->product->name,
                'variant_name' => $aren1L->name,
                'sku' => $aren1L->sku,
                'quantity' => '2.000',
                'original_unit_price' => '98000.00',
                'unit_price' => '98000.00',
                'modifiers' => [],
                'modifiers_total' => '0.00',
                'line_total' => '196000.00',
                'notes' => 'Less sugar 1 botol',
            ],
            [
                'product_id' => $aren250->product_id,
                'product_variant_id' => $aren250->id,
                'product_name' => $aren250->product->name,
                'variant_name' => $aren250->name,
                'sku' => $aren250->sku,
                'quantity' => '1.000',
                'original_unit_price' => '32000.00',
                'unit_price' => '23000.00',
                'price_override_by' => $supervisor->id,
                'price_override_reason' => 'Bundling promo botol 1L',
                'modifiers' => [],
                'modifiers_total' => '0.00',
                'line_total' => '23000.00',
            ],
            [
                'product_id' => $croissant->product_id,
                'product_variant_id' => $croissant->id,
                'product_name' => $croissant->product->name,
                'variant_name' => $croissant->name,
                'sku' => $croissant->sku,
                'quantity' => '1.000',
                'original_unit_price' => '35000.00',
                'unit_price' => '35000.00',
                'modifiers' => [['id' => 1, 'group_name' => 'Serving', 'name' => 'Warm in Oven', 'price' => '0.00']],
                'modifiers_total' => '0.00',
                'line_total' => '35000.00',
            ],
        ]);

        Payment::query()->withoutGlobalScopes()->create([
            'order_id' => $completedOrder->id,
            'outlet_id' => $outlet->id,
            'cashier_shift_id' => $morningShift->id,
            'user_id' => $cashier->id,
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Completed,
            'amount' => '180000.00',
            'tendered_amount' => '200000.00',
            'change_amount' => '20000.00',
            'idempotency_key' => 'seed-idem-0001',
            'paid_at' => $t0->addMinutes(45),
        ]);

        Payment::query()->withoutGlobalScopes()->create([
            'order_id' => $completedOrder->id,
            'outlet_id' => $outlet->id,
            'cashier_shift_id' => $morningShift->id,
            'user_id' => $cashier->id,
            'method' => PaymentMethod::Qris,
            'status' => PaymentStatus::Completed,
            'amount' => '71633.00',
            'tendered_amount' => '71633.00',
            'change_amount' => '0.00',
            'provider' => 'QRIS BCA',
            'reference_number' => 'QR-99283741',
            'qris_payload' => '00020101021226580014ID.CO.QRIS.WWW01159360091430000010207JKT-SNP0303UMI5204581253033605405716335802ID5920SIBOTOL SENOPATI JKT6009INDONESIA6304A1B2',
            'idempotency_key' => 'seed-idem-0001',
            'paid_at' => $t0->addMinutes(45),
        ]);

        // 3. Held (Draft) Order parked on Terminal 02 ready to be resumed on ANY terminal in Senopati
        $heldOrder = Order::query()->withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal2->id,
            'cashier_shift_id' => $morningShift->id,
            'user_id' => $cashier->id,
            'order_number' => $sequences->next($outlet, 'ORD'),
            'status' => OrderStatus::Held,
            'customer_name' => 'Rama Wijaya (Meja VIP 04)',
            'customer_phone' => '08118881234',
            'table_label' => 'VIP-04',
            'subtotal' => '112000.00',
            'discount_value' => '0.00',
            'discount_total' => '0.00',
            'service_charge_rate' => '5.00',
            'service_charge_total' => '5600.00',
            'tax_rate' => '11.00',
            'tax_total' => '12936.00',
            'grand_total' => '130536.00',
            'notes' => 'Menunggu tambahan pesanan teman, parkir dulu di Terminal 02.',
            'held_at' => CarbonImmutable::now()->subMinutes(20),
        ]);

        $heldOrder->items()->createMany([
            [
                'product_id' => $matcha250->product_id,
                'product_variant_id' => $matcha250->id,
                'product_name' => $matcha250->product->name,
                'variant_name' => $matcha250->name,
                'sku' => $matcha250->sku,
                'quantity' => '2.000',
                'original_unit_price' => '40000.00',
                'unit_price' => '40000.00',
                'modifiers' => [],
                'modifiers_total' => '0.00',
                'line_total' => '80000.00',
                'notes' => 'Less ice',
            ],
            [
                'product_id' => $aren250->product_id,
                'product_variant_id' => $aren250->id,
                'product_name' => $aren250->product->name,
                'variant_name' => $aren250->name,
                'sku' => $aren250->sku,
                'quantity' => '1.000',
                'original_unit_price' => '32000.00',
                'unit_price' => '32000.00',
                'modifiers' => [],
                'modifiers_total' => '0.00',
                'line_total' => '32000.00',
            ],
        ]);

        // 4. Sample Manual Stock Adjustment (Damaged Goods with Supervisor PIN)
        $adjustment = StockAdjustment::query()->withoutGlobalScopes()->create([
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal1->id,
            'adjustment_number' => $sequences->next($outlet, 'ADJ'),
            'type' => StockAdjustmentType::Damaged,
            'status' => 'completed',
            'reason' => '2 botol retak saat bongkar muat chiller',
            'notes' => 'Sudah difoto untuk klaim logistik',
            'created_by' => $cashier->id,
            'authorized_by' => $supervisor->id,
            'authorized_at' => $t0->addHours(2),
            'completed_at' => $t0->addHours(2),
        ]);

        StockAdjustmentItem::query()->create([
            'stock_adjustment_id' => $adjustment->id,
            'product_variant_id' => $aren250->id,
            'system_quantity' => '87.000',
            'counted_quantity' => null,
            'quantity_change' => '-2.000',
            'notes' => 'Botol pecah segel',
        ]);

        StockMovement::query()->withoutGlobalScopes()->create([
            'outlet_id' => $outlet->id,
            'product_id' => $aren250->product_id,
            'product_variant_id' => $aren250->id,
            'type' => StockMovementType::Adjustment,
            'quantity_change' => '-2.000',
            'quantity_before' => '87.000',
            'quantity_after' => '85.000',
            'reference_type' => 'StockAdjustment',
            'reference_id' => $adjustment->id,
            'user_id' => $cashier->id,
            'notes' => $adjustment->adjustment_number.' (damaged): 2 botol retak saat bongkar muat chiller',
            'created_at' => $t0->addHours(2),
        ]);

        // 5. Sample Transactional Outbox events for CRM
        OutboxMessage::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'aggregate_type' => 'Order',
            'aggregate_id' => $completedOrder->uuid,
            'event_type' => 'order.completed',
            'event_version' => 1,
            'payload' => [
                'order_number' => $completedOrder->order_number,
                'outlet_code' => $outlet->code,
                'crm_customer_ref' => $completedOrder->crm_customer_ref,
                'grand_total' => $completedOrder->grand_total,
                'is_split_payment' => true,
            ],
            'status' => OutboxStatus::Published,
            'attempts' => 1,
            'available_at' => $t0->addMinutes(45),
            'published_at' => $t0->addMinutes(45)->addSeconds(2),
        ]);

        OutboxMessage::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'aggregate_type' => 'StockAdjustment',
            'aggregate_id' => $adjustment->uuid,
            'event_type' => 'stock.adjusted',
            'event_version' => 1,
            'payload' => [
                'adjustment_number' => $adjustment->adjustment_number,
                'type' => 'damaged',
                'sku' => $aren250->sku,
                'quantity_change' => '-2.000',
            ],
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'available_at' => $t0->addHours(2),
        ]);

        // 6. Comprehensive Append-Only Audit Trail entries with old_values vs new_values
        $seedLogs = [
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::USER_LOGIN,
                'entity_type' => 'User',
                'entity_id' => (string) $cashier->id,
                'old_values' => null,
                'new_values' => ['username' => $cashier->username, 'outlet_code' => $outlet->code, 'terminal_code' => $terminal1->code],
                'created_at' => $t0->subMinutes(2),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::SHIFT_OPENED,
                'entity_type' => 'CashierShift',
                'entity_id' => (string) $morningShift->id,
                'old_values' => null,
                'new_values' => [
                    'shift_number' => $morningShift->shift_number,
                    'opening_cash' => '500000.00',
                    'opening_notes' => $morningShift->opening_notes,
                ],
                'created_at' => $t0,
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::PRICE_OVERRIDE,
                'entity_type' => 'Order',
                'entity_id' => (string) $completedOrder->id,
                'old_values' => ['sku' => $aren250->sku, 'unit_price' => '32000.00'],
                'new_values' => [
                    'sku' => $aren250->sku,
                    'unit_price' => '23000.00',
                    'reason' => 'Bundling promo botol 1L',
                    'authorized_by_name' => $supervisor->name,
                ],
                'created_at' => $t0->addMinutes(44),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::DISCOUNT_OVERRIDE,
                'entity_type' => 'Order',
                'entity_id' => (string) $completedOrder->id,
                'old_values' => ['max_cashier_discount_percent' => '10.00', 'discount_total' => '0.00'],
                'new_values' => [
                    'discount_type' => 'percent',
                    'discount_value' => '15.00',
                    'discount_total' => '38100.00',
                    'discount_reason' => 'VIP Partner Corporate Discount 15%',
                    'authorized_by_name' => $supervisor->name,
                ],
                'created_at' => $t0->addMinutes(44)->addSeconds(10),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::ORDER_CREATED,
                'entity_type' => 'Order',
                'entity_id' => (string) $completedOrder->id,
                'old_values' => null,
                'new_values' => [
                    'order_number' => $completedOrder->order_number,
                    'customer_name' => $completedOrder->customer_name,
                    'subtotal' => '254000.00',
                    'discount_total' => '38100.00',
                    'service_charge_total' => '10795.00',
                    'tax_total' => '24938.00',
                    'grand_total' => '251633.00',
                ],
                'created_at' => $t0->addMinutes(45),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::PAYMENT_PROCESSED,
                'entity_type' => 'Order',
                'entity_id' => (string) $completedOrder->id,
                'old_values' => ['paid_total' => '0.00', 'status' => 'held'],
                'new_values' => [
                    'order_number' => $completedOrder->order_number,
                    'status' => 'completed',
                    'grand_total' => '251633.00',
                    'paid_total' => '271633.00',
                    'change_total' => '20000.00',
                    'is_split_payment' => true,
                    'payments' => [
                        ['method' => 'cash', 'amount' => '180000.00', 'tendered_amount' => '200000.00', 'change_amount' => '20000.00'],
                        ['method' => 'qris', 'amount' => '71633.00', 'provider' => 'QRIS BCA', 'reference_number' => 'QR-99283741'],
                    ],
                ],
                'created_at' => $t0->addMinutes(45)->addSeconds(1),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::STOCK_ADJUSTED,
                'entity_type' => 'StockAdjustment',
                'entity_id' => (string) $adjustment->id,
                'old_values' => [
                    'items' => [['sku' => $aren250->sku, 'name' => 'SiBotol Kopi Susu Gula Aren - Botol 250ml', 'quantity' => '87.000']],
                ],
                'new_values' => [
                    'adjustment_number' => $adjustment->adjustment_number,
                    'type' => 'damaged',
                    'reason' => $adjustment->reason,
                    'authorized_by_name' => $supervisor->name,
                    'items' => [['sku' => $aren250->sku, 'name' => 'SiBotol Kopi Susu Gula Aren - Botol 250ml', 'quantity_change' => '-2.000', 'quantity' => '85.000']],
                ],
                'created_at' => $t0->addHours(2),
            ],
            [
                'actor' => $manager,
                'terminal' => $terminal1,
                'action' => AuditAction::PRODUCT_OUTLET_SETTING_UPDATED,
                'entity_type' => 'ProductOutletSetting',
                'entity_id' => '1',
                'old_values' => ['sku' => 'SBT-AREN-250', 'is_available' => true, 'price_override' => null, 'effective_price' => '32000.00'],
                'new_values' => ['sku' => 'SBT-AREN-250', 'is_available' => true, 'price_override' => '29000.00', 'effective_price' => '29000.00', 'outlet_code' => 'BDG-DGO'],
                'created_at' => $t0->addHours(2)->addMinutes(20),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal1,
                'action' => AuditAction::SHIFT_CLOSED,
                'entity_type' => 'CashierShift',
                'entity_id' => (string) $morningShift->id,
                'old_values' => ['status' => 'open', 'opening_cash' => '500000.00'],
                'new_values' => [
                    'shift_number' => $morningShift->shift_number,
                    'status' => 'closed',
                    'expected_cash' => '645000.00',
                    'actual_cash' => '645000.00',
                    'cash_difference' => '0.00',
                    'discrepancy_status' => 'balanced',
                ],
                'created_at' => $t0->addHours(3),
            ],
            [
                'actor' => $cashier,
                'terminal' => $terminal2,
                'action' => AuditAction::ORDER_HELD,
                'entity_type' => 'Order',
                'entity_id' => (string) $heldOrder->id,
                'old_values' => null,
                'new_values' => [
                    'order_number' => $heldOrder->order_number,
                    'table_label' => 'VIP-04',
                    'customer_name' => $heldOrder->customer_name,
                    'grand_total' => $heldOrder->grand_total,
                    'terminal_code' => $terminal2->code,
                ],
                'created_at' => CarbonImmutable::now()->subMinutes(20),
            ],
        ];

        foreach ($seedLogs as $log) {
            /** @var User $actor */
            $actor = $log['actor'];
            /** @var PosTerminal $term */
            $term = $log['terminal'];

            AuditLog::query()->withoutGlobalScopes()->create([
                'organization_id' => $org->id,
                'actor_id' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'actor_role_snapshot' => $actor->roleSnapshot(),
                'outlet_id' => $outlet->id,
                'pos_terminal_id' => $term->id,
                'action_event' => $log['action'],
                'entity_type' => $log['entity_type'],
                'entity_id' => $log['entity_id'],
                'old_values' => $log['old_values'],
                'new_values' => $log['new_values'],
                'metadata' => [
                    'ip_address' => '192.168.10.21',
                    'user_agent' => 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) SiBotolPOS/1.0',
                    'http_method' => 'POST',
                    'path' => '/api/v1/pos',
                    'request_id' => (string) Str::uuid(),
                ],
                'ip_address' => '192.168.10.21',
                'request_id' => (string) Str::uuid(),
                'created_at' => $log['created_at'],
            ]);
        }
    }

    /**
     * @param  array<string, Role>  $roleModels
     */
    private function seedSubDistributorEcosystem(
        Organization $org,
        Outlet $outletJakarta,
        Outlet $outletBandung,
        array $roleModels,
        User $superAdmin,
        User $manager,
        string $passwordHash,
        string $pinHash,
    ): void {
        // Update existing Jakarta & Bandung outlets with city/region metadata
        $outletJakarta->update([
            'type' => 'branch',
            'name' => 'Outlet Jakarta (Senopati)',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'region' => 'Jabodetabek',
        ]);

        $outletBandung->update([
            'type' => 'branch',
            'name' => 'Outlet Bandung (Dago)',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'region' => 'Priangan',
        ]);

        // Create Outlet Tangerang & Outlet Subang
        $outletTangerang = Outlet::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'TNG-BSD'],
            [
                'type' => 'branch',
                'name' => 'Outlet Tangerang',
                'city' => 'Tangerang',
                'province' => 'Banten',
                'region' => 'Jabodetabek',
                'address' => 'Jl. Raya Serpong KM 8 No. 45, BSD City, Tangerang',
                'phone' => '021-53128890',
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => '11.00',
                'service_charge_rate' => '0.00',
                'max_cashier_discount_percent' => '10.00',
                'allow_negative_stock' => false,
                'qris_merchant_name' => 'SIBOTOL TANGERANG',
                'is_active' => true,
            ],
        );

        $outletSubang = Outlet::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'SBG-KTA'],
            [
                'type' => 'branch',
                'name' => 'Outlet Subang',
                'city' => 'Subang',
                'province' => 'Jawa Barat',
                'region' => 'Pantura & Subang',
                'address' => 'Jl. Otto Iskandardinata No. 19, Karanganyar, Subang',
                'phone' => '0260-411223',
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => '11.00',
                'service_charge_rate' => '0.00',
                'max_cashier_discount_percent' => '10.00',
                'allow_negative_stock' => false,
                'qris_merchant_name' => 'SIBOTOL SUBANG',
                'is_active' => true,
            ],
        );

        // POS Terminals for Tangerang & Subang
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletTangerang->id, 'code' => 'POS-TNG-01'],
            ['name' => 'Counter Kasir 01 - Tangerang', 'device_identifier' => 'POS-TNG-01', 'is_active' => true],
        );
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletTangerang->id, 'code' => 'POS-TNG-02'],
            ['name' => 'Counter Grosir 02 - Tangerang', 'device_identifier' => 'POS-TNG-02', 'is_active' => true],
        );
        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletSubang->id, 'code' => 'POS-SBG-01'],
            ['name' => 'Counter Kasir 01 - Subang', 'device_identifier' => 'POS-SBG-01', 'is_active' => true],
        );

        // Central Warehouse & Branch Warehouses
        $centralWarehouse = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'WH-CENTRAL'],
            [
                'outlet_id' => null,
                'name' => 'Central Warehouse (Head Office)',
                'type' => 'central',
                'city' => 'Jakarta',
                'address' => 'Kawasan Industri Pulogadung Blok J-12, Jakarta Timur',
                'phone' => '021-4608800',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $whTangerang = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'WH-TNG'],
            [
                'outlet_id' => $outletTangerang->id,
                'name' => 'Branch Warehouse Tangerang',
                'type' => 'branch',
                'city' => 'Tangerang',
                'address' => $outletTangerang->address,
                'phone' => $outletTangerang->phone,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $whJakarta = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'WH-JKT'],
            [
                'outlet_id' => $outletJakarta->id,
                'name' => 'Branch Warehouse Jakarta',
                'type' => 'branch',
                'city' => 'Jakarta Selatan',
                'address' => $outletJakarta->address,
                'phone' => $outletJakarta->phone,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $whBandung = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'WH-BDG'],
            [
                'outlet_id' => $outletBandung->id,
                'name' => 'Branch Warehouse Bandung',
                'type' => 'branch',
                'city' => 'Bandung',
                'address' => $outletBandung->address,
                'phone' => $outletBandung->phone,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $whSubang = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'WH-SBG'],
            [
                'outlet_id' => $outletSubang->id,
                'name' => 'Branch Warehouse Subang',
                'type' => 'branch',
                'city' => 'Subang',
                'address' => $outletSubang->address,
                'phone' => $outletSubang->phone,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        // Assign Super Admin & Manager to all 4 outlets
        $superAdmin->outlets()->sync([
            $outletTangerang->id => ['is_default' => true, 'can_switch_outlet' => true, 'is_favorite' => true],
            $outletJakarta->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => true],
            $outletBandung->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => false],
            $outletSubang->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => false],
        ]);

        $manager->outlets()->sync([
            $outletTangerang->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => false],
            $outletJakarta->id => ['is_default' => true, 'can_switch_outlet' => true, 'is_favorite' => true],
            $outletBandung->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => false],
        ]);

        // Additional Sub-Distributor Users (Cashier Tangerang, Cashier Subang, Warehouse Staff, Sales Rep, Finance, Owner)
        $cashierTng = User::query()->updateOrCreate(
            ['username' => 'kasir.tng'],
            [
                'organization_id' => $org->id,
                'name' => 'Siti Rahma (Kasir Tangerang)',
                'email' => 'kasir.tng@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => null,
                'is_active' => true,
            ],
        );
        $cashierTng->roles()->sync([$roleModels[RoleName::Cashier->value]->id]);
        $cashierTng->outlets()->sync([
            $outletTangerang->id => ['is_default' => true, 'can_switch_outlet' => false, 'is_favorite' => true],
        ]);

        $cashierSbg = User::query()->updateOrCreate(
            ['username' => 'kasir.sbg'],
            [
                'organization_id' => $org->id,
                'name' => 'Dedi Kurniawan (Kasir Subang)',
                'email' => 'kasir.sbg@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => null,
                'is_active' => true,
            ],
        );
        $cashierSbg->roles()->sync([$roleModels[RoleName::Cashier->value]->id]);
        $cashierSbg->outlets()->sync([
            $outletSubang->id => ['is_default' => true, 'can_switch_outlet' => false, 'is_favorite' => true],
        ]);

        $warehouseStaff = User::query()->updateOrCreate(
            ['username' => 'gudang.pusat'],
            [
                'organization_id' => $org->id,
                'name' => 'Hendra Gunawan (Kepala Gudang)',
                'email' => 'gudang@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => $pinHash,
                'is_active' => true,
            ],
        );
        $warehouseStaff->roles()->sync([$roleModels[RoleName::WarehouseStaff->value]->id]);
        $warehouseStaff->outlets()->sync([
            $outletTangerang->id => ['is_default' => true, 'can_switch_outlet' => true],
            $outletJakarta->id => ['is_default' => false, 'can_switch_outlet' => true],
        ]);
        $warehouseStaff->warehouses()->sync([
            $centralWarehouse->id => ['is_default' => true],
            $whTangerang->id => ['is_default' => false],
            $whJakarta->id => ['is_default' => false],
        ]);

        $salesRep = User::query()->updateOrCreate(
            ['username' => 'sales.b2b'],
            [
                'organization_id' => $org->id,
                'name' => 'Fajar Nugroho (Sales B2B)',
                'email' => 'sales@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => null,
                'is_active' => true,
            ],
        );
        $salesRep->roles()->sync([$roleModels[RoleName::SalesRep->value]->id]);
        $salesRep->outlets()->sync([
            $outletTangerang->id => ['is_default' => true, 'can_switch_outlet' => true],
            $outletJakarta->id => ['is_default' => false, 'can_switch_outlet' => true],
        ]);

        $financeUser = User::query()->updateOrCreate(
            ['username' => 'finance.ar'],
            [
                'organization_id' => $org->id,
                'name' => 'Lina Marlina (Finance & AR)',
                'email' => 'finance@sibotol.id',
                'password' => $passwordHash,
                'pin_hash' => $pinHash,
                'is_active' => true,
            ],
        );
        $financeUser->roles()->sync([$roleModels[RoleName::Finance->value]->id]);
        $financeUser->outlets()->sync([
            $outletTangerang->id => ['is_default' => true, 'can_switch_outlet' => true],
            $outletJakarta->id => ['is_default' => false, 'can_switch_outlet' => true],
            $outletBandung->id => ['is_default' => false, 'can_switch_outlet' => true],
            $outletSubang->id => ['is_default' => false, 'can_switch_outlet' => true],
        ]);

        // Populate wholesale/reseller/cost prices & sync inventories for Central + all 4 Branch Warehouses
        $variants = ProductVariant::query()->withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->get();

        $branchMap = [
            [$outletTangerang, $whTangerang, '120.000'],
            [$outletJakarta, $whJakarta, null], // preserve existing Jakarta stock
            [$outletBandung, $whBandung, null], // preserve existing Bandung stock
            [$outletSubang, $whSubang, '95.000'],
        ];

        foreach ($variants as $variant) {
            $basePrice = (float) $variant->price;
            $variant->update([
                'cost_price' => number_format(round($basePrice * 0.62, 2), 2, '.', ''),
                'reseller_price' => number_format(round($basePrice * 0.88, 2), 2, '.', ''),
                'wholesale_price' => number_format(round($basePrice * 0.80, 2), 2, '.', ''),
                'min_wholesale_qty' => '10.000',
            ]);

            // Central Warehouse Stock (500 units per variant)
            WarehouseInventory::query()->withoutGlobalScopes()->updateOrCreate(
                ['warehouse_id' => $centralWarehouse->id, 'product_variant_id' => $variant->id],
                [
                    'organization_id' => $org->id,
                    'outlet_id' => null,
                    'product_id' => $variant->product_id,
                    'quantity' => '500.000',
                    'reserved_quantity' => '0.000',
                    'in_transit_quantity' => '0.000',
                    'reorder_level' => '50.000',
                ],
            );

            foreach ($branchMap as [$branchOutlet, $branchWh, $defaultQty]) {
                ProductOutletSetting::query()->updateOrCreate(
                    ['outlet_id' => $branchOutlet->id, 'product_variant_id' => $variant->id],
                    [
                        'product_id' => $variant->product_id,
                        'is_available' => true,
                    ],
                );

                $existingInv = OutletInventory::query()->withoutGlobalScopes()
                    ->where('outlet_id', $branchOutlet->id)
                    ->where('product_variant_id', $variant->id)
                    ->first();

                $qtyToUse = $existingInv ? $existingInv->quantity : ($defaultQty ?? '100.000');
                $reorderToUse = $existingInv ? $existingInv->reorder_level : '20.000';

                $outletInv = OutletInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $branchOutlet->id, 'product_variant_id' => $variant->id],
                    [
                        'warehouse_id' => $branchWh->id,
                        'product_id' => $variant->product_id,
                        'quantity' => $qtyToUse,
                        'reorder_level' => $reorderToUse,
                        'version' => 1,
                    ],
                );

                WarehouseInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['warehouse_id' => $branchWh->id, 'product_variant_id' => $variant->id],
                    [
                        'organization_id' => $org->id,
                        'outlet_id' => $branchOutlet->id,
                        'product_id' => $variant->product_id,
                        'quantity' => $outletInv->quantity,
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'reorder_level' => $outletInv->reorder_level,
                    ],
                );
            }
        }

        // Suppliers / Principals
        $supplier1 = Supplier::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'SUP-PBN'],
            [
                'name' => 'PT Principal Botol & Kemasan Nusantara',
                'contact_person' => 'Irwan Setiawan',
                'phone' => '021-89001122',
                'email' => 'order@principalbotol.co.id',
                'city' => 'Cikarang',
                'address' => 'Kawasan Industri Jababeka Tahap II Blok EE-4, Cikarang',
                'payment_term_days' => 30,
                'is_active' => true,
            ],
        );

        $supplier2 = Supplier::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'SUP-GAYO'],
            [
                'name' => 'PT Gayo Roastery & Extract Principal',
                'contact_person' => 'Mahmudin Siregar',
                'phone' => '021-77889900',
                'email' => 'supply@gayoprincipal.id',
                'city' => 'Jakarta',
                'address' => 'Jl. Raya Bekasi KM 22, Cakung, Jakarta Timur',
                'payment_term_days' => 45,
                'is_active' => true,
            ],
        );

        // Sample Purchase Order to Central Warehouse
        $firstVariant = $variants->first();
        if ($firstVariant !== null) {
            $po = PurchaseOrder::query()->withoutGlobalScopes()->updateOrCreate(
                ['po_number' => 'PO-SIBOTOL-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'supplier_id' => $supplier1->id,
                    'target_warehouse_id' => $centralWarehouse->id,
                    'outlet_id' => null,
                    'status' => 'ordered',
                    'order_date' => now()->subDays(2)->toDateString(),
                    'expected_date' => now()->addDays(2)->toDateString(),
                    'subtotal' => '4000000.00',
                    'tax_total' => '440000.00',
                    'grand_total' => '4440000.00',
                    'paid_total' => '0.00',
                    'payment_status' => 'unpaid',
                    'notes' => 'Restock bulanan Gudang Pusat dari Principal Utama',
                    'created_by' => $superAdmin->id,
                    'approved_by' => $superAdmin->id,
                ],
            );

            PurchaseOrderItem::query()->updateOrCreate(
                ['purchase_order_id' => $po->id, 'product_variant_id' => $firstVariant->id],
                [
                    'product_id' => $firstVariant->product_id,
                    'sku' => $firstVariant->sku,
                    'product_name' => $firstVariant->product->name,
                    'variant_name' => $firstVariant->name,
                    'ordered_qty' => '200.000',
                    'received_qty' => '0.000',
                    'damaged_qty' => '0.000',
                    'unit_cost' => '20000.00',
                    'line_total' => '4000000.00',
                ],
            );

            // Sample Stock Transfer to Tangerang
            $transfer = StockTransfer::query()->withoutGlobalScopes()->updateOrCreate(
                ['transfer_number' => 'TRF-TNG-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'source_warehouse_id' => $centralWarehouse->id,
                    'destination_warehouse_id' => $whTangerang->id,
                    'source_outlet_id' => null,
                    'destination_outlet_id' => $outletTangerang->id,
                    'status' => 'requested',
                    'requested_by' => $warehouseStaff->id,
                    'requested_at' => now()->subHours(5),
                    'notes' => 'Permintaan stok rutin cabang Tangerang dari Gudang Pusat',
                ],
            );

            StockTransferItem::query()->updateOrCreate(
                ['stock_transfer_id' => $transfer->id, 'product_variant_id' => $firstVariant->id],
                [
                    'product_id' => $firstVariant->product_id,
                    'sku' => $firstVariant->sku,
                    'product_name' => $firstVariant->product->name,
                    'variant_name' => $firstVariant->name,
                    'requested_qty' => '50.000',
                    'approved_qty' => '50.000',
                    'shipped_qty' => '0.000',
                    'received_qty' => '0.000',
                    'damaged_qty' => '0.000',
                    'discrepancy_qty' => '0.000',
                ],
            );
        }

        // B2B Customers (Wholesaler, Reseller, Retailer, Business)
        $custTangerang = Customer::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'CUST-TNG-001'],
            [
                'outlet_id' => $outletTangerang->id,
                'name' => 'Toko Grosir Makmur Jaya BSD',
                'company_name' => 'CV Makmur Jaya Distribusi',
                'customer_group' => 'wholesaler',
                'pricing_tier' => 'wholesale',
                'phone' => '0812-8899-1122',
                'email' => 'purchasing@makmurjayabsd.id',
                'city' => 'Tangerang',
                'address' => 'Pasar Modern BSD City Blok R-15, Tangerang Selatan',
                'credit_limit' => '50000000.00',
                'payment_term_days' => 30,
                'outstanding_balance' => '2841600.00',
                'is_active' => true,
            ],
        );

        $custJakarta = Customer::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'CUST-JKT-001'],
            [
                'outlet_id' => $outletJakarta->id,
                'name' => 'Agen Minuman & Botol Kemang',
                'company_name' => 'PT Mitra Kemang Raya',
                'customer_group' => 'reseller',
                'pricing_tier' => 'reseller',
                'phone' => '0811-900-4455',
                'email' => 'finance@mitrakemang.co.id',
                'city' => 'Jakarta Selatan',
                'address' => 'Jl. Kemang Raya No. 41, Jakarta Selatan',
                'credit_limit' => '35000000.00',
                'payment_term_days' => 14,
                'outstanding_balance' => '1500000.00',
                'is_active' => true,
            ],
        );

        Customer::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'CUST-BDG-001'],
            [
                'outlet_id' => $outletBandung->id,
                'name' => 'Grosir Pasir Kaliki Bandung',
                'company_name' => 'UD Pasir Kaliki Sejahtera',
                'customer_group' => 'wholesaler',
                'pricing_tier' => 'wholesale',
                'phone' => '0821-2233-4455',
                'email' => 'order@paskalsejahtera.id',
                'city' => 'Bandung',
                'address' => 'Jl. Pasir Kaliki No. 89, Bandung',
                'credit_limit' => '40000000.00',
                'payment_term_days' => 30,
                'outstanding_balance' => '0.00',
                'is_active' => true,
            ],
        );

        Customer::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'CUST-SBG-001'],
            [
                'outlet_id' => $outletSubang->id,
                'name' => 'Retailer Pasar Baru Subang',
                'company_name' => 'Toko Berkah Subang',
                'customer_group' => 'retailer',
                'pricing_tier' => 'retail',
                'phone' => '0852-9988-7766',
                'email' => 'berkah.subang@gmail.com',
                'city' => 'Subang',
                'address' => 'Komplek Ruko Pasar Baru Subang No. 8',
                'credit_limit' => '15000000.00',
                'payment_term_days' => 14,
                'outstanding_balance' => '0.00',
                'is_active' => true,
            ],
        );

        // Seed sample B2B Sales Order + Delivery + Invoice in Outlet Tangerang & Outlet Jakarta
        if ($firstVariant !== null) {
            $soTng = SalesOrder::query()->withoutGlobalScopes()->updateOrCreate(
                ['so_number' => 'SO-TNG-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'outlet_id' => $outletTangerang->id,
                    'warehouse_id' => $whTangerang->id,
                    'customer_id' => $custTangerang->id,
                    'status' => 'invoiced',
                    'payment_type' => 'tempo',
                    'payment_term_days' => 30,
                    'due_date' => now()->addDays(20)->toDateString(),
                    'subtotal' => '2560000.00',
                    'discount_total' => '0.00',
                    'tax_total' => '281600.00',
                    'grand_total' => '2841600.00',
                    'credit_limit_warning' => false,
                    'approved_by' => $superAdmin->id,
                    'created_by' => $salesRep->id,
                    'notes' => 'Pengiriman grosir 100 botol ke BSD Tangerang',
                    'confirmed_at' => now()->subDays(5),
                    'delivered_at' => now()->subDays(4),
                ],
            );

            SalesOrderItem::query()->updateOrCreate(
                ['sales_order_id' => $soTng->id, 'product_variant_id' => $firstVariant->id],
                [
                    'product_id' => $firstVariant->product_id,
                    'sku' => $firstVariant->sku,
                    'product_name' => $firstVariant->product->name,
                    'variant_name' => $firstVariant->name,
                    'ordered_qty' => '100.000',
                    'fulfilled_qty' => '100.000',
                    'unit_price' => '25600.00',
                    'discount_amount' => '0.00',
                    'line_total' => '2560000.00',
                ],
            );

            Delivery::query()->withoutGlobalScopes()->updateOrCreate(
                ['delivery_number' => 'DO-TNG-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'outlet_id' => $outletTangerang->id,
                    'warehouse_id' => $whTangerang->id,
                    'sales_order_id' => $soTng->id,
                    'status' => 'delivered',
                    'driver_name' => 'Asep Sunandar',
                    'vehicle_number' => 'B 9452 TNG',
                    'recipient_name' => $custTangerang->name,
                    'delivery_address' => $custTangerang->address,
                    'dispatched_at' => now()->subDays(4)->subHours(3),
                    'delivered_at' => now()->subDays(4),
                    'created_by' => $warehouseStaff->id,
                ],
            );

            Invoice::query()->withoutGlobalScopes()->updateOrCreate(
                ['invoice_number' => 'INV-TNG-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'outlet_id' => $outletTangerang->id,
                    'customer_id' => $custTangerang->id,
                    'sales_order_id' => $soTng->id,
                    'status' => 'unpaid',
                    'issue_date' => now()->subDays(4)->toDateString(),
                    'due_date' => now()->addDays(26)->toDateString(),
                    'subtotal' => '2560000.00',
                    'discount_total' => '0.00',
                    'tax_total' => '281600.00',
                    'grand_total' => '2841600.00',
                    'paid_total' => '0.00',
                    'balance_due' => '2841600.00',
                    'notes' => 'Tagihan Tempo 30 Hari - Toko Grosir Makmur Jaya BSD',
                    'created_by' => $financeUser->id,
                ],
            );

            // Overdue partial invoice in Jakarta for Aging Report demo
            Invoice::query()->withoutGlobalScopes()->updateOrCreate(
                ['invoice_number' => 'INV-JKT-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'outlet_id' => $outletJakarta->id,
                    'customer_id' => $custJakarta->id,
                    'sales_order_id' => null,
                    'status' => 'overdue',
                    'issue_date' => now()->subDays(25)->toDateString(),
                    'due_date' => now()->subDays(11)->toDateString(),
                    'subtotal' => '2500000.00',
                    'discount_total' => '0.00',
                    'tax_total' => '275000.00',
                    'grand_total' => '2775000.00',
                    'paid_total' => '1275000.00',
                    'balance_due' => '1500000.00',
                    'notes' => 'Sisa tagihan tempo Agen Kemang (Overdue 11 hari)',
                    'created_by' => $financeUser->id,
                ],
            );
        }

        // 13. Seed Master Diskon (Global & Outlet-Specific)
        $masterDiscounts = [
            [
                'code' => 'MEMBER-5',
                'name' => 'Diskon Member SiBotol 5%',
                'description' => 'Potongan 5% untuk member aktif di seluruh outlet',
                'outlet_id' => null,
                'type' => 'percent',
                'value' => '5.00',
                'min_purchase' => '50000.00',
                'max_discount' => '25000.00',
                'applies_to' => 'both',
                'is_active' => true,
            ],
            [
                'code' => 'GROSIR-10',
                'name' => 'Diskon Pembelian Karton / Grosir 10%',
                'description' => 'Diskon 10% khusus pembelian minimal Rp 300.000',
                'outlet_id' => null,
                'type' => 'percent',
                'value' => '10.00',
                'min_purchase' => '300000.00',
                'max_discount' => '150000.00',
                'applies_to' => 'both',
                'is_active' => true,
            ],
            [
                'code' => 'HEMAT-15K',
                'name' => 'Voucher Potongan Langsung Rp 15.000',
                'description' => 'Potongan Rp 15.000 untuk transaksi Counter POS minimal Rp 100.000',
                'outlet_id' => null,
                'type' => 'fixed',
                'value' => '15000.00',
                'min_purchase' => '100000.00',
                'max_discount' => null,
                'applies_to' => 'pos',
                'is_active' => true,
            ],
            [
                'code' => 'PROMO-TNG-20K',
                'name' => 'Promo Spesial Outlet Tangerang Rp 20.000',
                'description' => 'Khusus transaksi di Outlet Tangerang BSD minimal Rp 150.000',
                'outlet_id' => $outletTangerang->id,
                'type' => 'fixed',
                'value' => '20000.00',
                'min_purchase' => '150000.00',
                'max_discount' => null,
                'applies_to' => 'pos',
                'is_active' => true,
            ],
        ];

        foreach ($masterDiscounts as $md) {
            Discount::query()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'code' => $md['code'],
                ],
                $md,
            );
        }

        // 14. Seed Enterprise Master Data Management, 5th Outlet, Multi-UOM, Multi-Barcodes, Price Lists & Batches (FEFO)
        $this->seedMasterDataAndInventoryEngine(
            org: $org,
            centralWarehouse: $centralWarehouse,
            outletTangerang: $outletTangerang,
            whTangerang: $whTangerang,
            outletJakarta: $outletJakarta,
            whJakarta: $whJakarta,
            outletBandung: $outletBandung,
            whBandung: $whBandung,
            outletSubang: $outletSubang,
            whSubang: $whSubang,
            supplier1: $supplier1,
            supplier2: $supplier2,
            custTangerang: $custTangerang,
            superAdmin: $superAdmin,
            financeUser: $financeUser,
            warehouseStaff: $warehouseStaff,
        );
    }

    private function seedMasterDataAndInventoryEngine(
        Organization $org,
        Warehouse $centralWarehouse,
        Outlet $outletTangerang,
        Warehouse $whTangerang,
        Outlet $outletJakarta,
        Warehouse $whJakarta,
        Outlet $outletBandung,
        Warehouse $whBandung,
        Outlet $outletSubang,
        Warehouse $whSubang,
        Supplier $supplier1,
        Supplier $supplier2,
        Customer $custTangerang,
        User $superAdmin,
        User $financeUser,
        User $warehouseStaff,
    ): void {
        // 5th Outlet: Outlet Bekasi + Branch Warehouse Bekasi
        $outletBekasi = Outlet::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'BKS-PKY'],
            [
                'type' => 'branch',
                'name' => 'Outlet Bekasi (Pekayon)',
                'city' => 'Bekasi',
                'province' => 'Jawa Barat',
                'region' => 'Jabodetabek',
                'address' => 'Jl. Raya Pekayon No. 27, Bekasi Selatan',
                'phone' => '021-82419900',
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => '11.00',
                'service_charge_rate' => '0.00',
                'max_cashier_discount_percent' => '10.00',
                'allow_negative_stock' => false,
                'qris_merchant_name' => 'SIBOTOL BEKASI',
                'is_active' => true,
            ],
        );

        PosTerminal::query()->updateOrCreate(
            ['outlet_id' => $outletBekasi->id, 'code' => 'POS-BKS-01'],
            ['name' => 'Counter Kasir 01 - Bekasi', 'device_identifier' => 'POS-BKS-01', 'is_active' => true],
        );

        $whBekasi = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'WH-BKS'],
            [
                'outlet_id' => $outletBekasi->id,
                'name' => 'Branch Warehouse Bekasi',
                'type' => 'branch',
                'city' => 'Bekasi',
                'address' => $outletBekasi->address,
                'phone' => $outletBekasi->phone,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $superAdmin->outlets()->syncWithoutDetaching([
            $outletBekasi->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => false],
        ]);
        $financeUser->outlets()->syncWithoutDetaching([
            $outletBekasi->id => ['is_default' => false, 'can_switch_outlet' => true, 'is_favorite' => false],
        ]);

        // Master Units
        $unitsBlueprint = [
            ['code' => 'BTL', 'name' => 'Botol', 'description' => 'Satuan dasar botol siap minum'],
            ['code' => 'PCS', 'name' => 'Pieces', 'description' => 'Satuan dasar per buah / unit'],
            ['code' => 'CUP', 'name' => 'Cup', 'description' => 'Satuan gelas / cup saji'],
            ['code' => 'PACK', 'name' => 'Pack (Isi 6)', 'description' => 'Kemasan shrink pack isi 6 unit dasar'],
            ['code' => 'DUS', 'name' => 'Dus / Karton (Isi 24)', 'description' => 'Kemasan karton grosir isi 24 unit dasar'],
            ['code' => 'KRT', 'name' => 'Krat Distribusi (Isi 12)', 'description' => 'Kemasan krat isi 12 unit'],
        ];

        $unitMap = [];
        foreach ($unitsBlueprint as $u) {
            $unitMap[$u['code']] = Unit::query()->updateOrCreate(
                ['organization_id' => $org->id, 'code' => $u['code']],
                [
                    'name' => $u['name'],
                    'description' => $u['description'],
                    'is_active' => true,
                ],
            );
        }

        // Master Principals
        $principal1 = Principal::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'PRIN-TBN'],
            [
                'name' => 'PT Tirta Botol Nusantara (Principal Utama)',
                'contact_person' => 'Hendra Kusnadi',
                'phone' => '021-89005500',
                'email' => 'principal@tirtabotol.co.id',
                'address' => 'Kawasan Industri Jababeka Blok EE-4, Cikarang',
                'is_active' => true,
            ],
        );
        $principal1->suppliers()->sync([$supplier1->id]);

        $principal2 = Principal::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'PRIN-KRI'],
            [
                'name' => 'PT Kopi Roastery & Extract Indonesia',
                'contact_person' => 'Robby Darmawan',
                'phone' => '021-77881100',
                'email' => 'principal@kopiroastery.id',
                'address' => 'Jl. Raya Bekasi KM 22, Cakung, Jakarta Timur',
                'is_active' => true,
            ],
        );
        $principal2->suppliers()->sync([$supplier2->id]);

        // Master Brands
        $brandSignature = Brand::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'BRD-SBT'],
            [
                'principal_id' => $principal1->id,
                'name' => 'SiBotol Signature',
                'description' => 'Lini produk minuman botol ready-to-drink unggulan SiBotol',
                'is_active' => true,
            ],
        );

        $brandRoast = Brand::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'BRD-RBN'],
            [
                'principal_id' => $principal2->id,
                'name' => 'RoastBean Specialty',
                'description' => 'Lini produk espresso & specialty coffee roastery',
                'is_active' => true,
            ],
        );

        $brandMatcha = Brand::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'BRD-MTC'],
            [
                'principal_id' => $principal1->id,
                'name' => 'MatchaKu Uji Kyoto',
                'description' => 'Lini minuman non-coffee ceremonial matcha & Belgian chocolate',
                'is_active' => true,
            ],
        );

        // Enrich Categories with code
        Category::query()->withoutGlobalScopes()->where('slug', 'signature-bottled')->update(['code' => 'CAT-BTL', 'description' => 'Minuman botol siap distribusi']);
        Category::query()->withoutGlobalScopes()->where('slug', 'espresso-bar')->update(['code' => 'CAT-ESP', 'description' => 'Minuman kopi espresso bar']);
        Category::query()->withoutGlobalScopes()->where('slug', 'non-coffee-matcha')->update(['code' => 'CAT-MTC', 'description' => 'Matcha & cokelat botol']);
        Category::query()->withoutGlobalScopes()->where('slug', 'pastry-snacks')->update(['code' => 'CAT-PST', 'description' => 'Artisan bakery & pastry']);

        // Enrich Products, Variants, Multi-UOM (BTL/PCS, PACK=6, DUS=24), Multi-Barcodes, Batches & Bekasi Inventory
        $products = Product::query()->withoutGlobalScopes()
            ->with('variants')
            ->where('organization_id', $org->id)
            ->get();

        $allWarehouses = [
            [$centralWarehouse, null, '500.000'],
            [$whTangerang, $outletTangerang->id, '120.000'],
            [$whJakarta, $outletJakarta->id, '85.000'],
            [$whBandung, $outletBandung->id, '60.000'],
            [$whSubang, $outletSubang->id, '95.000'],
            [$whBekasi, $outletBekasi->id, '110.000'],
        ];

        foreach ($products as $product) {
            $baseUnitCode = strtoupper((string) ($product->unit ?: 'BTL'));
            $baseUnitModel = $unitMap[$baseUnitCode] ?? $unitMap['BTL'];
            $isMatcha = str_starts_with($product->sku, 'MTC') || str_starts_with($product->sku, 'CHO');
            $isEspresso = str_starts_with($product->sku, 'ESP');
            $brandModel = $isMatcha ? $brandMatcha : ($isEspresso ? $brandRoast : $brandSignature);
            $principalModel = $isEspresso ? $principal2 : $principal1;
            $defaultVariant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();

            $product->update([
                'brand_id' => $brandModel->id,
                'principal_id' => $principalModel->id,
                'base_unit_id' => $baseUnitModel->id,
                'short_description' => Str::limit((string) $product->description, 90),
                'manufacturer' => $principalModel->name,
                'product_type' => 'regular',
                'default_barcode' => $defaultVariant?->barcode,
                'track_batch' => true,
                'track_expiry' => true,
                'shelf_life_days' => 30,
                'is_active' => true,
            ]);

            foreach ($product->variants as $variant) {
                $costPrice = number_format(round((float) $variant->price * 0.62, 2), 2, '.', '');
                $minPrice = number_format(round((float) $variant->price * 0.75, 2), 2, '.', '');

                $variant->update([
                    'cost_price' => $costPrice,
                    'average_cost' => $costPrice,
                    'last_purchase_cost' => $costPrice,
                    'min_selling_price' => $minPrice,
                ]);

                // Ensure Bekasi inventory & outlet setting exist
                ProductOutletSetting::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outletBekasi->id, 'product_variant_id' => $variant->id],
                    ['product_id' => $product->id, 'is_available' => true],
                );

                OutletInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outletBekasi->id, 'product_variant_id' => $variant->id],
                    [
                        'warehouse_id' => $whBekasi->id,
                        'product_id' => $product->id,
                        'quantity' => '110.000',
                        'reorder_level' => '20.000',
                        'version' => 1,
                    ],
                );

                WarehouseInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['warehouse_id' => $whBekasi->id, 'product_variant_id' => $variant->id],
                    [
                        'organization_id' => $org->id,
                        'outlet_id' => $outletBekasi->id,
                        'product_id' => $product->id,
                        'quantity' => '110.000',
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'damaged_quantity' => '0.000',
                        'quarantine_quantity' => '0.000',
                        'reorder_level' => '20.000',
                    ],
                );

                // Seed Base Unit (1 = 1), PACK (1 = 6), DUS (1 = 24) for default variant
                if ($variant->is_default) {
                    $baseBarcode = $variant->barcode ?: ('899700'. str_pad((string) $variant->id, 7, '0', STR_PAD_LEFT));
                    $packBarcode = $baseBarcode.'-PK6';
                    $dusBarcode = $baseBarcode.'-DS24';

                    $puBase = ProductUnit::query()->updateOrCreate(
                        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'unit_code' => $baseUnitCode],
                        [
                            'organization_id' => $org->id,
                            'unit_id' => $baseUnitModel->id,
                            'unit_name' => $baseUnitModel->name,
                            'conversion_factor' => '1.000',
                            'is_base' => true,
                            'is_purchase_unit' => false,
                            'is_sales_unit' => false,
                            'barcode' => $baseBarcode,
                            'selling_price' => $variant->price,
                            'purchase_cost' => $costPrice,
                            'min_purchase_qty' => '1.000',
                            'is_active' => true,
                        ],
                    );

                    $packPrice = number_format(round((float) $variant->price * 6 * 0.95, 2), 2, '.', '');
                    $packCost = number_format(round((float) $costPrice * 6, 2), 2, '.', '');
                    $puPack = ProductUnit::query()->updateOrCreate(
                        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'unit_code' => 'PACK'],
                        [
                            'organization_id' => $org->id,
                            'unit_id' => $unitMap['PACK']->id,
                            'unit_name' => 'Pack (Isi 6 '.$baseUnitCode.')',
                            'conversion_factor' => '6.000',
                            'is_base' => false,
                            'is_purchase_unit' => false,
                            'is_sales_unit' => false,
                            'barcode' => $packBarcode,
                            'selling_price' => $packPrice,
                            'purchase_cost' => $packCost,
                            'min_purchase_qty' => '1.000',
                            'is_active' => true,
                        ],
                    );

                    $dusPrice = number_format(round((float) $variant->price * 24 * 0.88, 2), 2, '.', '');
                    $dusCost = number_format(round((float) $costPrice * 24, 2), 2, '.', '');
                    $puDus = ProductUnit::query()->updateOrCreate(
                        ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'unit_code' => 'DUS'],
                        [
                            'organization_id' => $org->id,
                            'unit_id' => $unitMap['DUS']->id,
                            'unit_name' => 'Dus / Karton (Isi 24 '.$baseUnitCode.')',
                            'conversion_factor' => '24.000',
                            'is_base' => false,
                            'is_purchase_unit' => true,
                            'is_sales_unit' => true,
                            'barcode' => $dusBarcode,
                            'selling_price' => $dusPrice,
                            'purchase_cost' => $dusCost,
                            'min_purchase_qty' => '1.000',
                            'is_active' => true,
                        ],
                    );

                    // Register all 3 barcodes in product_barcodes
                    ProductBarcode::query()->updateOrCreate(
                        ['organization_id' => $org->id, 'barcode' => $baseBarcode],
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'product_unit_id' => $puBase->id,
                            'barcode_type' => 'EAN13',
                            'is_primary' => true,
                            'is_active' => true,
                            'label' => 'Barcode satuan dasar ('.$baseUnitCode.')',
                        ],
                    );

                    ProductBarcode::query()->updateOrCreate(
                        ['organization_id' => $org->id, 'barcode' => $packBarcode],
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'product_unit_id' => $puPack->id,
                            'barcode_type' => 'CODE128',
                            'is_primary' => false,
                            'is_active' => true,
                            'label' => 'Barcode kemasan Pack isi 6',
                        ],
                    );

                    ProductBarcode::query()->updateOrCreate(
                        ['organization_id' => $org->id, 'barcode' => $dusBarcode],
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'product_unit_id' => $puDus->id,
                            'barcode_type' => 'CODE128',
                            'is_primary' => false,
                            'is_active' => true,
                            'label' => 'Barcode kemasan Dus/Karton isi 24',
                        ],
                    );

                    // Supplier Product mapping
                    SupplierProduct::query()->updateOrCreate(
                        [
                            'organization_id' => $org->id,
                            'supplier_id' => $isEspresso ? $supplier2->id : $supplier1->id,
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                        ],
                        [
                            'supplier_sku' => 'PRIN-'.$variant->sku,
                            'purchase_price' => $costPrice,
                            'min_order_qty' => '24.000',
                            'lead_time_days' => 3,
                            'is_default_supplier' => true,
                        ],
                    );

                    // Inventory Settings for Central Warehouse
                    ProductInventorySetting::query()->updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'warehouse_id' => $centralWarehouse->id,
                            'outlet_id' => null,
                        ],
                        [
                            'organization_id' => $org->id,
                            'min_stock' => '50.000',
                            'max_stock' => '1000.000',
                            'reorder_point' => '100.000',
                            'safety_stock' => '20.000',
                            'allow_negative_stock' => false,
                        ],
                    );

                    // Seed FEFO Batches in Central & Tangerang Warehouses
                    foreach ([[$centralWarehouse, null, '250.000'], [$whTangerang, $outletTangerang->id, '60.000'], [$whJakarta, $outletJakarta->id, '40.000']] as [$whModel, $outId, $batchQty]) {
                        // Batch 1: Expiring soon (in 18 days) -> FEFO Priority #1
                        InventoryBatch::query()->updateOrCreate(
                            [
                                'warehouse_id' => $whModel->id,
                                'product_variant_id' => $variant->id,
                                'batch_number' => sprintf('LOT-%s-A01', $variant->sku),
                            ],
                            [
                                'organization_id' => $org->id,
                                'outlet_id' => $outId,
                                'product_id' => $product->id,
                                'lot_number' => 'L2026-01',
                                'manufacturing_date' => now()->subMonths(5)->toDateString(),
                                'expiry_date' => now()->addDays(18)->toDateString(),
                                'initial_quantity' => $batchQty,
                                'on_hand_quantity' => $batchQty,
                                'reserved_quantity' => '0.000',
                                'damaged_quantity' => '0.000',
                                'quarantine_quantity' => '0.000',
                                'unit_cost' => $costPrice,
                                'status' => 'available',
                            ],
                        );

                        // Batch 2: Fresh batch (expiring in 150 days) -> FEFO Priority #2
                        InventoryBatch::query()->updateOrCreate(
                            [
                                'warehouse_id' => $whModel->id,
                                'product_variant_id' => $variant->id,
                                'batch_number' => sprintf('LOT-%s-B02', $variant->sku),
                            ],
                            [
                                'organization_id' => $org->id,
                                'outlet_id' => $outId,
                                'product_id' => $product->id,
                                'lot_number' => 'L2026-02',
                                'manufacturing_date' => now()->subDays(15)->toDateString(),
                                'expiry_date' => now()->addDays(150)->toDateString(),
                                'initial_quantity' => $batchQty,
                                'on_hand_quantity' => $batchQty,
                                'reserved_quantity' => '0.000',
                                'damaged_quantity' => '0.000',
                                'quarantine_quantity' => '0.000',
                                'unit_cost' => $costPrice,
                                'status' => 'available',
                            ],
                        );
                    }
                }
            }
        }

        // Price Lists (Retail, Reseller, Wholesale, Customer-Specific VIP)
        $plWholesale = PriceList::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'PL-WHOLESALE'],
            [
                'name' => 'Price List Grosir Sub-Distributor (Wholesale)',
                'type' => 'wholesale',
                'customer_group' => 'wholesaler',
                'priority' => 20,
                'is_active' => true,
            ],
        );

        $plReseller = PriceList::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'PL-RESELLER'],
            [
                'name' => 'Price List Agen & Reseller Resmi',
                'type' => 'reseller',
                'customer_group' => 'reseller',
                'priority' => 15,
                'is_active' => true,
            ],
        );

        $plVip = PriceList::query()->updateOrCreate(
            ['organization_id' => $org->id, 'code' => 'PL-VIP-MAKMUR'],
            [
                'name' => 'Kontrak Harga Khusus CV Makmur Jaya BSD',
                'type' => 'customer',
                'customer_id' => $custTangerang->id,
                'priority' => 50,
                'is_active' => true,
            ],
        );

        $custTangerang->update([
            'price_list_id' => $plVip->id,
            'npwp' => '02.889.112.3-411.000',
            'sales_pic' => 'Fajar Nugroho (Sales B2B)',
            'customer_status' => 'active',
        ]);

        CustomerAddress::query()->updateOrCreate(
            ['customer_id' => $custTangerang->id, 'label' => 'Gudang Utama BSD'],
            [
                'recipient_name' => 'Bpk. Hendra (Kepala Toko)',
                'phone' => $custTangerang->phone,
                'address' => (string) $custTangerang->address,
                'city' => 'Tangerang',
                'is_default' => true,
            ],
        );

        $firstProduct = $products->first();
        $firstDefVariant = $firstProduct?->variants?->firstWhere('is_default', true);
        if ($firstProduct !== null && $firstDefVariant !== null) {
            PriceListItem::query()->updateOrCreate(
                [
                    'price_list_id' => $plWholesale->id,
                    'product_variant_id' => $firstDefVariant->id,
                    'min_qty' => '1.000',
                ],
                [
                    'product_id' => $firstProduct->id,
                    'price' => '25600.00',
                    'discount_percent' => '0.00',
                    'discount_amount' => '0.00',
                ],
            );

            PriceListItem::query()->updateOrCreate(
                [
                    'price_list_id' => $plReseller->id,
                    'product_variant_id' => $firstDefVariant->id,
                    'min_qty' => '1.000',
                ],
                [
                    'product_id' => $firstProduct->id,
                    'price' => '28000.00',
                    'discount_percent' => '0.00',
                    'discount_amount' => '0.00',
                ],
            );

            PriceListItem::query()->updateOrCreate(
                [
                    'price_list_id' => $plVip->id,
                    'product_variant_id' => $firstDefVariant->id,
                    'min_qty' => '1.000',
                ],
                [
                    'product_id' => $firstProduct->id,
                    'price' => '24500.00',
                    'discount_percent' => '0.00',
                    'discount_amount' => '0.00',
                ],
            );

            // Sample Purchase Request
            $pr = PurchaseRequest::query()->updateOrCreate(
                ['pr_number' => 'PR-SIBOTOL-2026-0001'],
                [
                    'organization_id' => $org->id,
                    'warehouse_id' => $centralWarehouse->id,
                    'outlet_id' => null,
                    'status' => 'approved',
                    'needed_by_date' => now()->addDays(4)->toDateString(),
                    'notes' => 'Permintaan pembelian 20 DUS Kopi Susu Gula Aren untuk stok Gudang Pusat',
                    'requested_by' => $warehouseStaff->id,
                    'approved_by' => $superAdmin->id,
                    'approved_at' => now()->subHours(3),
                ],
            );

            PurchaseRequestItem::query()->updateOrCreate(
                ['purchase_request_id' => $pr->id, 'product_variant_id' => $firstDefVariant->id],
                [
                    'product_id' => $firstProduct->id,
                    'sku' => $firstDefVariant->sku,
                    'product_name' => $firstProduct->name,
                    'variant_name' => $firstDefVariant->name,
                    'uom_code' => 'DUS',
                    'conversion_factor' => '24.000',
                    'requested_qty' => '20.000',
                    'approved_qty' => '20.000',
                    'estimated_unit_cost' => '476160.00',
                    'notes' => '20 DUS = 480 Botol',
                ],
            );
        }
    }
}

