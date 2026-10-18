<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Pencarian LIKE case-insensitive di kolom JSON (mis. title->id, title->en).
 *
 * Di MySQL, hasil json_extract ber-collation utf8mb4_bin sehingga LIKE biasa jadi case-sensitive
 * ("listrik" tidak menemukan "Listrik"). Kedua sisi di-lower() agar konsisten di semua database.
 */
class JsonSearch
{
    /**
     * Tambahkan "(lower(col1) like ? or lower(col2) like ? ...)" sebagai satu grup OR.
     * Untuk menggabungkan dengan kondisi lain (mis. orWhereHas), panggil di dalam closure where().
     *
     * @param  list<string>  $columns  path JSON, mis. ['title->id', 'title->en']
     */
    public static function orWhereLike(BuilderContract $query, array $columns, string $term): void
    {
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $grammar = $query->getQuery()->getGrammar();

        foreach ($columns as $column) {
            $query->orWhereRaw('lower('.$grammar->wrap($column).') like ?', [$like]);
        }
    }

    /** Pola LIKE (lowercase, wildcard di-escape) untuk kolom biasa. */
    public static function likePattern(string $term): string
    {
        return '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
    }
}
