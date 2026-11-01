import Dexie, { type IndexableType, type Table } from 'dexie';
import type {
  PosCategoryRecord,
  PosMemberSearchResult,
  PosOrderListItem,
  PosProductRecord,
  PosProductSerialRecord,
  PosProductVariantRecord,
  PosTableRecord,
} from '@/services/api/pos';
import { getActiveOutletId, getSessionPosAccess, getSessionUser, isPosOfflineSessionExpired } from '@/services/api/client';
import type { PosOrderDraft, PosOutletSettings } from '@/services/api/posOrders';

export type PosOfflineScope = {
  tenantSlug: string;
  outletId: string;
  userId: string;
  isCashier: boolean;
};

type ScopedRecord = PosOfflineScope & { id: number | string };

export type CachedProduct = Omit<PosProductRecord, 'variants'> & PosOfflineScope;
export type CachedCategory = PosCategoryRecord & PosOfflineScope;
export type CachedVariant = PosProductVariantRecord & PosOfflineScope;
export type CachedSerial = PosProductSerialRecord & PosOfflineScope & {
  product_id: number;
  is_available: true;
};
export type CachedTable = PosTableRecord & PosOfflineScope;
export type CachedMember = PosMemberSearchResult & PosOfflineScope;

export type CachedStockLevel = PosOfflineScope & {
  id: string;
  productId: number;
  variantId: number | null;
  quantity: number;
  updatedAt: string;
};

export type OfflineOrderItem = {
  id: string;
  product_id: number;
  product_variant_id: number | null;
  product_name: string;
  quantity: number;
  unit_price: number;
  line_total: number;
  serial_numbers: string[];
  options: string[];
};

export type OfflineOrder = PosOfflineScope & {
  id: string;
  display_id: number;
  client_uuid: string;
  local_invoice_no: string;
  order_number: string;
  items: OfflineOrderItem[];
  subtotal: number;
  discount_amount: number;
  points_discount_amount: number;
  service_amount: number;
  tax_amount: number;
  rounding_amount: number;
  final_amount: number;
  payment_method: string | null;
  payment_amount: number | null;
  paid_manually: boolean;
  sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
  server_order_id?: number;
  final_invoice_no?: string;
  updated_at_client?: string;
  last_error: string | null;
  attempts: number;
  created_at_client: string;
  created_offline: boolean;
  paid_at_client: string | null;
  created_by_user_id: string;
  outlet_id: string;
  member_id: number | null;
  draft: PosOrderDraft;
  customer_name: string | null;
  customer_phone: string | null;
  customer_whatsapp: string | null;
  table_id: number | null;
  table_code: string | null;
  service_type: string;
  notes: string | null;
  organization_name: string;
  organization_address: string | null;
  organization_phone: string | null;
  payment_note: string | null;
};

export type OfflineOrderItemRecord = PosOfflineScope & OfflineOrderItem & {
  client_uuid: string;
};

export type PendingStockMovement = PosOfflineScope & {
  id: string;
  client_uuid: string;
  product_id: number;
  product_variant_id: number | null;
  movement_type: 'sale' | 'purchase' | 'adjustment' | 'return' | 'transfer';
  quantity_delta: number;
  created_at_client: string;
  outlet_id: string;
};

export type PosSyncQueueItem = PosOfflineScope & {
  id: string;
  client_uuid: string;
  record_type: 'order' | 'stock_movement';
  status: 'pending' | 'syncing' | 'synced' | 'failed';
  attempts: number;
  last_error: string | null;
  next_retry_at: string | null;
  created_at_client: string;
};

export type PosOfflineMeta = PosOfflineScope & {
  id: string;
  value: unknown;
  updatedAt: string;
};

export type CachedOutletSettings = PosOfflineScope & {
  id: string;
  settings: PosOutletSettings;
  payment_methods: string[];
  updatedAt: string;
};

class PosOfflineDatabase extends Dexie {
  products!: Table<CachedProduct, [string, string, string, number]>;
  categories!: Table<CachedCategory, [string, string, string, number]>;
  product_variants!: Table<CachedVariant, [string, string, string, number]>;
  product_serials!: Table<CachedSerial, [string, string, string, number]>;
  stock_levels!: Table<CachedStockLevel, string>;
  members!: Table<CachedMember, [string, string, string, number]>;
  orders!: Table<OfflineOrder, [string, string, string, string]>;
  order_items!: Table<OfflineOrderItemRecord, [string, string, string, string]>;
  stock_movements_pending!: Table<PendingStockMovement, string>;
  sync_queue!: Table<PosSyncQueueItem, [string, string, string, string]>;
  meta!: Table<PosOfflineMeta, [string, string, string, string]>;

  get diningTables(): Table<CachedTable, [string, string, string, number]> {
    return this.table('tables');
  }

  constructor() {
    super('hellom-pos-offline');

    this.version(1).stores({
      products: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+sku], [tenantSlug+outletId+userId+barcode]',
      categories: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId]',
      product_variants: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+product_id], [tenantSlug+outletId+userId+sku], [tenantSlug+outletId+userId+barcode]',
      product_serials: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+product_id], [tenantSlug+outletId+userId+serial_number]',
      stock_levels: '&id, [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+productId]',
      members: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+name], [tenantSlug+outletId+userId+phone]',
      tables: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId]',
      orders: '&[tenantSlug+outletId+userId+id], &[tenantSlug+outletId+userId+client_uuid], [tenantSlug+outletId+userId+sync_status], [tenantSlug+outletId+userId+created_at_client]',
      order_items: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId+client_uuid]',
      stock_movements_pending: '&id, [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+client_uuid], [tenantSlug+outletId+userId+product_id]',
      sync_queue: '&[tenantSlug+outletId+userId+id], &[tenantSlug+outletId+userId+client_uuid], [tenantSlug+outletId+userId+status], [tenantSlug+outletId+userId+created_at_client]',
      meta: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId]',
    });

    this.version(2).stores({
      products: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+sku], [tenantSlug+outletId+userId+barcode], [tenantSlug+outletId+userId+name]',
      members: '&[tenantSlug+outletId+userId+id], [tenantSlug+outletId+userId], [tenantSlug+outletId+userId+name], [tenantSlug+outletId+userId+phone]',
    });

    this.version(3).stores({}).upgrade(async (transaction) => {
      await transaction.table('orders').toCollection().modify((order: OfflineOrder) => {
        order.created_offline ??= true;
      });
    });
  }
}

export const posOfflineDb = new PosOfflineDatabase();

let storagePersistenceRequested = false;

async function ensureDatabaseReady() {
  await posOfflineDb.open();
  if (storagePersistenceRequested) return;
  storagePersistenceRequested = true;

  if (typeof navigator === 'undefined') return;
  if (!navigator.storage?.persist) return;
  try {
    const persisted = await navigator.storage.persist();
    if (!persisted) console.warn('[POS offline] Browser did not grant persistent storage.');
  } catch (error) {
    console.warn('[POS offline] Failed to request persistent storage:', error);
  }
}

export function getPosOfflineScope(): PosOfflineScope | null {
  const user = getSessionUser<{
    id?: number | string;
    current_organization?: { slug?: string | null; pos_tenant_slug?: string | null } | null;
    organizations?: Array<{ slug?: string | null; pos_tenant_slug?: string | null }>;
  }>();
  const access = getSessionPosAccess();
  const outletId = getActiveOutletId();
  const userId = user?.id === undefined ? '' : String(user.id);
  const tenantSlug =
    access?.tenant_slug ||
    user?.current_organization?.pos_tenant_slug ||
    user?.current_organization?.slug ||
    user?.organizations?.[0]?.pos_tenant_slug ||
    user?.organizations?.[0]?.slug ||
    '';
  const isCashier = Boolean(access?.is_cashier || access?.pos_role === 'cashier' || access?.business_role === 'cashier');
  if (!tenantSlug || !userId || !outletId) return null;

  if (isCashier) {
    const allowed = new Set((access?.outlets ?? []).map((outlet) => String(outlet.id)));
    if (access?.outlet_id !== null && access?.outlet_id !== undefined) allowed.add(String(access.outlet_id));
    if (!allowed.has(outletId)) return null;
  }

  return { tenantSlug, outletId, userId, isCashier };
}

function scopeKey(scope: PosOfflineScope): [string, string, string] {
  return [scope.tenantSlug, scope.outletId, scope.userId];
}

async function recordsForScope<T extends ScopedRecord, TKey extends IndexableType>(
  table: Table<T, TKey>,
  scope: PosOfflineScope,
): Promise<T[]> {
  return table.where('[tenantSlug+outletId+userId]').equals(scopeKey(scope)).toArray();
}

function omitSensitiveProductFields(product: PosProductRecord, scope: PosOfflineScope): CachedProduct {
  const { cost_price: costPrice, variants: _variants, ...safeProduct } = product;
  return {
    ...safeProduct,
    ...(scope.isCashier ? {} : { cost_price: costPrice }),
    ...scope,
  };
}

export async function readCachedPosMasterData() {
  const scope = getPosOfflineScope();
  if (!scope) return null;
  await ensureDatabaseReady();
  if (scope.isCashier) {
    await posOfflineDb.products
      .toCollection()
      .filter((product) => product.tenantSlug === scope.tenantSlug && product.userId === scope.userId && product.cost_price !== undefined)
      .modify((product) => {
        delete product.cost_price;
      });
  }
  const [products, categories, tables, variants, stockLevels, movements] = await Promise.all([
    recordsForScope(posOfflineDb.products, scope),
    recordsForScope(posOfflineDb.categories, scope),
    recordsForScope(posOfflineDb.diningTables, scope),
    recordsForScope(posOfflineDb.product_variants, scope),
    recordsForScope(posOfflineDb.stock_levels, scope),
    recordsForScope(posOfflineDb.stock_movements_pending, scope),
  ]);
  if (products.length === 0 && categories.length === 0 && tables.length === 0) return null;

  const variantsByProduct = new Map<number, CachedVariant[]>();
  variants.forEach((variant) => {
    const rows = variantsByProduct.get(variant.product_id) ?? [];
    rows.push(variant);
    variantsByProduct.set(variant.product_id, rows);
  });
  const stockByProduct = new Map(stockLevels.map((stock) => [
    stock.variantId === null ? stock.productId : stock.variantId * -1,
    stock.quantity,
  ]));
  const pendingStockByProduct = new Map<number, number>();
  movements.forEach((movement) => {
    if (movement.product_variant_id === null) {
      pendingStockByProduct.set(
        movement.product_id,
        (pendingStockByProduct.get(movement.product_id) ?? 0) + movement.quantity_delta,
      );
    } else {
      const variantKey = movement.product_variant_id * -1;
      pendingStockByProduct.set(
        variantKey,
        (pendingStockByProduct.get(variantKey) ?? 0) + movement.quantity_delta,
      );
    }
  });

  return {
    scope,
    products: products.map((product) => {
      const stock = stockByProduct.get(product.id);
      return {
        ...product,
        stock: product.track_stock && (stockByProduct.get(product.id) ?? stock) !== undefined
          ? (stockByProduct.get(product.id) ?? stock ?? 0) + (pendingStockByProduct.get(product.id) ?? 0)
          : product.stock,
        variants: (variantsByProduct.get(product.id) ?? []).map((variant) => ({
          ...variant,
          stock: variant.track_stock && variant.stock !== null
            ? variant.stock + (pendingStockByProduct.get(variant.id * -1) ?? 0)
            : variant.stock,
        })),
      } satisfies PosProductRecord;
    }),
    categories: categories.map(({ tenantSlug: _tenantSlug, outletId: _outletId, userId: _userId, isCashier: _isCashier, ...category }) => category),
    tables: tables.map(({ tenantSlug: _tenantSlug, outletId: _outletId, userId: _userId, isCashier: _isCashier, ...table }) => table),
  };
}

export async function cachePosMasterData(input: {
  products: PosProductRecord[];
  categories: PosCategoryRecord[];
  tables?: PosTableRecord[];
}) {
  const scope = getPosOfflineScope();
  if (!scope) return;
  await ensureDatabaseReady();
  const key = scopeKey(scope);
  const now = new Date().toISOString();
  const productRows = input.products.map((product) => omitSensitiveProductFields(product, scope));
  const categoryRows = input.categories.map((category) => ({ ...category, ...scope }));
  const tableRows = (input.tables ?? []).map((table) => ({ ...table, ...scope }));
  const variantRows: CachedVariant[] = input.products.flatMap((product) =>
    (product.variants ?? []).map((variant) => ({ ...variant, ...scope })),
  );
  const stockRows: CachedStockLevel[] = input.products.flatMap((product) => [
    ...(product.track_stock && product.stock !== null
      ? [{
          ...scope,
          id: `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:${product.id}:product`,
          productId: product.id,
          variantId: null,
          quantity: product.stock,
          updatedAt: now,
        }]
      : []),
    ...(product.variants ?? []).flatMap((variant) =>
      variant.track_stock && variant.stock !== null
        ? [{
            ...scope,
            id: `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:${product.id}:${variant.id}`,
            productId: product.id,
            variantId: variant.id,
            quantity: variant.stock,
            updatedAt: now,
          }]
        : [],
    ),
  ]);
  const refreshedProductIds = new Set(input.products.map((product) => product.id));
  const syncedClientUuids = new Set(
    (await recordsForScope(posOfflineDb.sync_queue, scope))
      .filter((item) => item.record_type === 'order' && item.status === 'synced')
      .map((item) => item.client_uuid),
  );
  const reconciledMovementIds = (await recordsForScope(posOfflineDb.stock_movements_pending, scope))
    .filter((movement) =>
      syncedClientUuids.has(movement.client_uuid) && refreshedProductIds.has(movement.product_id),
    )
    .map((movement) => movement.id);

  await posOfflineDb.transaction(
    'rw',
    [posOfflineDb.products, posOfflineDb.categories, posOfflineDb.diningTables, posOfflineDb.product_variants, posOfflineDb.stock_levels, posOfflineDb.meta, posOfflineDb.stock_movements_pending],
    async () => {
      await Promise.all([
        posOfflineDb.products.where('[tenantSlug+outletId+userId]').equals(key).delete(),
        posOfflineDb.categories.where('[tenantSlug+outletId+userId]').equals(key).delete(),
        posOfflineDb.diningTables.where('[tenantSlug+outletId+userId]').equals(key).delete(),
        posOfflineDb.product_variants.where('[tenantSlug+outletId+userId]').equals(key).delete(),
        posOfflineDb.stock_levels.where('[tenantSlug+outletId+userId]').equals(key).delete(),
      ]);
      await Promise.all([
        posOfflineDb.products.bulkPut(productRows),
        posOfflineDb.categories.bulkPut(categoryRows),
        posOfflineDb.diningTables.bulkPut(tableRows),
        posOfflineDb.product_variants.bulkPut(variantRows),
        posOfflineDb.stock_levels.bulkPut(stockRows),
        posOfflineDb.stock_movements_pending.bulkDelete(reconciledMovementIds),
        posOfflineDb.meta.put({
          ...scope,
          id: `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:master-data-updated`,
          value: now,
          updatedAt: now,
        }),
      ]);
    },
  );
}

export async function cachePosMembers(members: PosMemberSearchResult[]) {
  const scope = getPosOfflineScope();
  if (!scope || members.length === 0) return;
  await ensureDatabaseReady();
  await posOfflineDb.members.bulkPut(members.map((member) => ({ ...member, ...scope })));
}

export async function cachePosOutletSettings(settings: PosOutletSettings, paymentMethods: string[]) {
  const scope = getPosOfflineScope();
  if (!scope) return;
  await ensureDatabaseReady();
  await posOfflineDb.meta.put({
    ...scope,
    id: `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:outlet-settings`,
    value: { settings, paymentMethods },
    updatedAt: new Date().toISOString(),
  });
}

export async function getCachedPosOutletSettings(): Promise<{ settings: PosOutletSettings; paymentMethods: string[] } | null> {
  const scope = getPosOfflineScope();
  if (!scope) return null;
  await ensureDatabaseReady();
  const record = await posOfflineDb.meta.get([
    scope.tenantSlug,
    scope.outletId,
    scope.userId,
    `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:outlet-settings`,
  ]);
  if (!record?.value || typeof record.value !== 'object') return null;
  const value = record.value as { settings?: PosOutletSettings; paymentMethods?: string[] };
  if (!value.settings || !Array.isArray(value.paymentMethods)) return null;
  return { settings: value.settings, paymentMethods: value.paymentMethods };
}

export async function searchCachedPosMembers(query: string): Promise<PosMemberSearchResult[]> {
  const scope = getPosOfflineScope();
  if (!scope || query.trim().length < 2) return [];
  await ensureDatabaseReady();
  const normalized = query.trim().toLocaleLowerCase('id-ID');
  const members = await recordsForScope(posOfflineDb.members, scope);
  return members
    .filter((member) =>
      member.name.toLocaleLowerCase('id-ID').includes(normalized) ||
      (member.phone ?? '').toLocaleLowerCase('id-ID').includes(normalized) ||
      (member.email ?? '').toLocaleLowerCase('id-ID').includes(normalized),
    )
    .sort((a, b) => Number(b.total_orders) - Number(a.total_orders))
    .slice(0, 20)
    .map(({ tenantSlug: _tenantSlug, outletId: _outletId, userId: _userId, isCashier: _isCashier, ...member }) => member);
}

export async function cacheAvailablePosSerials(productId: number, serials: PosProductSerialRecord[]) {
  const scope = getPosOfflineScope();
  if (!scope) return;
  await ensureDatabaseReady();
  await posOfflineDb.transaction('rw', posOfflineDb.product_serials, async () => {
    await posOfflineDb.product_serials
      .where('[tenantSlug+outletId+userId+product_id]')
      .equals([...scopeKey(scope), productId])
      .delete();
    await posOfflineDb.product_serials.bulkPut(
      serials.map((serial) => ({ ...serial, ...scope, product_id: productId, is_available: true as const })),
    );
  });
}

export async function getCachedAvailablePosSerials(productId: number, variantId?: number) {
  const scope = getPosOfflineScope();
  if (!scope) return [];
  await ensureDatabaseReady();
  const serials = await recordsForScope(posOfflineDb.product_serials, scope);
  return serials
    .filter((serial) => serial.product_id === productId && (!variantId || serial.product_variant_id === variantId))
    .map(({ tenantSlug: _tenantSlug, outletId: _outletId, userId: _userId, isCashier: _isCashier, product_id: _productId, is_available: _isAvailable, ...serial }) => serial);
}

export async function saveOfflineOrder(order: Omit<OfflineOrder, keyof PosOfflineScope | 'id' | 'display_id' | 'client_uuid' | 'local_invoice_no' | 'order_number' | 'sync_status' | 'last_error' | 'attempts' | 'created_at_client' | 'created_offline' | 'paid_at_client' | 'created_by_user_id' | 'outlet_id' | 'member_id'> & {
  items: OfflineOrderItem[];
  member_id?: number | null;
  created_offline?: boolean;
}) {
  if (isPosOfflineSessionExpired()) {
    throw new Error('Sesi offline melewati batas waktu. Hubungkan perangkat ke internet dan login ulang sebelum membuat transaksi.');
  }
  const scope = getPosOfflineScope();
  if (!scope) throw new Error('Outlet atau sesi pengguna tidak valid untuk menyimpan transaksi offline.');
  await ensureDatabaseReady();
  const now = new Date().toISOString();
  const clientUuid = crypto.randomUUID();
  const localInvoiceNo = `LOCAL-${now.replace(/\D/g, '').slice(0, 14)}-${clientUuid.slice(0, 6).toUpperCase()}`;
  const id = `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:${clientUuid}`;
  const displayId = -(Date.now() * 1000 + Math.floor(Math.random() * 1000));
  const { items, ...orderData } = order;
  const offlineOrder: OfflineOrder = {
    ...scope,
    ...orderData,
    id,
    display_id: displayId,
    client_uuid: clientUuid,
    local_invoice_no: localInvoiceNo,
    order_number: localInvoiceNo,
    items,
    sync_status: 'pending',
    last_error: null,
    attempts: 0,
    created_at_client: now,
    created_offline: order.created_offline ?? !navigator.onLine,
    paid_at_client: null,
    created_by_user_id: scope.userId,
    outlet_id: scope.outletId,
    member_id: order.member_id ?? null,
    draft: order.draft,
  };
  const movements: PendingStockMovement[] = items.map((item) => ({
    ...scope,
    id: `${clientUuid}:${item.id}`,
    client_uuid: clientUuid,
    product_id: item.product_id,
    product_variant_id: item.product_variant_id,
    movement_type: 'sale',
    quantity_delta: -item.quantity,
    created_at_client: now,
    outlet_id: scope.outletId,
  }));
  const queueItem: PosSyncQueueItem = {
    ...scope,
    id,
    client_uuid: clientUuid,
    record_type: 'order',
    status: 'pending',
    attempts: 0,
    last_error: null,
    next_retry_at: null,
    created_at_client: now,
  };
  await posOfflineDb.transaction('rw', [posOfflineDb.orders, posOfflineDb.order_items, posOfflineDb.stock_movements_pending, posOfflineDb.sync_queue], async () => {
    await posOfflineDb.orders.add(offlineOrder);
    await posOfflineDb.order_items.bulkAdd(items.map((item) => ({ ...item, ...scope, client_uuid: clientUuid })));
    await posOfflineDb.stock_movements_pending.bulkAdd(movements);
    await posOfflineDb.sync_queue.add(queueItem);
  });
  if (typeof window !== 'undefined') window.dispatchEvent(new Event('pos-sync-queue-updated'));
  return offlineOrder;
}

export async function getOfflineOrders() {
  const scope = getPosOfflineScope();
  if (!scope) return [];
  await ensureDatabaseReady();
  return (await recordsForScope(posOfflineDb.orders, scope))
    .filter((order) => order.sync_status !== 'synced');
}

export async function getPosSyncQueueOrders() {
  const scope = getPosOfflineScope();
  if (!scope) return [];
  await ensureDatabaseReady();
  const queue = (await recordsForScope(posOfflineDb.sync_queue, scope))
    .filter((item) => item.record_type === 'order' && item.status !== 'synced')
    .sort((a, b) => a.created_at_client.localeCompare(b.created_at_client));
  const entries = await Promise.all(queue.map(async (item) => ({
    queue: item,
    order: await posOfflineDb.orders.get([...scopeKey(scope), item.id]),
  })));
  return entries.filter((entry): entry is typeof entry & { order: OfflineOrder } => Boolean(entry.order));
}

export async function getDueOfflineOrders(limit = 20) {
  const scope = getPosOfflineScope();
  if (!scope) return [];
  await ensureDatabaseReady();
  const now = new Date().toISOString();
  const queue = (await recordsForScope(posOfflineDb.sync_queue, scope))
    .filter((item) => item.record_type === 'order' && item.status === 'pending' && (!item.next_retry_at || item.next_retry_at <= now))
    .sort((a, b) => a.created_at_client.localeCompare(b.created_at_client))
    .slice(0, limit);
  const orders = await Promise.all(queue.map((item) => posOfflineDb.orders.get([...scopeKey(scope), item.id])));
  return orders.filter((order): order is OfflineOrder => Boolean(order));
}

export async function recoverStaleOfflineOrders() {
  const scope = getPosOfflineScope();
  if (!scope) return;
  await ensureDatabaseReady();
  const now = new Date().toISOString();
  const stale = (await recordsForScope(posOfflineDb.sync_queue, scope))
    .filter((item) => item.record_type === 'order' && item.status === 'syncing' &&
      (!item.next_retry_at || item.next_retry_at <= now));
  for (const item of stale) {
    await updateOfflineOrderSync(item.client_uuid, {
      status: 'pending',
      lastError: 'Sinkronisasi sebelumnya terhenti; transaksi dikembalikan ke antrean.',
      nextRetryAt: null,
    });
  }
}

export async function updateOfflineOrderSync(
  clientUuid: string,
  update: {
    status: PosSyncQueueItem['status'];
    lastError?: string | null;
    nextRetryAt?: string | null;
    incrementAttempts?: boolean;
    serverOrderId?: number;
    finalInvoiceNo?: string;
  },
) {
  const scope = getPosOfflineScope();
  if (!scope) return;
  await ensureDatabaseReady();
  await posOfflineDb.transaction(
    'rw',
    [posOfflineDb.orders, posOfflineDb.sync_queue, posOfflineDb.stock_movements_pending],
    async () => {
      const queue = await posOfflineDb.sync_queue
        .where('[tenantSlug+outletId+userId+client_uuid]')
        .equals([...scopeKey(scope), clientUuid])
        .first();
      const order = await posOfflineDb.orders
        .where('[tenantSlug+outletId+userId+client_uuid]')
        .equals([...scopeKey(scope), clientUuid])
        .first();
      if (!queue || !order) return;
      const now = new Date().toISOString();
      const nextAttempts = order.attempts + (update.incrementAttempts ? 1 : 0);
      await posOfflineDb.sync_queue.update([...scopeKey(scope), queue.id], {
        status: update.status,
        attempts: nextAttempts,
        last_error: update.lastError ?? null,
        next_retry_at: update.nextRetryAt ?? null,
      });
      await posOfflineDb.orders.update([...scopeKey(scope), order.id], {
        sync_status: update.status,
        attempts: nextAttempts,
        last_error: update.lastError ?? null,
        ...(update.serverOrderId !== undefined ? { server_order_id: update.serverOrderId } : {}),
        ...(update.finalInvoiceNo !== undefined
          ? { order_number: update.finalInvoiceNo, final_invoice_no: update.finalInvoiceNo }
          : {}),
      });
      if (update.status === 'synced') {
        await posOfflineDb.orders.update([...scopeKey(scope), order.id], {
          sync_status: 'synced',
          last_error: null,
          updated_at_client: now,
        });
      }
    },
  );
}

export async function retryOfflineOrder(clientUuid: string) {
  return updateOfflineOrderSync(clientUuid, { status: 'pending', lastError: null, nextRetryAt: null });
}

export async function removeFailedOfflineOrderFromQueue(clientUuid: string) {
  const scope = getPosOfflineScope();
  if (!scope) throw new Error('Sesi atau outlet tidak valid.');
  await ensureDatabaseReady();
  await posOfflineDb.transaction(
    'rw',
    [posOfflineDb.orders, posOfflineDb.sync_queue, posOfflineDb.stock_movements_pending],
    async () => {
      const queue = await posOfflineDb.sync_queue
        .where('[tenantSlug+outletId+userId+client_uuid]')
        .equals([...scopeKey(scope), clientUuid])
        .first();
      if (!queue || queue.status !== 'failed') {
        throw new Error('Hanya transaksi gagal yang dapat dihapus dari antrean.');
      }
      const order = await posOfflineDb.orders
        .where('[tenantSlug+outletId+userId+client_uuid]')
        .equals([...scopeKey(scope), clientUuid])
        .first();
      if (!order) throw new Error('Transaksi lokal tidak ditemukan.');
      await posOfflineDb.stock_movements_pending
        .where('[tenantSlug+outletId+userId+client_uuid]')
        .equals([...scopeKey(scope), clientUuid])
        .delete();
      await posOfflineDb.sync_queue.delete([...scopeKey(scope), queue.id]);
      await posOfflineDb.orders.update([...scopeKey(scope), order.id], {
        last_error: 'Dihapus dari antrean oleh administrator; data transaksi lokal tetap disimpan.',
      });
    },
  );
  if (typeof window !== 'undefined') window.dispatchEvent(new Event('pos-sync-queue-updated'));
}

export async function getPosSyncCounts() {
  const scope = getPosOfflineScope();
  if (!scope) return { pending: 0, syncing: 0, failed: 0 };
  await ensureDatabaseReady();
  const queue = await recordsForScope(posOfflineDb.sync_queue, scope);
  return {
    pending: queue.filter((item) => item.status === 'pending').length,
    syncing: queue.filter((item) => item.status === 'syncing').length,
    failed: queue.filter((item) => item.status === 'failed').length,
  };
}

export async function applyPosMasterDataPull(delta: {
  products: PosProductRecord[];
  categories: PosCategoryRecord[];
  tables: PosTableRecord[];
  members: PosMemberSearchResult[];
  tables_enabled: boolean;
  serials_enabled: boolean;
  server_time: string;
  serials: Array<{
    id: number;
    product_id: number;
    product_variant_id: number | null;
    serial_number: string;
    status: string;
  }>;
  removed: { products: number[]; categories: number[]; variants: number[]; serials: number[]; tables: number[]; members: number[] };
}) {
  const scope = getPosOfflineScope();
  if (!scope) return;
  await ensureDatabaseReady();
  const cached = await readCachedPosMasterData();
  const removeIds = <T extends { id: number }>(rows: T[], removed: number[]) =>
    rows.filter((row) => !removed.includes(row.id));
  const mergeById = <T extends { id: number }>(current: T[], incoming: T[]) => {
    const records = new Map(current.map((record) => [record.id, record]));
    incoming.forEach((record) => records.set(record.id, record));
    return [...records.values()];
  };
  const cachedProducts = (cached?.products ?? []).map((product) => ({
    ...product,
    variants: product.variants?.filter((variant) => !delta.removed.variants.includes(variant.id)),
  }));
  const products = mergeById(
    removeIds(cachedProducts, delta.removed.products),
    delta.products,
  );
  const categories = mergeById(
    removeIds(cached?.categories ?? [], delta.removed.categories),
    delta.categories,
  );
  const tables = delta.tables_enabled
    ? mergeById(removeIds(cached?.tables ?? [], delta.removed.tables), delta.tables)
    : [];
  const members = await recordsForScope(posOfflineDb.members, scope);
  const retainedMembers = removeIds(
    members.map(({ tenantSlug: _tenantSlug, outletId: _outletId, userId: _userId, isCashier: _isCashier, ...member }) => member),
    delta.removed.members,
  );
  await cachePosMasterData({ products, categories, tables });
  await posOfflineDb.members.bulkPut(
    mergeById(retainedMembers, delta.members).map((member) => ({ ...member, ...scope })),
  );
  await posOfflineDb.transaction('rw', posOfflineDb.product_serials, async () => {
    if (!delta.serials_enabled) {
      await posOfflineDb.product_serials.where('[tenantSlug+outletId+userId]').equals(scopeKey(scope)).delete();
      return;
    }
    for (const serial of delta.serials) {
      if (serial.status === 'available') {
        await posOfflineDb.product_serials.put({
          ...serial,
          ...scope,
          is_available: true,
        });
      } else {
        await posOfflineDb.product_serials.delete([...scopeKey(scope), serial.id]);
      }
    }
    if (delta.removed.serials.length > 0) {
      await Promise.all(delta.removed.serials.map((serialId) =>
        posOfflineDb.product_serials.delete([...scopeKey(scope), serialId]),
      ));
    }
  });
  await posOfflineDb.meta.put({
    ...scope,
    id: `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:pull-since`,
    value: delta.server_time,
    updatedAt: delta.server_time,
  });
}

export async function getPosSyncCursor() {
  const scope = getPosOfflineScope();
  if (!scope) return null;
  await ensureDatabaseReady();
  const record = await posOfflineDb.meta.get([
    scope.tenantSlug,
    scope.outletId,
    scope.userId,
    `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:pull-since`,
  ]);
  return typeof record?.value === 'string' ? record.value : null;
}

export function toPosOrderListItem(order: OfflineOrder): PosOrderListItem {
  const paid = order.paid_at_client !== null;
  return {
    id: order.display_id,
    order_number: order.local_invoice_no,
    customer_name: order.customer_name || 'Pelanggan',
    table: order.table_id === null ? null : {
      id: order.table_id,
      code: order.table_code || String(order.table_id),
      name: order.table_code || String(order.table_id),
    },
    table_label: order.table_code || '',
    service_type: order.service_type,
    status: 'new',
    status_label: 'Menunggu sinkronisasi',
    allowed_next: [],
    payment_status: paid ? 'paid' : 'unpaid',
    payment_method: order.payment_method,
    subtotal_amount: order.subtotal,
    total_amount: order.subtotal,
    discount_amount: order.discount_amount,
    points_discount_amount: order.points_discount_amount,
    service_amount: order.service_amount,
    tax_amount: order.tax_amount,
    rounding_amount: order.rounding_amount,
    final_amount: order.final_amount,
    member_id: order.member_id,
    created_at: order.created_at_client,
    updated_at: order.created_at_client,
    items_count: order.items.length,
    items: order.items.map((item, index) => ({
      id: index + 1,
      product_name: item.product_name,
      quantity: item.quantity,
      unit_price: item.unit_price,
      line_total: item.line_total,
      options: item.options,
    })),
    offline_client_uuid: order.client_uuid,
    local_invoice_no: order.local_invoice_no,
    sync_status: order.sync_status,
    paid_manually: order.paid_manually,
  };
}

export async function countUnsyncedPosRecords() {
  const scope = getPosOfflineScope();
  if (!scope) return 0;
  await ensureDatabaseReady();
  return posOfflineDb.sync_queue
    .where('[tenantSlug+outletId+userId]')
    .equals(scopeKey(scope))
    .filter((item) => item.status === 'pending' || item.status === 'failed' || item.status === 'syncing')
    .count();
}

export async function clearSyncedPosCacheOnLogout(scopeOverride?: PosOfflineScope | null) {
  const activeScope = scopeOverride ?? getPosOfflineScope();
  if (!activeScope) return;
  await ensureDatabaseReady();
  const scopes = new Map<string, PosOfflineScope>();
  const allRows = await Promise.all(posOfflineDb.tables.map((table) => table.toArray() as Promise<unknown[]>));
  for (const row of allRows.flat()) {
    if (!row || typeof row !== 'object') continue;
    const record = row as Partial<PosOfflineScope>;
    if (record.tenantSlug !== activeScope.tenantSlug || record.userId !== activeScope.userId ||
      typeof record.outletId !== 'string' || typeof record.isCashier !== 'boolean') continue;
    const scope = record as PosOfflineScope;
    scopes.set(scope.outletId, scope);
  }
  if (scopes.size === 0) scopes.set(activeScope.outletId, activeScope);

  for (const scope of scopes.values()) {
    const scopedOrders = await recordsForScope(posOfflineDb.orders, scope);
    const syncedOrders = scopedOrders.filter((order) => order.sync_status === 'synced');
    const scopedQueue = await recordsForScope(posOfflineDb.sync_queue, scope);

    await posOfflineDb.transaction(
      'rw',
      [
        posOfflineDb.products,
        posOfflineDb.categories,
        posOfflineDb.product_variants,
        posOfflineDb.product_serials,
        posOfflineDb.stock_levels,
        posOfflineDb.members,
        posOfflineDb.diningTables,
        posOfflineDb.orders,
        posOfflineDb.order_items,
        posOfflineDb.stock_movements_pending,
        posOfflineDb.sync_queue,
        posOfflineDb.meta,
      ],
      async () => {
        const scopedIndex = '[tenantSlug+outletId+userId]';
        await Promise.all([
          posOfflineDb.products.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.categories.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.product_variants.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.product_serials.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.stock_levels.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.members.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.diningTables.where(scopedIndex).equals(scopeKey(scope)).delete(),
          posOfflineDb.meta.where(scopedIndex).equals(scopeKey(scope)).delete(),
        ]);

        if (syncedOrders.length > 0) {
          await posOfflineDb.orders.bulkDelete(syncedOrders.map((order) => [...scopeKey(scope), order.id]));
          await Promise.all(syncedOrders.map((order) =>
            posOfflineDb.order_items
              .where('[tenantSlug+outletId+userId+client_uuid]')
              .equals([...scopeKey(scope), order.client_uuid])
              .delete(),
          ));
          await Promise.all(syncedOrders.map((order) =>
            posOfflineDb.stock_movements_pending
              .where('[tenantSlug+outletId+userId+client_uuid]')
              .equals([...scopeKey(scope), order.client_uuid])
              .delete(),
          ));
        }

        const syncedQueueIds: [string, string, string, string][] = scopedQueue
          .filter((item) => item.status === 'synced')
          .map((item) => [scope.tenantSlug, scope.outletId, scope.userId, item.id]);
        if (syncedQueueIds.length > 0) await posOfflineDb.sync_queue.bulkDelete(syncedQueueIds);
      },
    );
  }
}

export async function confirmPosContextChange() {
  try {
    const pendingCount = await countUnsyncedPosRecords();
    if (pendingCount === 0) return true;
    return window.confirm(
      `Ada ${pendingCount} transaksi belum tersinkron di perangkat ini. Data lokal tidak akan dihapus, tetapi transaksi ini tetap harus disinkronkan sebelum dapat diproses di server. Lanjutkan?`,
    );
  } catch (error) {
    console.error('[POS offline] Failed to verify pending local transactions:', error);
    window.alert('Antrean lokal tidak dapat diperiksa. Perubahan outlet atau logout dibatalkan agar data transaksi tetap aman.');
    return false;
  }
}

export async function getOfflineOrderById(orderId: string) {
  const scope = getPosOfflineScope();
  if (!scope) return null;
  await ensureDatabaseReady();
  return posOfflineDb.orders.get([scope.tenantSlug, scope.outletId, scope.userId, orderId]);
}

export async function getOfflineOrderByDisplayId(displayId: number) {
  const scope = getPosOfflineScope();
  if (!scope) return null;
  await ensureDatabaseReady();
  return posOfflineDb.orders
    .where('[tenantSlug+outletId+userId]')
    .equals(scopeKey(scope))
    .filter((order) => order.display_id === displayId)
    .first();
}

export async function saveOfflinePayment(
  clientUuid: string,
  payment: { method: string; amount: number; note: string | null; paidManually: boolean },
) {
  const scope = getPosOfflineScope();
  if (!scope) throw new Error('Outlet atau sesi pengguna tidak valid untuk menyimpan pembayaran offline.');
  await ensureDatabaseReady();
  const orderId = `${scope.tenantSlug}:${scope.outletId}:${scope.userId}:${clientUuid}`;
  const order = await posOfflineDb.orders.get([
    scope.tenantSlug,
    scope.outletId,
    scope.userId,
    orderId,
  ]);
  if (!order) throw new Error('Transaksi lokal tidak ditemukan.');
  const paidAt = new Date().toISOString();
  await posOfflineDb.orders.update([...scopeKey(scope), order.id], {
    payment_method: payment.method,
    payment_amount: payment.amount,
    payment_note: payment.note,
    paid_manually: payment.paidManually,
    paid_at_client: paidAt,
  });
  if (typeof window !== 'undefined') window.dispatchEvent(new Event('pos-sync-queue-updated'));
  return {
    ...order,
    payment_method: payment.method,
    payment_amount: payment.amount,
    payment_note: payment.note,
    paid_manually: payment.paidManually,
    paid_at_client: paidAt,
  };
}
