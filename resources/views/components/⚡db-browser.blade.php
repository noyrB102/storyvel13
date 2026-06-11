<?php

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination;

    public string $selectedTable = '';
    public int $perPage = 50;
    public string $search = '';
    public string $sortCol = '';
    public string $sortDir = 'asc';

    public bool $showEdit = false;
    public array $editRow = [];
    public mixed $editPk = null;

    public function mount(): void
    {
        $tables = $this->tableList();
        if ($tables) {
            $this->selectedTable = $tables[0];
        }
    }

    public function tableList(): array
    {
        return collect(DB::select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ))->pluck('name')->toArray();
    }

    public function tableColumns(): array
    {
        if (! $this->selectedTable) return [];
        return collect(DB::select("PRAGMA table_info(\"{$this->selectedTable}\")"))
            ->map(fn ($c) => [
                'name' => $c->name,
                'type' => strtoupper($c->type ?? 'TEXT'),
                'pk'   => (bool) $c->pk,
            ])
            ->toArray();
    }

    public function pkColumn(): string
    {
        $pk = collect($this->tableColumns())->firstWhere('pk', true);
        return $pk ? $pk['name'] : 'id';
    }

    public function tableRowCount(string $table): int
    {
        return DB::table($table)->count();
    }

    public function selectTable(string $table): void
    {
        $this->selectedTable = $table;
        $this->search = '';
        $this->sortCol = '';
        $this->resetPage();
    }

    public function sortBy(string $col): void
    {
        if ($this->sortCol === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortCol = $col;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function openEdit(mixed $pk): void
    {
        $row = DB::table($this->selectedTable)->where($this->pkColumn(), $pk)->first();
        $this->editRow = (array) $row;
        $this->editPk = $pk;
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $pkCol = $this->pkColumn();
        $data  = collect($this->editRow)->except($pkCol)->toArray();
        DB::table($this->selectedTable)->where($pkCol, $this->editPk)->update($data);
        $this->showEdit = false;
        $this->editRow  = [];
        $this->editPk   = null;
    }

    public function deleteRow(mixed $pk): void
    {
        DB::table($this->selectedTable)->where($this->pkColumn(), $pk)->delete();
    }

    public function with(): array
    {
        $columns = $this->tableColumns();
        $pkCol   = $this->pkColumn();
        $query   = DB::table($this->selectedTable);

        if ($this->search && $this->selectedTable) {
            $s = $this->search;
            $query->where(function ($q) use ($s, $columns) {
                foreach ($columns as $col) {
                    $q->orWhereRaw("CAST(\"{$col['name']}\" AS TEXT) LIKE ?", ["%{$s}%"]);
                }
            });
        }

        $query = $this->sortCol
            ? $query->orderBy($this->sortCol, $this->sortDir)
            : $query->orderByDesc($pkCol);

        return [
            'tables'  => $this->tableList(),
            'columns' => $columns,
            'rows'    => $this->selectedTable ? $query->paginate($this->perPage) : null,
            'pkCol'   => $pkCol,
        ];
    }
}

?>

<div class="flex h-[calc(100vh-57px)] overflow-hidden bg-gray-50 dark:bg-zinc-950 font-mono text-xs">

    {{-- ─── Left Sidebar: Tables ─── --}}
    <aside class="flex w-52 shrink-0 flex-col border-r border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-gray-200 px-3 py-2.5 dark:border-zinc-700">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Tables</span>
        </div>
        <nav class="flex-1 overflow-y-auto py-1">
            @foreach ($tables as $t)
                <button
                    wire:click="selectTable('{{ $t }}')"
                    class="flex w-full items-center justify-between px-3 py-1.5 text-left transition-colors
                        {{ $selectedTable === $t
                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                            : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-zinc-800' }}"
                >
                    <span class="truncate">{{ $t }}</span>
                    <span class="ml-1 shrink-0 text-[10px] text-gray-400">{{ $this->tableRowCount($t) }}</span>
                </button>
            @endforeach
        </nav>
    </aside>

    {{-- ─── Main Panel ─── --}}
    <div class="flex flex-1 flex-col overflow-hidden">

        {{-- Toolbar --}}
        <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $selectedTable }}</span>
            <span class="text-gray-400">·</span>
            <span class="text-gray-400">{{ $rows?->total() ?? 0 }} rows</span>

            <div class="ml-auto flex items-center gap-2">
                {{-- Search --}}
                <div class="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2 top-1/2 size-3 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.197 5.197a7.5 7.5 0 0 0 10.606 10.606Z" />
                    </svg>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search…"
                        class="h-7 rounded-md border border-gray-200 bg-gray-50 pl-6 pr-3 text-xs text-gray-700 placeholder-gray-400 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 w-48"
                    />
                </div>
                {{-- Per page --}}
                <select
                    wire:model.live="perPage"
                    class="h-7 rounded-md border border-gray-200 bg-gray-50 px-2 text-xs text-gray-600 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
                >
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>
        </div>

        {{-- Data Grid --}}
        <div class="flex-1 overflow-auto">
            @if ($rows && $rows->count() > 0)
                <table class="w-max min-w-full border-collapse">
                    <thead class="sticky top-0 z-10 bg-gray-100 dark:bg-zinc-800">
                        <tr>
                            @foreach ($columns as $col)
                                <th
                                    wire:click="sortBy('{{ $col['name'] }}')"
                                    class="cursor-pointer border-b border-r border-gray-200 px-3 py-1.5 text-left text-[11px] font-semibold text-gray-600 hover:bg-gray-200 select-none dark:border-zinc-700 dark:text-gray-400 dark:hover:bg-zinc-700 whitespace-nowrap"
                                >
                                    <span class="flex items-center gap-1">
                                        {{ $col['name'] }}
                                        <span class="text-[9px] text-gray-400 dark:text-zinc-500">{{ $col['type'] }}</span>
                                        @if ($sortCol === $col['name'])
                                            <svg xmlns="http://www.w3.org/2000/svg" class="size-2.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                                                @if ($sortDir === 'asc')
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" />
                                                @else
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" />
                                                @endif
                                            </svg>
                                        @endif
                                    </span>
                                </th>
                            @endforeach
                            <th class="sticky right-0 border-b border-l border-gray-200 bg-gray-100 px-3 py-1.5 text-[11px] font-semibold text-gray-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-500">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            <tr class="group {{ $i % 2 === 0 ? 'bg-white dark:bg-zinc-900' : 'bg-gray-50 dark:bg-zinc-850' }} hover:bg-blue-50 dark:hover:bg-blue-900/10">
                                @foreach ($columns as $col)
                                    @php $val = $row->{$col['name']} ?? null; @endphp
                                    <td class="border-b border-r border-gray-100 px-3 py-1 text-gray-700 dark:border-zinc-800 dark:text-gray-300 whitespace-nowrap max-w-xs truncate"
                                        title="{{ is_string($val) && strlen($val) > 80 ? $val : '' }}">
                                        @if (is_null($val))
                                            <span class="italic text-gray-300 dark:text-zinc-600">NULL</span>
                                        @else
                                            {{ Str::limit((string)$val, 80) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="sticky right-0 border-b border-l border-gray-100 bg-white px-2 py-1 dark:border-zinc-800 dark:bg-zinc-900 group-hover:bg-blue-50 dark:group-hover:bg-blue-900/10">
                                    <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100">
                                        <button
                                            wire:click="openEdit({{ $row->$pkCol }})"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-900/30"
                                        >Edit</button>
                                        <button
                                            wire:click="deleteRow({{ $row->$pkCol }})"
                                            wire:confirm="Delete this row?"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                                        >Del</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif ($selectedTable)
                <div class="flex h-full items-center justify-center text-gray-400">No rows found.</div>
            @endif
        </div>

        {{-- Pagination --}}
        @if ($rows && $rows->hasPages())
            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="text-[11px] text-gray-400">
                    {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}
                </span>
                <div class="flex items-center gap-1">
                    @if ($rows->onFirstPage())
                        <span class="rounded px-2 py-1 text-gray-300 dark:text-zinc-600">← Prev</span>
                    @else
                        <button wire:click="previousPage" class="rounded px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-zinc-800">← Prev</button>
                    @endif
                    <span class="px-2 text-gray-500">Page {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                    @if ($rows->hasMorePages())
                        <button wire:click="nextPage" class="rounded px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-zinc-800">Next →</button>
                    @else
                        <span class="rounded px-2 py-1 text-gray-300 dark:text-zinc-600">Next →</span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- ─── Edit Modal ─── --}}
    @if ($showEdit)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            wire:keydown.escape="$set('showEdit', false)"
        >
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        Edit row — <span class="font-normal text-gray-500">{{ $selectedTable }}</span>
                    </h2>
                    <button wire:click="$set('showEdit', false)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
                    @foreach ($editRow as $key => $value)
                        @php $isPk = $key === $pkCol; $isLong = is_string($value) && strlen($value) > 80; @endphp
                        <div>
                            <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ $key }}
                                @if ($isPk)
                                    <span class="rounded bg-amber-50 px-1 text-[10px] text-amber-600 dark:bg-amber-900/20 dark:text-amber-400">PK</span>
                                @endif
                            </label>
                            @if ($isPk)
                                <input
                                    type="text"
                                    value="{{ $value }}"
                                    disabled
                                    class="w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-1.5 font-mono text-xs text-gray-400 dark:border-zinc-600 dark:bg-zinc-700"
                                />
                            @elseif ($isLong)
                                <textarea
                                    wire:model="editRow.{{ $key }}"
                                    rows="4"
                                    class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 font-mono text-xs text-gray-800 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                                >{{ $value }}</textarea>
                            @else
                                <input
                                    type="text"
                                    wire:model="editRow.{{ $key }}"
                                    class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 font-mono text-xs text-gray-800 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3 dark:border-zinc-700">
                    <button
                        wire:click="$set('showEdit', false)"
                        class="rounded-lg border border-gray-200 px-4 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-zinc-600 dark:text-gray-300"
                    >Cancel</button>
                    <button
                        wire:click="saveEdit"
                        class="rounded-lg bg-blue-500 px-4 py-1.5 text-xs font-medium text-white hover:bg-blue-600"
                    >Save changes</button>
                </div>
            </div>
        </div>
    @endif

</div>
