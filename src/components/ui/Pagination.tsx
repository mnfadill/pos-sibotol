import React from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from './Button';

interface PaginationProps {
  currentPage: number;
  lastPage: number;
  total: number;
  perPage?: number;
  onPageChange: (page: number) => void;
}

export const Pagination: React.FC<PaginationProps> = ({
  currentPage,
  lastPage,
  total,
  onPageChange,
}) => {
  if (total === 0) return null;

  return (
    <div className="px-4 py-3 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600">
      <div>
        Menampilkan halaman <span className="font-bold text-slate-900">{currentPage}</span> dari{' '}
        <span className="font-bold text-slate-900">{lastPage}</span> &bull; Total{' '}
        <span className="font-bold text-slate-900">{total}</span> data
      </div>

      <div className="flex items-center gap-1.5">
        <Button
          variant="outline"
          size="xs"
          disabled={currentPage <= 1}
          onClick={() => onPageChange(currentPage - 1)}
          icon={<ChevronLeft className="w-3.5 h-3.5" />}
        >
          Sebelumnya
        </Button>

        <Button
          variant="outline"
          size="xs"
          disabled={currentPage >= lastPage}
          onClick={() => onPageChange(currentPage + 1)}
        >
          Selanjutnya
          <ChevronRight className="w-3.5 h-3.5" />
        </Button>
      </div>
    </div>
  );
};

