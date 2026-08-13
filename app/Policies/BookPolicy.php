<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Book $book): bool
    {
        //
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;

        //

    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Book $book): bool|Response
    {
        if ($user->id === $book->user_id) {
            return true;
        } else {
            return Response::deny('他の人が登録した書籍は更新できません。');
        }
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Book $book): bool|Response
    {
        if ($user->id === $book->user_id) {
            return true;
        } else {
            return Response::deny('他の人が登録した書籍は削除できません。');
        }
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Book $book): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Book $book): bool
    {
        //
    }
}
