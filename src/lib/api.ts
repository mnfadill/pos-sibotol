import axios, { AxiosError } from 'axios';

const TOKEN_STORAGE_KEY = 'pos_sibotol_token';

export const api = axios.create({
  baseURL: '/api/v1',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY);
}

export function setStoredToken(token: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
  }
}

export function generateIdempotencyKey(prefix = 'pos'): string {
  const uuid =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  return `${prefix}-${uuid}`;
}

api.interceptors.request.use((config) => {
  const token = getStoredToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  config.headers['X-Request-Id'] = generateIdempotencyKey('req');
  return config;
});

export function extractErrorMessage(error: unknown): string {
  if (error instanceof AxiosError) {
    const data = error.response?.data as
      | { message?: string; code?: string; errors?: Record<string, string[]> }
      | undefined;

    if (data?.errors) {
      const firstError = Object.values(data.errors).flat()[0];
      if (firstError) return firstError;
    }
    if (data?.message) {
      return data.code ? `[${data.code}] ${data.message}` : data.message;
    }
    return error.message;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Terjadi kesalahan sistem yang tidak diketahui.';
}

