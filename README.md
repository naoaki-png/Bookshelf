# COACHTECH Bookshelf 書籍レビューアプリ

## 概要

書籍レビューアプリケーション`「BookShelf」`です。
ユーザーは書籍を登録・閲覧し、レビューの投稿やお気に入り登録ができます。ジャンルによる分類やレビューへのいいね機能、平均評価に基づくランキング機能も備えています。外部アプリケーション向けの公開API（JSON）も提供します。

## 作成者

杉本有聡

## 使用技術

- PHP 8.5
- Laravel 10.50.2
- MySQL 8.4
- Docker / Docker Compose / Laravel Sail
- Vite / Tailwind CSS 3.4
- Laravel Fortify（認証）
- Sanctum 3.3
- phpMyAdmin
- notification
- Enum

## ER図

![ER図](docs/er-diagram.png)

## 環境開発URL

http://localhost

## 動作環境

- Docker
- Docker Compose

※ Windowsの場合はWSL2の利用を推奨します。

## 環境構築手順

### 1. リポジトリをクローン

```
git clone https://github.com/naoaki-png/Bookshelf.git
```

### 2. .envファイルの準備

`.env.example` をコピーして `.env` を作成します。

```
cp .env.example .env
```

### 3. Laravel Sailのインストール

プロジェクト作成後、`bookshelf-app` ディレクトリに移動し、`Laravel Sail`をインストールします。

#### プロジェクトディレクトリに移動

```
cd bookshelf-app
```

#### Laravel Sailをインストール

```
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html -e COMPOSER_CACHE_DIR=/tmp/composer_cache laravelsail/php82-composer:latest composer require laravel/sail --dev
```

#### Sailの設定ファイルをパブリッシュ（MySQLを選択）

```
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html -e COMPOSER_CACHE_DIR=/tmp/composer_cache laravelsail/php82-composer:latest php artisan sail:install --with=mysql
```

※M1/M2/M3 Mac（Apple Silicon）をお使いの方：
`sail up -d` 実行時に `no matching manifest for linux/arm64/v8` エラーが発生した場合、`compose.yaml` の `mysql` サービスに `platform: 'linux/amd64'` を追加してください。

### 4. Laravel Sailの起動

フロントエンドのスタイリングにTailwind CSSを使用します。
以下の手順でセットアップを行ってください。

#### Sailコンテナの起動

```
./vendor/bin/sail up -d
```

#### エイリアスの設定（任意）

1. 毎回 `./vendor/bin/sail` と入力する手間を省くため、`sail` だけで実行できるようにエイリアスを設定します。

```
echo "alias sail='[ -f sail ] && bash sail || bash vendor/bin/sail'" >> ~/.zshrc
```

2. シェルを再起動するか、新しいターミナルを開いてエイリアスを有効にする

```
exec $SHELL
```

### 5.NPM依存パッケージのインストール

```
sail npm install
```

### 6. アプリケーションキーの生成

```
sail artisan key:generate
```

### 7. データベースのマイグレーションと初期データ投入

以下のコマンドでテーブルを作成し、ダミーデータを投入します。

```
sail artisan migrate:fresh --seed
```

※コンテナ内にデータが残っており、エラーが生じているケースなどがあります。 その場合は、以下のコマンドを順に実行して各コンテナを再起動して下さい。

```
sail down -v
```

```
sail up -d
```

```
sail artisan migrate:fresh --seed
```

### 8. Vite開発サーバーの起動

```
sail npm run dev
```

### 9. アプリケーションへのアクセス

ブラウザで[http://localhost](http://localhost)にアクセスします。

## テスト実行

```
sail artisan test
```

## 機能一覧

- ユーザー認証（登録、ログイン、ログアウト）
- 書籍登録・更新・削除・検索
- 書籍詳細・レビュー評価（登録・更新・削除）・レビューに対していいね機能
- 読書計画登録・更新・削除
- ジャンル管理登録・更新削除
- ランキング表示
- 書籍お気に入り登録・削除
- マイレポート表示

## APIエンドポイント一覧

| HTTPメソッド | URI                  | 概要                                           |
| :----------- | :------------------- | :--------------------------------------------- |
| GET          | /api/v1/books        | 書籍一覧 (検索・ページネーション付き)          |
| GET          | /api/v1/books/{book} | 書籍詳細                                       |
| POST         | /api/v1/books        | 書籍の新規登録（Sanctum）                      |
| PUT          | /api/v1/books/{book} | 書籍更新（Sanctum + BookPolicy（所有者のみ）） |
| DELETE       | /api/v1/books/{book} | 書籍削除（Sanctum + BookPolicy（所有者のみ）） |
| POST         | /api/v1/login        |
| DELETE       | /api/v1/logout       |
