@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- Logo KOLEKSI (PNG 140x60 dari website); alt = nama aplikasi bila gambar diblokir klien email --}}
<img src="{{ config('mail.logo_url') }}" class="brand-logo" width="140" height="60" alt="{{ trim(strip_tags($slot)) ?: config('app.name') }}">
</a>
</td>
</tr>
