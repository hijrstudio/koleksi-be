<?php

namespace App\Enums;

enum ManagementGroup: string
{
    case Penasihat = 'penasihat';
    case Bidang = 'bidang';

    public function label(): string
    {
        return match ($this) {
            self::Penasihat => 'Penasihat',
            self::Bidang => 'Bidang',
        };
    }

    /**
     * Jabatan bawaan yang langsung terisi saat membuat periode baru.
     *
     * @return list<array{id: string, en: string}>
     */
    public function defaultPositions(): array
    {
        return match ($this) {
            self::Penasihat => [
                ['id' => 'Ketua Umum', 'en' => 'General Chairman'],
                ['id' => 'Wakil Ketua', 'en' => 'Vice Chairman'],
                ['id' => 'Sekretaris', 'en' => 'Secretary'],
                ['id' => 'Wakil Sekretaris', 'en' => 'Vice Secretary'],
                ['id' => 'Bendahara', 'en' => 'Treasurer'],
                ['id' => 'Wakil Bendahara', 'en' => 'Vice Treasurer'],
            ],
            self::Bidang => [
                ['id' => '', 'en' => ''],
            ],
        };
    }
}
