import React, { createContext, useCallback, useContext, useState } from 'react';
import { AlertCircle, CheckCircle2, Info, TriangleAlert, X } from 'lucide-react';

export type ToastType = 'success' | 'error' | 'warning' | 'info';

interface ToastMessage {
  id: string;
  type: ToastType;
  title: string;
  description?: string;
}

interface ToastContextValue {
  toast: (options: { type?: ToastType; title: string; description?: string }) => void;
}

const ToastContext = createContext<ToastContextValue>({
  toast: () => undefined,
});

export const useToast = () => useContext(ToastContext);

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [items, setItems] = useState<ToastMessage[]>([]);

  const removeToast = useCallback((id: string) => {
    setItems((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback(
    ({
      type = 'info',
      title,
      description,
    }: {
      type?: ToastType;
      title: string;
      description?: string;
    }) => {
      const id = `${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
      setItems((prev) => [...prev.slice(-3), { id, type, title, description }]);
      window.setTimeout(() => removeToast(id), 4200);
    },
    [removeToast],
  );

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      <div className="fixed top-4 right-4 z-[80] flex flex-col gap-2 w-full max-w-sm pointer-events-none">
        {items.map((item) => {
          const styles: Record<ToastType, { border: string; iconBg: string; icon: React.ReactNode }> = {
            success: {
              border: 'border-emerald-200 bg-white',
              iconBg: 'bg-emerald-50 text-emerald-600',
              icon: <CheckCircle2 className="w-4 h-4" />,
            },
            error: {
              border: 'border-rose-200 bg-white',
              iconBg: 'bg-rose-50 text-rose-600',
              icon: <AlertCircle className="w-4 h-4" />,
            },
            warning: {
              border: 'border-amber-200 bg-white',
              iconBg: 'bg-amber-50 text-amber-600',
              icon: <TriangleAlert className="w-4 h-4" />,
            },
            info: {
              border: 'border-blue-200 bg-white',
              iconBg: 'bg-blue-50 text-blue-600',
              icon: <Info className="w-4 h-4" />,
            },
          };

          const st = styles[item.type];

          return (
            <div
              key={item.id}
              className={`pointer-events-auto flex items-start gap-3 p-3.5 rounded-xl border shadow-lg ${st.border} transition-all`}
            >
              <div
                className={`w-7 h-7 rounded-lg flex items-center justify-center shrink-0 ${st.iconBg}`}
              >
                {st.icon}
              </div>
              <div className="flex-1 min-w-0">
                <div className="text-xs font-bold text-slate-900">{item.title}</div>
                {item.description && (
                  <div className="text-[11px] text-slate-600 mt-0.5 leading-relaxed">
                    {item.description}
                  </div>
                )}
              </div>
              <button
                type="button"
                onClick={() => removeToast(item.id)}
                className="p-1 rounded text-slate-400 hover:text-slate-700 cursor-pointer"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            </div>
          );
        })}
      </div>
    </ToastContext.Provider>
  );
};

