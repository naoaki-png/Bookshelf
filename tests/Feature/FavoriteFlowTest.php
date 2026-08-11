<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * お気に入りの登録解除と、お気に入り一覧の絞り込みを確認する(正常系)。
 *
 * お気に入りは favorites テーブルに user_id と book_id を1行入れるだけの機能で、
 * 見るべき点は「切り替わること」と「他人の分が混ざらないこと」の2つ。
 */
class FavoriteFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * お気に入りは、同じ URL を叩くたびに付いたり外れたりする(トグル)。
     *
     * 前提: ユーザー1人、書籍1冊。favorites はまだ0行
     * 操作: POST /books/{book}/favorites を2回叩く
     * 期待: 1回目で favorites に1行 / 2回目でその行が消える / 元の画面へ戻る
     *
     * from() で書籍詳細から来たことにしている理由:
     * FavoritesController@toggle は back() で返しており、これは
     * 「直前にいたページ」に戻すという意味。テストは HTTP リクエストを
     * 直接投げるので直前のページが存在せず、from() を書かないと
     * 戻り先が想定と変わってしまう。押した場所へ戻ることまで含めて仕様。
     */
    public function test_お気に入りは2回叩くと元に戻る(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        // 1回目 -- お気に入りに入る
        $this->actingAs($user)
            ->from(route('books.show', $book))
            ->post('/books/' . $book->id . '/favorites')
            ->assertRedirect(route('books.show', $book));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);

        // 2回目 -- 同じ URL でお気に入りから外れる
        $this->actingAs($user)
            ->from(route('books.show', $book))
            ->post('/books/' . $book->id . '/favorites');

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseCount('favorites', 0);
    }

    /**
     * お気に入り一覧には、自分がお気に入りにした書籍だけが並ぶ。
     *
     * 前提: 自分がお気に入りにした書籍1冊、他人がお気に入りにした書籍1冊、
     *       誰もお気に入りにしていない書籍1冊
     * 操作: GET /favorites
     * 期待: 200 / 自分の1冊のタイトルが出る / 残り2冊のタイトルは出ない
     *
     * 3冊置いている理由:
     * FavoritesController@index は $user->favoriteBooks() で絞っている。
     * ここが Book::all() に書き換わると全部出るし、絞り込みの条件を
     * 間違えると他人の分まで出る。この2つの壊れ方は原因が違うので、
     * 「他人がお気に入りにした本」と「誰のお気に入りでもない本」を
     * 別々に置いて、どちらも出ないことを確かめている。
     */
    public function test_お気に入り一覧には自分がお気に入りにした書籍だけが並ぶ(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $myFavorite = Book::factory()->create(['title' => '自分がお気に入りにした本']);
        $othersFavorite = Book::factory()->create(['title' => '他人がお気に入りにした本']);
        $notFavorited = Book::factory()->create(['title' => '誰のお気に入りでもない本']);

        Favorite::factory()->create(['user_id' => $user->id, 'book_id' => $myFavorite->id]);
        Favorite::factory()->create(['user_id' => $otherUser->id, 'book_id' => $othersFavorite->id]);

        $response = $this->actingAs($user)->get('/favorites');

        $response->assertOk();
        $response->assertSee('自分がお気に入りにした本');
        $response->assertDontSee('他人がお気に入りにした本');
        $response->assertDontSee('誰のお気に入りでもない本');
    }
}
