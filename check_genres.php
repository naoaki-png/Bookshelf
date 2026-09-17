foreach (App\Models\Book::with("genres")->orderBy("id")->get() as ) {
    echo str_pad(->title, 24) . " => [" . ->genres->count() . "] " . ->genres->pluck("name")->implode(" / ") . PHP_EOL;
}
