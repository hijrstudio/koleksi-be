<?php

/*
 * Poin papan peringkat forum. Ubah nilai / batas di sini; aktivitas yang sudah tercatat tidak ikut berubah.
 *
 *   points     : poin per aktivitas
 *   daily_cap  : maksimal berapa aktivitas per hari (WIB) yang dapat poin; null = tanpa batas
 */
return [

    'timezone' => 'Asia/Jakarta',

    'actions' => [
        'visit' => ['points' => 5, 'daily_cap' => 1],          // kunjungan harian (login)
        'discussion' => ['points' => 10, 'daily_cap' => 3],    // membuat diskusi
        'comment' => ['points' => 5, 'daily_cap' => 10],       // komentar / balasan di diskusi orang lain
        'poll' => ['points' => 5, 'daily_cap' => null],        // ikut polling / voting (sekali per polling)
        'author' => ['points' => 100, 'daily_cap' => null],    // penulis artikel / berita yang terbit
        'read' => ['points' => 25, 'daily_cap' => null],       // baca artikel / berita >= read_seconds
        'menu' => ['points' => 10, 'daily_cap' => null],       // buka menu (sekali per menu per hari)
    ],

    // Komentar lebih pendek dari ini tetap tersimpan, tapi tidak dapat poin (cegah spam "ok", "mantap")
    'comment_min_length' => 15,

    'read_seconds' => 180,

    // Menu yang memberi poin (key → awalan path di website, tanpa prefix bahasa)
    'menus' => [
        'tentang-kami' => '/tentang-kami',
        'panduan-praktis' => '/panduan-praktis',
        'kegiatan' => '/kegiatan',
        'galeri' => '/kegiatan/galeri',
        'berita' => '/berita',
        'ev-garage' => '/ev-garage',
        'spklu' => '/spklu',
    ],

];
