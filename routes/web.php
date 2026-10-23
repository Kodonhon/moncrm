<?php

use Illuminate\Support\Facades\Route;

// Le CRM s'ouvre directement sur la liste des clients et prospects
Route::redirect('/', '/admin/clients');
Route::redirect('/admin', '/admin/clients');

