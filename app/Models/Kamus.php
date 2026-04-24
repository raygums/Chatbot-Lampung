<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kamus extends Model
{
    // Tambahkan baris ini agar Laravel tidak mencari tabel 'kamuses'
    protected $table = 'kamus'; 

    // Jika kamu ingin semua kolom bisa diisi (mass assignment), tambahkan ini juga:
    protected $guarded = []; 
}
