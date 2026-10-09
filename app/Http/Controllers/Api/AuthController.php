<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
                function ($attribute, $value, $fail) {
                    $allowedDomains = ['dict.go.tz', 'nssf.or.tz', 'gmail.com'];
                    $domain = strtolower((string) substr((string) strrchr($value, '@'), 1));
                    if (! in_array($domain, $allowedDomains, true)) {
                        $fail('Registration is restricted to authorized domains (@dict.go.tz, @nssf.or.tz, @gmail.com).');
                    }
                },
            ],
            'password' => ['required', 'string', Password::min(8)->numbers()],
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'User registered successfully. A verification link has been sent to your email address.',
            'user'    => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login'    => 'required|string',
            'password' => 'required|string',
        ]);

        // The schema and login form use email; there is no username column.
        // Resolve the stored email case-insensitively on PostgreSQL as well.
        $attemptedUser = filter_var($request->login, FILTER_VALIDATE_EMAIL)
            ? User::whereRaw('LOWER(email) = ?', [strtolower($request->login)])->first()
            : null;

        if (! $attemptedUser || ! Auth::attempt(['email' => $attemptedUser->email, 'password' => $request->password])) {
            // Only logged when the identifier matches a real account — that's
            // the actionable "someone is guessing this account's password"
            // signal; a typo'd/unknown login isn't worth a row.
            if ($attemptedUser) {
                $this->recordAudit($attemptedUser->id, 'login_failed', $request);
            }

            throw ValidationException::withMessages([
                'login' => ['The provided credentials do not match our records.'],
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->is_active === false) {
            Auth::logout();
            $this->recordAudit($user->id, 'login_blocked_disabled', $request);
            throw ValidationException::withMessages([
                'login' => ['This account is disabled. Contact ICT Support.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $this->recordAudit($user->id, 'login_success', $request);

        return response()->json([
            'message'        => 'Login successful',
            'token'          => $token,
            'email_verified' => true,
            'user'           => $user->toAuthArray(),
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        if ($user) {
            $this->recordAudit($user->id, 'logout', $request);
        }

        return response()->json(['message' => 'Logout successful'], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $auth = $user->toAuthArray();

        return response()->json([
            'data'           => $auth,
            'user'           => $auth,
            'email_verified' => $user->hasVerifiedEmail(),
            'permissions'    => $auth['permissions'],
        ], 200);
    }

    public function users(): JsonResponse
    {
        $users = User::query()
            ->with('activeRoles')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->activeRoles
                    ->map(fn ($role) => [
                        'id' => $role->id,
                        'name' => $role->name,
                    ])
                    ->values(),
            ]);

        return response()->json(['data' => $users]);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.'], 200);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email address verified successfully.'], 200);
    }

    public function resendVerification(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.'], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email link resent successfully.'], 200);
    }

    private function recordAudit(int $userId, string $action, Request $request, ?string $description = null): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'ip_address' => $request->ip(),
        ]);
    }
}
