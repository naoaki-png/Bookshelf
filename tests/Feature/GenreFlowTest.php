<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ジャンルの削除条件と、ジャンル詳細の絞り込みを確認する(正常系)。
 *
 * ジャンルの削除には「書籍が0冊のときだけ消せる」という業務ルールがあり、
 * GenresController@destroy の if / else の両側が仕様になっている。
 * 消せる側だけを見ると、条件式が消えて常に削除されるコードを通してしまうので、
 * 消せない側も1本立てている。
 */
class GenreFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 書籍が1冊も紐づいていないジャンルは削除できる。
     *
     * 前提: 書籍が0冊のジャンル1件
     * 操作: DELETE /genres/{genre}
     * 期待: genres から消える / ジャンル一覧へリダイレクト / success メッセージが付く
     *
     * フラッシュメッセージまで見ている理由:
     * このコントローラーは削除できたときは success、できなかったときは error と、
     * セッションのキーを変えることで結果を伝えている。
     * リダイレクト先だけだと成功と失敗の区別がつかないケースがあるため、
     * どちらの分岐を通ったかをキーで確定させている。
     */
    public function test_書籍が0冊のジャンルは削除できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => '削除するジャンル']);

        $this->actingAs($user)
            ->delete('/genres/' . $genre->id)
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('genres', ['id' => $genre->id]);
    }

    /**
     * 書籍が紐づいているジャンルは削除されず、エラーメッセージが返る。
     *
     * 前提: 書籍1冊が紐づいたジャンル1件
     * 操作: DELETE /genres/{genre}
     * 期待: genres に残る / 元の画面へ戻る / error メッセージが付く
     *
     * 「残ること」を assertDatabaseHas で見ている理由:
     * このルートは削除できない場合でもエラー画面を出さず、
     * リダイレクトで戻すだけなので、レスポンスの見た目からは
     * 削除されたかどうかが分からない。DB を見ないと判定できない。
     *
     * from() を書いているのは back() で戻すため(お気に入りのトグルと同じ理由)。
     */
    public function test_書籍が紐づいたジャンルは削除できない(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => '書籍が入っているジャンル']);
        $book = Book::factory()->create();
        $book->genres()->sync([$genre->id]);

        $this->actingAs($user)
            ->from(route('genres.index'))
            ->delete('/genres/' . $genre->id)
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
    }

    /**
     * ジャンル詳細には、そのジャンルの書籍だけが並ぶ。
     *
     * 前提: ジャンルAに2冊、ジャンルBに1冊
     * 操作: ジャンルAで GET /genres/{genre}
     * 期待: 200 / Aの2冊のタイトルが出る / Bの1冊のタイトルは出ない
     *
     * Aを2冊にしている理由:
     * 1冊だと「先頭の1件だけ取れている」場合と区別がつかない。
     * Bを1冊置いているのは、絞り込みが外れて全件出る壊れ方を拾うため。
     */
    public function test_ジャンル詳細にはそのジャンルの書籍だけが並ぶ(): void
    {
        $user = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '小説']);
        $genreB = Genre::factory()->create(['name' => '技術書']);

        $bookA1 = Book::factory()->create(['title' => '小説の本その1']);
        $bookA2 = Book::factory()->create(['title' => '小説の本その2']);
        $bookB1 = Book::factory()->create(['title' => '技術書の本']);

        $bookA1->genres()->sync([$genreA->id]);
        $bookA2->genres()->sync([$genreA->id]);
        $bookB1->genres()->sync([$genreB->id]);

        $response = $this->actingAs($user)->get('/genres/' . $genreA->id);

        $response->assertOk();
        $response->assertSee('小説の本その1');
        $response->assertSee('小説の本その2');
        $response->assertDontSee('技術書の本');
    }
}
