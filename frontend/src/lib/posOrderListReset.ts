const POS_ORDER_LIST_RESET_KEY = 'pos-order-list-reset-at';
const POS_ORDER_LIST_RESET_EVENT = 'pos-order-list-reset-changed';

function getTodaySixAm(referenceDate: Date) {
  const sixAm = new Date(referenceDate);
  sixAm.setHours(6, 0, 0, 0);
  return sixAm;
}

function getLatestScheduledReset(referenceDate = new Date()) {
  const todaySixAm = getTodaySixAm(referenceDate);

  if (referenceDate >= todaySixAm) {
    return todaySixAm;
  }

  const previousSixAm = new Date(todaySixAm);
  previousSixAm.setDate(previousSixAm.getDate() - 1);
  return previousSixAm;
}

function readStoredResetAt() {
  if (typeof window === 'undefined') return null;

  const rawValue = window.localStorage.getItem(POS_ORDER_LIST_RESET_KEY);
  if (!rawValue) return null;

  const parsedDate = new Date(rawValue);
  return Number.isNaN(parsedDate.getTime()) ? null : parsedDate;
}

function persistResetAt(resetAt: Date) {
  if (typeof window === 'undefined') return resetAt;

  const isoValue = resetAt.toISOString();
  window.localStorage.setItem(POS_ORDER_LIST_RESET_KEY, isoValue);
  window.dispatchEvent(new CustomEvent(POS_ORDER_LIST_RESET_EVENT, { detail: isoValue }));
  return resetAt;
}

export function ensurePosOrderListResetAt(referenceDate = new Date()) {
  const scheduledReset = getLatestScheduledReset(referenceDate);
  const storedReset = readStoredResetAt();

  if (!storedReset || storedReset < scheduledReset) {
    return persistResetAt(scheduledReset);
  }

  return storedReset;
}

export function getPosOrderListResetAt() {
  return ensurePosOrderListResetAt();
}

export function resetPosOrderListNow(referenceDate = new Date()) {
  return persistResetAt(referenceDate);
}

export function isOrderVisibleAfterReset(orderDateValue: string, resetAt: Date) {
  const orderDate = new Date(orderDateValue);
  if (Number.isNaN(orderDate.getTime())) return true;
  return orderDate >= resetAt;
}

export function getPosOrderListResetEventName() {
  return POS_ORDER_LIST_RESET_EVENT;
}
