<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
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

/**
 * Isian role menampilkan nama yang terbaca ("Admin Sales", bukan "admin-sales"),
 * dan pilihan kosongnya tidak dobel (placeholder + opsi kosong sekaligus).
 */
it('menampilkan nama role yang terbaca dan satu pilihan kosong di tiap isian role', function () {
    $this->seed();
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    $html = Livewire::actingAs($admin)->test('pages::master.user')->call('openCreate')->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $opsi = array_map(fn ($o) => trim($o->textContent), iterator_to_array((new DOMXPath($dom))->query('//select/option')));
    $jumlah = array_count_values($opsi);

    expect($jumlah['— Pilih Role —'] ?? 0)->toBe(1)
        ->and($jumlah['Semua Role'] ?? 0)->toBe(1)
        ->and($opsi)->toContain('Admin Sales', 'Admin Teknik', 'Admin Legal');
    foreach (array_keys(RolePermissionSeeder::matriksRole()) as $namaTeknis) {
        expect($opsi)->not->toContain($namaTeknis);
    }
});
