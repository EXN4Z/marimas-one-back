<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCabangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // atau cek role admin di sini kalau perlu
    }

    public function rules(): array
    {
        return [
            'nama'    => 'required|string|max:255',
            'alamat'  => 'required|string',
            'telepon' => 'required|string|max:20',
            'link'    => 'required|string',
            'email'   => 'required|email|unique:users,email',
        ];
    }
}