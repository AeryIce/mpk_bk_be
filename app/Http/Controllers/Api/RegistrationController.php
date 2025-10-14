<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    // POST /api/registrations  (PUBLIC)
    public function store(Request $r) {
        $data = $r->validate([
            'instansi'   => ['required','string','max:150'],
            'pic'        => ['required','string','max:120'],
            'jabatan'    => ['nullable','string','max:120'],
            'email'      => ['required','email','max:190'],
            'wa'         => ['required','string','max:50'],
            'alamat'     => ['required','string','max:255'],
            'kelurahan'  => ['nullable','string','max:120'],
            'kecamatan'  => ['nullable','string','max:120'],
            'kota'       => ['required','string','max:120'],
            'provinsi'   => ['required','string','max:120'],
            'kodepos'    => ['nullable','string','max:20'],
            'lat'        => ['nullable','numeric','between:-90,90'],
            'lng'        => ['nullable','numeric','between:-180,180'],
            'catatan'    => ['nullable','string'],
        ]);

        $reg = Registration::create($data);

        return response()->json(['ok'=>true,'data'=>$reg], 201);
    }

    // GET /api/registrations  (ADMIN)
    public function index(Request $r) {
        $this->authorizeAdmin($r);
        $q = $r->string('q')->toString();
        $query = Registration::query();

        if ($q !== '') {
            $query->where(function($w) use ($q) {
                $w->where('instansi','like',"%$q%")
                  ->orWhere('pic','like',"%$q%")
                  ->orWhere('email','like',"%$q%")
                  ->orWhere('wa','like',"%$q%")
                  ->orWhere('kota','like',"%$q%")
                  ->orWhere('provinsi','like',"%$q%");
            });
        }

        $rows = $query->orderByDesc('id')->paginate(perPage: (int)$r->get('per_page', 20));
        return ['ok'=>true, ...$rows->toArray()];
    }

    private function authorizeAdmin(Request $r): void {
        $u = $r->user();
        if (!$u || !in_array($u->role ?? 'sponsor', ['admin','superadmin'], true)) {
            abort(403, 'forbidden');
        }
    }
}
