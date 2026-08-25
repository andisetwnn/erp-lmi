<?php

use App\Services\Import\SopRowParser;

beforeEach(function () {
    $this->parser = new SopRowParser;
});

describe('parseNomorUnit', function () {
    it('pads unit satu digit jadi dua digit', function () {
        expect($this->parser->parseNomorUnit('4'))->toBe('04')
            ->and($this->parser->parseNomorUnit(4))->toBe('04')
            ->and($this->parser->parseNomorUnit(' 9 '))->toBe('09');
    });

    it('membiarkan unit yang sudah dua digit', function () {
        expect($this->parser->parseNomorUnit('12'))->toBe('12')
            ->and($this->parser->parseNomorUnit('26'))->toBe('26');
    });

    it('menormalkan unit yang sudah ter-pad supaya lookup konsisten', function () {
        expect($this->parser->parseNomorUnit('04'))->toBe('04')
            ->and($this->parser->parseNomorUnit('004'))->toBe('04');
    });

    it('mengembalikan nilai non-numerik apa adanya', function () {
        expect($this->parser->parseNomorUnit('4A'))->toBe('4A')
            ->and($this->parser->parseNomorUnit(''))->toBe('');
    });

    it('memetakan unit Excel ke format unit sistem berjalan', function (string $excel, string $sistem) {
        expect($this->parser->parseNomorUnit($excel))->toBe($sistem);
    })->with([
        ['3', '03'],   // IMAM SYAMSUDDIN  AB-3  → AB-03
        ['4', '04'],   // MIFTAHUDIN       AB-4  → AB-04
        ['8', '08'],   // UJANG ZAKARIA    AB-8  → AB-08
        ['9', '09'],   // TAUFIK HIDAYAT   AB-9  → AB-09
        ['22', '22'],  // INDRA NUR SAPUTRA AB-22 (tidak berubah)
    ]);
});

describe('parseNik', function () {
    it('menyaring non-digit dan memotong ke 16 karakter', function () {
        expect($this->parser->parseNik('3671-1125-1285-0007'))->toBe('3671112512850007')
            ->and($this->parser->parseNik('36711125128500071234'))->toBe('3671112512850007');
    });

    it('mengembalikan string kosong kalau tidak ada digit', function () {
        expect($this->parser->parseNik(''))->toBe('')
            ->and($this->parser->parseNik('-'))->toBe('');
    });

    it('mengoreksi NIK yang salah ketik di Excel ke NIK terverifikasi', function () {
        expect($this->parser->parseNik('3674051107830014'))->toBe('3174051107830014')  // MIFTAHUDIN
            ->and($this->parser->parseNik('7315115807990005'))->toBe('3172020201940002'); // NURRAHMAYANI JAFAR
    });

    it('membiarkan NIK yang tidak ada di daftar koreksi', function () {
        expect($this->parser->parseNik('3671082012860003'))->toBe('3671082012860003');
    });
});
