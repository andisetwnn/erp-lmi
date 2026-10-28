<?php

use App\Models\User;
use Livewire\Livewire;

/**
 * Judul kolom tabel pengguna harus sejajar dengan isinya. Komponen
 * x-sortable-column sudah membuat <th> sendiri; kalau dibungkus <th> lagi,
 * browser memecahnya jadi dua kolom judul dan semua judul bergeser satu
 * (centang TTD tampil di bawah judul "Role").
 */
it('punya jumlah judul kolom yang sama dengan jumlah sel tiap baris', function () {
    $this->seed();
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    $html = Livewire::actingAs($admin)->test('pages::master.user')->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);
    $judul = $xpath->query('//table/thead/tr/th');
    $baris = $xpath->query('//table/tbody/tr[td]');

    expect($baris->length)->toBeGreaterThan(0);
    foreach ($baris as $tr) {
        expect($xpath->query('td', $tr)->length)->toBe($judul->length);
    }
    expect(array_map(fn ($th) => trim(preg_replace('/\s+/', ' ', $th->textContent)), iterator_to_array($judul)))
        ->toBe(['User', 'Role', 'TTD', 'Status', 'Aksi']);
});
