<?php

namespace Database\Seeders;

use App\Models\Master\Bank;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\Master\TempatKerja;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;

class ProspectCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $proyek = Proyek::where('kode_surat', 'GA')->first();
        if (! $proyek) {
            return;
        }

        // Sales pemilik prospect (Tim Alpha)
        $budi = Sales::where('kode', 'SLS-001')->first();
        $siti = Sales::where('kode', 'SLS-002')->first();
        $andi = Sales::where('kode', 'SLS-003')->first();
        if (! $budi) {
            return;
        }

        // Tempat kerja & Bank lookup by name (sudah di-seed sebelumnya)
        $tkBank = TempatKerja::where('nama', 'PT Bank Mandiri Tbk')->first();
        $tkTelkom = TempatKerja::where('nama', 'PT Telkom Indonesia')->first();
        $tkPertamina = TempatKerja::where('nama', 'PT Pertamina')->first();
        $tkAstra = TempatKerja::where('nama', 'PT Astra Honda Motor')->first();
        $tkDinas = TempatKerja::where('nama', 'Dinas Pendidikan Kota Depok')->first();

        $bcaBank = Bank::where('nama', 'like', '%BCA%')->first();
        $bniBank = Bank::where('nama', 'like', '%BNI%')->first();
        $briBank = Bank::where('nama', 'like', '%BRI%')->first();
        $mandiriBank = Bank::where('nama', 'like', '%Mandiri%')->first();

        // Alamat default (Jawa Barat → Depok → Cilodong → Sukamaju)
        $jabar = Province::where('name', 'like', '%JAWA BARAT%')->first();
        $depok = $jabar ? City::where('province_code', $jabar->code)->where('name', 'like', '%DEPOK%')->first() : null;
        $cilodong = $depok ? District::where('city_code', $depok->code)->where('name', 'like', '%CILODONG%')->first() : null;
        $sukamaju = $cilodong ? Village::where('district_code', $cilodong->code)->where('name', 'like', '%SUKAMAJU%')->first() : null;

        $data = [
            // ===== COLD (baru lead, data minimal) =====
            ['nama' => 'Anita Permata Sari',  'hp' => '6285740907321', 'sumber' => 'Kantor',             'status' => 'cold', 'days' => 1, 'alamat' => false],
            ['nama' => 'Diana Wahyuni',       'hp' => '6289647641848', 'sumber' => 'Referral',           'status' => 'cold', 'days' => 1, 'alamat' => false],
            ['nama' => 'Leo Saputra',         'hp' => '6283860481780', 'sumber' => 'Instagram',          'status' => 'cold', 'days' => 1, 'alamat' => false],
            ['nama' => 'Dewi Lestari',        'hp' => '6285900429874', 'sumber' => 'TikTok',             'status' => 'cold', 'days' => 2, 'alamat' => true],
            ['nama' => 'Jawa Sari',           'hp' => '6289731897041', 'sumber' => 'WhatsApp Broadcast', 'status' => 'cold', 'days' => 2, 'alamat' => false],
            ['nama' => 'Suwarto',             'hp' => '6282245973775', 'sumber' => 'Kantor',             'status' => 'cold', 'days' => 3, 'alamat' => false],

            // ===== WARM (sudah follow up, data sebagian) =====
            ['nama' => 'Citra Prabandini',    'hp' => '6283842714943', 'sumber' => 'Pameran',            'status' => 'warm', 'days' => 5, 'alamat' => true,  'sales' => $siti, 'tempat_kerja' => $tkTelkom],
            ['nama' => 'Putri Anistia',       'hp' => '6288233095700', 'sumber' => 'Brosur',             'status' => 'warm', 'days' => 6, 'alamat' => false, 'sales' => $siti],
            ['nama' => 'Hardianto',           'hp' => '6288220170151', 'sumber' => 'Walk-in',            'status' => 'warm', 'days' => 7, 'alamat' => true],
            ['nama' => 'Maya Susanti',        'hp' => '6281234567001', 'sumber' => 'Iklan Online',       'status' => 'warm', 'days' => 4, 'alamat' => false],

            // ===== HOT — DATA HAMPIR LENGKAP TAPI BELUM (untuk test "belum bisa FINISH") =====
            // Bambang: punya NIK + foto + alamat + BI, TAPI belum NPWP + perusahaan + rekening + kontak darurat
            ['nama' => 'Bambang Setiawan', 'hp' => '6281234567002', 'sumber' => 'Referral',  'status' => 'hot', 'days' => 8,  'alamat' => true,
                'nik' => '3275010101800001', 'foto_ktp' => true, 'bi' => true],

            // Rina: punya NIK + foto + alamat + BI + perusahaan, TAPI belum NPWP + rekening + kontak darurat
            ['nama' => 'Rina Marlina', 'hp' => '6281234567003', 'sumber' => 'Instagram', 'status' => 'hot', 'days' => 10, 'alamat' => true,
                'sales' => $andi, 'nik' => '3275020202820002', 'foto_ktp' => true, 'bi' => true, 'tempat_kerja' => $tkPertamina],

            // Hendra: data minimal, baru status HOT (uji yang masih banyak missing)
            ['nama' => 'Hendra Gunawan', 'hp' => '6281234567004', 'sumber' => 'Pameran', 'status' => 'hot', 'days' => 9, 'alamat' => true],

            // ===== FINISH — DATA LENGKAP, SIAP DI-BOOKING + DIBUATKAN SPR =====
            ['nama' => 'Joko Susilo', 'hp' => '6281234567005', 'sumber' => 'Referral', 'status' => 'finish', 'days' => 20, 'alamat' => true,
                'nik' => '3275030303780005', 'npwp' => '09.012.345.6-001.000', 'foto_ktp' => true, 'bi' => true,
                'tempat_kerja' => $tkAstra, 'bank' => $bcaBank, 'nomor_rekening' => '6789012345', 'rekening_atas_nama' => 'Joko Susilo',
                'kontak_darurat' => [
                    ['nama' => 'Sri Susilowati', 'hubungan' => 'pasangan', 'nomor_telepon' => '6281234500001'],
                    ['nama' => 'Hadi Susilo',    'hubungan' => 'saudara',  'nomor_telepon' => '6281234500002'],
                ],
            ],
            ['nama' => 'Endang Trisnowati', 'hp' => '6281234567006', 'sumber' => 'Kantor', 'status' => 'finish', 'days' => 25, 'alamat' => true,
                'sales' => $siti, 'nik' => '3275040404750006', 'npwp' => '09.034.567.8-002.000', 'foto_ktp' => true, 'bi' => true,
                'tempat_kerja' => $tkDinas, 'bank' => $mandiriBank, 'nomor_rekening' => '1700123456', 'rekening_atas_nama' => 'Endang Trisnowati',
                'kontak_darurat' => [
                    ['nama' => 'Bagus Pramono',  'hubungan' => 'pasangan', 'nomor_telepon' => '6281234500003'],
                    ['nama' => 'Lina Trisno',    'hubungan' => 'anak',     'nomor_telepon' => '6281234500004'],
                    ['nama' => 'Rahmat Trisno',  'hubungan' => 'saudara',  'nomor_telepon' => '6281234500005'],
                ],
            ],
            // Prospect FINISH siap-coba-booking: sales sendiri Budi, biar gampang test
            ['nama' => 'Agus Pratama', 'hp' => '6281234567007', 'sumber' => 'Walk-in', 'status' => 'finish', 'days' => 15, 'alamat' => true,
                'nik' => '3275050505850007', 'npwp' => '09.056.789.0-003.000', 'foto_ktp' => true, 'bi' => true,
                'tempat_kerja' => $tkBank, 'bank' => $bniBank, 'nomor_rekening' => '0123456789', 'rekening_atas_nama' => 'Agus Pratama',
                'kontak_darurat' => [
                    ['nama' => 'Wati Pratama',  'hubungan' => 'pasangan', 'nomor_telepon' => '6281234500006'],
                    ['nama' => 'Andri Pratama', 'hubungan' => 'saudara',  'nomor_telepon' => '6281234500007'],
                ],
            ],
            ['nama' => 'Fitri Anggraini', 'hp' => '6281234567008', 'sumber' => 'TikTok', 'status' => 'finish', 'days' => 12, 'alamat' => true,
                'sales' => $andi, 'nik' => '3275060606880008', 'npwp' => '09.078.901.2-004.000', 'foto_ktp' => true, 'bi' => true,
                'tempat_kerja' => $tkTelkom, 'bank' => $briBank, 'nomor_rekening' => '4567890123', 'rekening_atas_nama' => 'Fitri Anggraini',
                'kontak_darurat' => [
                    ['nama' => 'Hadi Anggraini', 'hubungan' => 'orang_tua', 'nomor_telepon' => '6281234500008'],
                    ['nama' => 'Lestari',        'hubungan' => 'saudara',   'nomor_telepon' => '6281234500009'],
                ],
            ],
        ];

        foreach ($data as $row) {
            $owner = $row['sales'] ?? $budi;
            $createdAt = Carbon::now()->subDays($row['days'])->subHours(rand(0, 12));

            $prospectData = [
                'sales_id' => $owner->id,
                'proyek_id' => $proyek->id,
                'nama_lengkap' => $row['nama'],
                'hp' => $row['hp'],
                'sumber' => $row['sumber'],
                'nik' => $row['nik'] ?? null,
                'npwp' => $row['npwp'] ?? null,
                'tempat_kerja_id' => isset($row['tempat_kerja']) ? $row['tempat_kerja']?->id : null,
                'bank_id' => isset($row['bank']) ? $row['bank']?->id : null,
                'nomor_rekening' => $row['nomor_rekening'] ?? null,
                'rekening_atas_nama' => $row['rekening_atas_nama'] ?? null,
                'status' => $row['status'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            // Foto KTP (placeholder path, file actual tidak di-seed)
            if (! empty($row['foto_ktp'])) {
                $prospectData['foto_ktp'] = 'prospect-ktp/dummy-'.uniqid().'.jpg';
            }

            // BI Checking (kalau diminta)
            if (! empty($row['bi'])) {
                $prospectData['bi_kol'] = (string) rand(1, 2);
                $prospectData['bi_dbr'] = round(15 + (rand(0, 1500) / 100), 2);
                $prospectData['bi_keterangan'] = 'BI checking via SLIK, status: '.
                    ($prospectData['bi_kol'] === '1' ? 'Lancar' : 'DPK ringan');
            }

            // Alamat optional
            if (! empty($row['alamat']) && $jabar) {
                $prospectData = array_merge($prospectData, [
                    'alamat' => 'Jl. '.fake()->streetName().' No. '.rand(1, 99).' RT/RW 00'.rand(1, 9).'/00'.rand(1, 9),
                    'provinsi_code' => $jabar->code,
                    'provinsi_nama' => $jabar->name,
                    'kota_code' => $depok?->code,
                    'kota_nama' => $depok?->name,
                    'kecamatan_code' => $cilodong?->code,
                    'kecamatan_nama' => $cilodong?->name,
                    'kelurahan_code' => $sukamaju?->code,
                    'kelurahan_nama' => $sukamaju?->name,
                ]);
            }

            $prospect = ProspectCustomer::firstOrCreate(
                ['hp' => $row['hp'], 'sales_id' => $owner->id],
                $prospectData,
            );

            // Kontak Darurat (untuk prospect yang FINISH-ready)
            if (! empty($row['kontak_darurat']) && $prospect->kontakDarurat()->count() === 0) {
                foreach ($row['kontak_darurat'] as $k) {
                    $prospect->kontakDarurat()->create($k);
                }
            }

            // ===== AUTO STATUS LOG (journey lengkap) =====
            $this->log($prospect->id, $owner->id, null, 'cold', 'Prospect baru ditambahkan.', $createdAt);

            $journey = match ($row['status']) {
                'cold' => [],
                'warm' => ['warm'],
                'hot' => ['warm', 'hot'],
                'finish' => ['warm', 'hot', 'finish'],
            };

            $prev = 'cold';
            $journeyTime = $createdAt->copy();
            foreach ($journey as $next) {
                $journeyTime = $journeyTime->copy()->addDays(rand(1, 4))->addHours(rand(1, 8));

                $catatan = match ($next) {
                    'warm' => $this->randomNote([
                        'Sudah follow up via WA, customer minta brosur lengkap.',
                        'Telp pertama, customer minta jadwal survey lokasi Sabtu pagi.',
                        'Customer minta info tipe Anggrek + cicilan KPR-nya.',
                        'Follow up berhasil, customer tertarik tipe 30/60.',
                    ]),
                    'hot' => $this->randomNote([
                        'Sudah survey lokasi, customer suka unit Blok A-05.',
                        'Customer siap booking minggu depan setelah konsultasi keluarga.',
                        'Negotiate harga, akan booking unit A-08 hook.',
                        'Customer minta hold unit dulu, akan booking 3 hari lagi.',
                    ]),
                    'finish' => $this->randomNote([
                        'Semua data lengkap, siap booking unit.',
                        'BI checking lancar, NPWP & rekening sudah verified.',
                        'Data customer lengkap, tinggal pilih unit & booking.',
                    ]),
                };

                $this->log($prospect->id, $owner->id, $prev, $next, $catatan, $journeyTime);
                $prev = $next;
            }
        }
    }

    private function log(int $prospectId, int $salesId, ?string $dari, string $ke, ?string $catatan, $at): void
    {
        ProspectCustomerStatusLog::firstOrCreate(
            [
                'prospect_customer_id' => $prospectId,
                'status_ke' => $ke,
                'status_dari' => $dari,
            ],
            [
                'catatan' => $catatan,
                'changed_by_sales_id' => $salesId,
                'created_at' => $at,
            ],
        );
    }

    private function randomNote(array $opts): string
    {
        return $opts[array_rand($opts)];
    }
}
