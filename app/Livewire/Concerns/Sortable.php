<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Livewire\Attributes\Url;

/**
 * Drop-in column-sort trait untuk Livewire/Volt component.
 *
 * Pakai:
 *   1. `use App\Livewire\Concerns\Sortable;` di kelas component.
 *   2. Optional: override defaultSortBy() / defaultSortDir() untuk default sort.
 *   3. Di query: `$this->applySort($query, ['kode', 'nama', 'created_at']);`
 *   4. Di template: `<x-sortable-column field="kode" :sort-by="$sortBy" :sort-dir="$sortDir">Kode</x-sortable-column>`.
 *
 * Catatan: pakai method (bukan property) untuk default supaya gak konflik dengan
 * trait composition di anonymous Volt class (PHP error: incompatible property).
 */
trait Sortable
{
    #[Url(as: 'sort', except: '')]
    public string $sortBy = '';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDir = 'asc';

    /** Override di component kalau mau set default sort column. */
    protected function defaultSortBy(): ?string
    {
        return null;
    }

    /** Override di component kalau mau default direction. */
    protected function defaultSortDir(): string
    {
        return 'asc';
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * Apply order by ke query. $allowed adalah whitelist kolom yang aman di-sort
     * (cegah SQL injection lewat URL param). Kolom dengan custom closure:
     *   ['kode', 'nama', 'special' => fn($q, $dir) => $q->orderBy('...', $dir)]
     */
    public function applySort(Builder $query, array $allowed): Builder
    {
        $col = $this->sortBy ?: $this->defaultSortBy();
        $dir = $this->sortBy ? $this->sortDir : $this->defaultSortDir();

        if (! $col) {
            return $query;
        }

        // Kolom dengan custom closure handler
        if (isset($allowed[$col]) && is_callable($allowed[$col])) {
            return $allowed[$col]($query, $dir);
        }

        // Simple kolom whitelist
        if (in_array($col, $allowed, true)) {
            return $query->orderBy($col, $dir);
        }

        return $query;
    }
}
