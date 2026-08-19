<?php

use App\Livewire\Concerns\Sortable;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Pengguna Sistem')] class extends Component
{
    use Sortable, WithPagination;

    #[Url(as: 'tab', except: 'pengguna')]
    public string $activeTab = 'pengguna';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'role', except: '')]
    public string $filterRole = '';

    #[Url(as: 'st', except: '')]
    public string $filterStatus = '';

    // Form state
    public ?int $editId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $selectedRole = '';

    public bool $isAktif = true;

    // Reset password
    public ?int $resetId = null;

    public ?string $resetName = null;

    public string $newPassword = '';

    // Tambah role
    public string $newRoleName = '';

    // Rename role
    public string $renameOldName = '';

    public string $renameNewName = '';

    // Delete role confirm
    public string $deleteRoleName = '';

    public int $deleteRoleUserCount = 0;

    // Two-panel role picker state
    #[Url(as: 'r', except: '')]
    public string $selectedRoleName = '';

    public string $roleSearch = '';

    public function selectRole(string $roleName): void
    {
        $this->selectedRoleName = $roleName;
    }

    // Label ramah pengguna untuk tiap permission (menggantikan tampilan kode teknis di UI)
    public const PERMISSION_LABEL = [
        // Sistem
        'user.kelola'               => 'Kelola Pengguna & Role',
        'ttd.kelola'                => 'Kelola Tanda Tangan Sendiri',
        // Master
        'master.kelola'             => 'Kelola Semua Master (Umbrella)',
        'master.perusahaan.kelola'  => 'Kelola Master Perusahaan',
        'master.proyek.kelola'      => 'Kelola Master Proyek',
        'master.tipe.kelola'        => 'Kelola Master Tipe Rumah',
        'master.rumah.kelola'       => 'Kelola Master Unit Rumah',
        'master.customer.kelola'    => 'Kelola Master Customer',
        'master.sales.kelola'       => 'Kelola Master Sales',
        'master.notaris.kelola'     => 'Kelola Master Notaris',
        'master.va.kelola'          => 'Kelola Master Virtual Account',
        'master.coa.kelola'         => 'Kelola Master COA',
        // SPR
        'spr.lihat'                 => 'Akses Menu SPR',
        'spr.approve'               => 'Persetujuan SPR',
        'spr.batal'                 => 'Pembatalan SPR',
        'spr.pindah-unit'           => 'Pindah Kavling',
        'spr.cetak'                 => 'Cetak PDF SPR',
        'biayatambahan.kelola'      => 'Kelola Biaya Tambahan Unit',
        'pemberkasan.kelola'        => 'Kelola Pemberkasan KPR',
        'pemberkasan.lihat'         => 'Lihat Pemberkasan KPR',
        // Finance
        'pembayaran.kelola'         => 'Kelola Penerimaan Konsumen',
        'pembayaran.approve'        => 'Approve Pembayaran',
        // Akunting
        'jurnal.umum.kelola'        => 'Kelola Jurnal Umum',
        'jurnal.bank.kelola'        => 'Kelola Jurnal Bank',
        'jurnal.kaskecil.kelola'    => 'Kelola Jurnal Kas Kecil',
        'jurnal.post'               => 'Posting Jurnal',
        'jurnal.delete'             => 'Hapus Jurnal',
        'bukubesar.lihat'           => 'Lihat Buku Besar',
        'kasbank.lihat'             => 'Lihat Kas & Bank',
        'labarugi.lihat'            => 'Lihat Laba Rugi',
        'neraca.lihat'              => 'Lihat Neraca',
        'neracasaldo.lihat'         => 'Lihat Neraca Saldo',
        'neracalajur.lihat'         => 'Lihat Neraca Lajur',
        'aruskas.lihat'             => 'Lihat Arus Kas',
        'aktivatetap.lihat'         => 'Lihat Aktiva Tetap',
        'aktivatetap.kelola'        => 'Kelola Aktiva Tetap',
        // Laporan & Monitoring
        'laporan.lihat'             => 'Lihat Laporan',
        'log.lihat'                 => 'Lihat Log Aktivitas',
        'monitoring.lihat'          => 'Monitoring & Notifikasi Umum',
        'notifikasi.keuangan'       => 'Notifikasi Khusus Keuangan',
    ];

    // Deskripsi tiap permission (untuk info di UI matrix)
    public const PERMISSION_DESC = [
        // Sistem
        'user.kelola'               => 'Menambah, mengubah, menonaktifkan pengguna sistem, mengatur role, dan mengelola permission per role.',
        'ttd.kelola'                => 'Mendaftarkan atau memperbarui gambar tanda tangan digital pribadi yang digunakan untuk keperluan persetujuan.',
        // Master
        'master.kelola'             => 'Umbrella: akses SEMUA menu master (perusahaan, proyek, tipe rumah, unit, customer, sales, notaris, VA, COA). Boleh dipakai bareng permission spesifik di bawah — sistem pakai OR.',
        'master.perusahaan.kelola'  => 'Mengubah data perusahaan (nama, alamat, logo, direksi) — biasanya super-admin only.',
        'master.proyek.kelola'      => 'Menambah / mengubah data proyek (cluster, alamat, siteplan). PM biasanya butuh ini.',
        'master.tipe.kelola'        => 'Menambah / mengubah data tipe rumah (harga jual, harga all-in, luas, spesifikasi). PM biasanya butuh ini untuk atur harga per tipe.',
        'master.rumah.kelola'       => 'Menambah / mengubah unit rumah (blok, nomor, biaya tambahan). PM butuh untuk buka blok baru.',
        'master.customer.kelola'    => 'Menambah / mengubah data customer (KTP, NPWP, alamat). Admin KPR biasanya butuh ini.',
        'master.sales.kelola'       => 'Menambah / mengubah data sales lapangan beserta akun DBOS-nya.',
        'master.notaris.kelola'     => 'Menambah / mengubah data notaris beserta biaya jasa. Finance biasanya butuh ini.',
        'master.va.kelola'          => 'Menambah / mengubah virtual account bank untuk penerimaan konsumen.',
        'master.coa.kelola'         => 'Menambah / mengubah Chart of Accounts (COA) beserta struktur hierarkinya. Finance biasanya butuh ini.',
        // SPR
        'spr.lihat'                 => 'Mengakses seluruh menu SPR: melihat daftar & detail SPR.',
        'spr.approve'               => 'Menyetujui atau menolak SPR pada tahap persetujuan Project Manager.',
        'spr.batal'                 => 'Memproses pembatalan SPR beserta pengembalian dana ke customer.',
        'spr.pindah-unit'           => 'Memindahkan customer dari satu kavling ke kavling lain, termasuk menukar unit antar dua SPR. Selisih harga dan realisasi otomatis diproses.',
        'spr.cetak'                 => 'Mencetak PDF SPR (baik draft maupun versi final bermaterai).',
        'biayatambahan.kelola'      => 'Input realisasi pembayaran biaya tambahan unit (kavling hook, view, dll) beserta refund saat SPR dibatalkan. Diproses terpisah dari SPR — tidak memengaruhi total harga SPR / cicilan.',
        'pemberkasan.kelola'        => 'Kelola tahapan pemberkasan KPR untuk Admin KPR: input tanggal Berkas Masuk, Wawancara, SP3K, LPA (khusus BTN), Rencana Akad + upload file berkas customer. Sumber data untuk tracking approval bank sebelum akad.',
        'pemberkasan.lihat'         => 'View-only tabel pemberkasan KPR — tidak bisa input/edit. Cocok untuk direktur, PM, dan Finance yg butuh visibility progress berkas ke bank.',
        // Finance
        'pembayaran.kelola'         => 'Mengakses menu Penerimaan Konsumen: konfirmasi UTJ, mencatat cicilan Uang Muka, menempelkan e-Materai, dan memproses realisasi pembayaran.',
        'pembayaran.approve'        => 'Menyetujui pengembalian dana (refund) & reversal realisasi pembayaran — biasanya finance-manager.',
        // Akunting
        'jurnal.umum.kelola'        => 'Input, edit jurnal umum (semua kategori bukti: KAS, BANK, PENJ, HPP, AKM, RJE, dsb) di modul Akunting.',
        'jurnal.bank.kelola'        => 'Input jurnal khusus transaksi bank (transfer masuk/keluar, kliring, biaya administrasi bank).',
        'jurnal.kaskecil.kelola'    => 'Input jurnal khusus kas kecil (petty cash) untuk operasional harian.',
        'jurnal.post'               => 'Memposting jurnal dari status draft menjadi posted (masuk ke buku besar). Biasanya level finance-manager / kepala akunting.',
        'jurnal.delete'             => 'Menghapus jurnal yang sudah tercatat (baik draft maupun posted). Biasanya finance-manager only.',
        'bukubesar.lihat'           => 'Melihat Buku Besar per akun COA: mutasi debet/kredit + running balance per periode.',
        'kasbank.lihat'             => 'Melihat dashboard Kas & Bank: saldo per akun kas (1001.*) dan bank (1002.*) per tanggal cutoff, plus total likuiditas.',
        'labarugi.lihat'            => 'Melihat Laporan Laba Rugi (pendapatan − beban), export PDF & Excel.',
        'neraca.lihat'              => 'Melihat Neraca (Aset, Kewajiban, Modal) per tanggal cutoff dan cek balance otomatis.',
        'neracasaldo.lihat'         => 'Melihat Neraca Saldo (Trial Balance): daftar semua akun + mutasi debet/kredit + saldo akhir, tools cross-check jurnal.',
        'neracalajur.lihat'         => 'Melihat Neraca Lajur (Worksheet 10 kolom): Neraca Saldo → AJP (kategori AKM/RJE) → Disesuaikan → Rugi/Laba + Neraca. Tools closing periode akhir bulan/tahun.',
        'aruskas.lihat'             => 'Melihat Laporan Arus Kas (Cash Flow) direct method: aktivitas Operasi, Investasi, dan Pendanaan.',
        'aktivatetap.lihat'         => 'Melihat daftar Aktiva Tetap (kendaraan, inventaris kantor, bangunan) beserta nilai buku dan akumulasi penyusutan.',
        'aktivatetap.kelola'        => 'Menambah, mengubah, menghapus data Aktiva Tetap serta mengelola perhitungan penyusutan.',
        // Laporan & Monitoring
        'laporan.lihat'             => 'Mengakses menu Laporan (Penjualan, Stok Unit, Kwitansi Masuk, Tunggakan UM, Pembatalan, Peringkat Sales).',
        'log.lihat'                 => 'Melihat Log Aktivitas (audit trail seluruh tindakan pengguna di dalam sistem).',
        'monitoring.lihat'          => 'Melihat feed monitoring realtime beserta notifikasi lonceng untuk seluruh aktivitas sistem.',
        'notifikasi.keuangan'       => 'Menerima notifikasi lonceng khusus untuk aktivitas yang berkaitan dengan tim Keuangan (SPR baru menunggu verifikasi UTJ, konsumen selesai tanda tangan, dan sebagainya).',
    ];

    // Remap prefix permission ke nama group yang lebih ringkas (biar akunting jadi 1 section, bukan 7)
    public const PERMISSION_GROUP_MAP = [
        'jurnal'      => 'akunting',
        'bukubesar'   => 'akunting',
        'labarugi'    => 'akunting',
        'neraca'      => 'akunting',
        'neracasaldo' => 'akunting',
        'aruskas'     => 'akunting',
        'aktivatetap' => 'akunting',
    ];

    protected function defaultSortBy(): ?string
    {
        return 'name';
    }

    protected function defaultSortDir(): string
    {
        return 'asc';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['pengguna', 'role'], true) ? $tab : 'pengguna';
        $this->resetPage();
    }

    // =========== ROLE CRUD ===========
    public function openCreateRole(): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);
        $this->reset(['newRoleName']);
        $this->resetErrorBag();
        Flux::modal('role-create')->show();
    }

    public function createRole(): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);

        $validated = $this->validate([
            'newRoleName' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                \Illuminate\Validation\Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
        ], [
            'newRoleName.regex' => 'Hanya huruf kecil, angka, dan tanda "-" (contoh: admin-sales).',
            'newRoleName.unique' => 'Nama role sudah dipakai.',
        ], [
            'newRoleName' => 'nama role',
        ]);

        Role::create(['name' => $validated['newRoleName'], 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Flux::modal('role-create')->close();
        Flux::toast(variant: 'success', text: "Role \"{$validated['newRoleName']}\" berhasil dibuat. Silakan atur permission-nya di tabel.");
        $this->reset(['newRoleName']);
    }

    public function openDeleteRole(string $roleName): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);
        if ($roleName === 'super-admin') {
            Flux::toast(variant: 'warning', text: 'Role super-admin tidak bisa dihapus.');
            return;
        }
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            Flux::toast(variant: 'danger', text: 'Role tidak ditemukan.');
            return;
        }

        $this->deleteRoleName = $roleName;
        $this->deleteRoleUserCount = $role->users()->count();
        Flux::modal('role-delete-confirm')->show();
    }

    public function confirmDeleteRole(): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);

        $roleName = $this->deleteRoleName;
        if ($roleName === '' || $roleName === 'super-admin') {
            Flux::modal('role-delete-confirm')->close();
            return;
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            Flux::modal('role-delete-confirm')->close();
            Flux::toast(variant: 'danger', text: 'Role tidak ditemukan.');
            return;
        }

        $userCount = $role->users()->count();
        if ($userCount > 0) {
            Flux::modal('role-delete-confirm')->close();
            Flux::toast(variant: 'warning', text: "Role \"{$roleName}\" masih dipakai oleh {$userCount} user. Pindahkan mereka ke role lain dulu.");
            return;
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Flux::modal('role-delete-confirm')->close();
        Flux::toast(variant: 'success', text: "Role \"{$roleName}\" berhasil dihapus.");
        $this->reset(['deleteRoleName', 'deleteRoleUserCount']);
    }

    public function openRenameRole(string $roleName): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);
        if ($roleName === 'super-admin') {
            Flux::toast(variant: 'warning', text: 'Role super-admin tidak bisa diubah namanya.');
            return;
        }
        $this->renameOldName = $roleName;
        $this->renameNewName = $roleName;
        $this->resetErrorBag();
        Flux::modal('role-rename')->show();
    }

    public function renameRole(): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);

        $validated = $this->validate([
            'renameNewName' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                \Illuminate\Validation\Rule::unique('roles', 'name')
                    ->where('guard_name', 'web')
                    ->ignore($this->renameOldName, 'name'),
            ],
        ], [
            'renameNewName.regex' => 'Hanya huruf kecil, angka, dan tanda "-".',
            'renameNewName.unique' => 'Nama role sudah dipakai.',
        ], [
            'renameNewName' => 'nama role baru',
        ]);

        if ($this->renameOldName === 'super-admin') {
            Flux::toast(variant: 'warning', text: 'Role super-admin tidak bisa diubah namanya.');
            return;
        }

        $role = Role::where('name', $this->renameOldName)->where('guard_name', 'web')->first();
        if (! $role) {
            Flux::toast(variant: 'danger', text: 'Role tidak ditemukan.');
            return;
        }

        $oldName = $role->name;
        $newName = $validated['renameNewName'];

        if ($oldName === $newName) {
            Flux::modal('role-rename')->close();
            return;
        }

        $role->update(['name' => $newName]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Flux::modal('role-rename')->close();
        Flux::toast(variant: 'success', text: "Role \"{$oldName}\" berhasil diubah menjadi \"{$newName}\".");
        $this->reset(['renameOldName', 'renameNewName']);
    }

    // =========== ROLE & PERMISSION ===========
    public function togglePermission(string $roleName, string $permissionName): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);

        // Super-admin selalu punya semua permission — dilock supaya tidak bisa self-lockout.
        if ($roleName === 'super-admin') {
            Flux::toast(variant: 'warning', text: 'Permission super-admin tidak bisa diubah (lock protection).');
            return;
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        $perm = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();

        if (! $role || ! $perm) {
            Flux::toast(variant: 'danger', text: 'Role atau permission tidak ditemukan.');
            return;
        }

        if ($role->hasPermissionTo($perm)) {
            $role->revokePermissionTo($perm);
            Flux::toast(variant: 'success', text: "Permission {$permissionName} dicabut dari {$roleName}.");
        } else {
            $role->givePermissionTo($perm);
            Flux::toast(variant: 'success', text: "Permission {$permissionName} diberikan ke {$roleName}.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterRole(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    // =========== CRUD ===========
    public function openCreate(): void
    {
        $this->reset(['editId', 'name', 'username', 'email', 'password', 'selectedRole', 'isAktif']);
        $this->isAktif = true;
        $this->resetErrorBag();
        Flux::modal('user-form')->show();
    }

    public function openEdit(int $id): void
    {
        $u = User::findOrFail($id);
        $this->editId = $u->id;
        $this->name = $u->name;
        $this->username = (string) ($u->username ?? '');
        $this->email = $u->email;
        $this->password = '';
        $this->selectedRole = (string) ($u->roles()->pluck('name')->first() ?? '');
        $this->isAktif = (bool) $u->is_aktif;
        $this->resetErrorBag();
        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/',
                \Illuminate\Validation\Rule::unique('users', 'username')->ignore($this->editId),
            ],
            'email' => ['required', 'email', 'max:255',
                \Illuminate\Validation\Rule::unique('users', 'email')->ignore($this->editId),
            ],
            'password' => $this->editId ? ['nullable', 'string', 'min:8'] : ['required', 'string', 'min:8'],
            'selectedRole' => ['required', 'string', 'exists:roles,name'],
            'isAktif' => ['boolean'],
        ], [], [
            'selectedRole' => 'role',
            'isAktif' => 'status aktif',
        ]);

        $data = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'is_aktif' => $validated['isAktif'],
        ];

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        if ($this->editId) {
            $user = User::findOrFail($this->editId);
            $user->update($data);
        } else {
            $data['password'] = Hash::make($validated['password']);
            $data['email_verified_at'] = now();
            $user = User::create($data);
        }

        // Assign single role (Spatie sync = replace all)
        $user->syncRoles([$validated['selectedRole']]);

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: $this->editId
            ? "User {$user->name} berhasil diperbarui."
            : "User {$user->name} berhasil dibuat.");

        $this->reset(['editId', 'name', 'username', 'email', 'password', 'selectedRole']);
    }

    public function toggleAktif(int $id): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);
        $u = User::findOrFail($id);

        if ($u->id === Auth::id()) {
            Flux::toast(variant: 'warning', text: 'Tidak bisa menonaktifkan akun sendiri.');
            return;
        }

        $u->update(['is_aktif' => ! $u->is_aktif]);
        Flux::toast(variant: 'success', text: $u->is_aktif ? "User {$u->name} diaktifkan." : "User {$u->name} dinonaktifkan.");
    }

    // =========== RESET PASSWORD ===========
    public function openReset(int $id): void
    {
        $u = User::findOrFail($id);
        $this->resetId = $u->id;
        $this->resetName = $u->name;
        $this->newPassword = '';
        $this->resetErrorBag();
        Flux::modal('user-reset-password')->show();
    }

    public function submitReset(): void
    {
        abort_unless(Auth::user()?->can('user.kelola'), 403);
        $validated = $this->validate([
            'newPassword' => ['required', 'string', 'min:8'],
        ], [], ['newPassword' => 'password baru']);

        $u = User::findOrFail($this->resetId);
        $u->update(['password' => Hash::make($validated['newPassword'])]);

        Flux::modal('user-reset-password')->close();
        Flux::toast(variant: 'success', text: "Password {$u->name} berhasil direset.");
        $this->reset(['resetId', 'resetName', 'newPassword']);
    }

    public function with(): array
    {
        $query = User::query()
            ->with('roles:id,name')
            ->when($this->search !== '', function ($q) {
                $s = $this->search;
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                        ->orWhere('username', 'like', "%{$s}%");
                });
            })
            ->when($this->filterRole !== '', function ($q) {
                $q->whereHas('roles', fn ($qq) => $qq->where('name', $this->filterRole));
            })
            ->when($this->filterStatus === 'aktif', fn ($q) => $q->where('is_aktif', true))
            ->when($this->filterStatus === 'nonaktif', fn ($q) => $q->where('is_aktif', false));

        $this->applySort($query, ['name', 'username', 'email', 'created_at']);

        $roles = Role::where('guard_name', 'web')->orderBy('name')->get();

        // Grouping permission by prefix (mis. spr.*, master.*) untuk UI.
        // PERMISSION_GROUP_MAP di-apply supaya beberapa prefix (jurnal/labarugi/neraca/dll)
        // dilebur ke satu section "akunting" biar tidak jadi 7 group kecil.
        $allPermissions = Permission::where('guard_name', 'web')->orderBy('name')->get();
        $permissionGroups = $allPermissions->groupBy(function ($p) {
            $prefix = explode('.', $p->name)[0];

            return self::PERMISSION_GROUP_MAP[$prefix] ?? $prefix;
        })->sortKeys();

        // Matrix: [roleName => [permName => hasIt]]
        $matrix = [];
        foreach ($roles as $role) {
            $rolePerms = $role->permissions->pluck('name')->toArray();
            foreach ($allPermissions as $p) {
                $matrix[$role->name][$p->name] = in_array($p->name, $rolePerms, true);
            }
        }

        // Auto-select first role kalau belum ada / role yang dipilih sudah tidak ada
        if ($this->activeTab === 'role') {
            $validRoleNames = $roles->pluck('name')->toArray();
            if ($this->selectedRoleName === '' || ! in_array($this->selectedRoleName, $validRoleNames, true)) {
                $this->selectedRoleName = $roles->firstWhere('name', '!=', 'super-admin')?->name
                    ?? $roles->first()?->name
                    ?? '';
            }
        }

        // Filter role di sidebar berdasarkan search
        $filteredRoles = $this->roleSearch === ''
            ? $roles
            : $roles->filter(fn ($r) => str_contains(strtolower($r->name), strtolower($this->roleSearch)))->values();

        // User count per role (untuk tampilan di list)
        $userCountPerRole = [];
        foreach ($roles as $r) {
            $userCountPerRole[$r->name] = $r->users()->count();
        }

        return [
            'users' => $query->paginate(15),
            'roles' => $roles,
            'filteredRoles' => $filteredRoles,
            'userCountPerRole' => $userCountPerRole,
            'permissionGroups' => $permissionGroups,
            'matrix' => $matrix,
        ];
    }
}; ?>

@php
    $roleBadgeMap = [
        'super-admin'     => ['label' => 'Super Admin',     'cls' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'],
        'direktur'        => ['label' => 'Direktur',        'cls' => 'bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300'],
        'project-manager' => ['label' => 'Project Manager', 'cls' => 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300'],
        'finance'         => ['label' => 'Keuangan',        'cls' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'],
        'admin-kpr'       => ['label' => 'Admin KPR',       'cls' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300'],
    ];
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 to-indigo-700 text-white shadow-sm">
                    <flux:icon.users class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('User Akses') }}</flux:heading>
                    <flux:subheading>{{ __('Kelola akun web, role, dan permission per modul.') }}</flux:subheading>
                </div>
            </div>

            @if ($activeTab === 'pengguna')
                <flux:button variant="primary" icon="plus" wire:click="openCreate">
                    {{ __('Tambah User') }}
                </flux:button>
            @endif
        </div>

        {{-- TABS --}}
        <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
            @foreach (['pengguna' => ['Pengguna', 'user-group'], 'role' => ['Role & Permission', 'shield-check']] as $key => [$label, $icon])
                @php $active = $activeTab === $key; @endphp
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition -mb-px',
                            'border-indigo-600 text-indigo-700 dark:border-indigo-400 dark:text-indigo-400' => $active,
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200' => ! $active,
                        ])>
                    @switch($icon)
                        @case('user-group') <flux:icon.user-group class="size-4" /> @break
                        @case('shield-check') <flux:icon.shield-check class="size-4" /> @break
                    @endswitch
                    {{ __($label) }}
                </button>
            @endforeach
        </div>

        @if ($activeTab === 'pengguna')
        {{-- FILTERS --}}
        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12">
            <div class="md:col-span-6">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                            :placeholder="__('Cari nama / email / username...')" />
            </div>
            <div class="md:col-span-3">
                <flux:select wire:model.live="filterRole" :placeholder="__('Semua Role')">
                    <flux:select.option value="">{{ __('Semua Role') }}</flux:select.option>
                    @foreach ($roles as $r)
                        <flux:select.option value="{{ $r->name }}">
                            {{ $roleBadgeMap[$r->name]['label'] ?? $r->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="md:col-span-3">
                <flux:select wire:model.live="filterStatus" :placeholder="__('Semua Status')">
                    <flux:select.option value="">{{ __('Semua Status') }}</flux:select.option>
                    <flux:select.option value="aktif">{{ __('Aktif') }}</flux:select.option>
                    <flux:select.option value="nonaktif">{{ __('Nonaktif') }}</flux:select.option>
                </flux:select>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50/80 dark:bg-zinc-800/60">
                        <tr class="border-b border-zinc-200 text-left text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:border-zinc-700">
                            <th class="px-4 py-3">
                                <x-sortable-column field="name" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('User') }}</x-sortable-column>
                            </th>
                            <th class="px-4 py-3">{{ __('Role') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('TTD') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($users as $row)
                            @php
                                $roleName = $row->roles->first()?->name;
                                $initials = collect(explode(' ', $row->name))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
                                $isSelf = $row->id === auth()->id();
                            @endphp
                            <tr @class([
                                'group transition hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40',
                                'opacity-60' => ! $row->is_aktif,
                            ])>
                                {{-- User: avatar + nama + username + email --}}
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-indigo-500 to-indigo-700 text-xs font-bold text-white shadow-sm">
                                            {{ $initials }}
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $row->name }}</span>
                                                @if ($isSelf)
                                                    <span class="rounded-md bg-indigo-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                                                        {{ __('Anda') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-zinc-500">
                                                <span class="font-mono">{{ $row->username ?? '—' }}</span>
                                                <span class="text-zinc-300">·</span>
                                                <span>{{ $row->email }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Role --}}
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($roleName)
                                        @php $b = $roleBadgeMap[$roleName] ?? ['label' => $roleName, 'cls' => 'bg-zinc-100 text-zinc-700']; @endphp
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider {{ $b['cls'] }}">
                                            <span class="size-1.5 rounded-full bg-current opacity-70"></span>
                                            {{ $b['label'] }}
                                        </span>
                                    @else
                                        <span class="text-[10px] italic text-zinc-400">{{ __('Tidak ada role') }}</span>
                                    @endif
                                </td>

                                {{-- TTD --}}
                                <td class="whitespace-nowrap px-4 py-3 text-center">
                                    @if ($row->tanda_tangan_path)
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400"
                                              title="{{ __('TTD sudah terdaftar') }}">
                                            <flux:icon.check class="size-3.5" />
                                        </span>
                                    @else
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-600"
                                              title="{{ __('Belum ada TTD') }}">
                                            <flux:icon.minus class="size-3.5" />
                                        </span>
                                    @endif
                                </td>

                                {{-- Status --}}
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($row->is_aktif)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                            {{ __('Aktif') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                            <span class="size-1.5 rounded-full bg-zinc-400"></span>
                                            {{ __('Nonaktif') }}
                                        </span>
                                    @endif
                                </td>

                                {{-- Aksi --}}
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1 opacity-60 transition group-hover:opacity-100">
                                        <button type="button" wire:click="openEdit({{ $row->id }})"
                                                title="{{ __('Edit') }}"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-indigo-50 hover:text-indigo-600 active:scale-95 dark:hover:bg-indigo-950/40">
                                            <flux:icon.pencil-square class="size-4" />
                                        </button>
                                        <button type="button" wire:click="openReset({{ $row->id }})"
                                                title="{{ __('Reset Password') }}"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-amber-50 hover:text-amber-600 active:scale-95 dark:hover:bg-amber-950/40">
                                            <flux:icon.key class="size-4" />
                                        </button>
                                        @if (! $isSelf)
                                            <button type="button" wire:click="toggleAktif({{ $row->id }})"
                                                    wire:confirm="{{ $row->is_aktif ? 'Nonaktifkan user ini?' : 'Aktifkan user ini?' }}"
                                                    title="{{ $row->is_aktif ? __('Nonaktifkan') : __('Aktifkan') }}"
                                                    @class([
                                                        'inline-flex h-8 w-8 items-center justify-center rounded-lg transition active:scale-95',
                                                        'text-zinc-500 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40' => $row->is_aktif,
                                                        'text-zinc-500 hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-emerald-950/40' => ! $row->is_aktif,
                                                    ])>
                                                @if ($row->is_aktif)
                                                    <flux:icon.lock-closed class="size-4" />
                                                @else
                                                    <flux:icon.lock-open class="size-4" />
                                                @endif
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center">
                                    <flux:icon.users class="mx-auto mb-2 size-10 text-zinc-300" />
                                    <div class="text-sm text-zinc-500">{{ __('Tidak ada user yang cocok dengan filter.') }}</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $users->links() }}
        </div>
        @endif

        {{-- ============ TAB: ROLE & PERMISSION (two-panel) ============ --}}
        @if ($activeTab === 'role')
            @php
                // Hanya super-admin yang dikunci mati (anti self-lockout).
                $lockedRoles = ['super-admin'];
                $activeRole = $roles->firstWhere('name', $selectedRoleName);
                $activeRoleBadge = $activeRole
                    ? ($roleBadgeMap[$activeRole->name] ?? ['label' => ucwords(str_replace('-', ' ', $activeRole->name)), 'cls' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'])
                    : null;
                $activeIsLocked = $activeRole && in_array($activeRole->name, $lockedRoles, true);
            @endphp

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[280px_1fr]">
                {{-- ========== LEFT PANEL: DAFTAR ROLE ========== --}}
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 p-3 dark:border-zinc-800">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                                {{ __('Role') }} <span class="text-zinc-400">({{ count($roles) }})</span>
                            </div>
                            <button type="button" wire:click="openCreateRole"
                                    class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-indigo-700"
                                    title="Tambah role baru">
                                <flux:icon.plus class="size-3" />
                                {{ __('Tambah') }}
                            </button>
                        </div>
                        <div class="relative">
                            <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-zinc-400" />
                            <input type="search" wire:model.live.debounce.200ms="roleSearch"
                                   placeholder="{{ __('Cari role...') }}"
                                   class="block h-8 w-full rounded-md border border-zinc-200 bg-white pl-8 pr-2 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        </div>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto py-1">
                        @forelse ($filteredRoles as $r)
                            @php
                                $b = $roleBadgeMap[$r->name] ?? ['label' => ucwords(str_replace('-', ' ', $r->name)), 'cls' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'];
                                $isActive = $selectedRoleName === $r->name;
                                $roleUserCount = $userCountPerRole[$r->name] ?? 0;
                                $permCount = collect($matrix[$r->name] ?? [])->filter()->count();
                            @endphp
                            <button type="button" wire:click="selectRole('{{ $r->name }}')"
                                    @class([
                                        'group flex w-full items-center justify-between gap-2 border-l-2 px-3 py-2 text-left text-xs transition',
                                        'border-indigo-600 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-950/30' => $isActive,
                                        'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => ! $isActive,
                                    ])>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-flex shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider {{ $b['cls'] }}">
                                            {{ $b['label'] }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-2 text-[10px] text-zinc-500">
                                        <span>{{ $roleUserCount }} {{ __('user') }}</span>
                                        <span>·</span>
                                        <span>{{ $permCount }} {{ __('izin') }}</span>
                                    </div>
                                </div>
                                @if ($isActive)
                                    <flux:icon.chevron-right class="size-4 shrink-0 text-indigo-500" />
                                @endif
                            </button>
                        @empty
                            <div class="px-3 py-6 text-center text-xs text-zinc-400">{{ __('Tidak ada role.') }}</div>
                        @endforelse
                    </div>
                </div>

                {{-- ========== RIGHT PANEL: DETAIL PERMISSION ========== --}}
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    @if (! $activeRole)
                        <div class="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                            <flux:icon.shield-check class="size-12 text-zinc-300" />
                            <div class="text-sm font-semibold text-zinc-500">{{ __('Pilih role di panel kiri') }}</div>
                            <div class="text-xs text-zinc-400">{{ __('Untuk melihat dan mengatur permission-nya.') }}</div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 p-4 dark:border-zinc-800">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wider {{ $activeRoleBadge['cls'] }}">
                                    {{ $activeRoleBadge['label'] }}
                                </span>
                                @if ($activeIsLocked)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <flux:icon.lock-closed class="size-3" />
                                        {{ __('Terkunci') }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                @if (! $activeIsLocked)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square"
                                                 wire:click="openRenameRole('{{ $activeRole->name }}')">
                                        {{ __('Ubah Nama') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" icon="trash"
                                                 wire:click="openDeleteRole('{{ $activeRole->name }}')"
                                                 class="text-rose-600 hover:text-rose-700 dark:text-rose-400">
                                        {{ __('Hapus') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        @if ($activeIsLocked)
                            <div class="mx-4 mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                                <flux:icon.lock-closed class="mt-0.5 size-4 shrink-0" />
                                <span>{{ __('Role super-admin memiliki seluruh permission secara otomatis dan tidak bisa diubah (proteksi anti self-lockout).') }}</span>
                            </div>
                        @endif

                        <div class="space-y-4 p-4">
                            @foreach ($permissionGroups as $groupName => $perms)
                                <div class="rounded-lg border border-zinc-100 dark:border-zinc-800">
                                    <div class="border-b border-zinc-100 bg-zinc-50/60 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/30">
                                        {{ __(ucfirst($groupName)) }}
                                    </div>
                                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @foreach ($perms as $perm)
                                            @php
                                                $hasIt = $matrix[$activeRole->name][$perm->name] ?? false;
                                                $label = $this::PERMISSION_LABEL[$perm->name] ?? ucwords(str_replace(['.', '-', '_'], ' ', $perm->name));
                                                $desc = $this::PERMISSION_DESC[$perm->name] ?? null;
                                            @endphp
                                            <label class="flex cursor-pointer items-start gap-3 px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/40 {{ $activeIsLocked ? 'cursor-not-allowed opacity-60' : '' }}">
                                                <div class="mt-0.5">
                                                    <input type="checkbox"
                                                           @checked($hasIt)
                                                           @disabled($activeIsLocked)
                                                           @if (! $activeIsLocked)
                                                               wire:click="togglePermission('{{ $activeRole->name }}', '{{ $perm->name }}')"
                                                           @endif
                                                           class="size-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-800" />
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $label }}</div>
                                                    @if ($desc)
                                                        <div class="mt-0.5 text-[10px] leading-tight text-zinc-500">{{ $desc }}</div>
                                                    @endif
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- MODAL: Tambah Role --}}
            <flux:modal name="role-create" class="md:w-md" focusable>
                <form wire:submit="createRole" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Tambah Role Baru') }}</flux:heading>
                        <flux:subheading>{{ __('Buat role kustom lalu atur permission-nya dari tabel.') }}</flux:subheading>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Nama Role') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="newRoleName" placeholder="mis. admin-sales" autofocus />
                        <flux:description class="text-[10px]">
                            {{ __('Huruf kecil, angka, tanda "-". Contoh: admin-sales, admin-marketing, notaris.') }}
                        </flux:description>
                        <flux:error name="newRoleName" />
                    </flux:field>

                    <div class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:bg-blue-950/30 dark:text-blue-200">
                        <flux:icon.information-circle class="mr-1 inline size-3.5" />
                        {{ __('Role dibuat tanpa permission apapun. Setelah dibuat, centang permission yang diinginkan di tabel.') }}
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit">{{ __('Buat Role') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            {{-- MODAL: Konfirmasi Hapus Role --}}
            <flux:modal name="role-delete-confirm" class="md:w-md">
                <div class="space-y-5">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                            <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                        </div>
                        <div>
                            <flux:heading size="lg">{{ __('Hapus Role?') }}</flux:heading>
                            <flux:subheading>
                                {{ __('Role ":name" akan dihapus permanen beserta semua permission yang menempel.', ['name' => $deleteRoleName]) }}
                            </flux:subheading>
                        </div>
                    </div>

                    @if ($deleteRoleUserCount > 0)
                        <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                            <flux:icon.exclamation-triangle class="mr-1 inline size-3.5" />
                            {{ __('Role ini masih dipakai oleh :n user. Pindahkan mereka ke role lain dulu sebelum menghapus.', ['n' => $deleteRoleUserCount]) }}
                        </div>
                    @else
                        <div class="rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                            {{ __('Tidak ada user yang sedang menggunakan role ini. Aman untuk dihapus.') }}
                        </div>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="button" icon="trash" wire:click="confirmDeleteRole"
                                     :disabled="$deleteRoleUserCount > 0">
                            {{ __('Hapus') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>

            {{-- MODAL: Rename Role --}}
            <flux:modal name="role-rename" class="md:w-md" focusable>
                <form wire:submit="renameRole" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Ubah Nama Role') }}</flux:heading>
                        <flux:subheading>{{ __('Semua user yang memakai role ini akan otomatis mengikuti nama baru.') }}</flux:subheading>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Nama Lama') }}</flux:label>
                        <flux:input value="{{ $renameOldName }}" disabled />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Nama Baru') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="renameNewName" placeholder="mis. keuangan-inti" autofocus />
                        <flux:description class="text-[10px]">
                            {{ __('Huruf kecil, angka, tanda "-". Contoh: admin-sales, keuangan-inti, notaris.') }}
                        </flux:description>
                        <flux:error name="renameNewName" />
                    </flux:field>

                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                        <flux:icon.exclamation-triangle class="mr-1 inline size-3.5" />
                        {{ __('Beberapa fitur (seperti dashboard executive, monitoring PM) merujuk ke nama role sistem. Kalau nama diubah, fitur tersebut mungkin perlu penyesuaian oleh developer.') }}
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    </div>

    {{-- ============ MODAL: FORM USER ============ --}}
    <flux:modal name="user-form" class="md:w-lg" focusable>
        <form wire:submit="save" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $editId ? __('Edit User') : __('Tambah User Baru') }}</flux:heading>
                <flux:subheading>{{ __('Akun ini akan login ke sistem pusat (web).') }}</flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Nama Lengkap') }} <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="name" placeholder="Contoh: Ade Wijaya" />
                <flux:error name="name" />
            </flux:field>

            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>{{ __('Username') }} <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="username" placeholder="ade.wijaya" />
                    <flux:description class="text-[10px]">huruf/angka/. _ -</flux:description>
                    <flux:error name="username" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Email') }} <span class="text-red-500">*</span></flux:label>
                    <flux:input type="email" wire:model="email" placeholder="ade@lmi.co.id" />
                    <flux:error name="email" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>
                    {{ __('Password') }}
                    @if (! $editId) <span class="text-red-500">*</span> @endif
                </flux:label>
                <flux:input type="password" wire:model="password" :placeholder="$editId ? __('Kosongkan jika tidak diubah') : __('Minimal 8 karakter')" />
                <flux:error name="password" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Role') }} <span class="text-red-500">*</span></flux:label>
                <flux:select wire:model="selectedRole" :placeholder="__('— Pilih Role —')">
                    <flux:select.option value="">{{ __('— Pilih Role —') }}</flux:select.option>
                    @foreach ($roles as $r)
                        <flux:select.option value="{{ $r->name }}">
                            {{ $roleBadgeMap[$r->name]['label'] ?? $r->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description class="text-[10px]">
                    {{ __('Satu user = satu role. Ganti role akan replace yang lama.') }}
                </flux:description>
                <flux:error name="selectedRole" />
            </flux:field>

            <div>
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <input type="checkbox" wire:model="isAktif" class="accent-emerald-600">
                    <span class="text-xs font-semibold">{{ __('User Aktif (bisa login)') }}</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button type="button" variant="filled">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">
                    {{ __('Simpan') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ============ MODAL: RESET PASSWORD ============ --}}
    <flux:modal name="user-reset-password" class="md:w-md" focusable>
        <form wire:submit="submitReset" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Reset Password') }}</flux:heading>
                <flux:subheading>{{ __('Set password baru untuk :name', ['name' => $resetName ?? '—']) }}</flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Password Baru') }} <span class="text-red-500">*</span></flux:label>
                <flux:input type="password" wire:model="newPassword" placeholder="{{ __('Minimal 8 karakter') }}" />
                <flux:error name="newPassword" />
            </flux:field>

            <div class="rounded-lg bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                <flux:icon.exclamation-triangle class="-mt-0.5 mr-1 inline size-3.5" />
                {{ __('Beri tahu user password baru via WA / email. Sistem tidak mengirim notifikasi otomatis.') }}
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="filled">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="key">
                    {{ __('Reset Password') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
