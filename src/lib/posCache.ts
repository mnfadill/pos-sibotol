import type { Category, DynamicTier, MasterDiscountRecord, ProductCatalog } from '../types/pos';

/**
 * Smart POS Browser Cache (Stale-While-Revalidate + Context Isolation).
 *
 * Isolates every cache entry by:
 *  - outletId
 *  - warehouseId
 *  - pricingContext (tier)
 *  - categoryId & page
 *
 * IMPORTANT: Browser cache is strictly for fast UI rendering (SWR).
 * Checkout, payment, and stock validation ALWAYS execute directly against the live backend database.
 */

const CACHE_PREFIX = 'sibotol_pos_swr_v1';
const META_MAX_AGE_MS = 10 * 60 * 1000; // 10 minutes max stale window for categories/brands/tiers
const CATALOG_MAX_AGE_MS = 5 * 60 * 1000; // 5 minutes max stale window for product catalog pages

interface CacheEnvelope<T> {
  data: T;
  savedAt: number;
  syncedAt?: string;
}

export interface PosBootstrapCache {
  categories: Category[];
  totalProducts: number;
  tiers: DynamicTier[];
  discounts: MasterDiscountRecord[];
}

export interface PosCatalogPageCache {
  products: ProductCatalog[];
  pagination?: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    has_more: boolean;
  };
  syncedAt?: string;
}

function buildBootstrapKey(outletId: number, warehouseId?: number | null): string {
  return `${CACHE_PREFIX}:boot:o${outletId}:w${warehouseId ?? 0}`;
}

function buildCatalogKey(params: {
  outletId: number;
  warehouseId?: number | null;
  categoryId: number;
  tier: string;
  page: number;
}): string {
  return `${CACHE_PREFIX}:cat:o${params.outletId}:w${params.warehouseId ?? 0}:c${params.categoryId}:t${params.tier.toUpperCase()}:p${params.page}`;
}

function safeGet<T>(key: string, maxAgeMs: number): CacheEnvelope<T> | null {
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as CacheEnvelope<T>;
    if (!parsed || typeof parsed.savedAt !== 'number') return null;
    if (Date.now() - parsed.savedAt > maxAgeMs) {
      window.localStorage.removeItem(key);
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

function safeSet<T>(key: string, data: T, syncedAt?: string): void {
  try {
    const payload: CacheEnvelope<T> = {
      data,
      savedAt: Date.now(),
      syncedAt,
    };
    window.localStorage.setItem(key, JSON.stringify(payload));
  } catch {
    // Storage quota full or private browsing — fail silently without blocking POS
  }
}

export const posCache = {
  getBootstrap(outletId: number, warehouseId?: number | null): CacheEnvelope<PosBootstrapCache> | null {
    return safeGet<PosBootstrapCache>(buildBootstrapKey(outletId, warehouseId), META_MAX_AGE_MS);
  },

  setBootstrap(outletId: number, warehouseId: number | null | undefined, data: PosBootstrapCache): void {
    safeSet(buildBootstrapKey(outletId, warehouseId), data, new Date().toISOString());
  },

  getCatalogPage(params: {
    outletId: number;
    warehouseId?: number | null;
    categoryId: number;
    tier: string;
    page: number;
  }): CacheEnvelope<PosCatalogPageCache> | null {
    return safeGet<PosCatalogPageCache>(buildCatalogKey(params), CATALOG_MAX_AGE_MS);
  },

  setCatalogPage(
    params: {
      outletId: number;
      warehouseId?: number | null;
      categoryId: number;
      tier: string;
      page: number;
    },
    data: PosCatalogPageCache,
  ): void {
    safeSet(buildCatalogKey(params), data, data.syncedAt);
  },

  /** Invalidate cached product pages for an outlet (e.g., immediately after checkout/stock change) */
  invalidateOutletCatalog(outletId: number): void {
    try {
      const prefix = `${CACHE_PREFIX}:cat:o${outletId}:`;
      const keysToRemove: string[] = [];
      for (let i = 0; i < window.localStorage.length; i++) {
        const k = window.localStorage.key(i);
        if (k && k.startsWith(prefix)) {
          keysToRemove.push(k);
        }
      }
      for (const k of keysToRemove) {
        window.localStorage.removeItem(k);
      }
    } catch {
      // ignore
    }
  },
};
