export type PaymentMethodType =
  | 'cash'
  | 'qris'
  | 'debit_card'
  | 'credit_card'
  | 'e_wallet'
  | 'bank_transfer';

export interface TerminalInfo {
  id: number;
  code: string;
  name: string;
  device_identifier?: string | null;
}

export interface WarehouseInfo {
  id: number;
  code: string;
  name: string;
  type: 'central' | 'branch';
  city?: string | null;
  address?: string | null;
}

export interface OutletInfo {
  id: number;
  code: string;
  type?: 'head_office' | 'branch';
  name: string;
  city?: string | null;
  province?: string | null;
  region?: string | null;
  address?: string | null;
  phone?: string | null;
  is_active?: boolean;
  is_favorite?: boolean;
  is_default?: boolean;
  last_accessed_at?: string | null;
  tax_rate: string;
  service_charge_rate: string;
  max_cashier_discount_percent: string;
  allow_negative_stock: boolean;
  warehouse?: WarehouseInfo | null;
  terminals?: TerminalInfo[];
}

export interface ShiftLiveSummary {
  shift_id: number;
  shift_number: string;
  status: 'open' | 'closed';
  opening_cash: string;
  total_sales: string;
  total_cash_sales: string;
  total_non_cash_sales: string;
  total_paid_in: string;
  total_paid_out: string;
  total_cash_refunds: string;
  expected_cash: string;
  actual_cash: string | null;
  cash_difference: string | null;
  transaction_count: number;
  breakdown_by_method: Record<PaymentMethodType, string>;
}

export interface CashMovementItem {
  id: number;
  type: 'paid_in' | 'paid_out';
  amount: string;
  reason: string;
  notes?: string | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
  };
}

export interface CashierShift {
  id: number;
  uuid: string;
  outlet_id: number;
  pos_terminal_id: number;
  user_id: number;
  shift_number: string;
  status: 'open' | 'closed';
  opening_cash: string;
  opening_notes?: string | null;
  opened_at: string;
  expected_cash?: string | null;
  actual_cash?: string | null;
  cash_difference?: string | null;
  total_sales?: string | null;
  total_cash_sales?: string | null;
  total_non_cash_sales?: string | null;
  total_paid_in?: string | null;
  total_paid_out?: string | null;
  total_cash_refunds?: string | null;
  transaction_count?: number | null;
  closing_notes?: string | null;
  closed_at?: string | null;
  closing_summary?: Record<string, unknown> | null;
  live_summary?: ShiftLiveSummary | null;
  cash_movements?: CashMovementItem[];
  cashier?: {
    id: number;
    name: string;
    username: string;
  } | null;
  terminal?: {
    id: number;
    code: string;
    name: string;
  } | null;
}

export interface UserSession {
  user: {
    id: number;
    name: string;
    username: string;
    email: string;
    role: string | null;
    role_display: string;
    permissions: string[];
    is_super_admin: boolean;
    can_switch_outlet?: boolean;
    can_view_company_dashboard?: boolean;
    has_pin: boolean;
  };
  organization: {
    id: number;
    code: string;
    name: string;
    legal_name?: string | null;
    currency: string;
  };
  central_warehouses?: WarehouseInfo[];
  available_outlets: OutletInfo[];
  active_outlet: OutletInfo | null;
  active_warehouse?: WarehouseInfo | null;
  active_terminal: TerminalInfo | null;
  active_shift: CashierShift | null;
  active_employee_shift?: ActiveEmployeeShift | null;
  operational_shifts?: OperationalShift[];
  requires_terminal_selection: boolean;
  requires_open_shift: boolean;
  requires_employee_shift_checkin?: boolean;
}

export interface OperationalShift {
  id: number;
  name: string;
  code: string;
  start_time: string;
  end_time: string;
  is_overnight: boolean;
  display_order: number;
}

export interface ActiveEmployeeShift {
  id: number;
  uuid: string;
  user_id: number;
  outlet_id: number;
  shift_id: number;
  shift_name?: string;
  shift_code?: string;
  start_time?: string;
  end_time?: string;
  work_date: string;
  checked_in_at: string;
  is_backdated: boolean;
  backdate_reason?: string | null;
  approval_status: string;
  status: string;
}

export interface GlobalActivityLog {
  id: number;
  organization_id: number;
  outlet_id: number;
  user_id: number;
  username_snapshot: string;
  employee_name_snapshot: string;
  shift_checkin_id?: number | null;
  shift_id?: number | null;
  shift_name_snapshot?: string | null;
  work_date?: string | null;
  module_name: string;
  action_type: string;
  entity_type: string;
  entity_id: string;
  description: string;
  old_values?: Record<string, unknown> | null;
  new_values?: Record<string, unknown> | null;
  ip_address?: string | null;
  created_at: string;
  outlet?: { id: number; code: string; name: string };
  user?: { id: number; name: string; username: string };
  shift?: { id: number; name: string; code: string };
}

export interface Category {
  id: number;
  parent_id: number | null;
  code?: string;
  name: string;
  sku?: string;
  base_price?: string | number;
  slug: string;
  description?: string | null;
  color: string | null;
  is_active?: boolean;
  products_count?: number;
  children?: Category[];
}

export interface ProductModifier {
  id: number;
  product_id: number;
  group_name: string;
  name: string;
  price: string;
  is_required: boolean;
  is_multiple: boolean;
}

export interface ProductVariantCatalog {
  id: number;
  product_id: number;
  name: string;
  sku: string;
  barcode: string | null;
  cost_price?: string;
  base_price: string;
  price_override: string | null;
  effective_price: string;
  tier_prices?: Record<string, string>;
  selected_tier?: string;
  wholesale_price?: string | null;
  reseller_price?: string | null;
  is_available: boolean;
  is_default: boolean;
  stock_quantity: string;
  reorder_level: string;
  units?: ProductUnit[];
}

export interface ProductCatalog {
  id: number;
  category_id: number | null;
  category?: {
    id: number;
    name: string;
    slug: string;
    color: string | null;
  } | null;
  brand_id?: number | null;
  brand?: {
    id: number;
    code: string;
    name: string;
  } | null;
  name: string;
  sku: string;
  description: string | null;
  base_price: string;
  unit: string;
  net_content?: string | number | null;
  net_content_unit?: string | null;
  abv?: string | number | null;
  packaging_type?: string | null;
  country_of_origin?: string | null;
  is_returnable_packaging?: boolean;
  track_stock: boolean;
  image_url: string | null;
  variants: ProductVariantCatalog[];
  product_units?: ProductUnit[];
  modifiers: ProductModifier[];
}

export interface CartItem {
  key: string;
  product_id: number;
  product_variant_id: number;
  product_name: string;
  variant_name: string;
  sku: string;
  quantity: number;
  original_unit_price: number;
  unit_price_override?: number | null;
  price_override_reason?: string;
  modifiers: ProductModifier[];
  notes?: string;
  uom_code?: string;
  unit_name?: string;
  conversion_factor?: string;
  customer_tier?: string | null;
  product_unit_id?: number | null;
}

export interface PaymentLegInput {
  id: string;
  method: PaymentMethodType;
  amount: number;
  tendered_amount: number;
  provider: string;
  reference_number: string;
  card_last4: string;
  qris_payload: string;
}

export interface OrderItemRecord {
  id: number;
  product_id: number;
  product_variant_id: number;
  product_name: string;
  variant_name: string;
  sku: string;
  quantity: string;
  original_unit_price: string;
  unit_price: string;
  price_override_reason?: string | null;
  modifiers: Array<{
    id: number;
    group_name: string;
    name: string;
    price: string;
  }> | null;
  modifiers_total: string;
  line_total: string;
  refunded_quantity: string;
  notes?: string | null;
}

export interface PaymentRecord {
  id: number;
  uuid: string;
  method: PaymentMethodType;
  status: string;
  amount: string;
  tendered_amount: string | null;
  change_amount: string;
  provider?: string | null;
  reference_number?: string | null;
  card_last4?: string | null;
  qris_payload?: string | null;
  paid_at?: string | null;
}

export type PaymentSchemeType = 'full' | 'partial' | 'tempo';

export interface OrderRecord {
  id: number;
  uuid: string;
  outlet_id: number;
  pos_terminal_id: number;
  customer_id?: number | null;
  order_number: string;
  status: 'held' | 'completed' | 'cancelled' | 'voided' | 'partially_refunded' | 'refunded';
  payment_term?: 'cash' | 'tempo' | string | null;
  due_date?: string | null;
  customer_name?: string | null;
  customer_phone?: string | null;
  crm_customer_ref?: string | null;
  table_label?: string | null;
  subtotal: string;
  discount_type?: 'percent' | 'fixed' | null;
  discount_value: string;
  discount_total: string;
  discount_reason?: string | null;
  service_charge_rate: string;
  service_charge_total: string;
  tax_rate: string;
  tax_total: string;
  grand_total: string;
  paid_total: string;
  change_total: string;
  refunded_total: string;
  notes?: string | null;
  held_at?: string | null;
  completed_at?: string | null;
  created_at: string;
  items: OrderItemRecord[];
  payments?: PaymentRecord[];
  customer?: {
    id: number;
    code: string;
    name: string;
    phone?: string | null;
    company_name?: string | null;
    address?: string | null;
    masked_nik?: string | null;
  } | null;
  invoice?: {
    id: number;
    invoice_number: string;
    status: 'unpaid' | 'partial' | 'paid' | 'overdue' | 'void';
    issue_date: string;
    due_date: string;
    grand_total: string;
    paid_total: string;
    balance_due: string;
    allocations?: Array<{
      id: number;
      amount: string;
      customer_payment?: {
        id: number;
        payment_number: string;
        method: string;
        amount: string;
        payment_date: string;
        reference_number?: string | null;
      } | null;
    }>;
  } | null;
  cashier?: {
    id: number;
    name: string;
    username: string;
  };
  terminal?: {
    id: number;
    code: string;
    name: string;
  };
}

export interface InventoryRecord {
  id: number;
  outlet_id: number;
  product_id: number;
  product_variant_id: number;
  quantity: string;
  reorder_level: string;
  last_counted_at: string | null;
  product: {
    id: number;
    name: string;
    sku: string;
    unit: string;
    category?: {
      id: number;
      name: string;
    } | null;
  };
  variant: {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    price: string;
  };
}

export interface StockMovementRecord {
  id: number;
  product_variant_id?: number;
  type: 'sale' | 'void' | 'refund' | 'adjustment' | 'opname' | 'receive' | 'transfer_out' | 'transfer_in' | 'sales_order';
  movement_type?: string;
  quantity_change: string;
  quantity_before: string;
  quantity_after: string;
  reference_type: string | null;
  reference_id: number | null;
  notes: string | null;
  created_at: string;
  variant?: {
    id: number;
    name: string;
    sku: string;
    product?: {
      id: number;
      name: string;
      unit: string;
    };
  };
  user?: {
    id: number;
    name: string;
    username: string;
  } | null;
}

export interface AuditLogRecord {
  id: number;
  uuid: string;
  actor_id: number | null;
  actor_name_snapshot: string | null;
  actor_role_snapshot: string | null;
  outlet_id: number | null;
  pos_terminal_id: number | null;
  action_event: string;
  entity_type: string | null;
  entity_id: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  request_id: string | null;
  created_at: string;
  outlet?: {
    id: number;
    code: string;
    name: string;
  } | null;
  terminal?: {
    id: number;
    code: string;
    name: string;
  } | null;
}

export interface AuditFilterOptions {
  actions: Array<{
    value: string;
    label: string;
    category: string;
    severity: 'low' | 'medium' | 'high';
  }>;
  actors: Array<{
    id: number;
    name: string;
    username: string;
  }>;
  outlets: Array<{
    id: number;
    code: string;
    name: string;
  }>;
  terminals: Array<{
    id: number;
    outlet_id: number;
    code: string;
    name: string;
  }>;
}

export interface DashboardSummaryResponse {
  period: 'today' | '7d' | '30d' | 'all';
  outlet?: {
    id: number;
    code: string;
    name: string;
    city?: string | null;
  };
  active_warehouse?: WarehouseInfo | null;
  kpis: {
    gross_sales: string;
    discount_total: string;
    net_sales: string;
    tax_and_service_total: string;
    grand_total_revenue: string;
    refunded_total: string;
    completed_orders_count: number;
    average_order_value: string;
    held_orders_count: number;
    voided_and_refunded_count: number;
    low_stock_count: number;
    b2b_sales_total?: string;
    b2b_orders_count?: number;
    combined_outlet_revenue?: string;
    outlet_receivables_balance?: string;
    outlet_open_invoices_count?: number;
    total_stock_units?: string;
    active_deliveries_count?: number;
  };
  active_shift_summary: ShiftLiveSummary | null;
  payment_breakdown: Array<{
    method: PaymentMethodType;
    label: string;
    total_amount: string;
    transaction_count: number;
    percentage: number;
  }>;
  top_products: Array<{
    product_variant_id: number;
    product_name: string;
    variant_name: string;
    sku: string;
    total_qty: string;
    total_revenue: string;
  }>;
  low_stock_items: Array<{
    id: number;
    product_variant_id: number;
    product_name: string;
    variant_name: string;
    sku: string;
    quantity: string;
    reorder_level: string;
    unit: string;
  }>;
  recent_orders: OrderRecord[];
  recent_sales_orders?: Array<{
    id: number;
    so_number: string;
    status: string;
    payment_type: string;
    grand_total: string;
    created_at: string;
    customer?: {
      id: number;
      code: string;
      name: string;
      customer_group: string;
    };
  }>;
  recent_activities: Array<{
    id: number;
    uuid: string;
    actor_name_snapshot: string | null;
    actor_role_snapshot: string | null;
    action_event: string;
    entity_type: string | null;
    entity_id: string | null;
    created_at: string;
  }>;
}

export interface CompanyDashboardResponse {
  kpis: {
    total_outlets: number;
    total_central_warehouses: number;
    total_counter_revenue: string;
    total_counter_orders: number;
    total_b2b_revenue: string;
    total_b2b_orders: number;
    total_company_revenue: string;
    central_warehouse_stock_qty: string;
    branch_warehouses_stock_qty: string;
    total_inventory_qty: string;
    total_receivables: string;
    open_invoices_count: number;
    overdue_invoices_count: number;
    overdue_receivables_amount: string;
  };
  central_warehouses: WarehouseInfo[];
  outlet_performance: Array<{
    outlet_id: number;
    code: string;
    type: string;
    name: string;
    city: string;
    warehouse_code: string;
    warehouse_name: string;
    counter_sales_revenue: string;
    counter_orders_count: number;
    b2b_sales_revenue: string;
    b2b_orders_count: number;
    total_revenue: string;
    stock_units: string;
    low_stock_count: number;
    receivables_balance: string;
  }>;
  pending_transfers: Array<{
    id: number;
    transfer_number: string;
    status: string;
    requested_at: string;
    source_warehouse?: { code: string; name: string };
    destination_warehouse?: { code: string; name: string };
    destination_outlet?: { code: string; name: string };
  }>;
  recent_invoices: Array<{
    id: number;
    invoice_number: string;
    status: string;
    due_date: string;
    grand_total: string;
    balance_due: string;
    customer?: { code: string; name: string; customer_group: string };
    outlet?: { code: string; name: string };
  }>;
}

export interface OutletTaxConfig {
  id: number;
  code: string;
  name: string;
  city?: string | null;
  tax_rate: string;
  service_charge_rate: string;
  max_cashier_discount_percent: string;
}

export interface MasterDiscountRecord {
  id: number;
  organization_id: number;
  outlet_id: number | null;
  code: string;
  name: string;
  description: string | null;
  type: 'percent' | 'fixed';
  value: string;
  min_purchase: string;
  max_discount: string | null;
  applies_to: 'both' | 'pos' | 'b2b';
  is_active: boolean;
  valid_from: string | null;
  valid_until: string | null;
  outlet?: {
    id: number;
    code: string;
    name: string;
  } | null;
}

export interface PricingRulesResponse {
  active_outlet_config: OutletTaxConfig | null;
  outlets_config: OutletTaxConfig[];
  discounts: MasterDiscountRecord[];
}

// ==========================================
// MASTER DATA & INVENTORY ENGINE TYPES
// ==========================================

export interface MasterUnit {
  id: number;
  organization_id: number;
  code: string;
  name: string;
  unit_type: 'quantity' | 'weight' | 'volume' | 'packaging';
  description?: string | null;
  is_base: boolean;
  is_active: boolean;
}

export interface Principal {
  id: number;
  organization_id: number;
  code: string;
  name: string;
  contact_person?: string | null;
  phone?: string | null;
  email?: string | null;
  city?: string | null;
  address?: string | null;
  is_active: boolean;
}

export interface Brand {
  id: number;
  organization_id: number;
  principal_id?: number | null;
  code: string;
  name: string;
  description?: string | null;
  image_url?: string | null;
  is_active: boolean;
  principal?: Principal | null;
}

export interface ProductUnit {
  id: number;
  organization_id: number;
  product_id: number;
  product_variant_id?: number | null;
  unit_id?: number | null;
  unit_code: string;
  unit_name: string;
  conversion_factor: string;
  is_base: boolean;
  is_purchase_unit: boolean;
  is_sales_unit: boolean;
  barcode?: string | null;
  selling_price: string;
  member_price?: string | null;
  reseller_price?: string | null;
  wholesale_price?: string | null;
  tier_a_price?: string | number | null;
  tier_b_price?: string | number | null;
  tier_c_price?: string | number | null;
  tier_d_price?: string | number | null;
  purchase_cost: string;
  min_purchase_qty: string;
  is_locked: boolean;
  is_active: boolean;
}

export interface ProductBarcode {
  id: number;
  organization_id: number;
  product_id: number;
  product_variant_id: number;
  product_unit_id?: number | null;
  barcode: string;
  barcode_type: 'EAN13' | 'CODE128' | 'QR' | 'INTERNAL';
  label?: string | null;
  is_primary: boolean;
  is_active: boolean;
  product_unit?: ProductUnit | null;
}

export interface PriceList {
  id: number;
  organization_id: number;
  outlet_id?: number | null;
  customer_id?: number | null;
  code: string;
  name: string;
  type: 'retail' | 'wholesale' | 'reseller' | 'customer' | 'outlet';
  customer_tier?: string | null;
  priority: number;
  effective_from?: string | null;
  effective_until?: string | null;
  is_active: boolean;
  items_count?: number;
  outlet?: { id: number; code: string; name: string } | null;
  customer?: { id: number; code: string; name: string } | null;
}

export interface PriceListItem {
  id: number;
  price_list_id: number;
  product_id: number;
  product_variant_id: number;
  product_unit_id?: number | null;
  min_qty: string;
  price: string;
  discount_percent: string;
  discount_amount: string;
  variant?: { id: number; name: string; sku: string } | null;
  product_unit?: ProductUnit | null;
}

export interface InventoryBatchItem {
  id: number;
  organization_id: number;
  warehouse_id: number;
  outlet_id?: number | null;
  product_id: number;
  product_variant_id: number;
  batch_number: string;
  lot_number?: string | null;
  manufacturing_date?: string | null;
  expiry_date?: string | null;
  initial_quantity: string;
  on_hand_quantity: string;
  reserved_quantity: string;
  damaged_quantity: string;
  quarantine_quantity: string;
  unit_cost: string;
  status: 'available' | 'quarantine' | 'damaged' | 'expired' | 'depleted';
  notes?: string | null;
  warehouse?: { id: number; code: string; name: string } | null;
  outlet?: { id: number; code: string; name: string } | null;
  variant?: { id: number; name: string; sku: string; product?: { id: number; name: string } } | null;
}

export interface StockLedgerItem {
  id: number;
  organization_id: number;
  outlet_id?: number | null;
  warehouse_id?: number | null;
  product_id: number;
  product_variant_id: number;
  batch_id?: number | null;
  type: string;
  uom_code: string;
  uom_quantity?: string | null;
  conversion_factor: string;
  quantity_change: string;
  quantity_before: string;
  quantity_after: string;
  unit_cost: string;
  reference_type: string;
  reference_id: number;
  user_id?: number | null;
  notes?: string | null;
  created_at: string;
  product?: { id: number; name: string; unit?: string } | null;
  variant?: { id: number; name: string; sku: string } | null;
  warehouse?: { id: number; code: string; name: string } | null;
  outlet?: { id: number; code: string; name: string } | null;
  batch?: { id: number; batch_number: string; expiry_date?: string } | null;
  user?: { id: number; name: string } | null;
}

export interface ProductInventorySetting {
  id: number;
  organization_id: number;
  warehouse_id?: number | null;
  outlet_id?: number | null;
  product_id: number;
  product_variant_id: number;
  min_stock: string;
  max_stock: string;
  reorder_point: string;
  safety_stock: string;
  default_warehouse_id?: number | null;
  allow_negative_stock: boolean;
  warehouse?: { id: number; code: string; name: string } | null;
}

export interface SupplierProduct {
  id: number;
  supplier_id: number;
  product_id: number;
  product_variant_id: number;
  supplier_sku?: string | null;
  purchase_price: string;
  last_purchase_price: string;
  lead_time_days: number;
  min_order_qty: string;
  is_default_supplier: boolean;
  supplier?: { id: number; code: string; name: string } | null;
}

export interface ProductPriceHistory {
  id: number;
  price_type: string;
  old_price?: string | null;
  new_price: string;
  old_cost?: string | null;
  new_cost?: string | null;
  reason?: string | null;
  created_at: string;
  changed_by?: { id: number; name: string } | null;
}

export interface DynamicTier {
  id: number;
  organization_id: number;
  name: string;
  code: string;
  description?: string | null;
  display_order: number;
  color: string;
  status: 'active' | 'inactive';
  total_products_count?: number;
  active_pricing_count?: number;
  customers_count?: number;
  created_at: string;
  updated_at: string;
}

export interface ProductTierItem {
  id: number;
  tier_id: number;
  product_id: number;
  product_variant_id?: number | null;
  status: 'active' | 'inactive';
  product: {
    id: number;
    name: string;
    sku: string;
    unit: string;
    category?: { id: number; name: string } | null;
    brand?: { id: number; name: string } | null;
  };
  variant?: {
    id: number;
    sku: string;
    cost_price: string;
    price: string;
  } | null;
  current_price?: string | null;
  effective_from?: string | null;
  effective_until?: string | null;
  price_status?: string | null;
}

export interface TierPriceHistoryItem {
  id: number;
  tier_id: number;
  product_id: number;
  price: string;
  effective_from: string;
  effective_until?: string | null;
  status: string;
  notes?: string | null;
  created_at: string;
  product?: { id: number; name: string; sku: string } | null;
  creator?: { id: number; name: string } | null;
}

export interface MasterProductItem {
  id: number;
  organization_id: number;
  category_id: number;
  brand_id?: number | null;
  principal_id?: number | null;
  base_unit_id?: number | null;
  name: string;
  sku?: string;
  base_price?: string | number;
  slug: string;
  short_description?: string | null;
  description?: string | null;
  image_url?: string | null;
  unit: string;
  net_content?: string | number | null;
  net_content_unit?: string | null;
  abv?: string | number | null;
  packaging_type?: string | null;
  country_of_origin?: string | null;
  is_returnable_packaging?: boolean;
  manufacturer?: string | null;
  product_type: 'regular' | 'bundle' | 'package' | 'non_inventory';
  default_barcode?: string | null;
  track_stock: boolean;
  track_batch: boolean;
  track_expiry: boolean;
  shelf_life_days?: number | null;
  is_active: boolean;
  stock_summary?: {
    on_hand: string;
    reserved: string;
    available: string;
    reorder_level?: string;
    display?: string;
    breakdown?: string | null;
  };
  category?: { id: number; name: string; slug: string; code?: string } | null;
  brand?: Brand | null;
  principal?: Principal | null;
  base_unit?: MasterUnit | null;
  variants: Array<{
    id: number;
    product_id: number;
    name: string;
    sku: string;
    barcode?: string | null;
    price: string;
    cost_price: string;
    average_cost: string;
    last_purchase_cost: string;
    member_price?: string | null;
    wholesale_price?: string | null;
    reseller_price?: string | null;
    min_selling_price: string;
    is_default: boolean;
    is_active: boolean;
    units?: ProductUnit[];
    barcodes?: ProductBarcode[];
  }>;
  units?: ProductUnit[];
  product_units?: ProductUnit[];
  productUnits?: ProductUnit[];
  barcodes?: ProductBarcode[];
  inventory_settings?: ProductInventorySetting[];
  inventorySettings?: any[];
  supplier_mappings?: SupplierProduct[];
  supplier_products?: any[];
  supplierProducts?: any[];
  batches?: InventoryBatchItem[];
  price_histories?: ProductPriceHistory[];
  priceHistories?: any[];
}

export interface Customer {
  id: number;
  outlet_id?: number | null;
  code: string;
  name: string;
  company_name?: string | null;
  customer_group: string;
  pricing_tier: string;
  tier_id?: number | null;
  tier?: { id: number; code: string; name: string; color: string } | null;
  phone?: string | null;
  email?: string | null;
  city?: string | null;
  address?: string | null;
  credit_limit: string;
  payment_term_days: number;
  outstanding_balance: string;
  customer_status?: string;
  is_active?: boolean;
}

// =====================================================================
// USER, ROLE & PERMISSION MANAGEMENT (RBAC)
// =====================================================================

export type AccountStatus = 'ACTIVE' | 'SUSPENDED' | 'DELETED';

export interface UserRoleBadge {
  id: number;
  name: string;
  display_name: string;
  level: number;
}

export interface UserOutletAssignment {
  id: number;
  code: string;
  name: string;
  is_default: boolean;
}

export interface ManagedUser {
  id: number;
  name: string;
  username: string;
  email: string;
  status: AccountStatus;
  is_super_admin: boolean;
  is_self: boolean;
  has_pin: boolean;
  last_login_at: string | null;
  roles: UserRoleBadge[];
  outlets: UserOutletAssignment[];
  all_outlets_access: boolean;
  default_outlet_id: number | null;
  suspended_at: string | null;
  suspension_reason?: string | null;
  created_at: string | null;
  deleted_at?: string | null;
  permissions?: string[];
  active_sessions?: number;
}

export interface ManagedRole {
  id: number;
  name: string;
  display_name: string;
  description: string | null;
  level: number;
  is_system: boolean;
  is_active: boolean;
  is_super_admin: boolean;
  permissions_count: number;
  permission_names: string[];
  users_count: number;
  assignable: boolean;
  editable: boolean;
  deletable: boolean;
  updated_at: string | null;
}

export interface PermissionMatrixItem {
  id: number;
  name: string;
  action: string;
  is_standard_action: boolean;
  description: string | null;
  grantable: boolean;
}

export interface PermissionModuleGroup {
  module: string;
  label: string;
  permissions: PermissionMatrixItem[];
}

export interface UserManagementStats {
  total: number;
  active: number;
  suspended: number;
  deleted: number;
  logged_in_today: number;
  never_logged_in: number;
}

export interface UserActivityLogEntry {
  id: number;
  action_event: string;
  label: string;
  category: string;
  severity: 'low' | 'medium' | 'high';
  actor_id: number | null;
  actor_name: string | null;
  actor_role: string | null;
  entity_type: string | null;
  entity_id: string | null;
  target_name: string | null;
  target_username: string | null;
  outlet: { id: number; code: string; name: string } | null;
  old_values: Record<string, any> | null;
  new_values: Record<string, any> | null;
  metadata: Record<string, any> | null;
  ip_address: string | null;
  created_at: string;
}







