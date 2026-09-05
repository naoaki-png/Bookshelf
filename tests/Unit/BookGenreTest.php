<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 書籍とジャンルの多対多リレーションを確認する。
 *
 * books と genres は book_genres を介した多対多で、
 * 書籍側からは Book::genres()、ジャンル側からは Genre::books() で辿る。
 * 両方向から確認しているのは、片側の定義だけが正しくても、
 * もう片側の中間テーブル名や外部キーが誤っていると気づけないため。
 */
class BookGenreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1つの書籍に複数のジャンルを同期できる。
     *
     * 前提: 書籍1件 / ジャンル3件
     * 操作: Book::genres()->sync() に3件のIDを渡し、そのあと2件のIDで同期し直す
     * 期待: 1回目で3件が紐づく / 2回目で2件に置き換わる
     *
     * 2回目の同期まで見ている理由:
     * sync() は「渡した集合の状態に合わせる」メソッドで、渡さなかった紐付けは削除される。
     * 1回目だけを見ると、追加しかしない attach() に置き換えてもテストが通ってしまうため、
     * 置き換えが起きることまでを仕様として固定している。
     */
    public function test_書籍は複数のジャンルと同期できる(): void
    {
        $book = Book::factory()->create();
        $genres = Genre::factory()->count(3)->create();

        $book->genres()->sync($genres->pluck('id'));

        $this->assertSame(3, $book->genres()->count());
        $this->assertEqualsCanonicalizing(
            $genres->pluck('name')->all(),
            $book->genres()->pluck('name')->all()
        );

        $book->genres()->sync($genres->take(2)->pluck('id'));

        $this->assertSame(2, $book->genres()->count());
    }

    /**
     * 1つのジャンルに複数の書籍が紐づく。
     *
     * 前提: ジャンルAに書籍3件 / ジャンルBに書籍1件
     * 操作: ジャンルAから Genre::books() を辿る
     * 期待: 3件が返り、ジャンルBの書籍は含まれない
     *
     * ジャンルBを用意している理由:
     * 中間テーブルでの絞り込みが効いていないと全書籍が返ってしまうが、
     * 対象ジャンルの書籍しか存在しない状態だと件数が一致して気づけないため。
     */
    public function test_ジャンルは中間テーブルを介して複数の書籍に紐づく(): void
    {
        $genreA = Genre::factory()->create(['name' => '技術書']);
        $genreB = Genre::factory()->create(['name' => '小説']);

        $booksA = Book::factory()->count(3)->create();
        $bookB = Book::factory()->create();

        foreach ($booksA as $book) {
            $book->genres()->sync([$genreA->id]);
        }
        $bookB->genres()->sync([$genreB->id]);

        $this->assertSame(3, $genreA->books()->count());
        $this->assertEqualsCanonicalizing(
            $booksA->pluck('id')->all(),
            $genreA->books()->pluck('books.id')->all()
        );
    }
}
