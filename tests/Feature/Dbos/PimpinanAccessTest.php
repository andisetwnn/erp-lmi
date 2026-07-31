<?php

use App\Models\Master\DismissedNotif;
use App\Models\Master\PimpinanActivityLog;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectReassignmentLog;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use App\Models\Master\SalesTarget;

function makeProyekTest(string $kode = 'PRY-TEST'): Proyek
{
    return Proyek::create([
        'nama_proyek' => 'Proyek Test '.$kode,
        'nama_perumahan' => 'Perumahan Test',
        'desa' => 'Desa Test',
        'kelurahan' => 'Kelurahan Test',
        'kecamatan' => 'Kecamatan Test',
        'kota_kabupaten' => 'Bekasi',
        'kode_surat' => $kode,
        'kode_akuntansi' => 'AKT-'.$kode,
        'kode_virtual_account' => 'VA-'.$kode,
    ]);
}

function makeSalesGrupWithPimpinan(string $namaGrup, string $kodePimpinan, string $namaPimpinan): array
{
    $grup = SalesGrup::create(['nama' => $namaGrup]);
    $pimpinan = Sales::create([
        'kode' => $kodePimpinan,
        'nama' => $namaPimpinan,
        'sales_grup_id' => $grup->id,
        'is_aktif' => true,
        'dbos_username' => strtolower($kodePimpinan),
        'dbos_password' => 'rahasia123',
    ]);
    $grup->update(['pimpinan_id' => $pimpinan->id]);

    return [$grup, $pimpinan];
}

function makeAnggota(SalesGrup $grup, string $kode, string $nama): Sales
{
    return Sales::create([
        'kode' => $kode,
        'nama' => $nama,
        'sales_grup_id' => $grup->id,
        'is_aktif' => true,
        'dbos_username' => strtolower($kode),
        'dbos_password' => 'rahasia123',
    ]);
}

beforeEach(function () {
    [$this->grupA, $this->pimpinanA] = makeSalesGrupWithPimpinan('Grup A', 'PIM-A', 'Pimpinan A');
    $this->anggotaA = makeAnggota($this->grupA, 'AGT-A1', 'Anggota A1');

    [$this->grupB, $this->pimpinanB] = makeSalesGrupWithPimpinan('Grup B', 'PIM-B', 'Pimpinan B');
    $this->anggotaB = makeAnggota($this->grupB, 'AGT-B1', 'Anggota B1');

    $this->proyek = makeProyekTest('PRY1');
});

it('redirects pimpinan to pimpinan home after dbos home', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.home'));
    $response->assertRedirect(route('dbos.pimpinan.home'));
});

it('redirects sales lapangan to sales home after dbos home', function () {
    $response = $this->actingAs($this->anggotaA, 'sales')->get(route('dbos.home'));
    $response->assertRedirect(route('dbos.sales-home'));
});

it('blocks pimpinan from accessing booking create route', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.booking.create'));
    $response->assertRedirect(route('dbos.home'));
});

it('blocks pimpinan from accessing database create route', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.database.create'));
    $response->assertRedirect(route('dbos.home'));
});

it('blocks pimpinan from accessing sales home directly', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.sales-home'));
    $response->assertRedirect(route('dbos.home'));
});

it('blocks sales lapangan from accessing pimpinan home', function () {
    $response = $this->actingAs($this->anggotaA, 'sales')->get(route('dbos.pimpinan.home'));
    $response->assertRedirect(route('dbos.home'));
});

it('blocks sales lapangan from accessing pimpinan anggota index', function () {
    $response = $this->actingAs($this->anggotaA, 'sales')->get(route('dbos.pimpinan.anggota.index'));
    $response->assertRedirect(route('dbos.home'));
});

it('allows pimpinan to load pimpinan home', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.pimpinan.home'));
    $response->assertOk();
});

it('allows pimpinan to load anggota index', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.pimpinan.anggota.index'));
    $response->assertOk();
    $response->assertSee('Anggota A1');
});

it('shows only anggota of own grup in anggota index', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.pimpinan.anggota.index'));
    $response->assertOk();
    $response->assertSee('Anggota A1');
    $response->assertDontSee('Anggota B1');
});

it('returns 404 when pimpinan tries to view anggota from other grup', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')
        ->get(route('dbos.pimpinan.anggota.show', $this->anggotaB->id));
    $response->assertNotFound();
});

it('allows pimpinan to view anggota of own grup', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')
        ->get(route('dbos.pimpinan.anggota.show', $this->anggotaA->id));
    $response->assertOk();
    $response->assertSee('Anggota A1');
});

it('scopes prospect index to own grup only', function () {
    ProspectCustomer::create([
        'sales_id' => $this->anggotaA->id,
        'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Prospect Anggota A',
        'hp' => '081234567890',
        'sumber' => 'Walk-in',
    ]);
    ProspectCustomer::create([
        'sales_id' => $this->anggotaB->id,
        'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Prospect Anggota B',
        'hp' => '081234567891',
        'sumber' => 'Walk-in',
    ]);

    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.pimpinan.prospect.index'));
    $response->assertOk();
    $response->assertSee('Prospect Anggota A');
    $response->assertDontSee('Prospect Anggota B');
});

it('returns 404 when pimpinan tries to view prospect from other grup', function () {
    $prospect = ProspectCustomer::create([
        'sales_id' => $this->anggotaB->id,
        'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Prospect B',
        'hp' => '081234567891',
        'sumber' => 'Walk-in',
    ]);

    $response = $this->actingAs($this->pimpinanA, 'sales')
        ->get(route('dbos.pimpinan.prospect.show', $prospect->id));
    $response->assertNotFound();
});

it('allows pimpinan to set target for anggota in own grup', function () {
    \Livewire\Livewire::actingAs($this->pimpinanA, 'sales')
        ->test('pages::dbos.pimpinan.anggota.show', ['id' => $this->anggotaA->id])
        ->set('targetProspect', 25)
        ->set('targetBooking', 8)
        ->call('saveTarget');

    $target = SalesTarget::where('sales_id', $this->anggotaA->id)
        ->where('periode', now()->format('Y-m'))
        ->first();

    expect($target)->not->toBeNull()
        ->and($target->target_prospect)->toBe(25)
        ->and($target->target_booking)->toBe(8)
        ->and($target->set_by_sales_id)->toBe($this->pimpinanA->id);
});

it('reassigns prospect to another sales in same grup with audit log', function () {
    $anggotaA2 = makeAnggota($this->grupA, 'AGT-A2', 'Anggota A2');

    $prospect = ProspectCustomer::create([
        'sales_id' => $this->anggotaA->id,
        'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Customer A',
        'hp' => '08111111111',
        'sumber' => 'Walk-in',
    ]);

    \Livewire\Livewire::actingAs($this->pimpinanA, 'sales')
        ->test('pages::dbos.pimpinan.prospect.show', ['id' => $prospect->id])
        ->set('reassignTargetId', $anggotaA2->id)
        ->set('reassignAlasan', 'Anggota A1 sedang cuti panjang')
        ->call('confirmReassign');

    expect($prospect->fresh()->sales_id)->toBe($anggotaA2->id);

    $log = ProspectReassignmentLog::where('prospect_customer_id', $prospect->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->from_sales_id)->toBe($this->anggotaA->id)
        ->and($log->to_sales_id)->toBe($anggotaA2->id)
        ->and($log->reassigned_by_sales_id)->toBe($this->pimpinanA->id)
        ->and($log->alasan)->toBe('Anggota A1 sedang cuti panjang');
});

it('rejects reassign to sales from different grup', function () {
    $prospect = ProspectCustomer::create([
        'sales_id' => $this->anggotaA->id,
        'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Customer A',
        'hp' => '08111111111',
        'sumber' => 'Walk-in',
    ]);

    // Pimpinan A coba pindahkan ke anggota B (grup lain)
    \Livewire\Livewire::actingAs($this->pimpinanA, 'sales')
        ->test('pages::dbos.pimpinan.prospect.show', ['id' => $prospect->id])
        ->set('reassignTargetId', $this->anggotaB->id)
        ->set('reassignAlasan', 'Attempt cross-grup')
        ->call('confirmReassign');

    // Prospect tidak boleh berubah owner
    expect($prospect->fresh()->sales_id)->toBe($this->anggotaA->id);
    expect(ProspectReassignmentLog::count())->toBe(0);
});

it('logs pimpinan activity when setting target', function () {
    \Livewire\Livewire::actingAs($this->pimpinanA, 'sales')
        ->test('pages::dbos.pimpinan.anggota.show', ['id' => $this->anggotaA->id])
        ->set('targetProspect', 20)
        ->set('targetBooking', 5)
        ->call('saveTarget');

    $log = PimpinanActivityLog::where('pimpinan_sales_id', $this->pimpinanA->id)
        ->where('action', 'set_target')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->subject)->toBe('Anggota A1')
        ->and($log->meta['target_prospect'])->toBe(20)
        ->and($log->meta['target_booking'])->toBe(5);
});

it('logs pimpinan activity when reassigning prospect', function () {
    $anggotaA2 = makeAnggota($this->grupA, 'AGT-A2', 'Anggota A2');
    $prospect = ProspectCustomer::create([
        'sales_id' => $this->anggotaA->id,
        'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Customer A',
        'hp' => '08111111111',
        'sumber' => 'Walk-in',
    ]);

    \Livewire\Livewire::actingAs($this->pimpinanA, 'sales')
        ->test('pages::dbos.pimpinan.prospect.show', ['id' => $prospect->id])
        ->set('reassignTargetId', $anggotaA2->id)
        ->set('reassignAlasan', 'Test reassign')
        ->call('confirmReassign');

    $log = PimpinanActivityLog::where('pimpinan_sales_id', $this->pimpinanA->id)
        ->where('action', 'reassign_prospect')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->subject)->toBe('Customer A')
        ->and($log->meta['to'])->toBe('Anggota A2');
});

it('allows pimpinan to dismiss a notification', function () {
    \Livewire\Livewire::actingAs($this->pimpinanA, 'sales')
        ->test('pages::dbos.pimpinan.home')
        ->call('dismissNotif', 'hot-stagnan');

    expect(DismissedNotif::isDismissed($this->pimpinanA->id, 'hot-stagnan'))->toBeTrue();
});

it('allows pimpinan to access SPR group page', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.pimpinan.spr.index'));
    $response->assertOk();
});

it('allows pimpinan to access activity feed page', function () {
    $response = $this->actingAs($this->pimpinanA, 'sales')->get(route('dbos.pimpinan.activity'));
    $response->assertOk();
});

it('blocks sales lapangan from accessing pimpinan activity feed', function () {
    $response = $this->actingAs($this->anggotaA, 'sales')->get(route('dbos.pimpinan.activity'));
    $response->assertRedirect(route('dbos.home'));
});

it('records last_login_at on DBOS login', function () {
    // Anggota dengan password sebelum login
    expect($this->anggotaA->last_login_at)->toBeNull();

    \Livewire\Livewire::test('pages::dbos.login')
        ->set('dbos_username', $this->anggotaA->dbos_username)
        ->set('password', 'rahasia123')
        ->call('login');

    expect($this->anggotaA->fresh()->last_login_at)->not->toBeNull();
});
