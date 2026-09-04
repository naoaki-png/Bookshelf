<?php

namespace App\Http\Controllers;

use App\Enums\ReadingPlanStatus;
use App\Http\Requests\StoreReadingPlanRequest;
use App\Http\Requests\UpdateReadingPlanRequest;
use App\Models\Book;
use App\Models\ReadingPlan;
use Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ReadingPlansController extends Controller
{
    /**
     * 読書計画の一覧を表示する。
     *
     * @param  Request  $request
     * @return View
     */
    public function index(Request $request): View
    {
        $currentStatus = $request->query('status', '');

        $readingPlans = Auth::user()->readingPlans()
            ->with('book')
            ->when($currentStatus, fn ($query) => $query->where('status', $currentStatus))
            ->orderBy('target_date')
            ->get();

        return view('reading-plans.index', compact('readingPlans', 'currentStatus'));
    }

    /**
     * 読書計画の新規作成画面を表示する。
     *
     * @return View
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
     * @param  StoreReadingPlanRequest  $request
     * @return RedirectResponse
     */
    public function store(StoreReadingPlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            Auth::user()->readingPlans()->create($data);
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['user_id' => Auth::id()]);

            return redirect(route('reading-plans.index'))->with('error', '予期せぬエラーが発生しました。もう一度やり直してください');
        }

        return redirect(route('reading-plans.index'))->with('success', '読書計画を登録しました');
    }

    /**
     * 読書計画の編集画面を表示する。
     *
     * @param  ReadingPlan  $plan
     * @return View
     */
    public function edit(ReadingPlan $plan): View
    {
        $this->authorize('update', $plan);

        return view('reading-plans.edit', ['readingPlan' => $plan]);
    }

    /**
     * 読書計画を更新する。
     *
     * @param  UpdateReadingPlanRequest  $request
     * @param  ReadingPlan  $plan
     * @return RedirectResponse
     */
    public function update(UpdateReadingPlanRequest $request, ReadingPlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        try {
            $plan->update($request->validated());
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['plan_id' => $plan->id, 'target_date' => $request->target_date]);

            return redirect(route('reading-plans.index'))->with('error', '予期せぬエラーが発生しました。もう一度やり直してください');
        }

        return redirect(route('reading-plans.index'))->with('success', '読書計画を更新しました');
    }

    /**
     * 読書計画を削除する。
     *
     * @param  ReadingPlan  $plan
     * @return RedirectResponse
     */
    public function destroy(ReadingPlan $plan): RedirectResponse
    {
        $this->authorize('delete', $plan);
        try {
            $plan->delete();
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['plan_id' => $plan->id]);

            return redirect(route('reading-plans.index'))->with('error', '予期せぬエラーが発生しました。もう一度やり直してください');
        }

        return redirect(route('reading-plans.index'))->with('success', '読書計画を削除しました');
    }

    /**
     * 読書計画を読了にする。
     *
     * @param  ReadingPlan  $plan
     * @return RedirectResponse
     */
    public function complete(ReadingPlan $plan): RedirectResponse
    {
        $this->authorize('complete', $plan);
        if ($plan->status === ReadingPlanStatus::Completed) {
            return redirect(route('reading-plans.index'))->with('error', 'この計画はすでに完了済みです');
        }
        try {
            $plan->status = ReadingPlanStatus::Completed;
            $plan->completed_at = now();
            $plan->save();
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['plan_id' => $plan->id]);

            return redirect(route('reading-plans.index'))->with('error', '予期せぬエラーが発生しました。もう一度やり直してください');
        }

        return redirect(route('reading-plans.index'))->with('success', '読書計画を完了しました');
    }
}
