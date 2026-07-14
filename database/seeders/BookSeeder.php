<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Book;
use App\Models\Genre;
use App\Models\BookUser;

class BookSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();
        $books = [
            ['no' => 1, 'title' => '吾輩は猫である', 'author' => '夏目漱石', 'isbn' => '9784101010014', 'published_date' => '1905-01-01', 'genres' => ['小説']],
            ['no' => 2, 'title' => '人を動かす', 'author' => 'D・カーネギー', 'isbn' => '9784422100524', 'published_date' => '1936-10-01', 'genres' => ['ビジネス, 自己啓発']],
            ['no' => 3, 'title' => 'リーダブルコード', 'author' => 'Dustin Boswell', 'isbn' => '9784873115658', 'published_date' => '2012-06-23', 'genres' => ['技術書']],
            ['no' => 4, 'title' => '7つの習慣', 'author' => 'スティーブン・R・コヴィー', 'isbn' => '9784863940246', 'published_date' => '2013-08-30', 'genres' => ['ビジネス', '自己啓発']],
            ['no' => 5, 'title' => '坊っちゃん', 'author' => '夏目漱石', 'isbn' => '9784101010021', 'published_date' => '1906-04-01', 'genres' => ['小説']],
            ['no' => 6, 'title' => 'サピエンス全史', 'author' => 'ユヴァル・ノア・ハラリ', 'isbn' => '9784309226712', 'published_date' => '2016-09-08', 'genres' => ['歴史', '科学']],
            ['no' => 7, 'title' => 'Clean Code', 'author' => 'Robert C. Martin', 'isbn' => '9784048930598', 'published_date' => '2017-12-18', 'genres' => ['技術書']],
            ['no' => 8, 'title' => '嫌われる勇気', 'author' => '岸見一郎・古賀史健', 'isbn' => '9784478025819', 'published_date' => '2013-12-13', 'genres' => ['自己啓発']],
            ['no' => 9, 'title' => '火花', 'author' => '又吉直樹', 'isbn' => '9784163902302', 'published_date' => '2015-03-11', 'genres' => ['小説']],
            ['no' => 10, 'title' => 'FACTFULNESS', 'author' => 'ハンス・ロスリング', 'isbn' => '9784822289607', 'published_date' => '2019-01-11', 'genres' => ['ビジネス', '科学']],
            ['no' => 11, 'title' => 'コンテナ物語', 'author' => 'マルク・レビンソン', 'isbn' => '9784822251468', 'published_date' => '2007-01-18', 'genres' => ['ビジネス', '歴史']],
        ];
        foreach ($books as $data) {
            $book = Book::firstOrCreate(
                ['isbn' => $data['isbn']],
                [
                    'title' => $data['title'],
                    'author' => $data['author'],
                    'description' => fake()->realText(),
                    'published_date' => $data['published_date'],
                    'image_url' => "https://placehold.co/200x300/e2e8f0/475569?text={$data['no']}",
                ]
            );
            $genreIds = Genre::whereIn('name', $data['genres'])->pluck('id');
            $book->genres()->sync($genreIds);
            BookUser::firstOrCreate([
                'user_id' => $user->id,
                'book_id' => $book->id,
            ]);
        }
    }

    //
}

