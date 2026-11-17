<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Mfa;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MfaController extends Controller
{
    public function __construct(private readonly Mfa $mfa) {}

    public function setup(Request $r): Response
    {
        abort_unless($r->hasSession(), 400, 'A stateful browser session is required.');

        return response()->json(['data' => $this->mfa->setup($r->user())]);
    }

    public function confirm(Request $r): Response
    {
        abort_unless($r->hasSession(), 400, 'A stateful browser session is required.');
        $data = $r->validate(['code' => 'required|string|regex:/^\d{6}$/']);
        $codes = $this->mfa->verify($r->user(), $data['code'], null, true);
        $r->session()->put('mfa_verified_at', now()->timestamp);

        return response()->json(['data' => ['recoveryCodes' => $codes]]);
    }

    public function challenge(Request $r): Response
    {
        abort_unless($r->hasSession(), 400, 'A stateful browser session is required.');
        $data = $r->validate(['code' => 'nullable|required_without:recoveryCode|string|regex:/^\d{6}$/', 'recoveryCode' => 'nullable|required_without:code|string|max:32']);
        $this->mfa->verify($r->user(), $data['code'] ?? null, $data['recoveryCode'] ?? null);
        $r->session()->put('mfa_verified_at', now()->timestamp);

        return response()->noContent();
    }
}
