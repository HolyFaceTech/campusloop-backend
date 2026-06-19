<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Models\ActivityLog;
use App\Rules\Recaptcha;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class AuthController extends Controller
{
    // LOGIN
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'g-recaptcha-response' => ['required', new Recaptcha()] 
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }

            if ($user->status !== 'active' || is_null($user->email_verified_at)) {
                return response()->json([
                    'message' => 'Account is inactive or not verified.',
                    'require_verification' => true,
                    'email' => $user->email 
                ], 403);
            }

            // Single Session Policy
            $user->tokens()->delete();
            // Generate New Token at Update Last Login
            $token = $user->createToken('campusloop-session')->plainTextToken;
            
            $user->update([
                'last_login_at' => now(),
                'current_session_id' => hash('sha256', $token) // Tracking reference
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Logged In',
                'description' => 'Successfully logged into the system.'
            ]);

            return response()->json([
                'message' => 'Login successful',
                'user' => $user,
                'token' => $token,
                'role' => $user->role
            ], 200);

        } catch (\Exception $e) {
            Log::error('AuthController login Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred during login. Please try again later.'], 500);
        }
    }

    // LOGOUT
    public function logout(Request $request)
    {
        try {
            $userId = $request->user()->id;

            ActivityLog::create([
                'user_id' => $userId,
                'action' => 'Logged Out',
                'description' => 'Securely logged out of the system.'
            ]);

            // Burahin ang current token ng user
            $request->user()->currentAccessToken()->delete();
            // Clear ang session tracking
            $request->user()->update(['current_session_id' => null]);

            return response()->json(['message' => 'Logged out successfully'], 200);

        } catch (\Exception $e) {
            Log::error('AuthController logout Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred during logout.'], 500);
        }
    }

    // FORGOT PASSWORD
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'g-recaptcha-response' => ['required', new Recaptcha()]
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json(['message' => 'If your email is registered, you will receive a secure reset link shortly.'], 200);
            }

            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => $token, 'created_at' => Carbon::now()]
            );

            $frontendUrl = rtrim((string) env('FRONTEND_URL', ''), '/');
            $resetLink = $frontendUrl.'/reset-password?token='.$token.'&email='.urlencode($request->email);

            dispatch(function () use ($resetLink, $user, $request) {
                try {
                    Mail::send('emails.reset_password', ['resetLink' => $resetLink, 'user' => $user], function ($message) use ($request) {
                        $message->to($request->email);
                        $message->subject('Reset Your CampusLoop Password');
                    });
                } catch (\Throwable $mailException) {
                    Log::error('AuthController forgotPassword mail failed: '.$mailException->getMessage(), [
                        'email' => $request->email,
                    ]);
                }
            });

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Requested Password Reset',
                'description' => 'Requested a secure link to reset account password.'
            ]);

            return response()->json(['message' => 'If your email is registered, you will receive a secure reset link shortly.'], 200);

        } catch (\Exception $e) {
            Log::error('AuthController forgotPassword Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while sending the reset link.'], 500);
        }
    }

    // RESET PASSWORD
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => [
                'required',
                'confirmed', // Hahanapin ang password_confirmation field galing sa React
                Password::min(8) // 8 characters pataas
                    ->letters()  // May letters (uppercase & lowercase)
                    ->mixedCase()
                    ->numbers()  // May numbers
                    ->symbols()  // May special characters
            ]
        ]);

        try {
            // I-verify ang Token at Email sa database
            $resetRequest = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$resetRequest || $resetRequest->token !== $request->token) {
                return response()->json(['message' => 'Invalid or expired reset token.'], 400);
            }

            if (Carbon::parse($resetRequest->created_at)->addHour()->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                return response()->json(['message' => 'Reset link has expired. Please request a new one.'], 400);
            }

            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $user->update([
                'password' => Hash::make($request->password)
            ]);

            // Burahin ang ginamit na token para hindi na magamit ulit
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Reset Account Password',
                'description' => 'Successfully changed the account password.'
            ]);

            return response()->json([
                'message' => 'Password has been successfully reset.',
                'status' => $user->status,
                'is_verified' => !is_null($user->email_verified_at),
                'role' => $user->role
            ], 200);

        } catch (\Exception $e) {
            Log::error('AuthController resetPassword Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while resetting your password.'], 500);
        }
    }

    // EMAIL VERIFICATION
    public function resendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if ($user->email_verified_at) {
                return response()->json(['message' => 'Account is already verified.'], 400);
            }

            $expires = now()->addHour()->timestamp;
            // Generate Secure Hash kasama ang expiration
            $hash = hash_hmac('sha256', $user->email . $expires, config('app.key'));
            // Buuin ang Verification Link pabalik sa React Frontend (Verify Page)
            $verifyLink = rtrim((string) env('FRONTEND_URL', ''), '/').'/verify?id='.$user->id.'&hash='.$hash.'&expires='.$expires.'&email='.urlencode($user->email);

            dispatch(function () use ($verifyLink, $user) {
                try {
                    Mail::send('emails.verify_email', ['verifyLink' => $verifyLink, 'user' => $user], function ($message) use ($user) {
                        $message->to($user->email);
                        $message->subject('Verify Your CampusLoop Account');
                    });
                } catch (\Throwable $mailException) {
                    Log::error('AuthController resendVerificationEmail mail failed: '.$mailException->getMessage(), [
                        'email' => $user->email,
                    ]);
                }
            });

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Requested Verification Email',
                'description' => 'Requested a new email verification link.'
            ]);

            return response()->json(['message' => 'Verification email sent successfully.'], 200);

        } catch (\Exception $e) {
            Log::error('AuthController resendVerificationEmail Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred while sending the verification email.'], 500);
        }
    }

    // VERIFY EMAIL
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'hash' => 'required',
            'expires' => 'required' 
        ], [
            'expires.required' => 'This verification link is outdated and no longer valid. Please request a new one.'
        ]);

        try {
            $user = User::find($request->id);

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            // ONE-TIME USE - Kung verified it means nagamit na ang link
            if ($user->email_verified_at) {
                return response()->json(['message' => 'This verification link has already been used. Your account is already active.'], 400);
            }

            // EXPIRATION CHECK (Strict 1 Hour)
            if (now()->timestamp > $request->expires) {
                return response()->json(['message' => 'Verification link has expired. Please request a new one.'], 400);
            }
            
            $expectedHash = hash_hmac('sha256', $user->email . $request->expires, config('app.key'));
            if (!hash_equals($expectedHash, $request->hash)) {
                return response()->json(['message' => 'Invalid verification link.'], 400);
            }

            $user->update([
                'email_verified_at' => now(),
                'status' => 'active' 
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Verified Account',
                'description' => 'Successfully verified email address and activated the account.'
            ]);

            return response()->json([
                'message' => 'Account successfully verified and activated.',
                'status' => $user->status,
                'role' => $user->role
            ], 200);

        } catch (\Exception $e) {
            Log::error('AuthController verifyEmail Error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => 'An unexpected error occurred during email verification.'], 500);
        }
    }
}