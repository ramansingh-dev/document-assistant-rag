<?php

use App\Livewire\Chat\ChatPage;
use Illuminate\Support\Facades\Route;

Route::get('/', ChatPage::class)->name('chat');
