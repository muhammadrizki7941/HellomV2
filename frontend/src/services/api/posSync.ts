import { apiRequest, buildQuery } from './client';
import type {
  PosCategoryRecord,
  PosMemberSearchResult,
  PosProductRecord,
  PosTableRecord,
} from './pos';
import type { PosOrderDraft } from './posOrders';

export type PosSyncOrderPayload = PosOrderDraft & {
  client_uuid: string;
  created_at_client: string;
  created_offline: boolean;
  outlet_id: number;
  payment_method: string | null;
  payment_amount: number | null;
  payment_note: string | null;
  paid_manually: boolean;
  paid_at_client: string | null;
};

export type PosSyncResult = {
  client_uuid: string;
  status: 'ok' | 'duplicate' | 'rejected';
  code?: string;
  reason?: string;
  errors?: Record<string, string[]>;
  order?: {
    id: number;
    order_number: string;
    payment_status: string;
    final_amount: number;
    created_at_client: string | null;
    synced_at: string | null;
  };
  warnings?: Array<{
    product_id: number;
    variant_id: number | null;
    name: string;
    on_hand: number;
    code: string;
  }>;
};

export type PosSyncPull = {
  since: string | null;
  server_time: string;
  outlet_id: number;
  tables_enabled: boolean;
  serials_enabled: boolean;
  products: PosProductRecord[];
  categories: PosCategoryRecord[];
  tables: PosTableRecord[];
  members: PosMemberSearchResult[];
  serials: Array<{
    id: number;
    product_id: number;
    product_variant_id: number | null;
    serial_number: string;
    status: string;
  }>;
  removed: {
    products: number[];
    categories: number[];
    variants: number[];
    serials: number[];
    tables: number[];
    members: number[];
  };
};

export function uploadPosSyncOrders(orders: PosSyncOrderPayload[]) {
  return apiRequest<{ results: PosSyncResult[]; server_time: string }>('/pos/sync/orders', {
    method: 'POST',
    body: { orders },
  });
}

export function pullPosSyncChanges(since: string | null) {
  return apiRequest<PosSyncPull>(`/pos/sync/pull${buildQuery({ since })}`);
}
