<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CoverPageBlockType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCoverPageBlockRequest;
use App\Http\Requests\Admin\UpdateCoverPageBlockRequest;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Services\Admin\CoverPageBlockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CoverPageBlockController extends Controller
{
    public function store(
        StoreCoverPageBlockRequest $request,
        CoverPage $coverPage,
        CoverPageBlockService $service,
    ): RedirectResponse {
        $type = CoverPageBlockType::from($request->validated('block_type'));
        $block = $service->create($coverPage, $type, $request->validated());

        return back()->with([
            'status' => $type->label().' added.',
            'focused_block_id' => $block->id,
        ]);
    }

    public function update(
        UpdateCoverPageBlockRequest $request,
        CoverPageBlock $block,
        CoverPageBlockService $service,
    ): RedirectResponse {
        $service->update($block, $request->validated());

        return back()->with('status', 'Block updated.');
    }

    public function destroy(CoverPageBlock $block, CoverPageBlockService $service): RedirectResponse
    {
        $service->delete($block);

        return back()->with('status', 'Block removed.');
    }

    public function moveUp(CoverPageBlock $block, CoverPageBlockService $service): RedirectResponse
    {
        $service->moveUp($block);

        return back();
    }

    public function moveDown(CoverPageBlock $block, CoverPageBlockService $service): RedirectResponse
    {
        $service->moveDown($block);

        return back();
    }

    public function reorder(Request $request, CoverPage $coverPage, CoverPageBlockService $service): RedirectResponse
    {
        $request->validate([
            'block_ids' => ['required', 'array'],
            'block_ids.*' => ['integer', 'exists:cover_page_blocks,id'],
        ]);

        $service->reorder($coverPage, $request->input('block_ids'));

        return back()->with('status', 'Blocks reordered.');
    }

    public function importCsv(Request $request, CoverPageBlock $block, CoverPageBlockService $service): RedirectResponse
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $service->importCsv($block, $request->file('csv'));

        return back()->with('status', 'CSV imported.');
    }
}
