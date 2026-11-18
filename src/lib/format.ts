export function formatIDR(value: string | number | null | undefined): string {
  const num = typeof value === 'string' ? parseFloat(value) : Number(value ?? 0);
  if (Number.isNaN(num)) return 'Rp 0';
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(num);
}

export function formatQty(value: string | number | null | undefined): string {
  const num = typeof value === 'string' ? parseFloat(value) : Number(value ?? 0);
  if (Number.isNaN(num)) return '0';
  return Number.isInteger(num) ? num.toString() : num.toFixed(2);
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '-';
  try {
    const date = new Date(iso);
    return new Intl.DateTimeFormat('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }).format(date);
  } catch {
    return iso;
  }
}

export interface DiffEntry {
  key: string;
  status: 'added' | 'removed' | 'changed' | 'unchanged';
  oldValue: unknown;
  newValue: unknown;
}

export function computePayloadDiff(
  oldValues: Record<string, unknown> | null | undefined,
  newValues: Record<string, unknown> | null | undefined,
): DiffEntry[] {
  const oldObj = oldValues ?? {};
  const newObj = newValues ?? {};
  const keys = Array.from(new Set([...Object.keys(oldObj), ...Object.keys(newObj)])).sort();

  return keys.map((key) => {
    const hasOld = Object.prototype.hasOwnProperty.call(oldObj, key);
    const hasNew = Object.prototype.hasOwnProperty.call(newObj, key);
    const oldVal = oldObj[key];
    const newVal = newObj[key];

    if (!hasOld && hasNew) {
      return { key, status: 'added', oldValue: undefined, newValue: newVal };
    }
    if (hasOld && !hasNew) {
      return { key, status: 'removed', oldValue: oldVal, newValue: undefined };
    }
    const isSame = JSON.stringify(oldVal) === JSON.stringify(newVal);
    return {
      key,
      status: isSame ? 'unchanged' : 'changed',
      oldValue: oldVal,
      newValue: newVal,
    };
  });
}

