<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\FaqRequest;
use App\Models\Faq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FaqController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Faq::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $faqs = Faq::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.faqs.index', [
            'title' => 'FAQs',
            'faqs' => $faqs,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Faq::class);

        return $this->form(new Faq([
            'status' => ContentStatus::Draft,
            'sort_order' => Faq::nextSortOrder(),
        ]));
    }

    public function store(FaqRequest $request): RedirectResponse
    {
        Faq::create($request->faqData());

        return redirect()->route('faqs.index')->with('success', 'FAQ berhasil ditambahkan.');
    }

    public function edit(Faq $faq): View
    {
        Gate::authorize('update', $faq);

        return $this->form($faq);
    }

    public function update(FaqRequest $request, Faq $faq): RedirectResponse
    {
        $faq->update($request->faqData());

        return redirect()->route('faqs.index')->with('success', 'FAQ berhasil diperbarui.');
    }

    public function destroy(Faq $faq): RedirectResponse
    {
        Gate::authorize('delete', $faq);

        $faq->delete();

        return redirect()->route('faqs.index')->with('success', 'FAQ berhasil dihapus.');
    }

    private function form(Faq $faq): View
    {
        return view('pages.cms.faqs.form', [
            'title' => $faq->exists ? 'Edit FAQ' : 'Tambah FAQ',
            'faq' => $faq->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
