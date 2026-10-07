<?php

$files = [
    // 1. Migration
    'database/migrations/2026_01_01_000001_create_smart_rt_tables.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('rts', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();
            $table->string('nama', 100);
            $table->string('rw', 10);
            $table->text('alamat');
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['user', 'warga', 'admin', 'pengelola', 'super_admin', 'developer'])->default('user');
            $table->enum('requested_role', ['none', 'admin', 'pengelola', 'super_admin', 'ketua'])->default('none');
            $table->enum('status', ['active', 'pending', 'rejected'])->default('active');
            $table->foreignId('rt_id')->nullable()->constrained('rts')->nullOnDelete();
        });

        Schema::create('user_warga_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('warga_id')->unique()->constrained('wargas')->cascadeOnDelete();
            $table->foreignId('verified_by')->constrained('users');
            $table->timestamp('verified_at');
            $table->timestamps();
        });

        Schema::create('warga_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('warga_id')->constrained('wargas')->cascadeOnDelete();
            $table->enum('status', ['diajukan', 'disetujui', 'ditolak', 'dibatalkan'])->default('diajukan');
            $table->unique(['user_id', 'warga_id']);
            $table->timestamps();
        });

        Schema::create('warga_claim_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('warga_claims')->cascadeOnDelete();
            $table->enum('check_type', ['nik_found', 'not_linked', 'same_rt', 'basic_match']);
            $table->boolean('passed');
            $table->text('detail');
            $table->unique(['claim_id', 'check_type']);
            $table->timestamps();
        });

        Schema::create('warga_claim_decisions', function (Blueprint $table) {
            $table->foreignId('claim_id')->primary()->constrained('warga_claims')->cascadeOnDelete();
            $table->foreignId('decided_by')->constrained('users');
            $table->enum('decision', ['disetujui', 'ditolak']);
            $table->text('reason');
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('kegiatan_hadirs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kegiatan_id')->constrained('kegiatans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['hadir', 'tidak']);
            $table->unique(['kegiatan_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('kegiatan_hadirs');
        Schema::dropIfExists('warga_claim_decisions');
        Schema::dropIfExists('warga_claim_checks');
        Schema::dropIfExists('warga_claims');
        Schema::dropIfExists('user_warga_links');
        Schema::dropIfExists('rts');
    }
};
PHP,

    // 2. Trait
    'app/Traits/BelongsToRt.php' => <<<'PHP'
<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToRt {
    public function scopeForRt(Builder $query, ?int $rtId): Builder {
        if ($rtId === null) {
            return $query;
        }
        return $query->where('rt_id', $rtId);
    }
}
PHP,

    // 3. Service
    'app/Services/NikVerificationService.php' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\Warga;
use App\Models\User;
use App\Models\UserWargaLink;
use App\Models\WargaClaim;
use App\Models\WargaClaimCheck;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NikVerificationService {
    public function check(User $user, string $nik, string $noKk, string $nama, string $tanggalLahir): array {
        $checks = [];
        
        $warga = Warga::where('nik', $nik)->first();
        $checks['nik_found'] = [
            'passed' => (bool)$warga,
            'detail' => $warga ? 'NIK ditemukan di database warga.' : 'NIK tidak terdaftar dalam data RT.'
        ];
        if (!$warga) return ['success' => false, 'failed_gate' => 'nik_found', 'checks' => $checks];

        $isLinked = UserWargaLink::where('warga_id', $warga->id)->exists();
        $checks['not_linked'] = [
            'passed' => !$isLinked,
            'detail' => !$isLinked ? 'NIK belum diklaim akun lain.' : 'NIK sudah tertaut ke akun lain.'
        ];
        if ($isLinked) return ['success' => false, 'failed_gate' => 'not_linked', 'checks' => $checks];

        $sameRt = $warga->keluarga && $warga->keluarga->rt_id === $user->scopeRtId();
        $checks['same_rt'] = [
            'passed' => (bool)$sameRt,
            'detail' => $sameRt ? 'RT sesuai dengan wilayah akun.' : 'RT pada NIK berbeda dengan RT lokasi akun Anda.'
        ];
        if (!$sameRt) return ['success' => false, 'failed_gate' => 'same_rt', 'checks' => $checks];

        $namaMatch = strtolower(trim($warga->nama)) === strtolower(trim($nama));
        $kkMatch = $warga->keluarga && $warga->keluarga->no_kk === $noKk;
        $tglMatch = Carbon::parse($warga->tanggal_lahir)->format('Y-m-d') === Carbon::parse($tanggalLahir)->format('Y-m-d');
        
        $basicMatch = $namaMatch && $kkMatch && $tglMatch;
        $checks['basic_match'] = [
            'passed' => $basicMatch,
            'detail' => $basicMatch ? 'Data pencocokan identitas valid.' : 'No KK, Nama, atau Tanggal Lahir tidak cocok.'
        ];

        if (!$basicMatch) return ['success' => false, 'failed_gate' => 'basic_match', 'checks' => $checks];

        return [
            'success' => true,
            'warga' => $warga,
            'checks' => $checks
        ];
    }

    public function submitClaim(User $user, Warga $warga, array $checksData): WargaClaim {
        return DB::transaction(function () use ($user, $warga, $checksData) {
            WargaClaim::where('user_id', $user->id)->where('status', 'diajukan')->delete();

            $claim = WargaClaim::create([
                'user_id' => $user->id,
                'warga_id' => $warga->id,
                'status' => 'diajukan'
            ]);

            foreach ($checksData as $type => $data) {
                WargaClaimCheck::create([
                    'claim_id' => $claim->id,
                    'check_type' => $type,
                    'passed' => $data['passed'],
                    'detail' => $data['detail'],
                ]);
            }

            return $claim;
        });
    }
}
PHP,

    // 4. Controller Verifikasi
    'app/Http/Controllers/WargaVerificationController.php' => <<<'PHP'
<?php

namespace App\Http/Controllers;

use Illuminate\Http\Request;
use App\Services\NikVerificationService;
use App\Models\WargaClaim;
use App\Models\UserWargaLink;
use App\Models\WargaClaimDecision;
use Illuminate\Support\Facades\DB;

class WargaVerificationController extends Controller {
    public function ajukan(Request $request, NikVerificationService $service) {
        $validated = $request->validate([
            'nik' => 'required|digits:16',
            'no_kk' => 'required|digits:16',
            'nama' => 'required|string|max:255',
            'tanggal_lahir' => 'required|date',
        ]);

        $user = auth()->user();
        $result = $service->check($user, $validated['nik'], $validated['no_kk'], $validated['nama'], $validated['tanggal_lahir']);

        if (!$result['success']) {
            return $request->expectsJson() 
                ? response()->json(['message' => $result['checks'][$result['failed_gate']]['detail']], 422)
                : back()->withErrors(['nik' => $result['checks'][$result['failed_gate']]['detail']]);
        }

        $claim = $service->submitClaim($user, $result['warga'], $result['checks']);

        return $request->expectsJson()
            ? response()->json(['message' => 'Pengajuan verifikasi berhasil dikirim.', 'claim' => $claim])
            : redirect()->back()->with('success', 'Pengajuan verifikasi NIK berhasil dikirim.');
    }

    public function setujui(Request $request, WargaClaim $claim) {
        $user = auth()->user();
        if (!$user->hasAtLeastRole('admin')) {
            abort(403, 'Akses ditolak.');
        }

        DB::transaction(function () use ($claim, $user) {
            UserWargaLink::where('user_id', $claim->user_id)->orWhere('warga_id', $claim->warga_id)->delete();

            UserWargaLink::create([
                'user_id' => $claim->user_id,
                'warga_id' => $claim->warga_id,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            $targetUser = $claim->user;
            if (!$targetUser->rt_id) {
                $targetUser->update(['rt_id' => $claim->warga->keluarga->rt_id]);
            }

            $claim->update(['status' => 'disetujui']);

            WargaClaimDecision::create([
                'claim_id' => $claim->id,
                'decided_by' => $user->id,
                'decision' => 'disetujui',
                'reason' => 'Verifikasi identitas dan dokumen sesuai.',
                'decided_at' => now(),
            ]);
        });

        return $request->expectsJson() 
            ? response()->json(['message' => 'Klaim NIK berhasil disetujui.'])
            : redirect()->back()->with('success', 'Klaim NIK disetujui.');
    }
}
PHP,

    // 5. Controller Kas
    'app/Http/Controllers/KasController.php' => <<<'PHP'
<?php

namespace App\Http/Controllers;

use Illuminate\Http\Request;
use App\Models\KasTransaksi;
use Illuminate\Support\Facades\Cache;

class KasController extends Controller {
    public function index(Request $request) {
        $rtId = auth()->user()->scopeRtId();
        $cacheKey = "kas_summary_rt_{$rtId}";

        $kasData = Cache::remember($cacheKey, 120, function () use ($rtId) {
            $query = KasTransaksi::forRt($rtId);
            return [
                'saldo_total' => $query->selectRaw("SUM(CASE WHEN arah = 'masuk' THEN jumlah ELSE -jumlah END) as total")->value('total') ?? 0,
                'deret_6_bulan' => $query->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') as periode, SUM(CASE WHEN arah = 'masuk' THEN jumlah ELSE -jumlah END) as net_jumlah")
                    ->groupBy('periode')
                    ->orderBy('periode', 'desc')
                    ->take(6)
                    ->get()
            ];
        });

        if ($request->expectsJson()) {
            return response()->json($kasData);
        }

        return view('admin.kas.index', compact('kasData'));
    }

    public function store(Request $request) {
        $validated = $request->validate([
            'tanggal' => 'required|date',
            'deskripsi' => 'required|string|max:500',
            'arah' => 'required|in:masuk,keluar',
            'jumlah' => 'required|numeric|min:1',
            'kategori' => 'nullable|string|max:100',
        ]);

        $rtId = auth()->user()->scopeRtId();
        $validated['rt_id'] = $rtId;

        KasTransaksi::create($validated);

        Cache::forget("kas_summary_rt_{$rtId}");
        Cache::forget("kas_laporan_rt_{$rtId}");
        Cache::forget("dashboard_stat_rt_{$rtId}");
        Cache::forget("iuran_summary_rt_{$rtId}");

        return $request->expectsJson()
            ? response()->json(['message' => 'Transaksi kas berhasil dicatat.'], 201)
            : redirect()->back()->with('success', 'Transaksi kas berhasil dicatat.');
    }
}
PHP,

    // 6. Middleware
    'app/Http/Middleware/EnsureRtAccess.php' => <<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRtAccess {
    public function handle(Request $request, Closure $next) {
        $user = auth()->user();
        if (!$user) {
            return $request->expectsJson() 
                ? response()->json(['message' => 'Unauthenticated.'], 401) 
                : redirect('/login');
        }

        if ($user->role === 'developer') {
            return $next($request);
        }

        $rtId = $request->route('rt_id') ?? $request->input('rt_id');
        if ($rtId && (int)$rtId !== (int)$user->scopeRtId()) {
            return $request->expectsJson() 
                ? response()->json(['message' => 'Akses Lintas-RT Ditolak.'], 403) 
                : abort(403, 'Akses Lintas-RT Ditolak.');
        }

        return $next($request);
    }
}
PHP,
];

foreach ($files as $path => $content) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
    echo "Dibuat: {$path}\n";
}

echo "\nSelesai! Seluruh struktur Backend berhasil dipasang.\n";
