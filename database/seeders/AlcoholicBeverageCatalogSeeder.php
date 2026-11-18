<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\PriceList;
use App\Models\Principal;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductOutletSetting;
use App\Models\ProductTierAssignment;
use App\Models\ProductTierPrice;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Tier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AlcoholicBeverageCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::query()->first();
        if ($org === null) {
            return;
        }

        // 1. Ensure Trade & Packaging Units exist
        $unitsConfig = [
            ['code' => 'BTL', 'name' => 'Botol', 'unit_type' => 'trade', 'is_base' => true, 'is_purchase' => true, 'is_sales' => true, 'desc' => 'Individual bottle'],
            ['code' => 'KLG', 'name' => 'Kaleng', 'unit_type' => 'trade', 'is_base' => true, 'is_purchase' => true, 'is_sales' => true, 'desc' => 'Individual can'],
            ['code' => 'PCS', 'name' => 'PCS / Unit', 'unit_type' => 'trade', 'is_base' => true, 'is_purchase' => true, 'is_sales' => true, 'desc' => 'Piece / unit'],
            ['code' => 'PACK', 'name' => 'Pack', 'unit_type' => 'packaging', 'is_base' => false, 'is_purchase' => true, 'is_sales' => true, 'desc' => 'Multipack packaging'],
            ['code' => 'DUS', 'name' => 'Dus / Karton / Case', 'unit_type' => 'packaging', 'is_base' => false, 'is_purchase' => true, 'is_sales' => true, 'desc' => 'Carton / box / case'],
            ['code' => 'KRT', 'name' => 'Krat', 'unit_type' => 'packaging', 'is_base' => false, 'is_purchase' => true, 'is_sales' => true, 'desc' => 'Beverage crate'],
            ['code' => 'PLT', 'name' => 'Pallet', 'unit_type' => 'packaging', 'is_base' => false, 'is_purchase' => true, 'is_sales' => false, 'desc' => 'Pallet distribution'],
        ];

        $unitsMap = [];
        foreach ($unitsConfig as $u) {
            $unitsMap[$u['code']] = Unit::query()->updateOrCreate(
                ['organization_id' => $org->id, 'code' => $u['code']],
                [
                    'name' => $u['name'],
                    'unit_type' => $u['unit_type'],
                    'description' => $u['desc'],
                    'is_base' => $u['is_base'],
                    'is_purchase_allowed' => $u['is_purchase'],
                    'is_sales_allowed' => $u['is_sales'],
                    'is_transfer_allowed' => true,
                    'is_active' => true,
                ],
            );
        }

        // 2. Ensure 11 Alcoholic Beverage Categories exist
        $categoriesConfig = [
            ['code' => 'CAT-WKY', 'name' => 'Whisky', 'slug' => 'whisky', 'color' => '#b45309'],
            ['code' => 'CAT-VDK', 'name' => 'Vodka', 'slug' => 'vodka', 'color' => '#0284c7'],
            ['code' => 'CAT-GIN', 'name' => 'Gin', 'slug' => 'gin', 'color' => '#0d9488'],
            ['code' => 'CAT-TEQ', 'name' => 'Tequila', 'slug' => 'tequila', 'color' => '#ea580c'],
            ['code' => 'CAT-RUM', 'name' => 'Rum', 'slug' => 'rum', 'color' => '#c2410c'],
            ['code' => 'CAT-BRN', 'name' => 'Brandy', 'slug' => 'brandy', 'color' => '#78350f'],
            ['code' => 'CAT-WIN', 'name' => 'Wine', 'slug' => 'wine', 'color' => '#991b1b'],
            ['code' => 'CAT-BER', 'name' => 'Beer', 'slug' => 'beer', 'color' => '#ca8a04'],
            ['code' => 'CAT-SOJ', 'name' => 'Soju', 'slug' => 'soju', 'color' => '#16a34a'],
            ['code' => 'CAT-LIQ', 'name' => 'Liqueur', 'slug' => 'liqueur', 'color' => '#9333ea'],
            ['code' => 'CAT-RTD', 'name' => 'Ready-to-Drink (RTD)', 'slug' => 'rtd', 'color' => '#db2777'],
        ];

        $categoriesMap = [];
        foreach ($categoriesConfig as $idx => $c) {
            $categoriesMap[$c['slug']] = Category::query()->withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'slug' => $c['slug']],
                [
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'color' => $c['color'],
                    'sort_order' => $idx + 10,
                    'is_active' => true,
                ],
            );
        }

        // 3. Ensure Principals & Brands
        $principals = [
            'PRIN-DAG' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-DAG'], ['name' => 'Diageo Global Spirits', 'is_active' => true]),
            'PRIN-PRD' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-PRD'], ['name' => 'Pernod Ricard Premium Brands', 'is_active' => true]),
            'PRIN-BAC' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-BAC'], ['name' => 'Bacardi-Martini Group', 'is_active' => true]),
            'PRIN-MBI' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-MBI'], ['name' => 'PT Multi Bintang Indonesia Tbk', 'is_active' => true]),
            'PRIN-HJR' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-HJR'], ['name' => 'HiteJinro International', 'is_active' => true]),
            'PRIN-JAG' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-JAG'], ['name' => 'Mast-Jägermeister SE', 'is_active' => true]),
            'PRIN-MHT' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-MHT'], ['name' => 'Moët Hennessy Global', 'is_active' => true]),
            'PRIN-TWE' => Principal::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'PRIN-TWE'], ['name' => 'Treasury Wine Estates', 'is_active' => true]),
        ];

        $brands = [
            'BRD-JWK' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-JWK'], ['name' => 'Johnnie Walker', 'principal_id' => $principals['PRIN-DAG']->id, 'is_active' => true]),
            'BRD-JMS' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-JMS'], ['name' => 'Jameson', 'principal_id' => $principals['PRIN-PRD']->id, 'is_active' => true]),
            'BRD-ABS' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-ABS'], ['name' => 'Absolut', 'principal_id' => $principals['PRIN-PRD']->id, 'is_active' => true]),
            'BRD-SMN' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-SMN'], ['name' => 'Smirnoff', 'principal_id' => $principals['PRIN-DAG']->id, 'is_active' => true]),
            'BRD-BMB' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-BMB'], ['name' => 'Bombay Sapphire', 'principal_id' => $principals['PRIN-BAC']->id, 'is_active' => true]),
            'BRD-JCR' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-JCR'], ['name' => 'Jose Cuervo', 'principal_id' => $principals['PRIN-PRD']->id, 'is_active' => true]),
            'BRD-BAC' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-BAC'], ['name' => 'Bacardi', 'principal_id' => $principals['PRIN-BAC']->id, 'is_active' => true]),
            'BRD-BIN' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-BIN'], ['name' => 'Bir Bintang', 'principal_id' => $principals['PRIN-MBI']->id, 'is_active' => true]),
            'BRD-HNK' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-HNK'], ['name' => 'Heineken', 'principal_id' => $principals['PRIN-MBI']->id, 'is_active' => true]),
            'BRD-JNR' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-JNR'], ['name' => 'Jinro Soju', 'principal_id' => $principals['PRIN-HJR']->id, 'is_active' => true]),
            'BRD-JAG' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-JAG'], ['name' => 'Jägermeister', 'principal_id' => $principals['PRIN-JAG']->id, 'is_active' => true]),
            'BRD-BAY' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-BAY'], ['name' => 'Baileys', 'principal_id' => $principals['PRIN-DAG']->id, 'is_active' => true]),
            'BRD-HNS' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-HNS'], ['name' => 'Hennessy Cognac', 'principal_id' => $principals['PRIN-MHT']->id, 'is_active' => true]),
            'BRD-PFD' => Brand::query()->updateOrCreate(['organization_id' => $org->id, 'code' => 'BRD-PFD'], ['name' => 'Penfolds', 'principal_id' => $principals['PRIN-TWE']->id, 'is_active' => true]),
        ];

        // 4. Retrieve existing Tiers
        $tiers = Tier::query()->where('organization_id', $org->id)->get()->keyBy('code');

        // 5. Retrieve Warehouses & Outlets
        $warehouses = Warehouse::query()->withoutGlobalScopes()->where('organization_id', $org->id)->get();
        $outlets = Outlet::query()->withoutGlobalScopes()->where('organization_id', $org->id)->get();

        // 6. Alcoholic Beverages Catalog Definitions
        $beveragesBlueprint = [
            // WHISKY
            [
                'cat' => 'whisky',
                'brand' => 'BRD-JWK',
                'prin' => 'PRIN-DAG',
                'sku' => 'WKY-JW-BLK700',
                'name' => 'Johnnie Walker Black Label 12YO',
                'net_content' => '700.00',
                'net_unit' => 'ml',
                'abv' => '40.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Scotland',
                'base_unit' => 'BTL',
                'barcode' => '5000267014005',
                'desc' => 'Iconic blended Scotch whisky matured for at least 12 years with deep smoky and fruit layers.',
                'cost' => '450000.00',
                't1' => '650000.00', // Retail
                't2' => '620000.00', // Member
                't3' => '590000.00', // Reseller
                't4' => '560000.00', // Wholesale
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '5000267014005-DS12',
                        'cost' => '5200000.00',
                        't1' => '7500000.00',
                        't2' => '7200000.00',
                        't3' => '6850000.00',
                        't4' => '6500000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],
            [
                'cat' => 'whisky',
                'brand' => 'BRD-JMS',
                'prin' => 'PRIN-PRD',
                'sku' => 'WKY-JMS-700',
                'name' => 'Jameson Irish Whiskey Triple Distilled',
                'net_content' => '700.00',
                'net_unit' => 'ml',
                'abv' => '40.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Ireland',
                'base_unit' => 'BTL',
                'barcode' => '5011007003003',
                'desc' => 'Smooth triple distilled Irish whiskey aged in oak casks for floral, spicy, and vanilla notes.',
                'cost' => '360000.00',
                't1' => '520000.00',
                't2' => '495000.00',
                't3' => '470000.00',
                't4' => '445000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '5011007003003-DS12',
                        'cost' => '4150000.00',
                        't1' => '6000000.00',
                        't2' => '5700000.00',
                        't3' => '5400000.00',
                        't4' => '5100000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // VODKA
            [
                'cat' => 'vodka',
                'brand' => 'BRD-ABS',
                'prin' => 'PRIN-PRD',
                'sku' => 'VDK-ABS-750',
                'name' => 'Absolut Vodka Original Swedish',
                'net_content' => '750.00',
                'net_unit' => 'ml',
                'abv' => '40.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Sweden',
                'base_unit' => 'BTL',
                'barcode' => '7312040017034',
                'desc' => 'Classic Swedish winter wheat continuous distillation vodka with clean and rich character.',
                'cost' => '310000.00',
                't1' => '450000.00',
                't2' => '430000.00',
                't3' => '410000.00',
                't4' => '390000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '7312040017034-DS12',
                        'cost' => '3550000.00',
                        't1' => '5200000.00',
                        't2' => '4950000.00',
                        't3' => '4700000.00',
                        't4' => '4450000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],
            [
                'cat' => 'vodka',
                'brand' => 'BRD-SMN',
                'prin' => 'PRIN-DAG',
                'sku' => 'VDK-SMN-750',
                'name' => 'Smirnoff No. 21 Red Label Vodka',
                'net_content' => '750.00',
                'net_unit' => 'ml',
                'abv' => '40.00',
                'packaging' => 'Glass Bottle',
                'country' => 'United Kingdom',
                'base_unit' => 'BTL',
                'barcode' => '5410316960010',
                'desc' => 'Triple distilled and 10 times charcoal-filtered pure grain vodka.',
                'cost' => '220000.00',
                't1' => '320000.00',
                't2' => '305000.00',
                't3' => '290000.00',
                't4' => '275000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '5410316960010-DS12',
                        'cost' => '2500000.00',
                        't1' => '3700000.00',
                        't2' => '3500000.00',
                        't3' => '3300000.00',
                        't4' => '3100000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // GIN
            [
                'cat' => 'gin',
                'brand' => 'BRD-BMB',
                'prin' => 'PRIN-BAC',
                'sku' => 'GIN-BMB-750',
                'name' => 'Bombay Sapphire London Dry Gin',
                'net_content' => '750.00',
                'net_unit' => 'ml',
                'abv' => '47.00',
                'packaging' => 'Glass Bottle',
                'country' => 'United Kingdom',
                'base_unit' => 'BTL',
                'barcode' => '5010677714005',
                'desc' => 'Vapour-infused with 10 hand-selected exotic botanicals for an exceptionally bright finish.',
                'cost' => '400000.00',
                't1' => '580000.00',
                't2' => '550000.00',
                't3' => '520000.00',
                't4' => '495000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '5010677714005-DS12',
                        'cost' => '4600000.00',
                        't1' => '6700000.00',
                        't2' => '6350000.00',
                        't3' => '6000000.00',
                        't4' => '5700000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // TEQUILA
            [
                'cat' => 'tequila',
                'brand' => 'BRD-JCR',
                'prin' => 'PRIN-PRD',
                'sku' => 'TEQ-JCR-750',
                'name' => 'Jose Cuervo Especial Reposado Tequila',
                'net_content' => '750.00',
                'net_unit' => 'ml',
                'abv' => '38.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Mexico',
                'base_unit' => 'BTL',
                'barcode' => '7501035010108',
                'desc' => 'Golden reposado tequila rested in oak barrels for rich notes of cooked agave and oak.',
                'cost' => '330000.00',
                't1' => '480000.00',
                't2' => '455000.00',
                't3' => '430000.00',
                't4' => '410000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '7501035010108-DS12',
                        'cost' => '3800000.00',
                        't1' => '5500000.00',
                        't2' => '5200000.00',
                        't3' => '4950000.00',
                        't4' => '4700000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // RUM
            [
                'cat' => 'rum',
                'brand' => 'BRD-BAC',
                'prin' => 'PRIN-BAC',
                'sku' => 'RUM-BAC-750',
                'name' => 'Bacardi Carta Blanca Superior White Rum',
                'net_content' => '750.00',
                'net_unit' => 'ml',
                'abv' => '40.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Puerto Rico',
                'base_unit' => 'BTL',
                'barcode' => '080480015302',
                'desc' => 'Aged in white oak barrels and filtered through charcoal for subtle floral and citrus notes.',
                'cost' => '260000.00',
                't1' => '390000.00',
                't2' => '370000.00',
                't3' => '350000.00',
                't4' => '330000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '080480015302-DS12',
                        'cost' => '3000000.00',
                        't1' => '4500000.00',
                        't2' => '4250000.00',
                        't3' => '4050000.00',
                        't4' => '3850000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // BEER (Both Bottle & Can)
            [
                'cat' => 'beer',
                'brand' => 'BRD-BIN',
                'prin' => 'PRIN-MBI',
                'sku' => 'BER-BIN-620',
                'name' => 'Bir Bintang Pilsener Botol Besar',
                'net_content' => '620.00',
                'net_unit' => 'ml',
                'abv' => '4.70',
                'packaging' => 'Glass Bottle',
                'country' => 'Indonesia',
                'base_unit' => 'BTL',
                'barcode' => '8992759111019',
                'desc' => 'Traditional Indonesian lager brewed with select hops and malted barley.',
                'cost' => '29000.00',
                't1' => '42000.00',
                't2' => '40000.00',
                't3' => '37000.00',
                't4' => '35000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '8992759111019-DS12',
                        'cost' => '340000.00',
                        't1' => '490000.00',
                        't2' => '465000.00',
                        't3' => '435000.00',
                        't4' => '410000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                    [
                        'code' => 'KRT',
                        'name' => 'Krat Distribusi (Isi 16 Botol)',
                        'factor' => '16.000',
                        'barcode' => '8992759111019-KR16',
                        'cost' => '450000.00',
                        't1' => '640000.00',
                        't2' => '610000.00',
                        't3' => '575000.00',
                        't4' => '545000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],
            [
                'cat' => 'beer',
                'brand' => 'BRD-HNK',
                'prin' => 'PRIN-MBI',
                'sku' => 'BER-HNK-330',
                'name' => 'Heineken Premium Lager Kaleng',
                'net_content' => '330.00',
                'net_unit' => 'ml',
                'abv' => '5.00',
                'packaging' => 'Can',
                'country' => 'Netherlands',
                'base_unit' => 'KLG',
                'barcode' => '8712000024018',
                'desc' => 'World-famous 100% barley malt and signature A-yeast Dutch pilsener.',
                'cost' => '20000.00',
                't1' => '30000.00',
                't2' => '28500.00',
                't3' => '26500.00',
                't4' => '25000.00',
                'alt_units' => [
                    [
                        'code' => 'PACK',
                        'name' => 'Pack (Isi 6 Kaleng)',
                        'factor' => '6.000',
                        'barcode' => '8712000024018-PK6',
                        'cost' => '118000.00',
                        't1' => '175000.00',
                        't2' => '165000.00',
                        't3' => '155000.00',
                        't4' => '145000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                    [
                        'code' => 'DUS',
                        'name' => 'Dus Karton (Isi 24 Kaleng)',
                        'factor' => '24.000',
                        'barcode' => '8712000024018-DS24',
                        'cost' => '460000.00',
                        't1' => '680000.00',
                        't2' => '640000.00',
                        't3' => '600000.00',
                        't4' => '570000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // SOJU
            [
                'cat' => 'soju',
                'brand' => 'BRD-JNR',
                'prin' => 'PRIN-HJR',
                'sku' => 'SOJ-JNR-360',
                'name' => 'Jinro Chamisul Fresh Soju',
                'net_content' => '360.00',
                'net_unit' => 'ml',
                'abv' => '16.90',
                'packaging' => 'Glass Bottle',
                'country' => 'South Korea',
                'base_unit' => 'BTL',
                'barcode' => '8801048101111',
                'desc' => 'Filtered through natural bamboo charcoal 4 times for pure and crisp soju taste.',
                'cost' => '62000.00',
                't1' => '95000.00',
                't2' => '90000.00',
                't3' => '82000.00',
                't4' => '78000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 20 Botol)',
                        'factor' => '20.000',
                        'barcode' => '8801048101111-DS20',
                        'cost' => '1200000.00',
                        't1' => '1800000.00',
                        't2' => '1700000.00',
                        't3' => '1580000.00',
                        't4' => '1490000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // LIQUEUR
            [
                'cat' => 'liqueur',
                'brand' => 'BRD-JAG',
                'prin' => 'PRIN-JAG',
                'sku' => 'LIQ-JAG-700',
                'name' => 'Jägermeister Herbal Liqueur 56 Botanicals',
                'net_content' => '700.00',
                'net_unit' => 'ml',
                'abv' => '35.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Germany',
                'base_unit' => 'BTL',
                'barcode' => '4067700014015',
                'desc' => 'Authentic German herbal digestif liqueur crafted with 56 natural herbs, blossoms, and roots.',
                'cost' => '370000.00',
                't1' => '540000.00',
                't2' => '515000.00',
                't3' => '490000.00',
                't4' => '465000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '4067700014015-DS12',
                        'cost' => '4250000.00',
                        't1' => '6250000.00',
                        't2' => '5950000.00',
                        't3' => '5650000.00',
                        't4' => '5350000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],
            [
                'cat' => 'liqueur',
                'brand' => 'BRD-BAY',
                'prin' => 'PRIN-DAG',
                'sku' => 'LIQ-BAY-700',
                'name' => 'Baileys The Original Irish Cream',
                'net_content' => '700.00',
                'net_unit' => 'ml',
                'abv' => '17.00',
                'packaging' => 'Glass Bottle',
                'country' => 'Ireland',
                'base_unit' => 'BTL',
                'barcode' => '5011013100156',
                'desc' => 'Rich blend of aged Irish whiskey with velvety fresh Irish dairy cream and cocoa.',
                'cost' => '340000.00',
                't1' => '490000.00',
                't2' => '465000.00',
                't3' => '440000.00',
                't4' => '415000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '5011013100156-DS12',
                        'cost' => '3900000.00',
                        't1' => '5650000.00',
                        't2' => '5350000.00',
                        't3' => '5100000.00',
                        't4' => '4800000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // WINE
            [
                'cat' => 'wine',
                'brand' => 'BRD-PFD',
                'prin' => 'PRIN-TWE',
                'sku' => 'WIN-PFD-750',
                'name' => 'Penfolds Koonunga Hill Shiraz Cabernet',
                'net_content' => '750.00',
                'net_unit' => 'ml',
                'abv' => '14.50',
                'packaging' => 'Glass Bottle',
                'country' => 'Australia',
                'base_unit' => 'BTL',
                'barcode' => '9311043003001',
                'desc' => 'South Australian premium red wine with plump dark fruit, spice, and supple tannins.',
                'cost' => '320000.00',
                't1' => '480000.00',
                't2' => '455000.00',
                't3' => '430000.00',
                't4' => '405000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 6 Botol)',
                        'factor' => '6.000',
                        'barcode' => '9311043003001-DS6',
                        'cost' => '1850000.00',
                        't1' => '2750000.00',
                        't2' => '2600000.00',
                        't3' => '2450000.00',
                        't4' => '2300000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // BRANDY
            [
                'cat' => 'brandy',
                'brand' => 'BRD-HNS',
                'prin' => 'PRIN-MHT',
                'sku' => 'BRN-HNS-700',
                'name' => 'Hennessy VSOP Privilege Cognac',
                'net_content' => '700.00',
                'net_unit' => 'ml',
                'abv' => '40.00',
                'packaging' => 'Glass Bottle',
                'country' => 'France',
                'base_unit' => 'BTL',
                'barcode' => '3245990250005',
                'desc' => 'Harmonious blend of 60 eaux-de-vie with aromas of fresh vanilla, cinnamon, and toasted oak.',
                'cost' => '1050000.00',
                't1' => '1450000.00',
                't2' => '1380000.00',
                't3' => '1300000.00',
                't4' => '1230000.00',
                'alt_units' => [
                    [
                        'code' => 'DUS',
                        'name' => 'Dus (Isi 12 Botol)',
                        'factor' => '12.000',
                        'barcode' => '3245990250005-DS12',
                        'cost' => '12100000.00',
                        't1' => '16800000.00',
                        't2' => '15950000.00',
                        't3' => '15100000.00',
                        't4' => '14300000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],

            // RTD (READY-TO-DRINK)
            [
                'cat' => 'rtd',
                'brand' => 'BRD-SMN',
                'prin' => 'PRIN-DAG',
                'sku' => 'RTD-SMN-275',
                'name' => 'Smirnoff Ice Original Lemon Botol',
                'net_content' => '275.00',
                'net_unit' => 'ml',
                'abv' => '4.50',
                'packaging' => 'Glass Bottle',
                'country' => 'Indonesia',
                'base_unit' => 'BTL',
                'barcode' => '8992759333015',
                'desc' => 'Crisp ready-to-drink beverage blending Smirnoff No. 21 vodka with natural zesty citrus.',
                'cost' => '18500.00',
                't1' => '28000.00',
                't2' => '26500.00',
                't3' => '24500.00',
                't4' => '23000.00',
                'alt_units' => [
                    [
                        'code' => 'PACK',
                        'name' => 'Pack (Isi 4 Botol)',
                        'factor' => '4.000',
                        'barcode' => '8992759333015-PK4',
                        'cost' => '72000.00',
                        't1' => '108000.00',
                        't2' => '102000.00',
                        't3' => '95000.00',
                        't4' => '89000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                    [
                        'code' => 'DUS',
                        'name' => 'Dus Karton (Isi 24 Botol)',
                        'factor' => '24.000',
                        'barcode' => '8992759333015-DS24',
                        'cost' => '425000.00',
                        't1' => '630000.00',
                        't2' => '595000.00',
                        't3' => '560000.00',
                        't4' => '525000.00',
                        'is_purchase' => true,
                        'is_sales' => true,
                    ],
                ],
            ],
        ];

        $today = date('Y-m-d');

        foreach ($beveragesBlueprint as $b) {
            $cat = $categoriesMap[$b['cat']] ?? null;
            $brand = $brands[$b['brand']] ?? null;
            $prin = $principals[$b['prin']] ?? null;
            $baseUnitModel = $unitsMap[$b['base_unit']] ?? $unitsMap['BTL'];

            $product = Product::query()->withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $b['sku']],
                [
                    'category_id' => $cat?->id,
                    'brand_id' => $brand?->id,
                    'principal_id' => $prin?->id,
                    'base_unit_id' => $baseUnitModel->id,
                    'name' => $b['name'],
                    'short_description' => Str::limit($b['desc'], 100),
                    'description' => $b['desc'],
                    'manufacturer' => $prin?->name,
                    'product_type' => 'regular',
                    'base_price' => $b['t1'],
                    'unit' => strtolower($b['base_unit']),
                    'net_content' => $b['net_content'],
                    'net_content_unit' => $b['net_unit'],
                    'abv' => $b['abv'],
                    'packaging_type' => $b['packaging'],
                    'country_of_origin' => $b['country'],
                    'is_returnable_packaging' => in_array($b['base_unit'], ['BTL', 'KLG'], true),
                    'default_barcode' => $b['barcode'],
                    'track_stock' => true,
                    'track_batch' => true,
                    'track_expiry' => true,
                    'shelf_life_days' => 730,
                    'is_active' => true,
                ],
            );

            $variant = ProductVariant::query()->withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $b['sku']],
                [
                    'product_id' => $product->id,
                    'name' => 'Standar (' . $b['net_content'] . ' ' . $b['net_unit'] . ')',
                    'barcode' => $b['barcode'],
                    'cost_price' => $b['cost'],
                    'average_cost' => $b['cost'],
                    'last_purchase_cost' => $b['cost'],
                    'price' => $b['t1'],
                    'member_price' => $b['t2'],
                    'reseller_price' => $b['t3'],
                    'wholesale_price' => $b['t4'],
                    'min_selling_price' => $b['cost'],
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );

            // Register Base Unit in product_units
            $puBase = ProductUnit::query()->updateOrCreate(
                ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'unit_code' => $b['base_unit']],
                [
                    'organization_id' => $org->id,
                    'unit_id' => $baseUnitModel->id,
                    'unit_name' => $baseUnitModel->name,
                    'conversion_factor' => '1.000',
                    'is_base' => true,
                    'is_purchase_unit' => empty($b['alt_units']),
                    'is_sales_unit' => true,
                    'barcode' => $b['barcode'],
                    'selling_price' => $b['t1'],
                    'member_price' => $b['t2'],
                    'reseller_price' => $b['t3'],
                    'wholesale_price' => $b['t4'],
                    'purchase_cost' => $b['cost'],
                    'min_purchase_qty' => '1.000',
                    'is_active' => true,
                ],
            );

            // Register Base Barcode
            ProductBarcode::query()->updateOrCreate(
                ['organization_id' => $org->id, 'barcode' => $b['barcode']],
                [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'product_unit_id' => $puBase->id,
                    'barcode_type' => 'EAN13',
                    'is_primary' => true,
                    'is_active' => true,
                    'label' => 'Barcode satuan dasar (' . $baseUnitModel->name . ')',
                ],
            );

            // Register Alternative Packaging Units (Dus, Pack, Krat)
            foreach ($b['alt_units'] as $alt) {
                $altUnitModel = $unitsMap[$alt['code']] ?? $unitsMap['DUS'];
                $puAlt = ProductUnit::query()->updateOrCreate(
                    ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'unit_code' => $alt['code']],
                    [
                        'organization_id' => $org->id,
                        'unit_id' => $altUnitModel->id,
                        'unit_name' => $alt['name'],
                        'conversion_factor' => $alt['factor'],
                        'is_base' => false,
                        'is_purchase_unit' => $alt['is_purchase'],
                        'is_sales_unit' => $alt['is_sales'],
                        'barcode' => $alt['barcode'],
                        'selling_price' => $alt['t1'],
                        'member_price' => $alt['t2'],
                        'reseller_price' => $alt['t3'],
                        'wholesale_price' => $alt['t4'],
                        'purchase_cost' => $alt['cost'],
                        'min_purchase_qty' => '1.000',
                        'is_active' => true,
                    ],
                );

                if (! empty($alt['barcode'])) {
                    ProductBarcode::query()->updateOrCreate(
                        ['organization_id' => $org->id, 'barcode' => $alt['barcode']],
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'product_unit_id' => $puAlt->id,
                            'barcode_type' => 'CODE128',
                            'is_primary' => false,
                            'is_active' => true,
                            'label' => 'Barcode kemasan ' . $alt['name'],
                        ],
                    );
                }
            }

            // Bind Tier Prices
            foreach ($tiers as $tierCode => $tierModel) {
                // Tier assignment
                ProductTierAssignment::query()->updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'tier_id' => $tierModel->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                    ],
                    ['status' => 'active'],
                );

                // Determine base unit tier price
                $basePriceVal = match ($tierCode) {
                    'TIER_2' => (float) $b['t2'],
                    'TIER_3' => (float) $b['t3'],
                    'TIER_4' => (float) $b['t4'],
                    default => (float) $b['t1'],
                };

                ProductTierPrice::query()->updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'tier_id' => $tierModel->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'product_unit_id' => $puBase->id,
                    ],
                    [
                        'price' => $basePriceVal,
                        'effective_from' => $today,
                        'status' => 'active',
                        'notes' => 'Harga resmi tier ' . $tierModel->name . ' satuan ' . $baseUnitModel->name,
                    ],
                );

                // Bind packaging units tier price
                foreach ($b['alt_units'] as $alt) {
                    $altPriceVal = match ($tierCode) {
                        'TIER_2' => (float) $alt['t2'],
                        'TIER_3' => (float) $alt['t3'],
                        'TIER_4' => (float) $alt['t4'],
                        default => (float) $alt['t1'],
                    };

                    $puRecord = ProductUnit::query()
                        ->where('product_id', $product->id)
                        ->where('unit_code', $alt['code'])
                        ->first();

                    if ($puRecord !== null) {
                        ProductTierPrice::query()->updateOrCreate(
                            [
                                'organization_id' => $org->id,
                                'tier_id' => $tierModel->id,
                                'product_id' => $product->id,
                                'product_variant_id' => $variant->id,
                                'product_unit_id' => $puRecord->id,
                            ],
                            [
                                'price' => $altPriceVal,
                                'effective_from' => $today,
                                'status' => 'active',
                                'notes' => 'Harga kemasan ' . $alt['name'] . ' untuk ' . $tierModel->name,
                            ],
                        );
                    }
                }
            }

            // Seed Stock into Central Warehouse (e.g. 240 units) and Outlets (e.g. 48 units)
            foreach ($warehouses as $wh) {
                $whQty = $wh->type === 'central' ? '240.000' : '48.000';
                WarehouseInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    [
                        'warehouse_id' => $wh->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'organization_id' => $org->id,
                        'outlet_id' => $wh->outlet_id,
                        'product_id' => $product->id,
                        'quantity' => $whQty,
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'damaged_quantity' => '0.000',
                        'quarantine_quantity' => '0.000',
                        'reorder_level' => '12.000',
                    ],
                );
            }

            foreach ($outlets as $outl) {
                ProductOutletSetting::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outl->id, 'product_variant_id' => $variant->id],
                    ['product_id' => $product->id, 'is_available' => true],
                );

                OutletInventory::query()->withoutGlobalScopes()->updateOrCreate(
                    ['outlet_id' => $outl->id, 'product_variant_id' => $variant->id],
                    [
                        'product_id' => $product->id,
                        'quantity' => '48.000',
                        'reorder_level' => '12.000',
                        'version' => 1,
                    ],
                );
            }
        }
    }
}

