import React from 'react';

interface CardProps {
  children: React.ReactNode;
  className?: string;
  padding?: 'none' | 'sm' | 'md' | 'lg';
  title?: React.ReactNode;
  subtitle?: React.ReactNode;
  action?: React.ReactNode;
}

const PADDING_MAP = {
  none: '',
  sm: 'p-3.5',
  md: 'p-5',
  lg: 'p-6',
};

export const Card: React.FC<CardProps> = ({
  children,
  className = '',
  padding = 'md',
  title,
  subtitle,
  action,
}) => {
  return (
    <div
      className={`bg-white border border-slate-200 rounded-xl shadow-2xs ${PADDING_MAP[padding]} ${className}`}
    >
      {(title || subtitle || action) && (
        <div className="flex flex-wrap items-center justify-between gap-2 pb-3.5 mb-4 border-b border-slate-100">
          <div>
            {title && <h3 className="text-sm font-bold text-slate-900">{title}</h3>}
            {subtitle && <p className="text-xs text-slate-500 mt-0.5">{subtitle}</p>}
          </div>
          {action && <div>{action}</div>}
        </div>
      )}
      {children}
    </div>
  );
};

interface StatCardProps {
  label: string;
  value: React.ReactNode;
  subtext?: React.ReactNode;
  subValue?: React.ReactNode;
  icon: React.ReactNode;
  accent?: 'blue' | 'emerald' | 'amber' | 'rose' | 'violet' | 'indigo';
  action?: React.ReactNode;
}

const ACCENT_STYLES = {
  blue: 'bg-blue-50 text-blue-600 border-blue-100',
  emerald: 'bg-emerald-50 text-emerald-600 border-emerald-100',
  amber: 'bg-amber-50 text-amber-600 border-amber-100',
  rose: 'bg-rose-50 text-rose-600 border-rose-100',
  violet: 'bg-violet-50 text-violet-600 border-violet-100',
  indigo: 'bg-indigo-50 text-indigo-600 border-indigo-100',
};

export const StatCard: React.FC<StatCardProps> = ({
  label,
  value,
  subtext,
  subValue,
  icon,
  accent = 'blue',
  action,
}) => {
  const resolvedSubtext = subtext ?? subValue;
  return (
    <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs flex flex-col justify-between gap-3">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold text-slate-500">{label}</p>
          <div className="text-xl font-extrabold text-slate-900 mt-1 tabular-nums">
            {value}
          </div>
        </div>
        <div
          className={`w-10 h-10 rounded-xl border flex items-center justify-center shrink-0 ${ACCENT_STYLES[accent]}`}
        >
          {icon}
        </div>
      </div>

      {(resolvedSubtext || action) && (
        <div className="pt-2.5 border-t border-slate-100 flex items-center justify-between gap-2 text-xs text-slate-500">
          <div className="truncate">{resolvedSubtext}</div>
          {action}
        </div>
      )}
    </div>
  );
};
