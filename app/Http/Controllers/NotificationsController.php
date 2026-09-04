<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class NotificationsController extends Controller
{
    /**
     * 通知一覧を表示する。
     *
     * @return View
     */
    public function index(): View
    {
        $notifications = Auth::user()->notifications()->get();

        return view('notifications.index', compact('notifications'));

    }

    /**
     * 通知を既読にする。
     *
     * @param  string  $id
     * @return RedirectResponse
     */
    public function read(string $id): RedirectResponse
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        try {
            $notification->markAsRead();
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['user_id' => Auth::id()]);

            return redirect(route('notifications.index'))->with('error', '予期せぬエラーが発生しました。もう一度やり直してください');
        }

        return redirect(route('notifications.index'))->with('success', '通知を既読にしました');
    }
}
