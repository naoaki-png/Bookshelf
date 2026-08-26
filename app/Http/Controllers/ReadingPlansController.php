<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use App\Models\Book;
use App\Models\ReadingPlan;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\StoreReadingPlanRequest;
use App\Http\Requests\UpdateReadingPlanRequest;
use App\Enums\ReadingPlanStatus;

class ReadingPlansController extends Controller
{
    /**
     * 読書計画の一覧を表示する。
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        $currentStatus = $request->query('status', '');

        $readingPlans = Auth::user()->readingPlans()
            ->with('book')
            ->when($currentStatus, fn($query) => $query->where('status', $currentStatus))
            ->orderBy('target_date')
            ->get();

        return view('reading-plans.index', compact('readingPlans', 'currentStatus'));
    }
    /**
     * 読書計画の新規作成画面を表示する。
     *
     * @return \Illuminate\View\View
     */
    public function create(): View
    {
        $books = Book::select('id', 'title', 'author')
            ->orderBy('title')
            ->get();
        return view('reading-plans.create', compact('books'));
    }
    /**
     * 読書計画を新規作成する。
     *
     * @param  \App\Http\Requests\StoreReadingPlanRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreReadingPlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        Auth::user()->readingPlans()->create($data);
        return redirect(route('reading-plans.index'))->with('success', '読書計画を登録しました');
    }
    /**
     * 読書計画の編集画面を表示する。
     *
     * @param  \App\Models\ReadingPlan  $plan
     * @return \Illuminate\View\View
     */
    public function edit(ReadingPlan $plan): View
    {
        $this->authorize('update', $plan);
        return view('reading-plans.edit', ['readingPlan' => $plan]);
    }
    /**
     * 読書計画を更新する。
     *
     * @param  \App\Http\Requests\UpdateReadingPlanRequest  $request
     * @param  \App\Models\ReadingPlan  $plan
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateReadingPlanRequest $request, ReadingPlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        $plan->update($request->validated());
        return redirect(route('reading-plans.index'))->with('success', '読書計画を更新しました');
    }
    /**
     * 読書計画を削除する。
     *
     * @param  \App\Models\ReadingPlan  $plan
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(ReadingPlan $plan): RedirectResponse
    {
        $this->authorize('delete', $plan);
        $plan->delete();
        return redirect(route('reading-plans.index'))->with('success', '読書計画を削除しました');
    }
    /**
     * 読書計画を読了にする。
     *
     * @param  \App\Models\ReadingPlan  $plan
     * @return \Illuminate\Http\RedirectResponse
     */
    public function complete(ReadingPlan $plan): RedirectResponse
    {
        $this->authorize('complete', $plan);
        $plan->status = ReadingPlanStatus::Completed;
        $plan->completed_at = now();
        $plan->save();
        return redirect(route('reading-plans.index'))->with('success', '読書計画を完了しました');
    }
}
