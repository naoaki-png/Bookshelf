<?php

namespace Tests\Unit;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * レビューといいねの多対多リレーションを確認する。
 *
 * reviews と users は review_likes を介した多対多で、
 * レビュー側からは Review::likedByUsers() で辿る。
 */
class ReviewLikeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1つのレビューに対して複数のユーザーがいいねできる。
     *
     * 前提: レビュー2件 / ユーザー3件
     * 操作: レビューAに3人、レビューBに1人がいいねを付ける
     * 期待: レビューAからは3人が返り、レビューBのいいねは混ざらない
     *
     * レビューBを用意している理由:
     * 中間テーブルでの絞り込みが効いていないと全ユーザーが返ってしまうが、
     * レビューが1件しか存在しない状態だと件数が一致して気づけないため。
     */
    public function test_レビューは中間テーブルを介して複数のユーザーからいいねされる(): void
    {
        $reviewA = Review::factory()->create();
        $reviewB = Review::factory()->create();
        $users = User::factory()->count(3)->create();

        $reviewA->likedByUsers()->sync($users->pluck('id'));
        $reviewB->likedByUsers()->sync([$users->first()->id]);

        $this->assertSame(3, $reviewA->likedByUsers()->count());
        $this->assertEqualsCanonicalizing(
            $users->pluck('id')->all(),
            $reviewA->likedByUsers()->pluck('users.id')->all()
        );
        $this->assertSame(1, $reviewB->likedByUsers()->count());
    }
}
