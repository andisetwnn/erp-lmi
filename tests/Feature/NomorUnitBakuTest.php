<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Nomor unit: satu bentuk saat disimpan, satu bentuk saat ditampilkan.
 *
 * Disimpan tanpa nol di depan, ditampilkan selalu dua digit lewat `kode_unit`.
 * Pemisahan ini penting karena kolomnya teks: tanpa penyeragaman saat menulis,
 * "08" dan "8" lolos sebagai dua baris berbeda dari unique(proyek, blok, nomor)
 * dan satu kavling fisik punya dua record — yang baru ketahuan setelah ada dua
 * SPR untuk rumah yang sama.
 *
 * Bahayanya nyata: sampai perbaikan ini, Master Rumah menyimpan apa adanya
 * sementara importer SOP dan importer stok kavling menyimpan versi berpadding.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();
});

function unitBaku(string $blok, string $nomor): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id,
        'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok,
        'nomor_unit' => $nomor,
        'status' => 'available',
    ]);
}

it('membuang nol di depan saat menyimpan', function () {
    expect(unitBaku('NA', '08')->nomor_unit)->toBe('8')
        ->and(unitBaku('NB', '008')->nomor_unit)->toBe('8')
        ->and(unitBaku('NC', '12')->nomor_unit)->toBe('12')
        ->and(unitBaku('ND', '7')->nomor_unit)->toBe('7');
});

it('menampilkan selalu dua digit', function () {
    expect(unitBaku('NE', '8')->kode_unit)->toBe('NE-08')
        ->and(unitBaku('NF', '08')->kode_unit)->toBe('NF-08')
        ->and(unitBaku('NG', '12')->kode_unit)->toBe('NG-12')
        ->and(unitBaku('NH', '103')->kode_unit)->toBe('NH-103');
});

it('membiarkan nomor yang bukan angka apa adanya', function () {
    // Mis. "4A" untuk kavling yang dibelah — jangan dirusak penyeragaman angka.
    expect(unitBaku('NI', '4A')->nomor_unit)->toBe('4A')
        ->and(Rumah::normalNomorUnit('4A'))->toBe('4A');
});

it('tidak menghilangkan nomor nol', function () {
    // ltrim('0', '0') menghasilkan string kosong — nomornya jangan sampai lenyap.
    expect(Rumah::normalNomorUnit('0'))->toBe('0')
        ->and(Rumah::normalNomorUnit('00'))->toBe('0');
});

it('menolak unit kembar yang hanya beda nol di depan', function () {
    // Inti seluruh perubahan ini: "AB-8" dan "AB-08" kavling yang sama.
    unitBaku('NJ', '8');

    expect(fn () => unitBaku('NJ', '08'))
        ->toThrow(QueryException::class);
});

it('menyeragamkan lewat importer juga, bukan cuma lewat layar', function () {
    // Importer SOP dulu menyimpan versi berpadding. Penyeragamannya sekarang di
    // model, jadi jalur mana pun menghasilkan bentuk yang sama.
    $lewatModel = Rumah::create([
        'proyek_id' => $this->proyek->id, 'tipe_rumah_id' => $this->tipe->id,
        'blok' => 'NK', 'nomor_unit' => '04', 'status' => 'available',
    ]);

    expect($lewatModel->fresh()->nomor_unit)->toBe('4');
});

it('tidak menyisakan label unit yang dirakit tangan di kode', function () {
    // Label yang disusun sendiri dari blok + nomor_unit melewati `kode_unit`,
    // jadi nolnya hilang dan satu layar bisa menampilkan dua gaya sekaligus.
    //
    // Yang dikecualikan merangkai blok+nomor untuk MEMBANDINGKAN, bukan untuk
    // ditampilkan — di sana bentuknya memang tidak boleh ikut dipadatkan.
    $kecuali = [
        'app/Models/Master/Rumah.php',                    // accessor-nya sendiri
        'app/Console/Commands/Master/ImportStockKavling.php',
        'app/Services/MatrixSinkronPemberkasan.php',
        'app/Services/MatrixSinkronTeknik.php',
        'app/Models/Matrix/MatrixUnit.php',
        'resources/views/pages/master/rumah.blade.php',   // pratinjau buat-massal, padding sendiri
        // Pemetaan siteplan menyusun labelnya di sisi peramban dari JSON,
        // jadi accessor PHP tidak sampai ke sana. Lihat catatan di tes bawah.
        'resources/views/pages/master/proyek-siteplan-mapping.blade.php',
    ];

    $pelanggar = [];

    foreach ([app_path(), resource_path('views')] as $akar) {
        $berkas = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar));

        foreach ($berkas as $f) {
            if ($f->isDir() || ! str_ends_with($f->getFilename(), '.php')) {
                continue;
            }

            $relatif = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));

            if (in_array($relatif, $kecuali, true)) {
                continue;
            }

            foreach (file($f->getPathname()) as $i => $baris) {
                // CONCAT di SQL dilewati: itu kunci pencarian, bukan tampilan —
                // dan padding di sana butuh fungsi yang berbeda antara MySQL
                // dan SQLite, sementara suite tes berjalan di SQLite.
                if (str_contains($baris, 'CONCAT(')) {
                    continue;
                }

                // Hanya perangkaian untuk ditampilkan: ada tanda hubung di antaranya.
                if (preg_match('/blok[^\n]{0,30}[\'"}]-[\'"{$][^\n]{0,30}nomor_unit/', $baris)) {
                    $pelanggar[] = $relatif.':'.($i + 1);
                }
            }
        }
    }

    expect($pelanggar)->toBe([], "Pakai \$rumah->kode_unit, jangan rakit sendiri:\n".implode("\n", $pelanggar));
});

it('menyesuaikan kata kunci pencarian ke bentuk simpanannya', function () {
    // Yang terbaca di layar "AF-08"; yang tersimpan "AF-8". Mengetik persis
    // seperti yang tertera harus tetap ketemu.
    expect(Rumah::normalCari('AF-08'))->toBe('AF-8')
        ->and(Rumah::normalCari('08'))->toBe('8')
        ->and(Rumah::normalCari('007'))->toBe('7')
        // Sebagian pemanggil sudah membungkusnya dengan jokaritas lebih dulu.
        ->and(Rumah::normalCari('%08%'))->toBe('%8%')
        ->and(Rumah::normalCari('%AF-08%'))->toBe('%AF-8%');
});

it('tidak mengubah kata kunci yang memang tidak berawalan nol', function () {
    expect(Rumah::normalCari('AF-8'))->toBe('AF-8')
        ->and(Rumah::normalCari('100'))->toBe('100')
        ->and(Rumah::normalCari('0'))->toBe('0')
        ->and(Rumah::normalCari('AB-0'))->toBe('AB-0')
        // Nomor SPR memuat nol yang bermakna — jangan sampai terpotong kalau
        // fungsi ini keliru dipasang ke seluruh kondisi pencarian.
        ->and(Rumah::normalCari('SPR/2026/07/0190'))->toBe('SPR/2026/07/0190');
});

it('menemukan unit lewat kode yang tertera di layar', function () {
    // Tes ujung-ke-ujung dari keluhan aslinya: layar menulis "NL-08",
    // basis data menyimpan "8".
    $unit = unitBaku('NL', '8');

    expect($unit->kode_unit)->toBe('NL-08');

    $ketemu = Rumah::where('blok', 'NL')
        ->where('nomor_unit', 'like', Rumah::normalCari('08'))
        ->first();

    expect($ketemu?->id)->toBe($unit->id);
});
