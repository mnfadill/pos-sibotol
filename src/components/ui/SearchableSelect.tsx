import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Package, Search, X } from 'lucide-react';

export interface SearchableOption {
  value: number;
  label: string;
  sublabel?: string;
  badge?: string;
  searchKeywords?: string;
}

interface SearchableSelectProps {
  options: SearchableOption[];
  value: number;
  onChange: (value: number) => void;
  placeholder?: string;
  emptyText?: string;
  className?: string;
}

export const SearchableSelect: React.FC<SearchableSelectProps> = ({
  options,
  value,
  onChange,
  placeholder = 'Ketik untuk mencari atau klik untuk memilih produk...',
  emptyText = 'Produk / varian tidak ditemukan.',
  className = '',
}) => {
  const [isOpen, setIsOpen] = useState<boolean>(false);
  const [query, setQuery] = useState<string>('');
  const [highlightedIndex, setHighlightedIndex] = useState<number>(0);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);

  const selectedOption = useMemo(
    () => options.find((opt) => opt.value === value) ?? null,
    [options, value],
  );

  const filteredOptions = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter((opt) => {
      const target = `${opt.label} ${opt.sublabel ?? ''} ${opt.badge ?? ''} ${opt.searchKeywords ?? ''}`.toLowerCase();
      return target.includes(q);
    });
  }, [options, query]);

  useEffect(() => {
    setHighlightedIndex(0);
  }, [query, isOpen]);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleSelect = (opt: SearchableOption) => {
    onChange(opt.value);
    setIsOpen(false);
    setQuery('');
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (!isOpen) {
      if (e.key === 'ArrowDown' || e.key === 'Enter') {
        e.preventDefault();
        setIsOpen(true);
      }
      return;
    }

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setHighlightedIndex((prev) =>
        filteredOptions.length > 0 ? (prev + 1) % filteredOptions.length : 0,
      );
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHighlightedIndex((prev) =>
        filteredOptions.length > 0
          ? (prev - 1 + filteredOptions.length) % filteredOptions.length
          : 0,
      );
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const target = filteredOptions[highlightedIndex];
      if (target) {
        handleSelect(target);
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      setIsOpen(false);
      setQuery('');
    }
  };

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      {/* Combobox Input / Trigger */}
      <div
        onClick={() => {
          setIsOpen(true);
          setTimeout(() => inputRef.current?.focus(), 0);
        }}
        className={`w-full px-3 py-2 rounded-lg bg-white border transition flex items-center justify-between gap-2 cursor-text ${
          isOpen
            ? 'border-[#2563EB] ring-2 ring-[#2563EB]/15'
            : 'border-slate-300 hover:border-slate-400'
        }`}
      >
        <div className="flex items-center gap-2 flex-1 min-w-0">
          <Search className="w-3.5 h-3.5 text-slate-400 shrink-0" />
          {isOpen ? (
            <input
              ref={inputRef}
              type="text"
              value={query}
              onChange={(e) => {
                setQuery(e.target.value);
                if (!isOpen) setIsOpen(true);
              }}
              onKeyDown={handleKeyDown}
              placeholder={
                selectedOption
                  ? `Cari produk... (Terpilih: ${selectedOption.label})`
                  : placeholder
              }
              className="w-full bg-transparent text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:outline-none"
            />
          ) : (
            <div className="truncate text-xs font-semibold text-slate-900">
              {selectedOption ? (
                <span>
                  {selectedOption.label}
                  {selectedOption.sublabel ? (
                    <span className="text-slate-500 font-normal">
                      {' '}
                      • {selectedOption.sublabel}
                    </span>
                  ) : null}
                </span>
              ) : (
                <span className="text-slate-400 font-normal">{placeholder}</span>
              )}
            </div>
          )}
        </div>

        <div className="flex items-center gap-1 shrink-0">
          {isOpen && query && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                setQuery('');
                inputRef.current?.focus();
              }}
              className="p-0.5 rounded hover:bg-slate-100 text-slate-400 hover:text-slate-700 cursor-pointer"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          )}
          {selectedOption?.badge && !isOpen && (
            <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-blue-50 text-[#2563EB] tabular-nums">
              {selectedOption.badge}
            </span>
          )}
          <ChevronDown
            className={`w-4 h-4 text-slate-400 transition-transform ${
              isOpen ? 'rotate-180 text-[#2563EB]' : ''
            }`}
          />
        </div>
      </div>

      {/* Dropdown Options List (Auto-shows on click & filters live as user types) */}
      {isOpen && (
        <div className="absolute left-0 right-0 mt-1.5 bg-white border border-slate-200 rounded-xl shadow-xl z-40 overflow-hidden">
          <div className="px-3 py-1.5 bg-slate-50 border-b border-slate-100 flex items-center justify-between text-[10px] font-bold text-slate-500">
            <span>
              {query.trim()
                ? `Hasil pencarian "${query}" (${filteredOptions.length})`
                : `Daftar Pilihan Produk (${options.length}) — Ketik untuk mempersempit`}
            </span>
            <span>↑↓ Enter</span>
          </div>

          <div className="max-h-60 overflow-y-auto divide-y divide-slate-100 text-xs">
            {filteredOptions.length === 0 ? (
              <div className="py-6 px-4 text-center text-slate-400 space-y-1">
                <Package className="w-5 h-5 mx-auto text-slate-300" />
                <div>{emptyText}</div>
              </div>
            ) : (
              filteredOptions.map((opt, idx) => {
                const isSelected = opt.value === value;
                const isHighlighted = idx === highlightedIndex;
                return (
                  <button
                    key={opt.value}
                    type="button"
                    onMouseEnter={() => setHighlightedIndex(idx)}
                    onClick={() => handleSelect(opt)}
                    className={`w-full px-3 py-2 text-left flex items-center justify-between gap-2 transition cursor-pointer ${
                      isSelected
                        ? 'bg-blue-50/90 text-[#2563EB] font-bold'
                        : isHighlighted
                          ? 'bg-slate-50 text-slate-900'
                          : 'text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    <div className="min-w-0">
                      <div className="truncate font-semibold">{opt.label}</div>
                      {opt.sublabel && (
                        <div className="text-[11px] text-slate-500 truncate">{opt.sublabel}</div>
                      )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      {opt.badge && (
                        <span
                          className={`text-[10px] font-bold px-2 py-0.5 rounded-full tabular-nums ${
                            isSelected
                              ? 'bg-[#2563EB] text-white'
                              : 'bg-slate-100 text-slate-700'
                          }`}
                        >
                          {opt.badge}
                        </span>
                      )}
                      {isSelected && <Check className="w-3.5 h-3.5 text-[#2563EB]" />}
                    </div>
                  </button>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
};
