import { MagnifyingGlass as Search } from '@phosphor-icons/react'
import type { ReactNode } from 'react'
import {
  useReactTable,
  getCoreRowModel,
  flexRender,
  type ColumnDef,
  type PaginationState,
  type OnChangeFn,
} from '@tanstack/react-table'
import { Button } from './ui/button'

interface DataTableProps<TData> {
  data: TData[]
  columns: ColumnDef<TData, unknown>[]
  pageCount: number
  pagination: PaginationState
  onPaginationChange: OnChangeFn<PaginationState>
  isLoading?: boolean
  total?: number
  emptyIcon?: ReactNode
  emptyLabel?: string
}

export function DataTable<TData>({
  data,
  columns,
  pageCount,
  pagination,
  onPaginationChange,
  isLoading = false,
  total,
  emptyIcon,
  emptyLabel = 'Sin resultados',
}: DataTableProps<TData>) {
  const table = useReactTable({
    data,
    columns,
    pageCount,
    state: { pagination },
    onPaginationChange,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
  })

  return (
    <div className="flex flex-col gap-3">
      <div
        className="relative overflow-hidden rounded-[18px] border border-gray-200 bg-white shadow-sm
          dark:border-[rgba(255,255,255,0.08)] dark:bg-[#18181b]
          dark:shadow-[0_4px_24px_-4px_rgba(0,0,0,0.55)]"
      >
        {/* hairline de acento superior */}
        <div
          className="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-kyro-indigo/60 to-transparent"
          data-accent="indigo"
        />
        <div className="overflow-x-auto">
          <table className="w-full border-separate border-spacing-0 text-sm tabular-nums">
            <thead>
              {table.getHeaderGroups().map((headerGroup) => (
                <tr key={headerGroup.id}>
                  {headerGroup.headers.map((header) => (
                    <th
                      key={header.id}
                      className="sticky top-0 z-10 px-4 py-3 text-left text-xs font-semibold uppercase
                        tracking-[0.08em] text-gray-500 bg-gray-50/95 backdrop-blur border-b border-gray-200
                        dark:text-zinc-400 dark:bg-[rgba(39,39,42,0.6)] dark:border-[rgba(255,255,255,0.07)]"
                    >
                      {header.isPlaceholder
                        ? null
                        : flexRender(header.column.columnDef.header, header.getContext())}
                    </th>
                  ))}
                </tr>
              ))}
            </thead>
            <tbody>
              {isLoading ? (
                <tr>
                  <td colSpan={columns.length} className="px-4 py-12 text-center text-gray-400 dark:text-zinc-500">
                    <span className="inline-flex items-center gap-2">
                      <span className="w-3 h-3 rounded-full border-2 border-current border-t-transparent animate-spin" />
                      Cargando…
                    </span>
                  </td>
                </tr>
              ) : table.getRowModel().rows.length === 0 ? (
                <tr>
                  <td colSpan={columns.length} className="px-4 py-12 text-center text-gray-400 dark:text-zinc-500">
                    <span className="inline-flex flex-col items-center gap-2">
                      <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/[0.04] dark:text-zinc-500">
                        {emptyIcon ?? <Search size={16} />}
                      </span>
                      {emptyLabel}
                    </span>
                  </td>
                </tr>
              ) : (
                table.getRowModel().rows.map((row) => (
                  <tr
                    key={row.id}
                    className="group transition-colors hover:bg-kyro-indigo/[0.04]"
                  >
                    {row.getVisibleCells().map((cell, ci) => (
                      <td
                        key={cell.id}
                        className="px-4 py-3 text-gray-700 border-b border-gray-100 align-middle
                          dark:text-zinc-300 dark:border-[rgba(255,255,255,0.045)]
                          group-hover:border-kyro-indigo/10
                          relative"
                      >
                        {ci === 0 && (
                          <span
                            aria-hidden
                            className="absolute left-0 top-0 bottom-0 w-[2px] origin-center scale-y-0 bg-kyro-indigo
                              transition-transform group-hover:scale-y-100"
                            data-accent="indigo"
                          />
                        )}
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex items-center justify-between text-sm text-gray-500 dark:text-zinc-400">
        <span>
          {total !== undefined && (
            <>
              <span className="font-semibold text-gray-700 dark:text-zinc-200">{total}</span>{' '}
              resultado{total !== 1 ? 's' : ''}
            </>
          )}
        </span>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => table.previousPage()}
            disabled={!table.getCanPreviousPage()}
          >
            Anterior
          </Button>
          <span className="text-xs tabular-nums">
            Página {pagination.pageIndex + 1} de {pageCount || 1}
          </span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => table.nextPage()}
            disabled={!table.getCanNextPage()}
          >
            Siguiente
          </Button>
        </div>
      </div>
    </div>
  )
}
