<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\ReadingPlan;

class ReadingPlanReminder extends Notification
{
    use Queueable;
    protected ReadingPlan $plan;
    protected string $timing;

    /**
     * Create a new notification instance.
     *
     * @param  ReadingPlan  $plan    通知対象の読書計画
     * @param  string  $timing  通知の種別
     */
    public function __construct(ReadingPlan $plan, string $timing)
    {
        $this->plan = $plan;
        $this->timing = $timing;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'timing' => $this->timing,
            'title' => $this->plan->book->title,
            'body' => match ($this->timing) {
                'three_days_before' => '読書計画の期限まであと3日です。',
                'on_due_date' => '読書計画の期限は本日です。',
                'three_days_after' => '読書計画の期限から3日が経過しました。',
            },
        ];
    }
}
