<?php

namespace App\Http\Controllers;

use App\Http\Requests\PageStoreRequest;
use App\Http\Requests\PageUpdateRequest;
use App\Manager\PageManager;
use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PageController extends Controller
{
    protected PageManager $pageManager;

    public function __construct(PageManager $pageManager)
    {
        $this->pageManager = $pageManager;
    }

    public function index()
    {
        $statusFilter = request('status');
        $pages = $this->pageManager->all($statusFilter);

        return view('modules.page.index', compact('pages', 'statusFilter'));
    }

    public function create()
    {
        return view('modules.page.form', ['page' => null]);
    }

    public function store(PageStoreRequest $request)
    {
        try {
            DB::beginTransaction();
            $this->pageManager->create($request->validated());
            DB::commit();

            return redirect()->route('page.index')->with('success', 'Page created successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Page creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(Page $page)
    {
        return view('modules.page.show', compact('page'));
    }

    public function edit(Page $page)
    {
        return view('modules.page.form', compact('page'));
    }

    public function update(PageUpdateRequest $request, Page $page)
    {
        try {
            DB::beginTransaction();
            $this->pageManager->update($page, $request->validated());
            DB::commit();

            return redirect()->route('page.index')->with('success', 'Page updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Page update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(Page $page)
    {
        try {
            DB::beginTransaction();
            $this->pageManager->delete($page);
            DB::commit();

            return redirect()->route('page.index')->with('success', 'Page deleted successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Page deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleStatus(Page $page)
    {
        try {
            DB::beginTransaction();
            $this->pageManager->toggleStatus($page);
            DB::commit();

            return back()->with('success', 'Page status updated.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Page status toggle failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
